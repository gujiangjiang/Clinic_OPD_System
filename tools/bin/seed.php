<?php
/**
 * ============================================================
 * tools/bin/seed.php — 统一造数 CLI 控制台入口（v8.17）
 * ============================================================
 * 模块化造数架构的统一调度入口：按「场景（scenarios）」装配完整数据，
 * 按「模块（seeder）」只重置单一领域（如药品与库存）。
 *
 * 用法（推荐直接用统一 CLI）：
 *   php tools/bin/seed.php --all                       # 全量测试造数（默认）
 *   php tools/bin/seed.php --scene=demo                # Demo 演示环境数据
 *   php tools/bin/seed.php --scene=call                # 叫号大屏专项测试
 *   php tools/bin/seed.php --scene=dept_call           # 多科室分诊叫号专项
 *   php tools/bin/seed.php --scene=doctor2001          # 医生 2001 接诊专项
 *   php tools/bin/seed.php --module=drug               # 仅重置药品与库存
 *
 * 历史脚本（tools/seed_test_data.php 等）已转为轻量级代理入口，
 * 内部委托到本统一 CLI；后续任何造数需求请在 scenarios/ 与 seeder/ 中扩展。
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

/* ---------------- 参数解析 ---------------- */
$opts = array('scene' => '', 'module' => '');
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--all') { $opts['scene'] = 'full'; }
    elseif (strpos($a, '--scene=') === 0) { $opts['scene'] = substr($a, 8); }
    elseif (strpos($a, '--module=') === 0) { $opts['module'] = substr($a, 9); }
    elseif ($a === '-h' || $a === '--help') { seed_usage(); exit(0); }
}

/**
 * 定位 PHP CLI 二进制（frankenphp 下 PHP_BINARY 为空，回退 $_SERVER['_']）：
 *  frankenphp → `<bin> php-cli`；系统 php → `<bin>`。
 */
function seed_php_bin() {
    $bin = '';
    if (PHP_BINARY !== '') $bin = PHP_BINARY;
    elseif (isset($_SERVER['_']) && $_SERVER['_'] !== '') $bin = $_SERVER['_'];
    elseif (PHP_BINDIR !== '' && is_file(PHP_BINDIR . '/php')) $bin = PHP_BINDIR . '/php';
    $bin = $bin !== '' ? $bin : 'php';
    return stripos(basename($bin), 'frankenphp') !== false
        ? escapeshellarg($bin) . ' php-cli'
        : escapeshellarg($bin);
}

/** 子进程执行造数脚本（隔离运行，逐行输出） */
function seed_run_script($script, $extraArgs = array()) {
    if (!is_file($script)) {
        fwrite(STDERR, "脚本不存在：$script\n");
        return 1;
    }
    $cmd = seed_php_bin() . ' ' . escapeshellarg($script);
    foreach ($extraArgs as $arg) $cmd .= ' ' . escapeshellarg($arg);
    passthru($cmd . ' 2>&1', $code);
    return (int)$code;
}

function seed_usage() {
    echo <<<TXT
统一造数 CLI（tools/bin/seed.php）
用法：
  php tools/bin/seed.php --all                 全量测试造数（默认）
  php tools/bin/seed.php --scene=demo           Demo 演示环境数据
  php tools/bin/seed.php --scene=call           叫号大屏专项测试
  php tools/bin/seed.php --scene=dept_call      多科室分诊叫号专项
  php tools/bin/seed.php --scene="doctor=2001"  指定医生工号接诊专项（校验存在且为医生角色）
  php tools/bin/seed.php --scene="dept=lab"     医技精细模式：仅开检验单（lab/exam/prescription/disposal 可组合）

基础字典模块（可多选，逗号/空格分隔）：
  php tools/bin/seed.php --module=clinic        仅重置机构信息（医院名称/必填机构代码/简介）
  php tools/bin/seed.php --module=screen        按科室分类动态生成叫号大屏与诊室窗口
  php tools/bin/seed.php --module=drug          仅重置药品与库存（DrugSeeder）
  php tools/bin/seed.php --module="lab exam disposal"  空格或逗号分隔多选

TXT;
}

$root = dirname(__DIR__);

/* ---------------- 模块分发（支持逗号/空格分隔多选） ---------------- */
$modules = preg_split('/[\s,]+/', trim($opts['module']), -1, PREG_SPLIT_NO_EMPTY);
$moduleMap = array(
    'clinic'    => array('seeder/ClinicInfoSeeder.php', '机构信息'),
    'screen'    => array('seeder/ScreenSeeder.php',     '叫号大屏/诊室窗口'),
    'drug'      => array('seeder/DrugSeeder.php',       '药品与库存'),
    'lab'       => array('seeder/LabSeeder.php',        '检验项目'),
    'exam'      => array('seeder/ExamSeeder.php',       '检查项目'),
    'disposal'  => array('seeder/DisposalSeeder.php',   '处置项目'),
);
if ($modules) {
    $code = 0;
    foreach ($modules as $m) {
        if (!isset($moduleMap[$m])) {
            fwrite(STDERR, "未知模块：{$m}（可用：clinic / screen / drug / lab / exam / disposal）\n");
            $code = 1;
            continue;
        }
        list($script, $label) = $moduleMap[$m];
        echo "== 模块：{$label}（{$m}）==\n";
        $c = seed_run_script($root . '/' . $script);
        if ($c !== 0) $code = $c;
    }
    exit($code);
}

/* ---------------- 场景分发 ---------------- */
$scenes = array(
    'full'       => array('full_seed.php',       '完整测试数据（平台 + 患者全链路）'),
    'demo'       => array('demo_seed.php',       'Demo 演示环境数据'),
    'call'       => array('call_seed.php',       '叫号大屏专项测试'),
    'dept_call'  => array('dept_call_seed.php',  '多科室分诊叫号专项'),
    'doctor2001' => array('doctor2001_seed.php', '医生 2001 接诊专项'),
);
$scene = $opts['scene'] !== '' ? $opts['scene'] : 'full';
// 带参场景解析：--scene="doctor=2001"（指定医生）/ --scene="dept=lab"（医技精细模式）
$extraArgs = array();
if (strpos($scene, '=') !== false) {
    list($base, $val) = explode('=', $scene, 2);
    if ($base === 'dept') {
        $scene = 'dept_call';
        $extraArgs = array($val);
    } elseif ($base === 'doctor') {
        $scene = 'doctor2001';
        $extraArgs = array($val);
    } else {
        fwrite(STDERR, "未知场景参数：{$scene}\n");
        seed_usage();
        exit(1);
    }
}
if (!isset($scenes[$scene])) {
    fwrite(STDERR, "未知场景：{$scene}（可用：full/demo/call/dept_call/doctor2001）\n");
    seed_usage();
    exit(1);
}
echo "== 场景：{$scenes[$scene][1]}（{$scene}）==\n";
// 全量造数前先做数据库依赖先验探测，缺失时终止避免写入脏数据
if ($scene === 'full') {
    $pfCode = seed_run_script($root . '/seeder/PreflightChecker.php', array('--all'));
    if ($pfCode !== 0) exit($pfCode);
}
exit(seed_run_script($root . '/scenarios/' . $scenes[$scene][0], $extraArgs));