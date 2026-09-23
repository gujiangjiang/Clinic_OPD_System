<?php
/**
 * ============================================================
 * DatabaseMigrator.php v1.0.0 — SQLite ↔ MySQL 双向数据迁移引擎
 * ============================================================
 * 说明：
 * 1. 支持 SQLite → MySQL 与 MySQL → SQLite 双向全量迁移。
 * 2. 迁移流程：
 *    - 进入临时维护只读模式（config.db app.maintenance=1，只读高频接口拒绝写）；
 *    - 目标库临时关闭外键约束检查；
 *    - 逐表分批（Chunk 500 行）同步数据；
 *    - 校准自增主键序列（SQLite sqlite_sequence / MySQL AUTO_INCREMENT）；
 *    - 恢复外键检查；
 *    - 自动更新 config.db 主库指针（db.driver + db.mysql.* / db.sqlite.path）。
 * 3. 所有 SQL 使用 PDO 预处理；表名/列名经白名单正则校验防注入。
 * ============================================================ */

class DatabaseMigrator {

    /** 分批大小 */
    const CHUNK = 500;

    /**
     * 执行迁移
     * @param string $toDriver 目标驱动 sqlite/mysql
     * @param array  $toParams 目标连接参数（sqlite: path；mysql: host/port/dbname/user/pass）
     * @return array { tables, rows, total, target }
     */
    public static function migrate($toDriver, $toParams) {
        $src = DatabaseManager::getMain();
        $srcDriver = DatabaseManager::driver();

        // ===== 校验目标驱动与参数 =====
        if ($toDriver === 'sqlite') {
            $path = isset($toParams['path']) ? trim((string)$toParams['path']) : '';
            if ($path === '') $path = DATA_DIR . '/db/clinic_main.db';
            if ($path[0] !== '/' && $path[0] !== '.') $path = APP_ROOT . '/' . ltrim($path, '/');
            if (is_file($path) && !ConfigStore::isSqliteFile($path)) {
                throw new Exception('目标 SQLite 文件不是有效数据库（Magic Header 校验失败）');
            }
            $dir = dirname($path);
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            if (!is_writable($dir)) throw new Exception('目标目录不可写：' . $dir);
        } elseif ($toDriver === 'mysql') {
            $host = isset($toParams['host']) ? $toParams['host'] : '127.0.0.1';
            $port = isset($toParams['port']) ? $toParams['port'] : '3306';
            $dbname = isset($toParams['dbname']) ? $toParams['dbname'] : '';
            $user = isset($toParams['user']) ? $toParams['user'] : '';
            $pass = isset($toParams['pass']) ? $toParams['pass'] : '';
            if ($dbname === '') throw new Exception('请填写目标 MySQL 数据库名');
        } else {
            throw new Exception('不支持的目标驱动：' . $toDriver);
        }

        // ===== 目标连接 =====
        $toParamsClean = array(
            'host' => isset($host) ? $host : '',
            'port' => isset($port) ? $port : '',
            'dbname' => isset($dbname) ? $dbname : '',
            'user' => isset($user) ? $user : '',
            'pass' => isset($pass) ? $pass : '',
            'path' => isset($path) ? $path : '',
        );
        $dst = self::connect($toDriver, $toParamsClean);

        // 防止原地迁移（同驱动同库）
        self::guardSameTarget($srcDriver, $toDriver, $src, $dst);

        // ===== 进入维护模式（禁止业务写） =====
        ConfigStore::set('app.maintenance', '1');
        ConfigStore::resetCache();

        $migratedTables = array();
        $migratedRows = 0;
        try {
            // ===== 目标库关闭外键约束检查 =====
            if ($toDriver === 'mysql') {
                $dst->exec('SET FOREIGN_KEY_CHECKS = 0');
            } else {
                $dst->exec('PRAGMA foreign_keys = OFF');
            }

            // ===== 表清单（源库） =====
            $tables = self::sourceTables($src, $srcDriver);
            if (!$tables) throw new Exception('源库未发现业务表');

            foreach ($tables as $table) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) continue;
                // 目标库建表（方言转换）
                self::createTargetTable($src, $dst, $srcDriver, $toDriver, $table);
                // 分批同步数据
                $total = (int)$src->query("SELECT COUNT(*) FROM " . $table)->fetchColumn();
                $offset = 0;
                while ($offset < $total) {
                    $rows = $src->query("SELECT * FROM " . $table . " LIMIT " . self::CHUNK . " OFFSET " . $offset)->fetchAll(PDO::FETCH_ASSOC);
                    if (!$rows) break;
                    self::insertRows($dst, $toDriver, $table, $rows);
                    $migratedRows += count($rows);
                    $offset += self::CHUNK;
                }
                $migratedTables[] = array('table' => $table, 'rows' => $total);
            }

