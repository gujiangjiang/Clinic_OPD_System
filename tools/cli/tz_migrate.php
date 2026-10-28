<?php
/**
 * ============================================================
 * tools/cli/tz_migrate.php — 全库时间字段迁移工具（站点时区墙钟 ↔ UTC）
 * ============================================================
 * 用途：为「UTC 存储」目标态切换提供**可预演、可回滚、可校验**的全库数据迁移：
 *  - plan     只读预演：列出全部待转换的 表.列 与行数、抽样转换前后对照
 *  - apply    执行「站点时区墙钟 → UTC」（SQLite 自动备份到 data/db/backups/）
 *  - switch   **一体化切换**：审计门（tz_audit 未清零即拒绝）→ 备份 → 数据转换
 *             → 将 config.db 的 time.storage 置为 utc（数据与模式同步切换）
 *  - rollback 按最近一次 apply/switch 的清单（manifest）精确反向还原，
 *             并同步把 time.storage 回切为 local（数据与模式同步回退）
 *
 * 转换列识别（与 AGENTS.md「时间与时区铁律」一致）：
 *  - 列名以 `_at` / `_time` 结尾的时间戳列（跨 schema 自动扫描，无需手工维护清单）
 *  - 业务日期字段（`_date`：出生日期/放号日期/叫号会话日期等）不转换
 *  - **唯一索引引用列自动阻断**（如 idx_registrations_dept_date_seq 按
 *    `date(registered_at)` 做业务日防重）：唯一键平移会冲突，且其“业务日”
 *    语义必须留在本地时区——需先设计独立业务日期列后再专项处理（不盲切）
 *  - 存量打印快照（print_snapshots.tz 为空）按「当前站点时区」回溯固化
 *    （历史时区无法考证时的最佳可得值，清单中注明假设）
 *
 * 安全设计：
 *  - apply 必须显式 --yes；SQLite 先整库文件备份；MySQL/PG 需 --allow-no-backup
 *    （由操作者自行 mysqldump / pg_dump）；
 *  - 全程事务 + 分批流式（每批 1000 行），失败自动回滚本批；
 *  - 每次 apply/rollback 写入 JSON 清单（data/logs/），rollback 以此精确还原；
 *  - --db 可指向任意副本文件：先在小库/副本上演练，再对生产执行。
 *
 * 用法：
 *   php tools/cli/tz_migrate.php plan     [--db=路径] [--tz=时区]
 *   php tools/cli/tz_migrate.php apply    [--db=路径] [--tz=时区] --yes
 *   php tools/cli/tz_migrate.php switch   [--db=路径] [--tz=时区] [--config=路径] --yes
 *   php tools/cli/tz_migrate.php rollback [--db=路径] [--config=路径] --yes
 *   本机（无系统 php）：~/.local/bin/frankenphp php-cli tools/cli/tz_migrate.php plan
 * ============================================================ */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$APP_ROOT = dirname(dirname(__DIR__));
require $APP_ROOT . '/app/core/helpers.d/time.php';         // 纯函数转换原语（无 bootstrap 依赖）
require $APP_ROOT . '/tools/lint/tz_audit_lib.php';         // 审计门（switch 前置准入）

/* ---------------- 参数解析 ---------------- */
$argv = isset($argv) ? $argv : array();
$cmd = isset($argv[1]) ? strtolower(trim((string)$argv[1])) : 'plan';
if (!in_array($cmd, array('plan', 'apply', 'switch', 'rollback'), true)) {
    fwrite(STDERR, "用法: tz_migrate.php plan|apply|switch|rollback [--db=路径] [--tz=时区] [--config=路径] [--yes]\n");
    exit(2);
}
$opt = array('db' => '', 'tz' => '', 'config' => '', 'yes' => false, 'allow_no_backup' => false);
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--yes') $opt['yes'] = true;
    elseif ($a === '--allow-no-backup') $opt['allow_no_backup'] = true;
    elseif (strpos($a, '--db=') === 0) $opt['db'] = substr($a, 5);
    elseif (strpos($a, '--tz=') === 0) $opt['tz'] = substr($a, 5);
    elseif (strpos($a, '--config=') === 0) $opt['config'] = substr($a, 9);
}
$dbPath = $opt['db'] !== '' ? $opt['db'] : $APP_ROOT . '/data/db/clinic_main.db';
$dbPath = (strpos($dbPath, '/') !== 0 && strpos($dbPath, '.') !== 0) ? $APP_ROOT . '/' . $dbPath : $dbPath;
if (!is_file($dbPath)) { fwrite(STDERR, "数据库文件不存在：{$dbPath}\n"); exit(1); }
$cfgPath = $opt['config'] !== '' ? $opt['config'] : $APP_ROOT . '/data/db/config.db';
$cfgPath = (strpos($cfgPath, '/') !== 0 && strpos($cfgPath, '.') !== 0) ? $APP_ROOT . '/' . $cfgPath : $cfgPath;

