<?php
/**
 * ============================================================
 * his.php — HIS 入向只读查询接口（外部 HIS/医保/BI 调用）
 * ============================================================
 * 说明：本文件仅为路由壳：Token 鉴权 + 动作分发。
 * 全部查询逻辑已下沉至 services/his/HisInboundRead.php
 * （业务逻辑与数据访问一律收口服务层/仓库层，本文件不再内联 SQL）。
 * 鉴权密钥统一为 integration.inbound.his.token（旧键 his_api_key 自动回退）；
 * 入向推送（患者/字典）与出向同步（Outbox）见 /api/external 与 services/his。
 * ============================================================ */

/* ---------- 入向 Token 认证（新键优先，旧键 his_api_key 回退） ---------- */
$hisKey = (string)integration_cfg('inbound.his.token', '', 'his_api_key');
if ($hisKey === '') {
    json_fail('HIS 接口未启用（请在接口管理 → HIS 接口配置入向 Token）');
}
// 密钥传递：推荐请求头 X-HIS-Key（不进日志/浏览器历史）；兼容仅 GET 参数方式的
// 外部 HIS 系统，也接受 api_key 参数（会进入访问日志，风险由管理员自行评估）。
$given = isset($_SERVER['HTTP_X_HIS_KEY']) ? trim((string)$_SERVER['HTTP_X_HIS_KEY']) : '';
if ($given === '') $given = trim((string)get('api_key', ''));
if ($given === '' || !hash_equals($hisKey, $given)) {
    integration_log_inbound('his', 'read', false, '只读查询 Token 校验失败', '');
    json_fail('HIS API 密钥无效');
}
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
integration_log_inbound('his', 'read', true, '只读查询：' . $action, '');

switch ($action) {
    case 'ping':
        json_ok(HisInboundRead::ping());
        break;
    case 'patient_get':
        json_ok(HisInboundRead::patientGet(get('id_card', ''), get('patient_no', '')));
        break;
    case 'visit_list':
        json_ok(HisInboundRead::visitList(get('patient_no', '')));
        break;
    case 'visit_status':
        json_ok(HisInboundRead::visitStatus(get('flow_no', '')));
        break;
    case 'order_list':
        json_ok(HisInboundRead::orderList(get('visit_id', 0)));
        break;
    case 'evidence_verify':
        json_ok(HisInboundRead::evidenceVerify(get('record_id', 0), get('cert_no', '')));
        break;
    default:
        json_fail('未知操作（可用：ping / patient_get / visit_list / visit_status / order_list / evidence_verify）');
}