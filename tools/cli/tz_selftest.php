<?php
/**
 * ============================================================
 * tools/cli/tz_selftest.php — 时间数据全链路自检 / UTC 切换验收
 * ============================================================
 * 模式一（默认）：当前库时间层全链路自检——环境口径、纯函数行为、全库时间列
 *   可解析性与「疑似口径不匹配」探测、reg_date 业务日一致性、序号唯一性、
 *   打印快照时区固化完整性。退出码 0=通过（可含警告）/ 1=存在失败。
 *
 * 模式二（--compare=迁移副本.db [--tz=时区]）：跨模式渲染等价验收——
 *   以 SQLite 副本为对象：原库（local 态）逐列抽样，与「迁移副本（utc 态）」
 *   同 rowid 值经 utc_to_local 渲染后逐值断言全等；验证切换后「存储换口径、
 *   展示零漂移」。退出码 0=零误差 / 1=存在差异。
 *
 * 用法：
 *   php tools/cli/tz_selftest.php
 *   php tools/cli/tz_selftest.php --compare=data/db/副本.db --tz=Asia/Shanghai [--db=原库路径] [--full]
 *   （--compare 默认每列随机抽 5 值；--full 全量逐值比对——切换验收建议 --full）
 *   本机（无系统 php）：~/.local/bin/frankenphp php-cli tools/cli/tz_selftest.php
 * ============================================================ */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$APP_ROOT = dirname(dirname(__DIR__));

/* 参数 */
$cliArg = array('db' => '', 'compare' => '', 'tz' => '', 'full' => false);
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--full') { $cliArg['full'] = true; continue; }
    foreach (array('db', 'compare', 'tz') as $k) {
        if (strpos($a, '--' . $k . '=') === 0) $cliArg[$k] = substr($a, strpos($a, '=') + 1);
    }
}
$abs = function ($p) use ($APP_ROOT) {
    return (strpos($p, '/') !== 0 && strpos($p, '.') !== 0) ? $APP_ROOT . '/' . $p : $p;
};
$dbPath = $abs($cliArg['db'] !== '' ? $cliArg['db'] : $APP_ROOT . '/data/db/clinic_main.db');

$fail = 0; $warn = 0;
function st_ok($msg) { echo "  ✓ {$msg}\n"; }
function st_bad(&$fail, $msg) { $fail++; echo "  ✗ {$msg}\n"; }
function st_warn(&$warn, $msg) { $warn++; echo "  ⚠ {$msg}\n"; }

/* ============================================================
 * 模式二：跨模式渲染等价验收（独立于 bootstrap：纯函数 + 两个 SQLite 文件）
 * ============================================================ */