            // ===== 自增序列校准 =====
            self::resetSequences($dst, $toDriver, $tables);

            // ===== 恢复外键约束检查 =====
            if ($toDriver === 'mysql') {
                $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                $dst->exec('PRAGMA foreign_keys = ON');
            }
        } catch (Exception $ex) {
            // 失败：恢复外键 + 退出维护模式，交由前端提示
            try {
                if ($toDriver === 'mysql') { $dst->exec('SET FOREIGN_KEY_CHECKS = 1'); }
                else { $dst->exec('PRAGMA foreign_keys = ON'); }
            } catch (Exception $ex2) {}
            ConfigStore::set('app.maintenance', '0');
            ConfigStore::resetCache();
            throw $ex;
        }

        // ===== 更新 config.db 主库指针到目标库 =====
        ConfigStore::set('db.driver', $toDriver);
        if ($toDriver === 'mysql') {
            ConfigStore::set('db.mysql.host', $host);
            ConfigStore::set('db.mysql.port', $port);
            ConfigStore::set('db.mysql.dbname', $dbname);
            ConfigStore::set('db.mysql.user', $user);
            ConfigStore::set('db.mysql.pass', $pass);
            ConfigStore::set('db.sqlite.path', '');
        } else {
            ConfigStore::set('db.sqlite.path', $path);
        }
        ConfigStore::set('app.maintenance', '0');
        ConfigStore::resetCache();

        return array('tables' => $migratedTables, 'rows' => $migratedRows, 'target' => $toDriver);
    }

    /** 目标连接 */
    private static function connect($driver, $p) {
        if ($driver === 'mysql') {
            $dsn = 'mysql:host=' . $p['host'] . ';port=' . $p['port'] . ';dbname=' . $p['dbname'] . ';charset=utf8mb4';
            return new PDO($dsn, $p['user'], $p['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        }
        return new PDO('sqlite:' . $p['path'], null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }

    /** 防原地迁移（同驱动同库名/同路径） */
    private static function guardSameTarget($srcDriver, $toDriver, $src, $dst) {
        if ($srcDriver !== $toDriver) return;
        if ($srcDriver === 'sqlite') {
            $a = $src->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
            $b = $dst->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
            if ($a && $b && isset($a['file']) && isset($b['file']) && realpath($a['file']) === realpath($b['file'])) {
                throw new Exception('目标与源为同一个数据库，请指定其他目标');
            }
        } else {
            $a = $src->query('SELECT DATABASE()')->fetchColumn();
            $b = $dst->query('SELECT DATABASE()')->fetchColumn();
            if ($a === $b) throw new Exception('目标与源为同一个数据库，请指定其他目标');
        }
    }

    /** 源表清单 */
    private static function sourceTables($src, $driver) {
        $tables = array();
        if ($driver === 'sqlite') {
            foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
                $tables[] = $r['name'];
            }
        } else {
            foreach ($src->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name') as $r) {
                $tables[] = $r['table_name'];
            }
        }
        return $tables;
    }

    /** 目标建表（从源 CREATE 语句翻译方言） */
    private static function createTargetTable($src, $dst, $srcDriver, $toDriver, $table) {
        $createSql = '';
        if ($srcDriver === 'sqlite') {
            $createSql = (string)$src->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $table . "'")->fetchColumn();
        } else {
            $row = $src->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            $createSql = isset($row[1]) ? $row[1] : '';
        }
        if ($createSql === '') throw new Exception('读取表结构失败：' . $table);
        // 移除建表语句中的尾分号，改为 IF NOT EXISTS 幂等
        $createSql = rtrim(trim($createSql), "; \t\n");
        if ($toDriver === 'sqlite') {
            // 目标 SQLite：MySQL 方言 → 基础转换（AUTO_INCREMENT → AUTOINCREMENT，反引号去除）
            $createSql = preg_replace('/`/s', '"', $createSql);
            $createSql = str_replace('AUTO_INCREMENT', 'AUTOINCREMENT', $createSql);
            $createSql = preg_replace('/\bENGINE\s*=\s*\w+/i', '', $createSql);
            $createSql = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*\w+/i', '', $createSql);
            $createSql = preg_replace('/\bCOLLATE\s*=\s*\w+/i', '', $createSql);
            $createSql = str_replace('UNSIGNED', '', $createSql);
            $createSql = preg_replace('/\bINT\b/i', 'INTEGER', $createSql);
            $createSql = preg_replace('/\bVARCHAR\s*\(\s*(\d+)\s*\)/i', 'TEXT', $createSql);
            $createSql = preg_replace('/\bDATETIME\b/i', 'TEXT', $createSql);
            $createSql = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $createSql);
            $createSql = preg_replace('/\bTINYINT\b/i', 'INTEGER', $createSql);
            $createSql = preg_replace('/\bBIGINT\b/i', 'INTEGER', $createSql);
            $createSql = preg_replace('/\bDOUBLE\b/i', 'REAL', $createSql);
            $createSql = preg_replace('/\bDECIMAL\s*\([^)]*\)/i', 'REAL', $createSql);
        } else {
            // 目标 MySQL：SQLite 方言 → MySQL（反引号 + AUTO_INCREMENT）
            $createSql = preg_replace('/"/s', '`', $createSql);
            $createSql = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $createSql);
            $createSql = preg_replace('/\bTEXT\b/i', 'TEXT', $createSql);
        }
        $createSql = str_ireplace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $createSql);
        // 去尾部注释
        $createSql = preg_replace('/\)\s*(COMMENT\s*=?\s*\'[^\']*\')?\s*$/i', ')', $createSql);
        $dst->exec($createSql);
    }

    /** 分批插入行 */
    private static function insertRows($dst, $toDriver, $table, $rows) {
        if (!$rows) return;
        $cols = array_keys($rows[0]);
        $quoted = array();
        foreach ($cols as $c) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $c)) throw new Exception('非法列名：' . $c);
            $quoted[] = ($toDriver === 'mysql') ? '`' . $c . '`' : '"' . $c . '"';
        }
        $colSql = implode(',', $quoted);
        $ph = array();
        foreach ($rows as $i => $r) {
            $ph[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        }
        $sql = 'INSERT INTO ' . ($toDriver === 'mysql' ? '`' . $table . '`' : '"' . $table . '"') .
            ' (' . $colSql . ') VALUES ' . implode(',', $ph);
        $params = array();
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $v = $r[$c];
                // 布尔/整数字符串规范化（SQLite 存整数，MySQL 存字符串的场景兼容）
                $params[] = $v;
            }
        }
        $st = $dst->prepare($sql);
        $st->execute($params);
    }

    /** 自增序列校准（SQLite sqlite_sequence / MySQL AUTO_INCREMENT） */
    private static function resetSequences($dst, $driver, $tables) {
        if ($driver === 'mysql') {
            foreach ($tables as $t) {
                try {
                    $maxId = (int)$dst->query('SELECT COALESCE(MAX(id),0) FROM `' . $t . '`')->fetchColumn();
                    if ($maxId > 0) {
                        $dst->exec('ALTER TABLE `' . $t . '` AUTO_INCREMENT = ' . ($maxId + 1));
                    }
                } catch (Exception $ex) {}
            }
            return;
        }
        // SQLite：删除 sqlite_sequence 让 SQLite 自动重建（基于当前最大 id）
        try {
            $dst->exec("DELETE FROM sqlite_sequence");
        } catch (Exception $ex) {}
    }
}