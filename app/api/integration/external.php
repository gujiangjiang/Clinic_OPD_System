<?php
/**
 * ============================================================
 * external.php — 外部集成入向统一控制器（纯路由壳）
 * ============================================================
 * 说明：承接第三方/上级系统向本系统推送的开放端点（不依赖登录会话）。
 * 所有请求统一收归 InboundGuard 网关守护：
 *   ① 模块启用开关 → ② IP 白名单（支持 CIDR）→ ③ API Key/Secret
 *   生命周期（过期/启用）→ ④ 粒度化 Scope 隔离（无权限统一 403）。
 *   POST /api/external/lis/callback           LIS 检验报告结果接收 Webhook   [report:write]
 *   POST /api/external/hl7/receiver           HL7 v2 消息接收（HTTP 代理）    [report:write]
 *   GET  /api/external/his/read?action=…      HIS 只读查询（基础信息调阅）     [patient:read|report:query]
 *   POST /api/external/his/sync-patient       HIS 患者预约/建档推送           [patient:sync]
 *   POST /api/external/his/sync-catalog       HIS 基础字典同步                [catalog:sync]
 * 业务逻辑全部委派 services 层；调用一律写入 inbound_events 审计表。
 * ============================================================ */

require_once APP_ROOT . '/app/config/bootstrap.php';

$__sub = defined('CURRENT_API_SUB') ? (string)CURRENT_API_SUB : '';
$__parts = $__sub !== '' ? explode('/', $__sub) : array();
$__mod = isset($__parts[0]) ? $__parts[0] : '';
$__act = isset($__parts[1]) ? $__parts[1] : '';

/** 读取请求体（JSON 优先，失败回退原始文本） */
function external_body() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $raw = isset($GLOBALS['HTTP_RAW_POST_DATA']) ? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
    }
    return (string)$raw;
}

/** 读取 JSON 请求体（解析失败返回 null） */
function external_json() {
    $json = json_decode(external_body(), true);
    return is_array($json) ? $json : null;
}

/** 读取 XML 请求体（返回简化数组） */
function external_xml() {
    $raw = external_body();
    $prev = libxml_use_internal_errors(true);
    $el = simplexml_load_string($raw);
    libxml_use_internal_errors($prev);
    if ($el === false) return null;
    return json_decode(json_encode($el), true);
}

/** HTTP 状态感知的失败响应（401/403/… 真实状态码 + 统一 JSON） */
function external_fail($http, $msg) {
    http_response_code((int)$http);
    json_response(false, $msg);
}

/**
 * 统一网关守护：返回 [caller, scopes]；失败按状态码输出并终止。
 * @param string $module 模块标识
 * @param array  $opts   InboundGuard::authorize 参数
 * @return array { caller:string, scopes:string }
 */
function external_gate($module, $opts) {
    $r = InboundGuard::authorize($module, $opts);
    if (!$r['ok']) {
        integration_log_inbound('inbound', $module, false, $r['msg'], '');
        external_fail($r['http'], $r['msg']);
    }
    return array('caller' => $r['caller'], 'scopes' => $r['scopes']);
}

/** 附加 Scope 校验（同一凭证不同 action 的细粒度隔离） */
function external_scope($module, $granted, $required) {
    if ($required === '' || $required === null) return;
    if (!InboundGuard::scopeAllows($granted, $required)) {
        integration_log_inbound('inbound', $module, false, '缺少 Scope：' . $required, '');
        external_fail(403, '无权限访问该接口（缺少 Scope：' . $required . '）');
    }
}

