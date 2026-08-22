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
        $visit['birth_date'] = $row['patient']['birth_date'];
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
        // 支持批量：检查申请单按分类拆分后，一次性打印多张（order_ids=1,2,3）
        $idsRaw = trim((string)get('order_ids', ''));
        if ($idsRaw !== '') {
            $orderIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), function ($v) { return $v > 0; })));
        } else {
            $orderIds = array_filter(array((int)get('order_id')), function ($v) { return $v > 0; });
        }
        if (!$orderIds) json_fail('开单记录不存在');
        $titles = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置申请单', 'prescription' => '门诊处方笺');
        $html = '';
        foreach ($orderIds as $orderId) {
            $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
            if (!$order) json_fail('开单记录不存在');
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($orderId));
            // 检查申请单标题动态化：显示「{检查分类}申请单」（如 CT申请单 / DR（数字化X线）申请单）
            $title = isset($titles[$order['order_type']]) ? $titles[$order['order_type']] : '申请单';
            if ($order['order_type'] === 'imaging' && isset($order['cat_name']) && trim((string)$order['cat_name']) !== '' && trim((string)$order['cat_name']) !== '检查') {
                $title = trim((string)$order['cat_name']) . '申请单';
            }
            $order['need_nurse_any'] = 0;
            foreach ($items as $it) {
                if (!empty($it['need_nurse'])) $order['need_nurse_any'] = 1;
            }
            $html .= pt_order($order, $items, $title);
        }
        json_ok(array('html' => $html));
        break;

    /* ---------------- 电子病历（补打） ---------------- */
    case 'record':
        $row = get_visit_row(get('visit_id'));
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        // 结构化病历优先（patient_records），无则回退旧 records 扁平数据
        $pr = DB::one('medical', 'SELECT * FROM patient_records WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        if ($pr) {
            $record = $record ? array_merge($record, array('emr_data' => $pr['emr_data'])) : array(
                'visit_type' => '初诊', 'emr_data' => $pr['emr_data'],
            );
        }
        $vitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        if (!$record) json_fail('该就诊暂无已保存的病历，请先在病历中完善主诉、现病史与初步诊断并保存后再打印');
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
        $visit['birth_date'] = $row['patient']['birth_date'];
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
        $visit['birth_date'] = $row && $row['patient'] ? $row['patient']['birth_date'] : '';
        json_ok(array('html' => pt_report($report, $result, $item, $visit)));
        break;

    default:
        json_fail('未知操作');
}
