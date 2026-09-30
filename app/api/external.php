<?php
/**
 * ============================================================
 * external.php — 外部集成入向统一控制器
 * ============================================================
 * 说明：承接第三方/上级系统向本系统推送的开放端点（不依赖登录会话，
 * 由 InboundGuard 按模块校验启用开关 + Token + IP 白名单）：
 *   POST /api/external/lis/callback            LIS 检验报告结果接收 Webhook
 *   POST /api/external/hl7/receiver            HL7 v2 消息接收（HTTP 代理）
 *   POST /api/external/his/sync-patient        HIS 患者预约/建档推送
 *   POST /api/external/his/sync-catalog        HIS 基础字典同步（药品/耗材/价表）
 * 全部调用写入 inbound_events 审计表（监控面板可溯源）。
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
        if ($__act === 'sync-patient') {
            $p = external_json();
            if ($p === null && !empty($_POST)) $p = $_POST;
            if ($p === null || empty($p['id_card'])) {
                integration_log_inbound('his', 'sync-patient', false, '载荷缺 id_card', external_body());
                json_fail('请提供 id_card');
            }
            try {
                $res = his_sync_patient($p);
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
                $res = his_sync_catalog($c);
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

/**
 * HIS 患者主数据同步（幂等建档：按身份证号更新可改字段，姓名/性别/出生日期锁定）
 * @param array $p { id_card, name, gender, birth_date, phone, address, ... }
 * @return array { patient_no, created:bool }
 * @throws Exception
 */
function his_sync_patient($p) {
    $idCard = strtoupper(trim((string)$p['id_card']));
    if ($idCard === '') throw new Exception('身份证号不能为空');
    $name = trim((string)(isset($p['name']) ? $p['name'] : ''));
    if ($name === '') throw new Exception('姓名不能为空');
    $patient = DB::one('SELECT * FROM patients WHERE id_card=?', array($idCard));
    $created = false;
    if ($patient) {
        DB::exec('UPDATE patients SET ethnicity=?, marital=?, occupation=?, work_unit=?, address=?, phone=? WHERE id_card=?',
            array(
                (string)(isset($p['ethnicity']) ? $p['ethnicity'] : $patient['ethnicity']),
                (string)(isset($p['marital']) ? $p['marital'] : $patient['marital']),
                (string)(isset($p['occupation']) ? $p['occupation'] : $patient['occupation']),
                (string)(isset($p['work_unit']) ? $p['work_unit'] : $patient['work_unit']),
                (string)(isset($p['address']) ? $p['address'] : $patient['address']),
                (string)(isset($p['phone']) ? $p['phone'] : $patient['phone']),
                $idCard,
            )
        );
        return array('patient_no' => $patient['patient_no'], 'created' => false);
    }
    $patientNo = his_next_patient_no();
    $age = 0;
    $birth = (string)(isset($p['birth_date']) ? $p['birth_date'] : '');
    if ($birth !== '') {
        $age = (int)floor((time() - strtotime($birth)) / 31536000);
    }
    DB::insert(
        'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array($patientNo, $idCard, $name,
            (string)(isset($p['gender']) ? $p['gender'] : ''), $birth, $age,
            (string)(isset($p['ethnicity']) ? $p['ethnicity'] : ''),
            (string)(isset($p['marital']) ? $p['marital'] : ''),
            (string)(isset($p['occupation']) ? $p['occupation'] : ''),
            (string)(isset($p['work_unit']) ? $p['work_unit'] : ''),
            (string)(isset($p['address']) ? $p['address'] : ''),
            (string)(isset($p['phone']) ? $p['phone'] : ''),
            now_str())
    );
    return array('patient_no' => $patientNo, 'created' => true);
}