switch ($__mod) {

    /* ==================== LIS 检验报告结果接收 ==================== */
    case 'lis':
        if ($__act !== 'callback') external_fail(404, '未知 LIS 操作');
        external_gate('lis', array(
            'tokenKey' => 'integration.inbound.lis.webhook_secret',
            'ipKey' => 'integration.inbound.lis.ip_whitelist',
            'requiredScope' => 'report:write',
        ));
        $payload = external_json();
        if ($payload === null && !empty($_POST)) $payload = $_POST;
        if ($payload === null) {
            integration_log_inbound('lis', 'callback', false, '回调载荷非 JSON', substr(external_body(), 0, 2000));
            external_fail(400, '回调载荷必须为 JSON');
        }
        try {
            $res = LisService::handleCallback($payload);
            integration_log_inbound('lis', 'callback', true, $res['msg'], external_body());
            json_ok(array('updated' => $res['updated'], 'report_id' => $res['report_id']), $res['msg']);
        } catch (Exception $ex) {
            integration_log_inbound('lis', 'callback', false, $ex->getMessage(), external_body());
            external_fail(400, $ex->getMessage());
        }
        break;

    /* ==================== HL7 v2 消息接收（HTTP 代理） ==================== */
    case 'hl7':
        if ($__act !== 'receiver') external_fail(404, '未知 HL7 操作');
        external_gate('hl7', array(
            'ipKey' => 'integration.inbound.hl7.ip_whitelist',
            'tokenKey' => 'integration.inbound.hl7.token',
            'skipApiGuard' => (trim((string)setting('integration.inbound.hl7.token', '')) === ''),
            'requiredScope' => 'report:write',
        ));
        $raw = external_body();
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['message'])) {
            $raw = (string)$json['message'];
        } elseif (empty($raw) && isset($_POST['message'])) {
            $raw = (string)$_POST['message'];
        }
        $parsed = HL7MessageParser::parse($raw);
        $msgType = isset($parsed['msh']['type']) ? $parsed['msh']['type'] : '';
        $ackCode = 'AA';
        $ackText = '';
        try {
            if (strpos($msgType, 'ORU') === 0) {
                $oru = HL7MessageParser::oruExtract($parsed);
                if (!$oru['ok']) throw new Exception('ORU 消息缺少 OBR 申请单号');
                $res = HL7InboundService::applyOru($oru);
                $ackText = $res['msg'];
                integration_log_inbound('hl7', 'receiver', true, 'ORU^R01 ' . $res['msg'], $raw);
            } elseif ($msgType !== '') {
                integration_log_inbound('hl7', 'receiver', true, '接收确认：' . $msgType, $raw);
            } else {
                $ackCode = 'AR';
                throw new Exception('无法解析 HL7 消息（缺少 MSH 段）');
            }
        } catch (Exception $ex) {
            if ($ackCode === 'AA') $ackCode = 'AE';   // 保留上一段设置的 AR（拒绝）
            $ackText = $ex->getMessage();
            integration_log_inbound('hl7', 'receiver', false, $ex->getMessage(), $raw);
        }
        $ack = HL7MessageBuilder::ack($raw, $ackCode, $ackText !== '' ? $ackText : 'received');
        $isJsonReq = is_array(json_decode(external_body(), true));
        if ($isJsonReq) {
            json_ok(array('ack' => $ack, 'msa' => $ackCode), $ackCode === 'AA' ? 'HL7 消息已接收' : 'HL7 处理失败');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $ack;
        exit;
        break;

    /* ==================== HIS 患者预约/建档推送与只读查询 ==================== */
    case 'his':
        // 只读查询：GET /api/external/his/read?action=ping
        if ($__act === 'read') {
            $q = trim((string)get('action', ''));
            $scopeMap = array(
                'ping' => '',
                'patient_get' => 'patient:read',
                'visit_list' => 'patient:read',
                'visit_status' => 'patient:read',
                'order_list' => 'report:query',
                'evidence_verify' => 'report:query',
            );
            if (!isset($scopeMap[$q])) {
                external_gate('his', array('tokenKey' => 'integration.inbound.his.token', 'legacyToken' => 'his_api_key', 'ipKey' => 'integration.inbound.his.ip_whitelist'));
                external_fail(400, '未知操作（可用：ping / patient_get / visit_list / visit_status / order_list / evidence_verify）');
            }
            external_gate('his', array(
                'tokenKey' => 'integration.inbound.his.token',
                'legacyToken' => 'his_api_key',
                'ipKey' => 'integration.inbound.his.ip_whitelist',
                'requiredScope' => $scopeMap[$q],
            ));
            integration_log_inbound('his', 'read', true, '只读查询：' . $q, '');
            switch ($q) {
                case 'ping': json_ok(HisInboundRead::ping()); break;
                case 'patient_get': json_ok(HisInboundRead::patientGet(get('id_card', ''), get('patient_no', ''))); break;
                case 'visit_list': json_ok(HisInboundRead::visitList(get('patient_no', ''))); break;
                case 'visit_status': json_ok(HisInboundRead::visitStatus(get('flow_no', ''))); break;
                case 'order_list': json_ok(HisInboundRead::orderList(get('visit_id', 0))); break;
                case 'evidence_verify': json_ok(HisInboundRead::evidenceVerify(get('record_id', 0), get('cert_no', ''))); break;
            }
            break;
        }
        if ($__act === 'sync-patient') {
            external_gate('his', array(
                'tokenKey' => 'integration.inbound.his.token', 'legacyToken' => 'his_api_key',
                'ipKey' => 'integration.inbound.his.ip_whitelist', 'requiredScope' => 'patient:sync',
            ));
            $p = external_json();
            if ($p === null && !empty($_POST)) $p = $_POST;
            if ($p === null || empty($p['id_card'])) {
                integration_log_inbound('his', 'sync-patient', false, '载荷缺 id_card', external_body());
                external_fail(400, '请提供 id_card');
            }
            try {
                $res = HisInboundSync::syncPatient($p);
                integration_log_inbound('his', 'sync-patient', true, '患者建档：' . $res['patient_no'], external_body());
                json_ok($res, '患者已同步');
            } catch (Exception $ex) {
                integration_log_inbound('his', 'sync-patient', false, $ex->getMessage(), external_body());
                external_fail(400, $ex->getMessage());
            }
            break;
        }
        if ($__act === 'sync-catalog') {
            external_gate('his', array(
                'tokenKey' => 'integration.inbound.his.token', 'legacyToken' => 'his_api_key',
                'ipKey' => 'integration.inbound.his.ip_whitelist', 'requiredScope' => 'catalog:sync',
            ));
            $c = external_json();
            if ($c === null && !empty($_POST)) $c = $_POST;
            if ($c === null) {
                integration_log_inbound('his', 'sync-catalog', false, '载荷非 JSON', external_body());
                external_fail(400, '载荷必须为 JSON');
            }
            try {
                $res = HisInboundSync::syncCatalog($c);
                integration_log_inbound('his', 'sync-catalog', true, $res['msg'], external_body());
                json_ok($res, '字典已同步');
            } catch (Exception $ex) {
                integration_log_inbound('his', 'sync-catalog', false, $ex->getMessage(), external_body());
                external_fail(400, $ex->getMessage());
            }
            break;
        }
        external_fail(404, '未知 HIS 入向操作');
        break;

    default:
        external_fail(404, '未知外部接口模块');
}
