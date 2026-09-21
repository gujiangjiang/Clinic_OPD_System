<?php
/**
 * ============================================================
 * his.php v1.1.0 — 预留 HIS 对接 API（需求23）
 * ============================================================
 * 说明：为未来扩展住院 HIS 等系统预留的只读数据接口：
 *   1. 通过 API 密钥认证（接口管理 → HIS 接口密钥，为空时接口关闭）
 *   2. 只读查询：连通性自检 / 患者档案 / 就诊记录 / 就诊状态 / 开单明细
 *   3. 接口均返回统一 JSON 格式 { ok, msg, data }
 * 认证方式：推荐请求头 X-HIS-Key: xxxx（密钥不进 URL，避免进入 Web 日志/浏览器历史/Referer）；
 * 兼容 GET 参数 api_key 方式（会进入访问日志，风险由管理员评估）。
 * 说明：本接口不依赖登录会话，供外部系统（住院HIS、医保、BI等）调用。
 * ============================================================ */

/* ---------- API 密钥认证 ---------- */
$hisKey = (string)setting('his_api_key', '');
if ($hisKey === '') {
    json_fail('HIS 接口未启用（请在系统设置中配置 HIS 接口密钥）');
}
// 密钥传递：推荐请求头 X-HIS-Key（不进日志/浏览器历史）；兼容仅 GET 参数方式的
// 外部 HIS 系统，也接受 api_key 参数（会进入访问日志，风险由管理员自行评估）。
$given = isset($_SERVER['HTTP_X_HIS_KEY']) ? trim((string)$_SERVER['HTTP_X_HIS_KEY']) : '';
if ($given === '') $given = trim((string)get('api_key', ''));
if ($given === '' || !hash_equals($hisKey, $given)) {
    json_fail('HIS API 密钥无效');
}

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

switch ($action) {

    /* ---------------- 连通性自检（接口管理页测试按钮使用，无需业务参数） ---------------- */
    case 'ping':
        // 医疗机构代码（org_code，安装/系统设置配置的医保结算/监管报送唯一标识）
        // 随自检返回，供外部系统（HIS/医保/BI）联调确认机构归属
        json_ok(array(
            'pong' => true,
            'system' => 'Clinic OPD System',
            'system_code' => (string)setting('his_system_code', ''),
            'org_code' => (string)setting('org_code', ''),
            'server_time' => now_str(),
        ));
        break;

    /* ---------------- 患者档案查询（按身份证 / 患者ID） ---------------- */
    case 'patient_get':
        $idCard = strtoupper(get('id_card', ''));
        $patientNo = get('patient_no', '');
        if ($idCard === '' && $patientNo === '') {
            json_fail('请提供 id_card 或 patient_no 参数');
        }
        $p = $idCard !== ''
            ? PatientRepository::one('SELECT * FROM patients WHERE id_card=?', array($idCard))
            : PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$p) json_fail('未检索到患者');
        unset($p['id']);
        json_ok(array('patient' => $p));
        break;

    /* ---------------- 就诊记录列表（按患者ID） ---------------- */
    case 'visit_list':
        $patientNo = get('patient_no', '');
        if ($patientNo === '') json_fail('请提供 patient_no 参数');
        $visits = PatientRepository::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
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
        $v = PatientRepository::one('SELECT * FROM registrations WHERE flow_no=?', array($flowNo));
        if (!$v) json_fail('未检索到该就诊记录');
        $p = PatientRepository::one('SELECT name, gender, age FROM patients WHERE patient_no=?', array($v['patient_no']));
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
        $orders = PatientRepository::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
        $out = array();
        foreach ($orders as $o) {
            $items = PatientRepository::q('SELECT item_name, price, quantity, single_dose, frequency, route, is_nurse, status FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
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

    /* ---------------- 存证校验（按记录ID/证明号核验指纹与凭据，外部机构验真用） ---------------- */
    case 'evidence_verify':
        $recId = (int)get('record_id', 0);
        $certNo = trim((string)get('cert_no', ''));
        if ($recId > 0) {
            $r = EmrRepository::one(
                "SELECT id AS rid, visit_id, patient_no, flow_no, record_type, evid_hash, evid_algo, evid_token, evid_signer, evid_time, created_at
                 FROM patient_records WHERE id=?", array($recId));
            if (!$r) json_fail('未检索到该病历存证记录');
            json_ok(array(
                'type' => 'record', 'record_id' => $recId, 'patient_no' => $r['patient_no'], 'flow_no' => $r['flow_no'],
                'record_type' => $r['record_type'], 'evid_hash' => $r['evid_hash'], 'evid_algo' => $r['evid_algo'],
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => $r['evid_time'],
                'created_at' => $r['created_at'],
            ));
            break;
        }
        if ($certNo !== '') {
            $r = EmrRepository::one(
                "SELECT id, visit_id, patient_no, flow_no, cert_no, content, evid_hash, evid_algo, evid_token, evid_signer, evid_time, created_at
                 FROM certificates WHERE cert_no=?", array($certNo));
            if (!$r) json_fail('未检索到该证明的存证记录');
            json_ok(array(
                'type' => 'certificate', 'cert_no' => $certNo, 'patient_no' => $r['patient_no'], 'flow_no' => $r['flow_no'],
                'content' => $r['content'], 'evid_hash' => $r['evid_hash'], 'evid_algo' => $r['evid_algo'],
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => $r['evid_time'],
                'created_at' => $r['created_at'],
            ));
            break;
        }
        json_fail('请提供 record_id 或 cert_no 参数');
        break;

    default:
        json_fail('未知操作（可用：ping / patient_get / visit_list / visit_status / order_list / evidence_verify）');
}
