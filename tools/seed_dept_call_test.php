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
 * 用法：frankenphp php-cli tools/seed_dept_call_test.php [数量N=20]
 * 幂等：同一就诊若已存在同类型待办单则不重复添加。
 * ============================================================ */

require dirname(__DIR__) . '/app/config/bootstrap.php';

$n = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 20;

// 字典：检验/检查/处置/药品
$labItem   = DB::one("SELECT * FROM lab_items WHERE status='approved' AND is_group=0 ORDER BY id LIMIT 1");
$examItem  = DB::one("SELECT * FROM exam_items WHERE status='approved' ORDER BY id LIMIT 1");
$procItem  = DB::one("SELECT * FROM disposal_items WHERE status='approved' AND is_nurse=1 ORDER BY id LIMIT 1");
$drugItem  = DB::one("SELECT * FROM drugs WHERE status='approved' ORDER BY id LIMIT 1");
if (!$labItem || !$examItem || !$procItem || !$drugItem) {
    echo "缺少检验/检查/处置/药品项目，请先维护并通过审核。\n";
    exit(1);
}

/** 生成唯一单号 */
function dept_call_unique_no($prefix, $table, $col) {
    do {
        $no = $prefix . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
    } while ((int)DB::val("SELECT COUNT(*) FROM $table WHERE $col=?", array($no)) > 0);
    return $no;
}

/** 为该就诊强制创建一张已缴费单（测试用；同一就诊同类型单可重复添加，保证各队列有足量新号） */
function dept_call_add_order($visit, $orderType, $itemType, $item, $price, $extra = array()) {
    $visitId = (int)$visit['id'];
    $now = now_str();
    $prefix = array('lab' => 'JY', 'imaging' => 'JC', 'prescription' => 'CF', 'procedure' => 'CZ');
    $orderNo = dept_call_unique_no($prefix[$orderType], 'orders', 'order_no');
    $deptId = (int)$visit['current_dept_id'];
    $deptName = (string)$visit['current_dept_name'];
    // 开单医生：取本科室任一启用医生（作为开单归属），无则用测试技师
    $doc = DB::one("SELECT id, name FROM users WHERE role='doctor' AND status=1 AND (dept_ids='' OR dept_ids LIKE ?) ORDER BY id LIMIT 1", array('%,' . $deptId . ',%'));
    if (!$doc) $doc = DB::one("SELECT id, name FROM users WHERE role='doctor' AND status=1 ORDER BY id LIMIT 1");
    $docId = $doc ? (int)$doc['id'] : 0;
    $docName = $doc ? (string)$doc['name'] : '系统测试';

    $pdo = DatabaseManager::getMain();
    $pdo->beginTransaction();
    try {
        $orderId = (int)DB::insert(
            'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($visitId, $visit['patient_no'], $visit['flow_no'], $orderType, $orderNo, $docId, $docName, $deptId, $deptName, $price, 'paid', $now, $now)
        );
        DB::insert(
            'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, price, quantity, single_dose, frequency, route, is_nurse, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array(
                $orderId, $visitId, $visit['patient_no'], $visit['flow_no'],
                $itemType, (int)$item['id'], (string)$item['name'],
                isset($item['spec']) ? (string)$item['spec'] : '',
                isset($item['unit']) ? (string)$item['unit'] : '',
                (float)$price, 1,
                isset($item['single_dose']) ? (string)$item['single_dose'] : '',
                isset($item['frequency']) ? (string)$item['frequency'] : '',
                isset($item['route']) ? (string)$item['route'] : '',
                (int)(isset($extra['is_nurse']) ? $extra['is_nurse'] : 0),
                'paid', $docId, $docName, $now,
            )
        );
        DB::insert(
            'INSERT INTO payments(order_id, visit_id, patient_no, flow_no, total, item_count, cashier_id, cashier_name, kind, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
            array($orderId, $visitId, $visit['patient_no'], $visit['flow_no'], $price, 1, 2, '收款员', 'order', $now)
        );
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return true;
}

$cnt = array('lab' => 0, 'imaging' => 0, 'prescription' => 0, 'procedure' => 0);
// 每类各挑「尚无该类型待办单」的待就诊/就诊中患者（保证队列新增 N 位新患者）
$plan = array(
    'lab'          => array('lab',          'lab',          $labItem,  (float)$labItem['price'], array()),
    'imaging'      => array('imaging',      'imaging',      $examItem, (float)$examItem['price'], array()),
    'prescription' => array('prescription', 'prescription', $drugItem, (float)$drugItem['price'], array()),
    'procedure'    => array('procedure',    'procedure',    $procItem, (float)$procItem['fee'],   array('is_nurse' => 1)),
);
foreach ($plan as $key => $cfg) {
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

echo "医技叫号测试数据生成完成：\n";
echo "  检验科（lab）       +" . $cnt['lab'] . " 张待检验单（新增患者）\n";
echo "  影像科（imaging）   +" . $cnt['imaging'] . " 张待检查单（新增患者）\n";
echo "  药房（pharmacy）    +" . $cnt['prescription'] . " 张待审方处方（新增患者）\n";
echo "  护士站（nurse）     +" . $cnt['procedure'] . " 条待执行护理处置（新增患者）\n";
echo "提示：在【叫号管理】为对应医技科室创建大屏诊室，然后用 lab4001/imaging5001/pharmacy6001/nurse3001 登录工作台绑定诊室测试叫号。\n";