if ($cliArg['compare'] !== '') {
    require $APP_ROOT . '/app/core/helpers.d/time.php';
    $migPath = $abs($cliArg['compare']);
    if (!is_file($dbPath)) { fwrite(STDERR, "原库不存在：{$dbPath}\n"); exit(1); }
    if (!is_file($migPath)) { fwrite(STDERR, "迁移副本不存在：{$migPath}\n"); exit(1); }
    $tz = $cliArg['tz'] !== '' ? $cliArg['tz'] : 'Asia/Shanghai';
    $opt = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
    $src = new PDO('sqlite:' . $dbPath, null, null, $opt);
    $mig = new PDO('sqlite:' . $migPath, null, null, $opt);

    echo "【跨模式渲染等价验收】原库(local 态)：{$dbPath}\n                      迁移副本(utc 态)：{$migPath}\n时区：{$tz}\n\n";
    $tables = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $cols = 0; $vals = 0; $mismatch = 0; $invalid = 0; $cases = array();
    foreach ($tables as $t) {
        $ti = $src->query("PRAGMA table_info(" . $t . ")")->fetchAll();
        foreach ($ti as $c) {
            $col = $c['name'];
            if (!preg_match('/_at$|_time$/i', $col)) continue;
            $n1 = (int)$src->query("SELECT COUNT(*) FROM $t WHERE " . q_ident($col) . " IS NOT NULL AND " . q_ident($col) . " != ''")->fetchColumn();
            $n2 = (int)$mig->query("SELECT COUNT(*) FROM $t WHERE " . q_ident($col) . " IS NOT NULL AND " . q_ident($col) . " != ''")->fetchColumn();
            if ($n1 === 0 && $n2 === 0) continue;
            $cols++;
            if ($n1 !== $n2) {
                $mismatch++;
                $cases[] = "{$t}.{$col} 行数不一致：原库 {$n1} ≠ 副本 {$n2}";
                continue;
            }
            $limit = $cliArg['full'] ? '' : ' ORDER BY RANDOM() LIMIT 5';
            $rows = $src->query("SELECT rowid AS __rid, " . q_ident($col) . " AS v FROM $t WHERE " . q_ident($col) . " IS NOT NULL AND " . q_ident($col) . " != ''" . $limit)->fetchAll();
            foreach ($rows as $r) {
                $v1 = (string)$r['v'];
                $st = $mig->prepare("SELECT " . q_ident($col) . " AS v FROM $t WHERE rowid=?");
                $st->execute(array((int)$r['__rid']));
                $v2 = (string)$st->fetchColumn();
                $vals++;
                $expect = $v1;                                  // local 态渲染 = 恒等
                $got = utc_to_local($v2, $tz);                  // utc 态渲染
                if ($got !== $expect) {
                    $mismatch++;
                    if (count($cases) < 10) $cases[] = "{$t}.{$col} rowid=" . (int)$r['__rid'] . " 原={$v1} 副本渲染={$got}（副本值={$v2}）";
                }
            }
        }
    }
    echo "对比：{$cols} 列 / " . ($cliArg['full'] ? '全量' : '抽样') . " {$vals} 值\n";
    if ($mismatch === 0) st_ok("渲染逐值全等（存储换口径，展示零漂移）");
    else {
        foreach (array_slice($cases, 0, 10) as $m) echo "  ✗ {$m}\n";
        if ($mismatch > count($cases)) echo "  ⋯ 其余 " . ($mismatch - count($cases)) . " 处省略\n";
    }
    // 打印快照时区固化（切换后应 100% 固化且等于站点时区）
    try {
        $nullTz = (int)$mig->query("SELECT COUNT(*) FROM print_snapshots WHERE tz IS NULL OR tz=''")->fetchColumn();
        $stx = $mig->prepare("SELECT COUNT(*) FROM print_snapshots WHERE tz!=?");
        $stx->execute(array($tz));
        $wrongTz = (int)$stx->fetchColumn();
        $all = (int)$mig->query("SELECT COUNT(*) FROM print_snapshots")->fetchColumn();
        if ($all > 0 && $nullTz > 0) st_bad($fail, "打印快照时区固化缺失 {$nullTz}/{$all} 条");
        elseif ($wrongTz > 0) st_bad($fail, "打印快照时区不等于站点时区 {$wrongTz}/{$all} 条");
        else st_ok("打印快照时区固化完整（{$all} 条，tz={$tz}）");
    } catch (Exception $e) { st_warn($warn, 'print_snapshots 检查跳过：' . $e->getMessage()); }
    $hardFail = $fail + $mismatch;
    echo "\n" . ($hardFail === 0 ? "✓ 验收通过：{$cols} 列 / {$vals} 值零误差\n" : "✗ 验收未通过：{$hardFail} 处差异\n");
    exit($hardFail === 0 ? 0 : 1);
}

/* 列名标识符安全包裹（PRAGMA 发现的列名按 SQLite 规则包裹） */
function q_ident($name) { return '"' . str_replace('"', '""', $name) . '"'; }

/* ============================================================
 * 模式一：当前库全链路自检（bootstrap：真实运行环境口径；APP_ROOT 常量由其定义）
 * ============================================================ */
require $APP_ROOT . '/app/config/bootstrap.php';

$driver = DatabaseManager::driver();
$pdo = DatabaseManager::getMain();
$siteTz = date_default_timezone_get();

echo "══════ 时间层全链路自检 ══════\n";
echo "库：{$dbPath}（{$driver}） 存储模式：" . (time_storage_utc() ? 'utc' : 'local') . " 站点时区：{$siteTz}\n\n";

