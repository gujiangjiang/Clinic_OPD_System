<?php
/**
 * ============================================================
 * fhir.php — HL7 FHIR R4 (4.0.1) 入向开放端点（Provider Server）
 * ============================================================
 * 基地址：/api/fhir/r4
 *   GET  /api/fhir/r4/metadata                 CapabilityStatement（免认证）
 *   POST /api/fhir/oauth/token                 SMART-on-FHIR OAuth2 令牌（client_credentials）
 *   GET  /api/fhir/r4/{Resource}/{id}          单资源读取（Patient/Encounter/Condition/
 *                                              Observation/MedicationRequest/ImagingStudy）
 *   GET  /api/fhir/r4/{Resource}?…             集合检索（Bundle + 分页 + _include）
 *
 * 行为约定：
 *   - Content-Type: application/fhir+json; charset=utf-8；强制 CORS 头；
 *   - 统一 OperationOutcome 错误模型（invalid/not-found/forbidden/security/…）；
 *   - Authorization: Bearer <token> 或 X-API-Key: <token>；
 *     缺少/无效 → 401 + WWW-Authenticate + OperationOutcome；
 *     无对应 Scope → 403；未识别查询参数静默忽略。
 * ============================================================ */

require_once APP_ROOT . '/app/config/bootstrap.php';

/* ---------- 统一响应输出（FHIR JSON + CORS） ---------- */
function fhir_cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function fhir_json($data, $status = 200, $extraHeaders = array()) {
    http_response_code($status);
    fhir_cors();
    header('Content-Type: application/fhir+json; charset=utf-8');
    header('fhirVersion: 4.0.1');
    foreach ($extraHeaders as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fhir_error($status, $code, $diagnostics, $extraHeaders = array()) {
    fhir_json(FhirService::operationOutcome($code, $diagnostics), $status, $extraHeaders);
}

/* ---------- 预检请求 ---------- */
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    fhir_cors();
    http_response_code(204);
    exit;
}

/* ---------- 路由解析（CURRENT_API_SUB 形如 r4/metadata、r4/Patient/{id}、oauth/token） ---------- */
$__sub = defined('CURRENT_API_SUB') ? (string)CURRENT_API_SUB : '';
$__parts = $__sub !== '' ? explode('/', $__sub) : array();
if (isset($__parts[0]) && strtolower($__parts[0]) === 'r4') array_shift($__parts);
$__seg0 = strtolower(isset($__parts[0]) ? $__parts[0] : '');

/* ============================================================
 * ① OAuth2 Token 端点（免认证，client_credentials）
 * ============================================================ */
if ($__seg0 === 'oauth') {
    $__act = strtolower(isset($__parts[1]) ? $__parts[1] : '');
    if ($__act !== 'token') {
        fhir_error(404, 'not-supported', '不支持的 OAuth 端点（仅 /api/fhir/oauth/token）');
    }
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
        fhir_error(400, 'invalid', '令牌端点仅支持 POST');
    }
    // 解析表单或 JSON 载荷
    $raw = file_get_contents('php://input');
    $in = array();
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $in = $json;
        else { parse_str($raw, $in); }
    }
    if (!empty($_POST)) $in = array_merge($in, $_POST);
    try {
        $resp = FhirService::issueToken(
            isset($in['grant_type']) ? (string)$in['grant_type'] : '',
            isset($in['client_id']) ? (string)$in['client_id'] : '',
            isset($in['client_secret']) ? (string)$in['client_secret'] : '',
            isset($in['scope']) ? (string)$in['scope'] : ''
        );
        integration_log_inbound('fhir', 'oauth/token', true, '颁发令牌：' . (isset($in['client_id']) ? $in['client_id'] : ''), '');
        fhir_json($resp, 200);
    } catch (FhirError $ex) {
        integration_log_inbound('fhir', 'oauth/token', false, $ex->getMessage(), '');
        fhir_error($ex->httpStatus, $ex->issueCode, $ex->getMessage());
    } catch (Exception $ex) {
        fhir_error(500, 'exception', '令牌颁发失败：' . $ex->getMessage());
    }
}

/* ============================================================
 * ② CapabilityStatement（免认证）
 * ============================================================ */
if ($__seg0 === '' || $__seg0 === 'metadata') {
    integration_log_inbound('fhir', 'r4', true, 'metadata（CapabilityStatement）', '');
    fhir_json(FhirService::capabilityStatement());
}

