<?php
/**
 * ============================================================
 * seed_dept_call_test.php — 医技叫号测试数据生成器
 * ============================================================
 * 说明：为「现有患者」中仍处于待就诊/就诊中（paid/visiting）的前 N 位
 * 各开具 4 张已缴费单：检验 / 检查 / 处方（发药）/ 护理处置（护士站执行），
 * 使检验科/影像科/药房/护士站四个医技叫号队列各出现 N 位待办患者，
 * 便于测试「绑定诊室 → 叫号下一位 → 大屏展示」全流程。
 *
 * 统一入口：php tools/bin/seed.php --scene=dept_call
 * 幂等：同一就诊若已存在同类型待办单则不重复添加。
 * ============================================================ */

require dirname(__DIR__, 2) . '/app/config/bootstrap.php';
require dirname(__DIR__) . '/seeder/PreflightChecker.php';
require dirname(__DIR__) . '/seeder/VisitFlowEngine.php';

/* ==================== 精细模式参数 ==================== */
// 用法：--scene="dept=lab"（仅检验）/ --scene="dept=lab,exam" / --scene="dept=prescription" / --scene="dept=disposal"
// 默认（无参数）：随机分布 检验/检查/处方/处置 四类，模拟真实门诊交叉开单
$deptArg = isset($argv[1]) ? trim((string)$argv[1]) : '';
$want = $deptArg === ''
    ? array('lab', 'imaging', 'prescription', 'disposal')
    : array_values(array_filter(explode(',', $deptArg)));
foreach ($want as $w) {
    if (!in_array($w, array('lab', 'imaging', 'exam', 'prescription', 'disposal'), true)) {
        preflight_die("未知医技类型「{$w}」（可选：lab / imaging / exam / prescription / disposal，可逗号组合）");
    }
}
// 规范化别名：exam = imaging（检查单），统一映射到 order_type=imaging
$want = array_map(function ($w) { return $w === 'exam' ? 'imaging' : $w; }, $want);

/* ==================== 前置依赖探测（Preflight） ==================== */
$pf = new PreflightChecker();
$pf->diagnosis();
if (in_array('lab', $want, true))          $pf->lab();
if (in_array('imaging', $want, true))      $pf->exam();
if (in_array('disposal', $want, true))     $pf->disposal();
if (in_array('prescription', $want, true)) $pf->drugs();

$n = isset($argv[2]) ? max(1, min(50, (int)$argv[2])) : 20;

// 字典：检验/检查/处置/药品（按需取）
$labItem  = in_array('lab', $want, true)          ? DB::one("SELECT * FROM lab_items WHERE status='approved' AND is_group=0 ORDER BY id LIMIT 1") : null;
$examItem = in_array('imaging', $want, true)      ? DB::one("SELECT * FROM exam_items WHERE status='approved' ORDER BY id LIMIT 1") : null;
$procItem = in_array('disposal', $want, true)     ? DB::one("SELECT * FROM disposal_items WHERE status='approved' AND is_nurse=1 ORDER BY id LIMIT 1") : null;
$drugItem = in_array('prescription', $want, true) ? DB::one("SELECT * FROM drugs WHERE status='approved' ORDER BY id LIMIT 1") : null;

/** 为该就诊强制创建一张已缴费单（测试用；统一走 VisitFlowEngine 状态机） */
function dept_call_add_order($visit, $orderType, $itemType, $item, $price, $extra = array()) {
    static $engine = null;
    if ($engine === null) $engine = new VisitFlowEngine();
    $engine->createPaidOrder($visit, $orderType, $itemType, $item, $price, $extra);
    return true;
}

$cnt = array('lab' => 0, 'imaging' => 0, 'prescription' => 0, 'procedure' => 0);
// 每类各挑「尚无该类型待办单」的待就诊/就诊中患者（保证队列新增 N 位新患者）
$plan = array(
    'lab'          => array('lab',          'lab',          $labItem,  $labItem  ? (float)$labItem['price']  : 0, array()),
    'imaging'      => array('imaging',      'imaging',      $examItem, $examItem ? (float)$examItem['price'] : 0, array()),
    'prescription' => array('prescription', 'prescription', $drugItem, $drugItem ? (float)$drugItem['price'] : 0, array()),
    'procedure'    => array('procedure',    'procedure',    $procItem, $procItem ? (float)$procItem['fee']   : 0, array('is_nurse' => 1)),
);
foreach ($plan as $key => $cfg) {
    // 精细模式：仅生成指定类型
    $orderType = $cfg[0];
    if (!in_array($orderType, $want, true)) continue;
    list($orderType, $itemType, $item, $price, $extra) = $cfg;
    $visits = DB::q(
        "SELECT id, patient_no, flow_no, current_dept_id, current_dept_name, visit_seq
         FROM registrations
         WHERE status IN ('paid','visiting')
           AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.visit_id=registrations.id AND o.order_type=? AND o.status IN ('paid','reviewed'))
         ORDER BY id DESC LIMIT ?",
        array($orderType, $n)
    );
    foreach ($visits as $v) {
        dept_call_add_order($v, $orderType, $itemType, $item, $price, $extra);
        $cnt[$key]++;
    }
}

echo "医技叫号测试数据生成完成" . ($deptArg !== '' ? "（精细模式：{$deptArg}）" : "（默认随机分布）") . "：\n";
echo "  检验科（lab）       +" . $cnt['lab'] . " 张待检验单（新增患者）\n";
echo "  影像科（imaging）   +" . $cnt['imaging'] . " 张待检查单（新增患者）\n";
echo "  药房（pharmacy）    +" . $cnt['prescription'] . " 张待审方处方（新增患者）\n";
echo "  护士站（nurse）     +" . $cnt['procedure'] . " 条待执行护理处置（新增患者）\n";
echo "提示：在【叫号管理】为对应医技科室创建大屏诊室，然后用 lab4001/imaging5001/pharmacy6001/nurse3001 登录工作台绑定诊室测试叫号。\n";
