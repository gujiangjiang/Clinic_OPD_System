<?php
/**
 * ============================================================
 * external.php — 外部集成入向统一控制器（纯路由壳）
 * ============================================================
 * 说明：承接第三方/上级系统向本系统推送的开放端点（不依赖登录会话，
 * 由 InboundGuard 按模块校验启用开关 + Token + IP 白名单）：
 *   POST /api/external/lis/callback            LIS 检验报告结果接收 Webhook
 *   POST /api/external/hl7/receiver            HL7 v2 消息接收（HTTP 代理）
 *   POST /api/external/his/sync-patient        HIS 患者预约/建档推送
 *   POST /api/external/his/sync-catalog        HIS 基础字典同步（药品/耗材/价表）
 * 业务逻辑全部委派 services 层（LisService / HL7 解析器 / HisInboundSync），
 * 本文件不再内联业务 SQL；调用一律写入 inbound_events 审计表（监控面板可溯源）。
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
    $raw = external_body();
    $json = json_decode($raw, true);
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

switch ($__mod) {

    /* ==================== LIS 检验报告结果接收 ==================== */
    case 'lis':
        if ($__act !== 'callback') json_fail('未知 LIS 操作');
        // 鉴权：webhook_secret 验签 + IP 白名单
        InboundGuard::check('lis', '', 'integration.inbound.lis.webhook_secret', 'integration.inbound.lis.ip_whitelist');
        $payload = external_json();
        if ($payload === null && !empty($_POST)) $payload = $_POST;
        if ($payload === null) {
            integration_log_inbound('lis', 'callback', false, '回调载荷非 JSON', substr(external_body(), 0, 2000));
            json_fail('回调载荷必须为 JSON');
        }
        try {
            $res = LisService::handleCallback($payload);
            integration_log_inbound('lis', 'callback', true, $res['msg'], external_body());
            json_ok(array('updated' => $res['updated'], 'report_id' => $res['report_id']), $res['msg']);
        } catch (Exception $ex) {
            integration_log_inbound('lis', 'callback', false, $ex->getMessage(), external_body());
            json_fail($ex->getMessage());
        }
        break;

    /* ==================== HL7 v2 消息接收（HTTP 代理） ==================== */
    case 'hl7':
        if ($__act !== 'receiver') json_fail('未知 HL7 操作');
        // 鉴权：IP 白名单（MLLP 守护进程与 HTTP 代理共用）
        if (!InboundGuard::ipAllowed((string)setting('integration.inbound.hl7.ip_whitelist', ''))) {
            integration_log_inbound('hl7', 'receiver', false, 'IP 白名单拒绝', '');
            json_fail('来源 IP 不在白名单内');
        }
        $raw = external_body();
        // 兼容 JSON 包裹 { "message": "..." }
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
                if ($oru['ok']) {
                    $obs = array();
                    foreach ($oru['observations'] as $o) {
                        $obs[] = array(
                            'name' => $o['item_name'] !== '' ? $o['item_name'] : $o['item_code'],
                            'value' => $o['value'],
                            'unit' => $o['unit'],
                            'ref_range' => $o['ref_range'],
                            'flag' => $o['flag'],
                        );
                    }
                    $res = LisService::applyObservationReport($oru['order_no'], $obs, '', '', '');
                    $ackText = $res['msg'];
                    integration_log_inbound('hl7', 'receiver', true, 'ORU^R01 ' . $res['msg'], $raw);
                } else {
                    throw new Exception('ORU 消息缺少 OBR 申请单号');
                }
            } elseif ($msgType !== '') {
                // ADT/其他消息：应答成功（接收确认），业务处理由后续扩展
                integration_log_inbound('hl7', 'receiver', true, '接收确认：' . $msgType, $raw);
            } else {
                $ackCode = 'AR';
                throw new Exception('无法解析 HL7 消息（缺少 MSH 段）');
            }
        } catch (Exception $ex) {
            $ackCode = 'AE';
            $ackText = $ex->getMessage();
            integration_log_inbound('hl7', 'receiver', false, $ex->getMessage(), $raw);
        }
        $ack = HL7MessageBuilder::ack($raw, $ackCode, $ackText !== '' ? $ackText : 'received');
        // HL7 规范：原始文本请求 → 回传纯文本 ACK 报文；JSON 包裹 → JSON 应答
        $isJsonReq = is_array(json_decode(external_body(), true));
        if ($isJsonReq) {
            json_ok(array('ack' => $ack, 'msa' => $ackCode), $ackCode === 'AA' ? 'HL7 消息已接收' : 'HL7 处理失败');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $ack;
        exit;
        break;

    /* ==================== HIS 患者预约/建档推送 ==================== */
    case 'his':
        InboundGuard::check('his', '', 'integration.inbound.his.token', 'integration.inbound.his.ip_whitelist', 'his_api_key');
        // 只读查询（统一入口，原 /api/his 旧版兼容端点已并入）：GET /api/external/his/read?action=ping
        if ($__act === 'read') {
            $q = trim((string)get('action', ''));
            integration_log_inbound('his', 'read', true, '只读查询：' . $q, '');
            switch ($q) {
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
            break;
        }
        if ($__act === 'sync-patient') {
            $p = external_json();
            if ($p === null && !empty($_POST)) $p = $_POST;
            if ($p === null || empty($p['id_card'])) {
                integration_log_inbound('his', 'sync-patient', false, '载荷缺 id_card', external_body());
                json_fail('请提供 id_card');
            }
            try {
                $res = HisInboundSync::syncPatient($p);
                integration_log_inbound('his', 'sync-patient', true, '患者建档：' . $res['patient_no'], external_body());
                json_ok($res, '患者已同步');
            } catch (Exception $ex) {
                integration_log_inbound('his', 'sync-patient', false, $ex->getMessage(), external_body());
                json_fail($ex->getMessage());
            }
            break;
        }
        if ($__act === 'sync-catalog') {
            $c = external_json();
            if ($c === null && !empty($_POST)) $c = $_POST;
            if ($c === null) {
                integration_log_inbound('his', 'sync-catalog', false, '载荷非 JSON', external_body());
                json_fail('载荷必须为 JSON');
            }
            try {
                $res = HisInboundSync::syncCatalog($c);
                integration_log_inbound('his', 'sync-catalog', true, $res['msg'], external_body());
                json_ok($res, '字典已同步');
            } catch (Exception $ex) {
                integration_log_inbound('his', 'sync-catalog', false, $ex->getMessage(), external_body());
                json_fail($ex->getMessage());
            }
            break;
        }
        json_fail('未知 HIS 入向操作');
        break;

    default:
        json_fail('未知外部接口模块');
}