/* --- 1. 环境口径 --- */
echo "【1】环境\n";
$cfgTz = (string)setting('timezone', '');
if ($cfgTz !== '' && $cfgTz !== $siteTz) st_bad($fail, "settings.timezone({$cfgTz}) 与 PHP 运行口径({$siteTz}) 不一致");
else st_ok("站点时区配置与运行口径一致（{$siteTz}）");
$storedMode = (string)ConfigStore::get('time.storage', '');
$storedUtc = ($storedMode === 'utc');
if ($storedUtc === time_storage_utc()) st_ok("time.storage({$storedMode}) 与运行模式一致");
else st_bad($fail, "time.storage({$storedMode}) 与运行模式(" . (time_storage_utc() ? 'utc' : 'local') . ") 不一致");

/* --- 2. 纯函数行为（模式感知） --- */
echo "【2】纯函数\n";
$now = now_str();
$ref = time_storage_utc() ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
if (abs(strtotime($now . (time_storage_utc() ? ' UTC' : '')) - strtotime($ref . (time_storage_utc() ? ' UTC' : ''))) <= 2) st_ok("now_str() 模式口径正确（{$now}）");
else st_bad($fail, "now_str() 口径异常：{$now}（期望约 {$ref}）");

if ((time() - strtotime(now_wall())) >= -2 && (time() - strtotime(now_wall())) <= 2) st_ok('now_wall() 对外“现在”为站点墙钟（两模式一致）');
else st_bad($fail, 'now_wall() 异常：' . now_wall());

$rtOk = true;
foreach (array($siteTz, 'UTC', 'America/New_York') as $tzc) {
    $u = local_to_utc('2026-06-15 12:34:56', $tzc);
    if ($u === '' || utc_to_local($u, $tzc) !== '2026-06-15 12:34:56') $rtOk = false;
}
if ($rtOk) st_ok('local_to_utc / utc_to_local 往返恒等（含跨时区样本）');
else st_bad($fail, '跨时区往返不恒等');

$wall = now_wall();
if (fmt_time(wall_to_storage($wall)) === $wall) st_ok('wall_to_storage → fmt_time 入出站往返恒等');
else st_bad($fail, '入出站助手往返异常：' . fmt_time(wall_to_storage($wall)) . ' ≠ ' . $wall);
if (!time_storage_utc()) st_ok('wall_to_storage local 模式恒等（老行为不变）');
elseif (wall_to_storage('bad-format') === '') st_ok('wall_to_storage 非法入参安全兜底（utc 模式返回空）');
else st_bad($fail, 'wall_to_storage 非法入参未兜底');

$ymd = date('Y-m-d');
$db = day_range($ymd);
if (is_array($db) && count($db) === 2 && $db[0] !== '' && $db[0] < $db[1]) {
    $back = time_storage_utc() ? utc_to_local($db[0]) : $db[0];
    if ($back === $ymd . ' 00:00:00') st_ok("day_range({$ymd}) 边界正确（模式感知）");
    else st_bad($fail, "day_range 边界回渲染异常：{$back}");
} else st_bad($fail, 'day_range 返回异常');

$future = time_storage_utc() ? gmdate('Y-m-d H:i:s', time() + 10) : date('Y-m-d H:i:s', time() + 10);
$past = time_storage_utc() ? gmdate('Y-m-d H:i:s', time() - 3600) : date('Y-m-d H:i:s', time() - 3600);
if (is_recent($now, 30) && !is_recent($future, 30) && !is_recent($past, 1800)) st_ok('is_recent 租约判定正确（含 fail-closed 未来时间）');
else st_bad($fail, 'is_recent 行为异常');