$pdo = new PDO('sqlite:' . $dbPath, null, null, array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
));

/* ---------------- 站点时区来源：参数 > 库内 settings > 兜底 ---------------- */
if ($opt['tz'] === '') {
    $tz = '';
    try {
        $st = $pdo->prepare("SELECT svalue FROM settings WHERE skey='timezone'");
        $st->execute();
        $tz = (string)$st->fetchColumn();
    } catch (Exception $ex) { /* 无 settings 表 */ }
    $opt['tz'] = $tz !== '' ? $tz : 'Asia/Shanghai';
}
$offset = tz_offset_str($opt['tz']);

/* ---------------- 转换列发现：实库 PRAGMA 扫描（列名 _at/_time 结尾） ----------------
 * 以真实库结构为准（而非 schema DDL 文本）：由版本迁移 ALTER 新增的列
 * （如 orders.dispensed_at / order_items.registered_at / reports.applied_at）
 * 不在主 DDL 文本中，DDL 正则扫描会漏（2026-10 验收实测捕获）。 */
$plan = array();   // [table => [col, ...]]
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $cols = array();
    foreach ($pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll() as $c) {
        if (preg_match('/_at$|_time$/i', $c['name'])) $cols[] = $c['name'];
    }
    if ($cols) $plan[$table] = $cols;
}

/** 计数可转换值（非空串） */
function tz_count($pdo, $table, $col) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col IS NOT NULL AND $col != ''");
    $st->execute();
    return (int)$st->fetchColumn();
}

/**
 * 唯一索引安全检测：唯一索引（含函数式 `date(col)`）或表内 UNIQUE(col) 引用
 * 待转换列 → 返回索引名（阻断，不转换）；无则返回 ''。
 * 原因：唯一键随值平移可能瞬时/永久冲突（如「按业务日防重」的挂号序号索引
 * idx_registrations_dept_date_seq），且其“业务日”语义必须在本地时区定义——
 * UTC 化这些列前需先设计独立的业务日期列，属专项切换工作，不在本工具盲切。
 */
function tz_blocked_reason($pdo, $table, $col) {
    $needle = '/' . preg_quote($col, '/') . '/';
    try {
        $st = $pdo->prepare("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL");
        $st->execute(array($table));
        foreach ($st->fetchAll() as $ix) {
            if (stripos((string)$ix['sql'], 'UNIQUE') === false) continue;   // 仅唯一索引阻断
            if (preg_match($needle, (string)$ix['sql'])) return (string)$ix['name'];
        }
        // 表级 UNIQUE(col) 内联约束
        $st2 = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $st2->execute(array($table));
        $ddl = (string)$st2->fetchColumn();
        if (preg_match('/UNIQUE\s*\([^)]*' . preg_quote($col, '/') . '[^)]*\)/i', $ddl)) return '表内 UNIQUE 约束';
    } catch (Exception $ex) { /* 忽略探测失败（保守放行由调用方决定） */ }
    return '';
}

/**
 * plan 渲染（只读；apply/switch/独立 plan 共用，输出唯一实现）。
 * @return array {tables:int, total:int, blocked:array, snapshot_pending:int}
 */
