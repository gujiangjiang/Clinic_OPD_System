<?php
/**
 * ============================================================
 * his.php v1.1.0 — 预留 HIS 对接 API（需求23）
 * ============================================================
 * 说明：为未来扩展住院 HIS 等系统预留的只读数据接口：
 *   1. 通过 API 密钥认证（系统设置 → HIS接口密钥，为空时接口关闭）
 *   2. 只读查询：患者档案 / 就诊记录 / 就诊状态 / 开单明细
 *   3. 接口均返回统一 JSON 格式 { ok, msg, data }
 * 认证方式（二选一）：
 *   GET 参数：/api/his?api_key=xxxx&action=patient_get&id_card=...
 *   请求头：  X-HIS-Key: xxxx
 * 说明：本接口不依赖登录会话，供外部系统（住院HIS、医保、BI等）调用。
 * ============================================================ */

/* ---------- API 密钥认证 ---------- */
$hisKey = (string)setting('his_api_key', '');
if ($hisKey === '') {
    json_fail('HIS 接口未启用（请在系统设置中配置 HIS 接口密钥）');
}
$given = get('api_key', '');
if ($given === '' && isset($_SERVER['HTTP_X_HIS_KEY'])) {
    $given = trim((string)$_SERVER['HTTP_X_HIS_KEY']);
}
if ($given === '' || !hash_equals($hisKey, $given)) {
    json_fail('HIS API 密钥无效');
}

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

switch ($action) {

    /* ---------------- 患者档案查询（按身份证 / 患者ID） ---------------- */
    case 'patient_get':
        $idCard = strtoupper(get('id_card', ''));
        $patientNo = get('patient_no', '');
        if ($idCard === '' && $patientNo === '') {
            json_fail('请提供 id_card 或 patient_no 参数');
        }
        $p = $idCard !== ''
            ? DB::one('SELECT * FROM patients WHERE id_card=?', array($idCard))
            : DB::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$p) json_fail('未检索到患者');
        unset($p['id']);
        json_ok(array('patient' => $p));
        break;

    /* ---------------- 就诊记录列表（按患者ID） ---------------- */
    case 'visit_list':
        $patientNo = get('patient_no', '');
        if ($patientNo === '') json_fail('请提供 patient_no 参数');
        $visits = DB::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
        $out = array();
        foreach ($visits as $v) {
            unset($v['id']);
            $out[] = $v;
        }
        json_ok(array('visits' => $out));
        break;

    /* ---------------- 就诊状态查询（按门诊流水号） ---------------- */
    case 'visit_status':
        $flowNo = get('flow_no', '');
        if ($flowNo === '') json_fail('请提供 flow_no 参数');
        $v = DB::one('SELECT * FROM registrations WHERE flow_no=?', array($flowNo));
        if (!$v) json_fail('未检索到该就诊记录');
        $p = DB::one('SELECT name, gender, age FROM patients WHERE patient_no=?', array($v['patient_no']));
        json_ok(array(
            'flow_no' => $v['flow_no'],
            'patient_no' => $v['patient_no'],
            'patient_name' => $p ? $p['name'] : '',
            'first_dept' => $v['first_dept_name'],
            'current_dept' => $v['current_dept_name'],
            'visit_seq' => (int)$v['visit_seq'],
            'status' => $v['status'],
            'status_name' => visit_status_name($v['status']),
            'registered_at' => $v['registered_at'],
        ));
        break;

    /* ---------------- 开单明细（按就诊ID） ---------------- */
    case 'order_list':
        $visitId = (int)get('visit_id', 0);
        if ($visitId <= 0) json_fail('请提供 visit_id 参数');
        $orders = DB::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
        $out = array();
        foreach ($orders as $o) {
            $items = DB::q('SELECT item_name, price, quantity, single_dose, frequency, route, is_nurse, status FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $out[] = array(
                'order_no' => $o['order_no'],
                'order_type' => $o['order_type'],
                'order_type_name' => isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : $o['order_type'],
                'doctor_name' => $o['doctor_name'],
                'total_amount' => (float)$o['total_amount'],
                'status' => $o['status'],
                'created_at' => $o['created_at'],
                'paid_at' => $o['paid_at'],
                'items' => $items,
            );
        }
        json_ok(array('orders' => $out));
        break;

    default:
        json_fail('未知操作（可用：patient_get / visit_list / visit_status / order_list）');
}
