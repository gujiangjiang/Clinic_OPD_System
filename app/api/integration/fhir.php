<?php
/**
 * ============================================================
 * fhir.php — FHIR R4 入向开放端点（Provider Server 模式）
 * ============================================================
 * 说明：本系统作为 FHIR 数据源对区域平台/上级机构提供标准资源调阅：
 *   GET /api/fhir/r4/metadata              CapabilityStatement
 *   GET /api/fhir/r4/Patient/{id}          Patient 资源（{id}=patient-{patient_no} 或患者编号）
 *   GET /api/fhir/r4/Encounter?patient={}  Encounter 检索（Bundle）
 * 鉴权：integration.inbound.fhir.enabled + allowed_tokens（Bearer/Token 列表）
 *      + ip_whitelist，由 InboundGuard 统一校验（不依赖登录会话）。
 * 响应按 FHIR 规范输出 Content-Type: application/fhir+json。
 * ============================================================ */

/** FHIR JSON 响应输出 */
function fhir_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/fhir+json; charset=utf-8');
    header('fhirVersion: 4.0.1');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- 入向鉴权（启用开关 + Token 列表 + IP 白名单） ---------- */
if (!integration_flag('integration.inbound.fhir.enabled')) {
    integration_log_inbound('fhir', 'r4', false, 'FHIR 入向未启用', '');
    fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
        'severity' => 'error', 'code' => 'forbidden', 'diagnostics' => 'FHIR 入向接口未启用',
    ))), 403);
}
if (!InboundGuard::ipAllowed((string)setting('integration.inbound.fhir.ip_whitelist', ''))) {
    integration_log_inbound('fhir', 'r4', false, 'IP 白名单拒绝', '');
    fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
        'severity' => 'error', 'code' => 'forbidden', 'diagnostics' => '来源 IP 不在白名单内',
    ))), 403);
}
if (!InboundGuard::tokenInList('integration.inbound.fhir.allowed_tokens')) {
    integration_log_inbound('fhir', 'r4', false, 'Token 校验失败', '');
    fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
        'severity' => 'error', 'code' => 'forbidden', 'diagnostics' => '鉴权失败：Token 无效',
    ))), 401);
}

/* ---------- 路由分发（CURRENT_API_SUB 形如 r4/metadata / r4/Patient/{id} / r4/Encounter） ---------- */
$__sub = defined('CURRENT_API_SUB') ? (string)CURRENT_API_SUB : '';
$__parts = $__sub !== '' ? explode('/', $__sub) : array();
array_shift($__parts);   // 去掉 r4 前缀

if (!$__parts) {
    fhir_json(FhirService::capabilityStatement());
}

$__seg0 = strtolower(isset($__parts[0]) ? $__parts[0] : '');
$__ok = false;
$__summary = '';

if ($__seg0 === 'metadata') {
    integration_log_inbound('fhir', 'r4', true, 'metadata（CapabilityStatement）', '');
    fhir_json(FhirService::capabilityStatement());
}

if ($__seg0 === 'patient') {
    $__id = urldecode(isset($__parts[1]) ? $__parts[1] : '');
    if ($__id === '') {
        fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
            'severity' => 'error', 'code' => 'required', 'diagnostics' => '缺少患者标识',
        ))), 400);
    }
    // 兼容 patient-{patient_no} 与裸患者编号两种 id 形态
    $__pno = (strpos($__id, 'patient-') === 0) ? substr($__id, 8) : $__id;
    $__patient = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($__pno));
    if (!$__patient) {
        integration_log_inbound('fhir', 'r4', false, 'Patient 未找到：' . $__id, '');
        fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
            'severity' => 'error', 'code' => 'not-found', 'diagnostics' => '患者不存在',
        ))), 404);
    }
    integration_log_inbound('fhir', 'r4', true, 'Patient: ' . $__id, '');
    fhir_json(FhirService::patientResource($__patient));
}

if ($__seg0 === 'encounter') {
    $__pno = trim((string)get('patient', ''));
    if ($__pno === '') $__pno = trim((string)get('subject', ''));
    if (strpos($__pno, 'Patient/') === 0) $__pno = substr($__pno, 8);
    if (strpos($__pno, 'patient-') === 0) $__pno = substr($__pno, 8);
    if ($__pno === '') {
        fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
            'severity' => 'error', 'code' => 'required', 'diagnostics' => '缺少 patient 参数',
        ))), 400);
    }
    integration_log_inbound('fhir', 'r4', true, 'Encounter?patient=' . $__pno, '');
    fhir_json(FhirService::encountersOfPatient($__pno));
}

integration_log_inbound('fhir', 'r4', false, '未知 FHIR 路由：' . $__sub, '');
fhir_json(array('resourceType' => 'OperationOutcome', 'issue' => array(array(
    'severity' => 'error', 'code' => 'not-supported', 'diagnostics' => '不支持的 FHIR 资源或操作',
))), 404);