/* --- 3. 数据层：全库时间列可解析性 + 疑似口径探测 --- */
echo "【3】数据层\n";
$tables = st_list_tables($pdo, $driver);
$colTotal = 0; $valTotal = 0; $invalidTotal = 0; $suspect = array();
foreach ($tables as $t) {
    $cols = st_list_columns($pdo, $driver, $t);
    foreach ($cols as $col) {
        if (!preg_match('/_at$|_time$/i', $col)) continue;
        $qcol = q_ident($col);
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM {$t} WHERE {$qcol} IS NOT NULL AND {$qcol} != ''")->fetchColumn();
        } catch (Exception $ex) { continue; }
        if ($n === 0) continue;
        $colTotal++;
        $rows = $pdo->query("SELECT {$qcol} AS v FROM {$t} WHERE {$qcol} IS NOT NULL AND {$qcol} != '' ORDER BY RANDOM() LIMIT 5")->fetchAll();
        foreach ($rows as $r) {
            $valTotal++;
            if (ts_epoch($r['v']) === null) $invalidTotal++;
        }
        $mx = (string)$pdo->query("SELECT MAX({$qcol}) FROM {$t} WHERE {$qcol} IS NOT NULL AND {$qcol} != ''")->fetchColumn();
        $mxEpoch = ts_epoch($mx);
        if ($mxEpoch !== null) {
            $diff = $mxEpoch - time();
            if ($diff > 3600 && $diff < 14 * 3600) $suspect[] = "{$t}.{$col} 最新值在未来 " . round($diff / 3600, 1) . " 小时（格式如 {$mx}）";
            elseif ($diff <= -19800 && $diff > -14 * 3600) $suspect[] = "{$t}.{$col} 最新值距今 " . round(-$diff / 3600, 1) . " 小时（疑似口径不匹配）";
        }
    }
}
if ($invalidTotal === 0) st_ok("时间列抽样全部可解析（{$colTotal} 列 / {$valTotal} 值）");
else st_bad($fail, "存在不可解析时间值 {$invalidTotal}/{$valTotal} 处");
if ($suspect) foreach ($suspect as $s) st_warn($warn, $s);
else st_ok('无「未来时间 / 疑似口径不匹配」列');

/* --- 4. 业务日与序号一致性 --- */
try {
    $mis = 0; $rows = $pdo->query("SELECT registered_at, reg_date FROM registrations WHERE registered_at IS NOT NULL AND registered_at != ''")->fetchAll();
    foreach ($rows as $r) {
        if ((string)$r['reg_date'] !== local_date_of($r['registered_at'])) $mis++;
    }
    if ($mis === 0) st_ok('reg_date 与 registered_at 业务日口径一致（' . count($rows) . ' 条）');
    else st_bad($fail, "reg_date 一致性失败 {$mis} 条（业务日列需与本地日口径一致）");
    $dup = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT first_dept_id, reg_date, visit_seq, COUNT(*) c FROM registrations GROUP BY first_dept_id, reg_date, visit_seq HAVING c > 1) x")->fetchColumn();
    if ($dup === 0) st_ok('(first_dept_id, reg_date, visit_seq) 无重复（唯一索引有效）');
    else st_bad($fail, "挂号业务日序号重复 {$dup} 组");
} catch (Exception $ex) { st_warn($warn, 'reg_date 检查跳过：' . $ex->getMessage()); }

/* --- 5. 打印快照时区固化 --- */
try {
    $all = (int)$pdo->query('SELECT COUNT(*) FROM print_snapshots')->fetchColumn();
    $nullTz = (int)$pdo->query("SELECT COUNT(*) FROM print_snapshots WHERE tz IS NULL OR tz=''")->fetchColumn();
    $badTz = 0;
    foreach ($pdo->query("SELECT DISTINCT tz FROM print_snapshots WHERE tz IS NOT NULL AND tz!=''")->fetchAll(PDO::FETCH_COLUMN) as $tzv) {
        try { new DateTimeZone($tzv); } catch (Exception $ex) { $badTz++; }
    }
    if ($badTz > 0) st_bad($fail, "打印快照存在非法时区名 {$badTz} 种");
    elseif (time_storage_utc() && $nullTz > 0) st_bad($fail, "utc 模式打印快照时区固化缺失 {$nullTz}/{$all} 条");
    elseif ($nullTz > 0) st_warn($warn, "local 模式打印快照待回溯固化 {$nullTz}/{$all} 条（切换时自动回溯）");
    else st_ok("打印快照时区固化完整（{$all} 条）");
} catch (Exception $ex) { st_warn($warn, 'print_snapshots 检查跳过：' . $ex->getMessage()); }

echo "\n══════ 结果：失败 {$fail} 项，警告 {$warn} 项 ══════\n";
exit($fail === 0 ? 0 : 1);

/* ---------------- 跨驱动结构发现 ---------------- */
function st_list_tables($pdo, $driver) {
    if ($driver === 'mysql') return $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    if ($driver === 'pgsql') return $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
}
function st_list_columns($pdo, $driver, $table) {
    if ($driver === 'mysql') {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    } elseif ($driver === 'pgsql') {
        $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name = ?");
    } else {
        $st = $pdo->prepare('SELECT name FROM pragma_table_info(?)');
    }
    try { $st->execute(array($table)); return $st->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $ex) { return array(); }
}