/** HIS 推送建档的患者编号生成（年月日 + 当日序号2位，与挂号建档同源） */
function his_next_patient_no() {
    $ymd = date('ymd');
    $seq = (int)DB::val('SELECT COUNT(*) FROM patients WHERE substr(patient_no,1,6)=?', array($ymd)) + 1;
    return $ymd . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

/**
 * HIS 基础字典同步（药品/检验项目/检查项目/处置项目价表，幂等 upsert）
 * @param array $c { drugs:[], lab_items:[], exam_items:[], disposal_items:[] }
 * @return array { msg:string, counts:[] }
 * @throws Exception
 */
function his_sync_catalog($c) {
    $counts = array('drugs' => 0, 'lab_items' => 0, 'exam_items' => 0, 'disposal_items' => 0);
    // 药品：按名称+规格匹配更新价格/库存单位口径，不存在则新建（待审核）
    if (isset($c['drugs']) && is_array($c['drugs'])) {
        foreach ($c['drugs'] as $d) {
            $name = trim((string)(isset($d['name']) ? $d['name'] : ''));
            $spec = trim((string)(isset($d['spec']) ? $d['spec'] : ''));
            if ($name === '') continue;
            $exist = DB::one('SELECT * FROM drugs WHERE name=? AND spec=?', array($name, $spec));
            $price = isset($d['price']) ? (float)$d['price'] : 0;
            if ($exist) {
                DB::exec('UPDATE drugs SET price=?, vendor=?, vendor_short=? WHERE id=?',
                    array($price,
                        (string)(isset($d['vendor']) ? $d['vendor'] : $exist['vendor']),
                        (string)(isset($d['vendor_short']) ? $d['vendor_short'] : $exist['vendor_short']),
                        (int)$exist['id']));
            } else {
                DB::insert(
                    'INSERT INTO drugs(name, generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency, route, price, qty, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($name, (string)(isset($d['generic_name']) ? $d['generic_name'] : ''), (string)(isset($d['category']) ? $d['category'] : ''),
                        (string)(isset($d['vendor']) ? $d['vendor'] : ''), (string)(isset($d['vendor_short']) ? $d['vendor_short'] : ''),
                        (string)(isset($d['package_unit']) ? $d['package_unit'] : '盒'), $spec,
                        (string)(isset($d['form']) ? $d['form'] : ''), (string)(isset($d['single_dose']) ? $d['single_dose'] : ''),
                        (string)(isset($d['frequency']) ? $d['frequency'] : ''), (string)(isset($d['route']) ? $d['route'] : ''),
                        $price, 0, 'pending', now_str())
                );
            }
            $counts['drugs']++;
        }
    }
    // 检验项目：按名称匹配更新价表，不存在则新建（待审核）
    if (isset($c['lab_items']) && is_array($c['lab_items'])) {
        foreach ($c['lab_items'] as $li) {
            $name = trim((string)(isset($li['name']) ? $li['name'] : ''));
            if ($name === '') continue;
            $exist = DB::one('SELECT * FROM lab_items WHERE name=? AND category=?',
                array($name, (string)(isset($li['category']) ? $li['category'] : '')));
            if ($exist) {
                DB::exec('UPDATE lab_items SET price=?, unit=?, normal_range=? WHERE id=?',
                    array((float)(isset($li['price']) ? $li['price'] : $exist['price']),
                        (string)(isset($li['unit']) ? $li['unit'] : $exist['unit']),
                        (string)(isset($li['normal_range']) ? $li['normal_range'] : $exist['normal_range']),
                        (int)$exist['id']));
            } else {
                DB::insert(
                    'INSERT INTO lab_items(category, name, unit, price, normal_range, status, created_at) VALUES(?,?,?,?,?,?,?)',
                    array((string)(isset($li['category']) ? $li['category'] : '其他'), $name,
                        (string)(isset($li['unit']) ? $li['unit'] : ''), (float)(isset($li['price']) ? $li['price'] : 0),
                        (string)(isset($li['normal_range']) ? $li['normal_range'] : ''), 'pending', now_str())
                );
            }
            $counts['lab_items']++;
        }
    }
    // 检查项目
    if (isset($c['exam_items']) && is_array($c['exam_items'])) {
        foreach ($c['exam_items'] as $ei) {
            $name = trim((string)(isset($ei['name']) ? $ei['name'] : ''));
            if ($name === '') continue;
            $exist = DB::one('SELECT * FROM exam_items WHERE name=?', array($name));
            if ($exist) {
                DB::exec('UPDATE exam_items SET price=?, category=? WHERE id=?',
                    array((float)(isset($ei['price']) ? $ei['price'] : $exist['price']),
                        (string)(isset($ei['category']) ? $ei['category'] : $exist['category']),
                        (int)$exist['id']));
            } else {
                DB::insert(
                    'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)',
                    array((string)(isset($ei['category']) ? $ei['category'] : ''), $name,
                        (float)(isset($ei['price']) ? $ei['price'] : 0),
                        (string)(isset($ei['description']) ? $ei['description'] : ''), 'pending', now_str())
                );
            }
            $counts['exam_items']++;
        }
    }
    // 处置项目
    if (isset($c['disposal_items']) && is_array($c['disposal_items'])) {
        foreach ($c['disposal_items'] as $di) {
            $name = trim((string)(isset($di['name']) ? $di['name'] : ''));
            if ($name === '') continue;
            $exist = DB::one('SELECT * FROM disposal_items WHERE name=?', array($name));
            if ($exist) {
                DB::exec('UPDATE disposal_items SET fee=?, description=? WHERE id=?',
                    array((float)(isset($di['fee']) ? $di['fee'] : $exist['fee']),
                        (string)(isset($di['description']) ? $di['description'] : $exist['description']),
                        (int)$exist['id']));
            } else {
                DB::insert(
                    'INSERT INTO disposal_items(name, fee, description, status, created_at) VALUES(?,?,?,?,?)',
                    array($name, (float)(isset($di['fee']) ? $di['fee'] : 0),
                        (string)(isset($di['description']) ? $di['description'] : ''), 'pending', now_str())
                );
            }
            $counts['disposal_items']++;
        }
    }
    $total = array_sum($counts);
    if ($total === 0) throw new Exception('载荷未包含可同步的字典数据');
    return array('msg' => '已同步 ' . $total . ' 条字典（药品 ' . $counts['drugs'] . ' / 检验 ' . $counts['lab_items'] . ' / 检查 ' . $counts['exam_items'] . ' / 处置 ' . $counts['disposal_items'] . '）', 'counts' => $counts);
}