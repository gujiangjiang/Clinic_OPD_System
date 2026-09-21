<?php
/**
 * ============================================================
 * tools/bin/seed.php — 统一造数 CLI 控制台入口
 * ============================================================
 * 模块化造数架构的统一调度入口：基础字典按「模块（seeder）」调度，
 * 就诊链/叫号等流程按「场景」组合多个 Seeder 完成。
 * 原 scenarios/ 场景脚本（full_seed / demo_seed / call_seed /
 * dept_call_seed / doctor2001_seed）已全部拆分合并到 tools/seeder/
 * 独立数据工厂（VisitSeeder / QueueSeeder 等），本入口直接调度。
 *
 * 用法（推荐直接用统一 CLI）：
 *   php tools/bin/seed.php --all                       # 全量测试造数（字典 + 就诊链，默认）
 *   php tools/bin/seed.php --scene=demo                # 演示数据（同 --all）
 *   php tools/bin/seed.php --scene=visit               # 患者就诊链专项（追加式，不动字典）
 *   php tools/bin/seed.php --scene="doctor=2001"       # 指定医生工号接诊专项（校验存在且为医生角色）
 *   php tools/bin/seed.php --scene="dept=2,5"          # 指定科室就诊链
 *   php tools/bin/seed.php --scene=call                # 门诊叫号大屏专项（当日已缴费新患者）
 *   php tools/bin/seed.php --scene="call=2,5:10"       # 门诊叫号（指定科室与每科室人数）
 *   php tools/bin/seed.php --scene=dept_call           # 医技叫号专项（既有就诊开已缴费单）
 *   php tools/bin/seed.php --scene="dept=lab"          # 医技精细模式：仅开检验单（lab/exam/prescription/disposal 可组合）
 *   php tools/bin/seed.php --module=drug               # 基础字典模块（见下方模块表，可多选逗号/空格分隔）
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
  php tools/bin/seed.php --all                       全量测试造数（字典 + 就诊链，默认）
  php tools/bin/seed.php --scene=demo                演示数据（同 --all）
  php tools/bin/seed.php --scene=visit               患者就诊链专项（追加式，不动字典）
  php tools/bin/seed.php --scene="doctor=2001"       指定医生工号接诊专项（校验存在且为医生角色）
  php tools/bin/seed.php --scene="dept=2,5"          指定科室就诊链
  php tools/bin/seed.php --scene=call                门诊叫号大屏专项（当日已缴费新患者）
  php tools/bin/seed.php --scene="call=2,5:10"       门诊叫号（指定科室与每科室人数）
  php tools/bin/seed.php --scene=dept_call           医技叫号专项（既有就诊开已缴费单）
  php tools/bin/seed.php --scene="dept=lab"          医技精细模式：仅开检验单（lab/exam/prescription/disposal 可组合）

基础字典模块（可多选，逗号/空格分隔）：
  php tools/bin/seed.php --module=clinic             仅重置机构信息（医院名称/必填机构代码/简介）
  php tools/bin/seed.php --module=dept               仅重置科室（临床 + 医技）
  php tools/bin/seed.php --module=user               仅重置用户账号（14 个测试账号，密码 123456）
  php tools/bin/seed.php --module=screen             按科室分类动态生成叫号大屏与诊室窗口
  php tools/bin/seed.php --module=drug               仅重置药品与库存（DrugSeeder）
  php tools/bin/seed.php --module=lab                仅重置检验项目（含 16 个检验组合/危急值上下限）
  php tools/bin/seed.php --module=exam               仅重置检查项目
  php tools/bin/seed.php --module=disposal           仅重置处置项目
  php tools/bin/seed.php --module=package            仅重置全院公共套餐（9 组）
  php tools/bin/seed.php --module=template           仅重置全院模板（病历/知情同意书/护理/嘱托/影像报告）

TXT;
}

$root = dirname(__DIR__);

/* ---------------- 模块分发（支持逗号/空格分隔多选） ---------------- */
$modules = preg_split('/[\s,]+/', trim($opts['module']), -1, PREG_SPLIT_NO_EMPTY);
$moduleMap = array(
    'clinic'    => array('seeder/ClinicInfoSeeder.php', '机构信息'),
    'dept'      => array('seeder/DeptSeeder.php',       '科室'),
    'user'      => array('seeder/UserSeeder.php',       '用户账号'),
    'screen'    => array('seeder/ScreenSeeder.php',     '叫号大屏/诊室窗口'),
    'drug'      => array('seeder/DrugSeeder.php',       '药品与库存'),
    'lab'       => array('seeder/LabSeeder.php',        '检验项目'),
    'exam'      => array('seeder/ExamSeeder.php',       '检查项目'),
    'disposal'  => array('seeder/DisposalSeeder.php',   '处置项目'),
    'package'   => array('seeder/PackageSeeder.php',    '全院公共套餐'),
    'template'  => array('seeder/TemplateSeeder.php',   '全院模板'),
    'visit'     => array('seeder/VisitSeeder.php',      '患者就诊链'),
);
if ($modules) {
    $code = 0;
    foreach ($modules as $m) {
        if (!isset($moduleMap[$m])) {
            fwrite(STDERR, "未知模块：{$m}（可用：" . implode(' / ', array_keys($moduleMap)) . "）\n");
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

/* ---------------- 场景解析 ----------------
 * full/demo：字典模块全量 + VisitSeeder（含旧业务数据清理，可重复执行）
 * visit：仅就诊链（追加式）
 * doctor=工号 / dept=2,5：就诊链参数化
 * call / dept_call / dept=lab：叫号队列（QueueSeeder，visit/tech 模式） */
$scene = $opts['scene'] !== '' ? $opts['scene'] : 'full';
$dictModules = array('clinic', 'dept', 'user', 'screen', 'drug', 'lab', 'exam', 'disposal', 'package', 'template');

// 带参场景解析：--scene="doctor=2001" / --scene="dept=..." / --scene="call=2,5:10"
$extraArgs = array();
if (strpos($scene, '=') !== false) {
    list($base, $val) = explode('=', $scene, 2);
    if ($base === 'doctor') {
        // 指定医生工号接诊专项（VisitSeeder）
        $extraArgs = array('doctor=' . $val);
        $scene = 'visit';
    } elseif ($base === 'call') {
        // 门诊叫号参数化：call=2,5:10（科室:每科室人数）
        $extraArgs = array($val);
        $scene = 'queue_visit';
    } elseif ($base === 'dept') {
        // dept=lab 等 → 医技叫号精细模式（支持 lab,exam:数量）；dept=2,5 等 → 指定科室就诊链
        if (strpos($val, ':') !== false) {
            // "lab,exam:10" — 医技类型:数量
            list($types, $cnt) = explode(':', $val, 2);
            $scene = 'queue_tech';
            $extraArgs = array('types=' . $types, 'count=' . $cnt);
        } elseif (preg_match('/^[a-z,]+$/i', $val)) {
            $scene = 'queue_tech';
            $extraArgs = array('types=' . $val);
        } elseif (preg_match('/^[0-9,\s]+$/', $val)) {
            $scene = 'visit';
            $extraArgs = array('depts=' . $val);
        } else {
            fwrite(STDERR, "未知场景参数：{$scene}\n");
            seed_usage();
            exit(1);
        }
    } else {
        fwrite(STDERR, "未知场景参数：{$scene}\n");
        seed_usage();
        exit(1);
    }
}

$scenes = array(
    'full'        => array('dict+visit',  '完整测试数据（字典 + 患者就诊链）'),
    'demo'        => array('dict+visit',  '演示环境数据（字典 + 患者就诊链）'),
    'visit'       => array('visit',       '患者就诊链专项'),
    'call'        => array('queue_visit', '门诊叫号大屏专项'),
    'dept_call'   => array('queue_tech',  '医技叫号专项'),
    'queue_visit' => array('queue_visit', '门诊叫号大屏专项'),
    'queue_tech'  => array('queue_tech',  '医技叫号专项'),
);
if (!isset($scenes[$scene])) {
    fwrite(STDERR, "未知场景：{$scene}（可用：full/demo/visit/call/dept_call，或 doctor=/dept=/call= 参数化场景）\n");
    seed_usage();
    exit(1);
}
echo "== 场景：{$scenes[$scene][1]}（{$scene}）==\n";

list($mode) = $scenes[$scene];

// 全量造数前先做数据库依赖先验探测，缺失时终止避免写入脏数据
if ($mode === 'dict+visit') {
    $pfCode = seed_run_script($root . '/seeder/PreflightChecker.php', array('--all'));
    if ($pfCode !== 0) exit($pfCode);
}

// 字典模块全量（clinic → dept → user → screen → drug → lab → exam → disposal → package → template）
if ($mode === 'dict+visit') {
    foreach ($dictModules as $m) {
        echo "== 模块：{$m} ==\n";
        $c = seed_run_script($root . '/' . $moduleMap[$m][0]);
        if ($c !== 0) exit($c);
    }
    exit(seed_run_script($root . '/seeder/VisitSeeder.php', array('clean', 'days=15')));
}
if ($mode === 'visit') {
    exit(seed_run_script($root . '/seeder/VisitSeeder.php', $extraArgs));
}
if ($mode === 'queue_visit') {
    exit(seed_run_script($root . '/seeder/QueueSeeder.php', array_merge(array('mode=visit'), $extraArgs)));
}
if ($mode === 'queue_tech') {
    exit(seed_run_script($root . '/seeder/QueueSeeder.php', array_merge(array('mode=tech'), $extraArgs)));
}
exit(1);
