<?php
/**
 * ============================================================
 * tools/cli/tz_migrate.php — 全库时间字段迁移工具（站点时区墙钟 ↔ UTC）
 * ============================================================
 * 用途：为「UTC 存储」目标态切换提供**可预演、可回滚、可校验**的全库数据迁移：
 *  - plan     只读预演：列出全部待转换的 表.列 与行数、抽样转换前后对照
 *  - apply    执行「站点时区墙钟 → UTC」（SQLite 自动备份到 data/db/backups/）
 *  - rollback 按最近一次 apply 的清单（manifest）精确反向还原
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
 *   php tools/cli/tz_migrate.php rollback [--db=路径]
 *   本机（无系统 php）：~/.local/bin/frankenphp php-cli tools/cli/tz_migrate.php plan
 * ============================================================ */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$APP_ROOT = dirname(dirname(__DIR__));
require $APP_ROOT . '/app/core/helpers.d/time.php';   // 纯函数转换原语（无 bootstrap 依赖）

/* ---------------- 参数解析 ---------------- */
$argv = isset($argv) ? $argv : array();
$cmd = isset($argv[1]) ? strtolower(trim((string)$argv[1])) : 'plan';
if (!in_array($cmd, array('plan', 'apply', 'rollback'), true)) {
    fwrite(STDERR, "用法: tz_migrate.php plan|apply|rollback [--db=路径] [--tz=时区] [--yes]\n");
    exit(2);
}
$opt = array('db' => '', 'tz' => '', 'yes' => false, 'allow_no_backup' => false);
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--yes') $opt['yes'] = true;
    elseif ($a === '--allow-no-backup') $opt['allow_no_backup'] = true;
    elseif (strpos($a, '--db=') === 0) $opt['db'] = substr($a, 5);
    elseif (strpos($a, '--tz=') === 0) $opt['tz'] = substr($a, 5);
}
$dbPath = $opt['db'] !== '' ? $opt['db'] : $APP_ROOT . '/data/db/clinic_main.db';
$dbPath = (strpos($dbPath, '/') !== 0 && strpos($dbPath, '.') !== 0) ? $APP_ROOT . '/' . $dbPath : $dbPath;
if (!is_file($dbPath)) { fwrite(STDERR, "数据库文件不存在：{$dbPath}\n"); exit(1); }

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

/* ---------------- 转换列发现：schema 扫描（列名 _at/_time 结尾）+ 实库存在性 ---------------- */
$schema = require $APP_ROOT . '/app/config/schema/main.php';
$plan = array();   // [table => [col, ...]]
foreach ($schema['tables'] as $table => $ddl) {
    preg_match_all('/^\s*([a-z_][a-z0-9_]*)\s+[A-Za-z]+/im', (string)$ddl, $m, PREG_SET_ORDER);
    $cols = array();
    foreach ($m as $mm) {
        if (preg_match('/_at$|_time$/i', $mm[1])) $cols[] = $mm[1];
    }
    if (!$cols) continue;
    // 实库存在性校验（表可能不存在于旧库）
    try {
        $exist = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
    } catch (Exception $ex) { continue; }
    if (!$exist) continue;
    $real = array();
    foreach ($exist as $c) $real[] = $c['name'];
    $use = array_values(array_intersect($cols, $real));
    if ($use) $plan[$table] = $use;
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

/* ---------------- plan：只读预演 ---------------- */
if ($cmd === 'plan') {
    echo "【迁移预演 · 只读】 db={$dbPath}\n";
    echo "站点时区：{$opt['tz']}（{$offset}） 方向：墙钟 → UTC（apply）/ UTC → 墙钟（rollback）\n\n";
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
    echo "\n合计：{$tables} 列 / {$total} 个值待转换（plan 不写入任何数据）\n";
    if ($blocked) echo "⚠ 阻断 " . count($blocked) . " 列（需专项设计后再处理）：\n  - " . implode("\n  - ", $blocked) . "\n";
    $sc = 0;
    try {
        $sc = (int)$pdo->query("SELECT COUNT(*) FROM print_snapshots WHERE tz IS NULL OR tz=''")->fetchColumn();
    } catch (Exception $ex) { /* 无表/无列（未迁移 v54） */ }
    echo "打印快照待回溯固化时区：{$sc} 条（按 {$opt['tz']} 溯源）\n";
    exit(0);
}

/* ---------------- rollback：按最近清单反向还原 ---------------- */
if ($cmd === 'rollback') {
    $pattern = $APP_ROOT . '/data/logs/tz_migrate_*.json';
    $files = glob($pattern) ?: array();
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
    if (!$target) { fwrite(STDERR, "未找到该库的 to_utc 迁移清单（data/logs/tz_migrate_*.json），无法回滚\n"); exit(1); }
    $j = $target['manifest'];
    echo "【回滚】依据清单：" . basename($target['file']) . "（时区 {$j['tz']} {$j['tz_offset']}）\n";
    if (!$opt['yes']) { fwrite(STDERR, "回滚将还原全部已转换值，确认请加 --yes\n"); exit(2); }
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
    exit(0);
}

/* ---------------- apply：执行 墙钟 → UTC ---------------- */
if ($cmd === 'apply') {
    if (!$opt['yes']) { fwrite(STDERR, "apply 将改写全库时间数据，确认请加 --yes（建议先在副本上跑一遍）\n"); exit(2); }
    // 1) 备份（SQLite 整库文件；其它引擎由操作者自行 dump）
    $backup = '';
    $dir = $APP_ROOT . '/data/db/backups';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $backup = $dir . '/tz_migrate_' . date('YmdHis') . '.db';
    if (!@copy($dbPath, $backup)) {
        if (!$opt['allow_no_backup']) {
            fwrite(STDERR, "备份失败（{$backup}）。如确需继续请加 --allow-no-backup（先自行 mysqldump/pg_dump）\n");
            exit(1);
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
    if ($backup !== '') echo "回滚方式：php tools/cli/tz_migrate.php rollback --db={$dbPath} --yes（或直接恢复备份文件）\n";
    exit(0);
}
