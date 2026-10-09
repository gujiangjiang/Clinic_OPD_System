<?php
/**
 * ============================================================
 * services/system/LogArchiver.php — 日志滑动窗口归档/清理（三库自适应）
 * ============================================================
 * 说明：面向追加写（Append-Only）日志表的生命周期维护，按创建时间
 * 淘汰超期数据，规避大事务锁死与存储膨胀。严格遵守【三库异构互迁零破坏】：
 *   - 不创建 PostgreSQL 声明式子表分区（子表会被跨库反射误识别为业务表）；
 *   - 不改变表逻辑结构（仅删数据），不影响 AUTOINCREMENT/IDENTITY 自增。
 *
 * 各驱动最低成本策略：
 *   - MySQL ：表已配置物理分区时优先 `ALTER TABLE ... DROP PARTITION`；
 *             未分区则 `DELETE ... LIMIT` 分批清理。
 *   - PostgreSQL ：`DELETE ... WHERE ctid IN (SELECT ... LIMIT n)` 分批，
 *             避免单条巨量 DELETE 触发膨胀；可选 VACUUM(ANALYZE)。
 *   - SQLite ：事务内 `DELETE ... WHERE rowid IN (SELECT ... LIMIT n)` 循环，
 *             结束后 `PRAGMA incremental_vacuum` 回收页，防文件膨胀与锁死。
 *
 * 安全：表名/列名经白名单校验；仅允许本类登记的日志表。
 * ============================================================ */
class LogArchiver {

    /** 登记可归档的日志表 → 时间列（白名单，防注入/误删业务表） */
    const TABLES = array(
        'system_logs' => 'created_at',
    );

    /** 单批删除行数（兼顾锁窗口与吞吐） */
    const DEFAULT_BATCH = 1000;

    /** 单表单次最大处理批数（防御性上限，避免异常时空转） */
    const MAX_BATCHES = 10000;

    /**
     * 执行归档清理
     * @param int        $days    保留天数（<=0 表示不按时间清理）
     * @param int        $batch   单批行数
     * @param bool       $dryRun  true 仅统计待清理行数不删除
     * @param array|null $tables  指定表（null = 全部登记表）
     * @return array   { driver, cutoff, dry_run, tables: { table: {deleted, mode, batches} } }
     */
    public static function archive($days, $batch = self::DEFAULT_BATCH, $dryRun = false, $tables = null) {
        $days = (int)$days;
        $batch = max(100, min(50000, (int)$batch));
        $pdo = DatabaseManager::getMain();
        $driver = DatabaseManager::driver();
        $targets = self::resolveTables($tables);
        $cutoff = $days > 0 ? date('Y-m-d H:i:s', time() - $days * 86400) : '';
        $out = array(
            'driver'  => $driver,
            'days'    => $days,
            'cutoff'  => $cutoff,
            'dry_run' => (bool)$dryRun,
            'tables'  => array(),
        );
        foreach ($targets as $table => $col) {
            $out['tables'][$table] = self::archiveTable($pdo, $driver, $table, $col, $cutoff, $batch, $dryRun);
        }
        // SQLite：清理后回收空闲页（auto_vacuum=incremental 时生效；否则空操作）
        if (!$dryRun && $driver === 'sqlite') {
            try { $pdo->exec('PRAGMA incremental_vacuum'); } catch (Exception $ex) {}
        }
        // PostgreSQL：可选维护（回收 + 统计），无权限时忽略
        if (!$dryRun && $driver === 'pgsql') {
            foreach (array_keys($targets) as $table) {
                try { $pdo->exec('VACUUM (ANALYZE) "' . $table . '"'); } catch (Exception $ex) {}
            }
        }
        return $out;
    }

    /** 解析并校验目标表集合（指定表名必须全部命中白名单，未知表名直接报错，避免误清理全部） */
    private static function resolveTables($tables) {
        if ($tables === null || $tables === array() || $tables === '') {
            return self::TABLES;
        }
        $req = is_array($tables) ? $tables : array_map('trim', explode(',', (string)$tables));
        $out = array();
        $unknown = array();
        foreach ($req as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            if (isset(self::TABLES[$t])) $out[$t] = self::TABLES[$t];
            else $unknown[] = $t;
        }
        if ($unknown) {
            throw new InvalidArgumentException('未知日志表：' . implode(',', $unknown)
                . '（可用：' . implode(',', array_keys(self::TABLES)) . '）');
        }
        return $out;
    }

    /** 单表归档（按驱动选择最低成本策略） */
    private static function archiveTable($pdo, $driver, $table, $col, $cutoff, $batch, $dryRun) {
        if ($cutoff === '') {
            return array('deleted' => 0, 'mode' => 'skip-no-retention', 'batches' => 0);
        }
        // MySQL：优先分区裁剪
        if ($driver === 'mysql') {
            $parts = self::mysqlDroppablePartitions($pdo, $table, $cutoff);
            if ($parts !== null && count($parts) > 0) {
                if ($dryRun) {
                    return array('deleted' => -1, 'mode' => 'mysql-drop-partition', 'partitions' => $parts, 'batches' => 0);
                }
                try {
                    $pdo->exec('ALTER TABLE `' . $table . '` DROP PARTITION ' . implode(',', array_map(function ($p) { return '`' . $p . '`'; }, $parts)));
                    return array('deleted' => -1, 'mode' => 'mysql-drop-partition', 'partitions' => $parts, 'batches' => 0);
                } catch (Exception $ex) {
                    // 分区裁剪失败：降级为行级清理
                }
            }
        }
        return self::batchDelete($pdo, $driver, $table, $col, $cutoff, $batch, $dryRun);
    }