/* ============================================================
 * ③ 受保护资源端点：鉴权（Bearer / X-API-Key + Scope）
 * ============================================================ */
$__resourceInput = isset($__parts[0]) ? (string)$__parts[0] : '';
$__adapter = FhirService::adapterFor($__resourceInput);
if (!$__adapter) {
    fhir_error(404, 'not-supported', '不支持的 FHIR 资源或操作：' . $__sub);
}

// 启用开关（系统级入向开放总闸）
if (!integration_flag('integration.inbound.fhir.enabled')) {
    integration_log_inbound('fhir', 'r4', false, 'FHIR 入向未启用', '');
    fhir_error(403, 'forbidden', 'FHIR 入向接口未启用');
}

// IP 白名单（受保护端点生效；/metadata 与 /oauth/token 已在前面免认证放行）
if (!InboundGuard::ipAllowed((string)setting('integration.inbound.fhir.ip_whitelist', ''))) {
    integration_log_inbound('fhir', 'r4', false, 'IP 白名单拒绝', '');
    fhir_error(403, 'forbidden', '来源 IP 不在白名单内');
}

// 提取凭证：Bearer / ?token= / X-API-Key
$__token = InboundGuard::providedToken();
if ($__token === '') $__token = InboundGuard::providedApiKey();

// ① 自包含 OAuth2 令牌校验
$__scope = '';
$__authorized = false;
$__payload = FhirService::verifyToken($__token);
if (is_array($__payload)) {
    $__authorized = true;
    $__scope = isset($__payload['scope']) ? (string)$__payload['scope'] : '';
}
// ② 长期静态 Token / API Key（settings 配置列表）
if (!$__authorized) {
    $__entry = InboundGuard::findListEntry('integration.inbound.fhir.allowed_tokens', $__token);
    if ($__entry) {
        $__state = InboundGuard::entryState($__entry);
        if ($__state === 'disabled') {
            integration_log_inbound('fhir', 'r4', false, '凭证已禁用', '');
            fhir_error(403, 'forbidden', '鉴权失败：该凭证已禁用');
        }
        if ($__state === 'expired') {
            integration_log_inbound('fhir', 'r4', false, '凭证已过期', '');
            fhir_error(401, 'security', '鉴权失败：该凭证已过期', array('WWW-Authenticate: Bearer error="invalid_token"'));
        }
        $__authorized = true;
        $__scope = isset($__entry['scopes']) && $__entry['scopes'] !== '' ? $__entry['scopes'] : '*';
    }
}
if (!$__authorized) {
    integration_log_inbound('fhir', 'r4', false, 'Token 校验失败', '');
    fhir_error(401, 'security', '鉴权失败：Token 无效或缺失', array('WWW-Authenticate: Bearer error="invalid_token"'));
}

// Scope 校验：system/{Resource}.read（支持 system/*.read 等通配）
$__resourceType = $__adapter::resourceType();
$__required = 'system/' . $__resourceType . '.read';
if (!FhirService::scopeAllows($__scope, $__required)) {
    integration_log_inbound('fhir', 'r4', false, '缺少 Scope：' . $__required, '');
    fhir_error(403, 'forbidden', '无权限访问该资源（缺少 Scope：' . $__required . '）');
}

/* ---------- 单资源读取 / 集合检索 ---------- */
$__id = isset($__parts[1]) ? urldecode((string)$__parts[1]) : '';
$__selfUrl = integration_host_base() . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : FhirAdapter::BASE_PATH);
try {
    if ($__id !== '') {
        $__res = FhirService::read($__resourceInput, $__id);
        integration_log_inbound('fhir', 'r4', true, $__resourceType . ': ' . $__id, '');
        fhir_json($__res);
    }
    $__bundle = FhirService::search($__resourceInput, $_GET, $__selfUrl);
    integration_log_inbound('fhir', 'r4', true, $__resourceType . ' 检索，共 ' . (int)$__bundle['total'] . ' 条', '');
    fhir_json($__bundle);
} catch (FhirError $ex) {
    integration_log_inbound('fhir', 'r4', false, $ex->getMessage(), '');
    fhir_error($ex->httpStatus, $ex->issueCode, $ex->getMessage());
} catch (Exception $ex) {
    integration_log_inbound('fhir', 'r4', false, '服务器内部错误：' . $ex->getMessage(), '');
    fhir_error(500, 'exception', '服务器内部错误');
}