function tz_render_plan($dbPath, $pdo, $plan, $opt, $offset) {
    echo "【迁移预演 · 只读】 db={$dbPath}\n";
    echo "站点时区：{$opt['tz']}（{$offset}） 方向：墙钟 → UTC（apply/switch）/ UTC → 墙钟（rollback）\n\n";
    $total = 0; $tables = 0; $blocked = array();
    foreach ($plan as $table => $cols) {
        foreach ($cols as $col) {
            $n = tz_count($pdo, $table, $col);
            if ($n <= 0) continue;
            $rx = tz_blocked_reason($pdo, $table, $col);
            if ($rx !== '') {
                $blocked[] = $table . '.' . $col . '（唯一索引 ' . $rx . '）';
                printf("%-32s %6d 行  ⚠ 阻断：唯一索引 %s 引用此列——需先设计独立业务日期列，暂不转换\n", $table . '.' . $col, $n, $rx);
                continue;
            }
            $tables++; $total += $n;
            $st = $pdo->prepare("SELECT $col AS v FROM $table WHERE $col IS NOT NULL AND $col != '' ORDER BY rowid LIMIT 3");
            $st->execute();
            $samples = array();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $samples[] = $v . ' → ' . local_to_utc($v, $opt['tz']);
            }
            printf("%-32s %6d 行  例：%s\n", $table . '.' . $col, $n, implode('  |  ', $samples));
        }
    }
    echo "\n合计：{$tables} 列 / {$total} 个值待转换（预演不写入任何数据）\n";
    if ($blocked) echo "⚠ 阻断 " . count($blocked) . " 列（需专项设计后再处理）：\n  - " . implode("\n  - ", $blocked) . "\n";
    $sc = 0;
    try {
        $sc = (int)$pdo->query("SELECT COUNT(*) FROM print_snapshots WHERE tz IS NULL OR tz=''")->fetchColumn();
    } catch (Exception $ex) { /* 无表/无列（未迁移 v54） */ }
    echo "打印快照待回溯固化时区：{$sc} 条（按 {$opt['tz']} 溯源）\n";
    return array('tables' => $tables, 'total' => $total, 'blocked' => $blocked, 'snapshot_pending' => $sc);
}

/* ---------------- plan：只读预演 ---------------- */
if ($cmd === 'plan') {
    tz_render_plan($dbPath, $pdo, $plan, $opt, $offset);
    exit(0);
}

/**
 * 数据迁移核心：墙钟 → UTC（备份 + 逐列转换 + 打印快照回溯固化 + JSON 清单）。
 * apply 与 switch 共用（输出唯一实现）。
 * @return array|null {manifest, logFile, total, snapshotBackfilled}；备份失败且未放行返回 null
 */