    /**
     * 计算可安全丢弃的 MySQL 分区（仅返回边界可解析且早于 cutoff 的分区名）。
     * 数据范围分区（RANGE COLUMNS 日期字符串 或 RANGE(TO_DAYS/UNIX_TIMESTAMP)）；
     * 无法判定时返回空数组（调用方降级为行级清理），绝不误删。
     */
    private static function mysqlDroppablePartitions($pdo, $table, $cutoff) {
        try {
            $st = $pdo->prepare(
                'SELECT PARTITION_NAME, PARTITION_DESCRIPTION FROM information_schema.PARTITIONS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL '
                . 'ORDER BY PARTITION_ORDINAL_POSITION'
            );
            $st->execute(array($table));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            return null;   // 查询失败 → 不定论，降级行级清理
        }
        if (!$rows) return array();   // 未分区
        $cutTs = strtotime($cutoff);
        $drop = array();
        foreach ($rows as $r) {
            $desc = trim((string)$r['PARTITION_DESCRIPTION']);
            if ($desc === '' || strtoupper($desc) === 'MAXVALUE') continue;   // 边界分区永不删
            $boundary = self::partitionBoundaryTs($desc);
            if ($boundary === null) return array();   // 存在无法解析的边界 → 整体放弃分区策略
            if ($boundary <= $cutTs) $drop[] = $r['PARTITION_NAME'];
        }
        return $drop;
    }

    /** 解析分区边界为时间戳：支持 日期字符串 / TO_DAYS 整数 / UNIX 时间戳整数 */
    private static function partitionBoundaryTs($desc) {
        $desc = trim($desc, " \t'\"");
        if ($desc === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $desc)) {
            $ts = strtotime(substr($desc, 0, 10));
            return $ts ? $ts : null;
        }
        if (ctype_digit($desc)) {
            $n = (int)$desc;
            // TO_DAYS 值较大（约 73 万～80 万）；UNIX 时间戳约 1.6e9
            if ($n > 2000000000) return $n;                       // UNIX 时间戳
            if ($n > 700000 && $n < 1000000) {                     // TO_DAYS → 日期
                $ts = ($n - 719163) * 86400;   // 1970-01-01 的 TO_DAYS = 719163
                return $ts;
            }
        }
        return null;
    }

    /** 驱动自适应分批删除 */
    private static function batchDelete($pdo, $driver, $table, $col, $cutoff, $batch, $dryRun) {
        if ($dryRun) {
            $sql = 'SELECT COUNT(*) FROM ' . self::qid($driver, $table) . ' WHERE ' . self::qid($driver, $col) . ' < ?';
            try { $st = $pdo->prepare($sql); $st->execute(array($cutoff)); $n = (int)$st->fetchColumn(); }
            catch (Exception $ex) { $n = 0; }
            return array('deleted' => $n, 'mode' => 'dry-run-count', 'batches' => 0);
        }
        $deleted = 0;
        $batches = 0;
        while ($batches < self::MAX_BATCHES) {
            $batches++;
            try {
                if ($driver === 'sqlite') {
                    $pdo->beginTransaction();
                    $sql = 'DELETE FROM "' . $table . '" WHERE rowid IN (SELECT rowid FROM "' . $table . '" WHERE "' . $col . '" < ? LIMIT ' . (int)$batch . ')';
                    $st = $pdo->prepare($sql);
                    $st->execute(array($cutoff));
                    $n = $st->rowCount();
                    $pdo->commit();
                } elseif ($driver === 'pgsql') {
                    $sql = 'DELETE FROM "' . $table . '" WHERE ctid IN (SELECT ctid FROM "' . $table . '" WHERE "' . $col . '" < ? LIMIT ' . (int)$batch . ')';
                    $st = $pdo->prepare($sql);
                    $st->execute(array($cutoff));
                    $n = $st->rowCount();
                } else { // mysql
                    $sql = 'DELETE FROM `' . $table . '` WHERE `' . $col . '` < ? LIMIT ' . (int)$batch;
                    $st = $pdo->prepare($sql);
                    $st->execute(array($cutoff));
                    $n = $st->rowCount();
                }
            } catch (Exception $ex) {
                if ($driver === 'sqlite' && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Exception $e2) {} }
                if (defined('DEBUG') && DEBUG) error_log('[LogArchiver] 清理失败 ' . $table . '：' . $ex->getMessage());
                break;
            }
            $deleted += $n;
            if ($n < $batch) break;   // 已无更多超期数据
        }
        return array('deleted' => $deleted, 'mode' => 'batch-delete', 'batches' => $batches);
    }

    /** 标识符引用（白名单已保证仅字母数字下划线，此处按驱动加引号） */
    private static function qid($driver, $name) {
        return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }
}
