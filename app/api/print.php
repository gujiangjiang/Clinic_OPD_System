<?php
/**
 * ============================================================
 * print.php — 统一打印中心数据接口
 * ============================================================
 * 说明：所有单据打印（挂号凭条/缴费凭条/申请单/处方单/处置单/
 * 检验检查报告/诊断证明/电子病历）统一由本接口提供 HTML，
 * 前端 print.js 渲染后打印。管理员【打印中心】及各科室补打均调用本接口。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';

switch ($action) {

    /* ---------------- 挂号凭条 ---------------- */
    case 'receipt':
        $row = get_visit_row(get('visit_id'));
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['first_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        $visit['status_name'] = visit_status_name($visit['status']);
        json_ok(array('html' => pt_receipt($visit, $row['patient'])));
        break;

    /* ---------------- 缴费凭条 ---------------- */
    case 'payment':
        $payId = (int)get('payment_id');
        $pay = DB::one('order', 'SELECT * FROM payments WHERE id=?', array($payId));
        if (!$pay) json_fail('缴费记录不存在');
        $items = array();
        if ($pay['kind'] === 'visit') {
            // 挂号费缴费：项目为挂号费
            $visit = DB::one('patient', 'SELECT * FROM registrations WHERE id=?', array($pay['visit_id']));
            $dept = $visit ? DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['first_dept_id'])) : null;
            $items[] = array('name' => '挂号费（' . ($visit ? $visit['first_dept_name'] : '') . '）', 'quantity' => 1, 'price' => $pay['total']);
        } else {
            $rows = DB::q('order', 'SELECT * FROM order_items WHERE order_id=?', array($pay['order_id']));
            foreach ($rows as $r) {
                $items[] = array('name' => $r['item_name'], 'quantity' => (int)$r['quantity'], 'price' => $r['price']);
            }
        }
        json_ok(array('html' => pt_payment($pay, $items)));
        break;

    /* ---------------- 申请单 / 处方单 / 处置单 ---------------- */
    case 'order':
        $orderId = (int)get('order_id');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order) json_fail('开单记录不存在');
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($orderId));
        $titles = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置单', 'prescription' => '处方单');
        $title = isset($titles[$order['order_type']]) ? $titles[$order['order_type']] : '申请单';
        $order['need_nurse_any'] = 0;
        foreach ($items as $it) {
            if (!empty($it['need_nurse'])) $order['need_nurse_any'] = 1;
        }
        json_ok(array('html' => pt_order($order, $items, $title)));
        break;

    /* ---------------- 电子病历（补打） ---------------- */
    case 'record':
        $row = get_visit_row(get('visit_id'));
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        $vitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        if (!$record) json_fail('该就诊暂无病历记录');
        json_ok(array('html' => pt_record($visit, $row['patient'], $record, $vitals)));
        break;

    /* ---------------- 诊断证明（补打） ---------------- */
    case 'certificate':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $cert = DB::one('medical', 'SELECT * FROM certificates WHERE visit_id=?', array($visitId));
        if (!$cert) json_fail('该就诊未开具诊断证明');
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        json_ok(array('html' => pt_certificate($visit, $row['patient'], $record, $cert, $cert['doctor_name'])));
        break;

    /* ---------------- 检验/检查报告 ---------------- */
    case 'report':
        $reportId = (int)get('report_id');
        $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($reportId));
        if (!$report) json_fail('报告不存在');
        $result = DB::one('lab', 'SELECT * FROM results WHERE id=?', array($report['result_id']));
        $item = null;
        if ($result) {
            $item = DB::one('lab', 'SELECT * FROM ' . ($result['type'] === 'lab' ? 'lab_items' : 'exam_items') . ' WHERE id=?', array($result['item_id']));
        }
        $row = get_visit_row($report['visit_id']);
        $visit = $row ? $row['visit'] : array();
        $visit['name'] = $row && $row['patient'] ? $row['patient']['name'] : '';
        $visit['gender'] = $row && $row['patient'] ? $row['patient']['gender'] : '';
        $visit['age'] = $row && $row['patient'] ? $row['patient']['age'] : '';
        json_ok(array('html' => pt_report($report, $result, $item, $visit)));
        break;

    default:
        json_fail('未知操作');
}
