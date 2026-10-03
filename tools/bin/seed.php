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
$opts = array('scene' => '', 'module' => '', 'clean' => false, 'append' => false, 'yes' => false);
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--all') { $opts['scene'] = 'full'; }
    elseif (strpos($a, '--scene=') === 0) { $opts['scene'] = substr($a, 8); }
    elseif (strpos($a, '--module=') === 0) { $opts['module'] = substr($a, 9); }
    elseif ($a === '--clean') { $opts['clean'] = true; }
    elseif ($a === '--append' || $a === '--no-clean') { $opts['append'] = true; }
    elseif ($a === '--yes' || $a === '-y') { $opts['yes'] = true; }
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

/**
 * 造数前自动备份主库（防止「全量场景」清空旧业务数据造成不可逆损失），
 * 备份至 data/db/backups/clinic_main.<时间戳>.db，仅保留最近 10 份。
 */
function seed_backup_db() {
    $db = dirname(dirname(dirname(__FILE__))) . '/data/db/clinic_main.db';
    if (!is_file($db)) return;
    $dir = dirname($db) . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $dst = $dir . '/clinic_main.' . date('Ymd_His') . '.db';
    if (@copy($db, $dst)) {
        echo "== 已自动备份主库 → data/db/backups/" . basename($dst) . " ==\n";
        $list = glob($dir . '/clinic_main.*.db');
        if (is_array($list)) {
            sort($list);
            while (count($list) > 10) { @unlink(array_shift($list)); }
        }
    } else {
        fwrite(STDERR, "警告：主库备份失败（$db），继续造数有清空风险\n");
    }
}

/**
 * 清空重建前的交互确认（`--yes` 跳过）。
 * 非交互环境（无 TTY）且未加 --yes 时中止，避免脚本被误清空数据。
 */
function seed_confirm_clean($yes) {
    if ($yes) return true;
    $isTty = function_exists('posix_isatty') ? @posix_isatty(STDIN) : true;
    if (!$isTty) {
        fwrite(STDERR, "检测到将【清空旧业务数据并重建】，但当前为非交互环境且未指定 --yes，已中止。\n"
            . "如需继续请追加 --yes；如需追加式造数请改用 --append。\n");
        return false;
    }
    fwrite(STDOUT, "⚠ 将清空旧业务数据并重建（已自动备份主库），是否继续？(yes/N): ");
    $line = trim((string)fgets(STDIN));
    return in_array(strtolower($line), array('y', 'yes'), true);
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
  php tools/bin/seed.php --scene=fhir                 FHIR/HL7 全链路验证数据（字典 + 3 套旅程）

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
  php tools/bin/seed.php --module=fhir               FHIR/HL7 全链路验证数据（3 套旅程 + DICOM UID/Series + 危急值）

可选修饰参数：
  --append            追加式：保留旧业务数据（--all/--scene=demo 默认清空重建，加此参数改为追加）
  --clean             清空重建（--scene=visit 默认追加，加此参数改为先清空重建）
  --yes / -y          清空重建免二次确认（非交互环境必须显式指定，否则中止）
  说明：任何造数操作前都会自动备份主库到 data/db/backups/（保留最近 10 份）；
        词典型数据（科室/用户/药品/检验/检查/处置/套餐/模板）按唯一键幂等去重，
        追加不会产生重复。

TXT;
}

$root = dirname(__DIR__);

// 无论追加还是清空，操作前统一自动备份主库（保留最近 10 份）
seed_backup_db();

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
    'fhir'      => array('seeder/FhirDemoSeeder.php',   'FHIR/HL7 全链路验证数据'),
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
    'full'        => array('dict+visit',  '完整测试数据（字典 + 患者就诊链 + FHIR 全链路）'),
    'demo'        => array('dict+visit',  '演示环境数据（字典 + 患者就诊链 + FHIR 全链路）'),
    'visit'       => array('visit',       '患者就诊链专项'),
    'fhir'        => array('dict+fhir',   'FHIR/HL7 全链路验证数据（字典 + 3 套旅程）'),
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
if ($mode === 'dict+visit' || $mode === 'dict+fhir') {
    $pfCode = seed_run_script($root . '/seeder/PreflightChecker.php', array('--all'));
    if ($pfCode !== 0) exit($pfCode);
}

// 字典模块全量（clinic → dept → user → screen → drug → lab → exam → disposal → package → template）
if ($mode === 'dict+visit' || $mode === 'dict+fhir') {
    foreach ($dictModules as $m) {
        echo "== 模块：{$m} ==\n";
        $c = seed_run_script($root . '/' . $moduleMap[$m][0]);
        if ($c !== 0) exit($c);
    }
}
if ($mode === 'dict+visit') {
    // 全量场景默认「清空重建」；加 --append 改为追加（不清空旧业务数据）
    $clean = !$opts['append'];
    echo $clean ? "== 模式：清空重建（默认；如需追加请加 --append）==\n" : "== 模式：追加（保留旧业务数据）==\n";
    if ($clean && !seed_confirm_clean($opts['yes'])) exit(1);
    $args = array('days=15');
    if ($clean) array_unshift($args, 'clean');
    $c = seed_run_script($root . '/seeder/VisitSeeder.php', $args);
    if ($c !== 0) exit($c);
    exit(seed_run_script($root . '/seeder/FhirDemoSeeder.php'));
}
if ($mode === 'dict+fhir') {
    exit(seed_run_script($root . '/seeder/FhirDemoSeeder.php'));
}
if ($mode === 'visit') {
    // 专项就诊链默认「追加」；加 --clean 改为先清空重建
    $clean = $opts['clean'] && !$opts['append'];
    echo $clean ? "== 模式：清空重建（--clean）==\n" : "== 模式：追加（默认；如需清空请加 --clean --yes）==\n";
    if ($clean && !seed_confirm_clean($opts['yes'])) exit(1);
    $args = $extraArgs;
    if ($clean) array_unshift($args, 'clean');
    exit(seed_run_script($root . '/seeder/VisitSeeder.php', $args));
}
if ($mode === 'queue_visit') {
    exit(seed_run_script($root . '/seeder/QueueSeeder.php', array_merge(array('mode=visit'), $extraArgs)));
}
if ($mode === 'queue_tech') {
    exit(seed_run_script($root . '/seeder/QueueSeeder.php', array_merge(array('mode=tech'), $extraArgs)));
}
exit(1);