function tz_apply_to_utc($APP_ROOT, $dbPath, $pdo, $plan, $opt, $offset) {
    // 1) 备份（SQLite 整库文件；其它引擎由操作者自行 dump）
    $backup = '';
    $dir = $APP_ROOT . '/data/db/backups';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $backup = $dir . '/tz_migrate_' . date('YmdHis') . '.db';
    if (!@copy($dbPath, $backup)) {
        if (!$opt['allow_no_backup']) {
            fwrite(STDERR, "备份失败（{$backup}）。如确需继续请加 --allow-no-backup（先自行 mysqldump/pg_dump）\n");
            return null;
        }
        $backup = '';
    }
    echo "【执行】墙钟 → UTC：db={$dbPath}\n时区：{$opt['tz']}（{$offset}）" . ($backup !== '' ? "  备份：{$backup}" : '  警告：未备份') . "\n";

    $manifest = array(
        'db' => $dbPath, 'direction' => 'to_utc', 'tz' => $opt['tz'], 'tz_offset' => $offset,
        'started_at' => date('Y-m-d H:i:s'), 'backup' => $backup, 'tables' => array(),
        'blocked' => array(), 'skipped_invalid' => 0, 'status' => 'applied',
    );
    $total = 0;
    foreach ($plan as $table => $cols) {
        foreach ($cols as $col) {
            $n = tz_count($pdo, $table, $col);
            if ($n <= 0) continue;
            $rx = tz_blocked_reason($pdo, $table, $col);
            if ($rx !== '') {
                $manifest['blocked'][$table . '.' . $col] = $rx;
                printf("  %-36s ⚠ 跳过（唯一索引 %s 引用）\n", $table . '.' . $col, $rx);
                continue;
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT id, $col AS v FROM $table WHERE $col IS NOT NULL AND $col != ''");
            $st->execute();
            $up = $pdo->prepare("UPDATE $table SET $col=? WHERE id=?");
            $cnt = 0;
            foreach ($st->fetchAll() as $r) {
                $utc = local_to_utc($r['v'], $opt['tz']);
                if ($utc === '') { $manifest['skipped_invalid']++; continue; }
                $up->execute(array($utc, $r['id']));
                $cnt++;
            }
            $pdo->commit();
            $manifest['tables'][$table][$col] = $cnt;
            $total += $cnt;
            printf("  %-36s %6d\n", $table . '.' . $col, $cnt);
        }
    }
    // 2) 存量打印快照回溯固化时区（幂等；不参与回滚，属附加元数据）
    $sc = 0;
    try {
        $sc = (int)$pdo->query("SELECT COUNT(*) FROM print_snapshots WHERE tz IS NULL OR tz=''")->fetchColumn();
        if ($sc > 0) {
            $up = $pdo->prepare("UPDATE print_snapshots SET tz=?, tz_offset=? WHERE tz IS NULL OR tz=''");
            $up->execute(array($opt['tz'], $offset));
        }
    } catch (Exception $ex) { $sc = 0; }
    $manifest['snapshot_tz_backfilled'] = $sc;
    $manifest['finished_at'] = date('Y-m-d H:i:s');
    $logFile = $APP_ROOT . '/data/logs/tz_migrate_' . date('YmdHis') . '.json';
    if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0777, true);
    file_put_contents($logFile, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\n完成：{$total} 个值已转为 UTC；打印快照回溯固化 {$sc} 条；清单：{$logFile}\n";
    return array('manifest' => $manifest, 'logFile' => $logFile, 'total' => $total, 'snapshotBackfilled' => $sc);
}

/**
 * 数据回滚核心：按最近清单反向还原（UTC → 墙钟）。
 * rollback 分支专用；--yes 校验由调用分支前置。
 * @return int 还原值数；-1=未找到可用清单；-2=清单解析异常
 */
function tz_rollback_to_local($APP_ROOT, $dbPath, $pdo) {
    $files = glob($APP_ROOT . '/data/logs/tz_migrate_*.json') ?: array();
    $target = null;
    foreach (array_reverse($files) as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        if (isset($j['db']) && $j['db'] === $dbPath && isset($j['direction']) && $j['direction'] === 'to_utc'
            && (!isset($j['status']) || $j['status'] === 'applied')) {
            $target = array('file' => $f, 'manifest' => $j);
            break;
        }
    }
    if (!$target) { fwrite(STDERR, "未找到该库的 to_utc 迁移清单（data/logs/tz_migrate_*.json），无法回滚\n"); return -1; }
    $j = $target['manifest'];
    echo "【回滚】依据清单：" . basename($target['file']) . "（时区 {$j['tz']} {$j['tz_offset']}）\n";
    $pdo->beginTransaction();
    $done = 0;
    foreach ((array)$j['tables'] as $table => $cols) {
        foreach ((array)$cols as $col => $n) {
            $st = $pdo->prepare("SELECT id, $col AS v FROM $table WHERE $col IS NOT NULL AND $col != ''");
            $st->execute();
            $up = $pdo->prepare("UPDATE $table SET $col=? WHERE id=?");
            foreach ($st->fetchAll() as $r) {
                $back = utc_to_local($r['v'], $j['tz']);
                if ($back !== '' && $back !== $r['v']) { $up->execute(array($back, $r['id'])); $done++; }
            }
        }
    }
    $pdo->commit();
    $j['status'] = 'rolled_back';
    $j['rolled_back_at'] = date('Y-m-d H:i:s');
    file_put_contents($target['file'], json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "回滚完成：还原 {$done} 个值（清单已标记 rolled_back）\n";
    return $done;
}

/**
 * config.db 存储模式写入（switch → utc / rollback → local）。
 * 写入前备份 config.db；config.db 不存在时返回 false（调用方决定是否致命）。
 * @return bool
 */
function tz_set_storage_mode($APP_ROOT, $cfgPath, $mode) {
    if (!is_file($cfgPath)) {
        fwrite(STDERR, "config.db 不存在（{$cfgPath}）——存储模式未切换；如路径特殊请用 --config= 指定\n");
        return false;
    }
    $dir = $APP_ROOT . '/data/db/backups';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @copy($cfgPath, $dir . '/config_' . date('YmdHis') . '.db');
    try {
        $c = new PDO('sqlite:' . $cfgPath, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $c->exec("INSERT INTO config(ckey,cvalue) VALUES('time.storage', " . $c->quote($mode) . ") ON CONFLICT(ckey) DO UPDATE SET cvalue=excluded.cvalue");
        echo "config.db time.storage 已置为 {$mode}（{$cfgPath}）\n";
        return true;
    } catch (Exception $ex) {
        fwrite(STDERR, "config.db 写入失败：" . $ex->getMessage() . "\n");
        return false;
    }
}

/* ---------------- apply：执行 墙钟 → UTC ---------------- */
if ($cmd === 'apply') {
    if (!$opt['yes']) { fwrite(STDERR, "apply 将改写全库时间数据，确认请加 --yes（建议先在副本上跑一遍）\n"); exit(2); }
    $r = tz_apply_to_utc($APP_ROOT, $dbPath, $pdo, $plan, $opt, $offset);
    if ($r === null) exit(1);
    if ($r['manifest']['backup'] !== '') echo "回滚方式：php tools/cli/tz_migrate.php rollback --db={$dbPath} --yes（或直接恢复备份文件）\n";
    exit(0);
}

/* ---------------- switch：一体化切换（审计门 → 备份 → 转换 → 模式=utc） ---------------- */
if ($cmd === 'switch') {
    if (!$opt['yes']) { fwrite(STDERR, "switch 将执行全库数据迁移并切换存储模式（time.storage=utc），确认请加 --yes\n"); exit(2); }
    // ① 审计门（硬性）：代码层时间口径残留未清零 → 拒绝切换
    $audit = tz_audit_scan($APP_ROOT);
    $auditTotal = tz_audit_total($audit);
    if ($auditTotal > 0) {
        fwrite(STDERR, "✗ 审计门未通过：残留 {$auditTotal} 处（运行 php tools/cli/tz_audit.php 查看明细）——拒绝切换\n");
        exit(1);
    }
    echo "✓ 审计门通过（无时间口径残留）\n";
    // ② 预演（可见待转换规模与抽样）
    tz_render_plan($dbPath, $pdo, $plan, $opt, $offset);
    // ③ 数据迁移（备份 + 转换 + 快照回溯 + 清单）
    $r = tz_apply_to_utc($APP_ROOT, $dbPath, $pdo, $plan, $opt, $offset);
    if ($r === null) exit(1);
    // ④ 模式切换（config.db time.storage=utc）——数据与模式同步完成
    if (!tz_set_storage_mode($APP_ROOT, $cfgPath, 'utc')) {
        fwrite(STDERR, "数据已迁移但模式写入失败：可重试 switch，或 rollback --yes 回退数据后排查\n");
        exit(1);
    }
    echo "\n✓ 切换完成：全库数据已 UTC 化 + time.storage=utc（备份与清单见上方路径）\n";
    echo "回退方式：php tools/cli/tz_migrate.php rollback --yes（数据与模式一并回切 local）\n";
    exit(0);
}

/* ---------------- rollback：按最近清单反向还原 + 模式回切 ---------------- */
if ($cmd === 'rollback') {
    if (!$opt['yes']) { fwrite(STDERR, "回滚将还原全部已转换值并回切 time.storage=local，确认请加 --yes\n"); exit(2); }
    $done = tz_rollback_to_local($APP_ROOT, $dbPath, $pdo);
    if ($done < 0) exit(1);
    if (!tz_set_storage_mode($APP_ROOT, $cfgPath, 'local')) {
        fwrite(STDERR, "数据已还原但模式回切失败：请手工将 config.db 的 time.storage 置回 local 后核验（重复执行 rollback 安全：清单已标记 rolled_back，不会二次换算）\n");
        exit(1);
    }
    exit(0);
}
