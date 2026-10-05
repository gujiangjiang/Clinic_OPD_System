<?php
/**
 * ============================================================
 * dicomweb.php — DICOMweb 入向服务（QIDO-RS / WADO-RS 元数据）
 * ============================================================
 * 说明：本系统作为轻量 DICOMweb 服务，对外提供基于 HTTP 的 DICOM 检索：
 *   GET /api/dicomweb/studies                          QIDO-RS 检查检索
 *       ?PatientID=&StudyInstanceUID=&Modality=&StudyDate=[&limit=&offset=]
 *   GET /api/dicomweb/studies/{studyUID}               单个检查
 *   GET /api/dicomweb/studies/{studyUID}/series        QIDO-RS 序列检索
 *   GET /api/dicomweb/studies/{studyUID}/metadata      WADO-RS 实例元数据（DICOM JSON）
 * 数据源：imaging_refs（影像引用，三单匹配后自动登记，只存引用与元数据）。
 * 鉴权：integration.inbound.pacs.enabled + token（X-API-Key / Bearer）
 *       + ip_whitelist + scope(pacs:read)，由 InboundGuard 统一校验。
 * 响应：Content-Type: application/dicom+json。
 * ============================================================ */

require_once APP_ROOT . '/app/config/bootstrap.php';

/** CORS 头 */
function dw_cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');
}

/** DICOM JSON 响应输出（PS3.18：application/dicom+json） */
function dw_json($data, $status = 200) {
    http_response_code($status);
    dw_cors();
    header('Content-Type: application/dicom+json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 错误响应：非 DICOM JSON 结构，用 text/plain 承载，避免污染 dicom+json 解码 */
function dw_error($status, $msg) {
    http_response_code($status);
    dw_cors();
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/**
 * DICOM 标签组装（VR + Value）。
 * 注意：PN 等结构化单值为关联数组时须整体作为 Value 元素（不可 array_values），
 * 否则会破坏 DICOM JSON 模型（PS3.18 Annex F）。
 */
function dw_tag($vr, $value) {
    if (is_array($value)) {
        if (!$value) return array('vr' => $vr, 'Value' => array());
        $isList = array_keys($value) === range(0, count($value) - 1);
        return array('vr' => $vr, 'Value' => $isList ? array_values($value) : array($value));
    }
    return array('vr' => $vr, 'Value' => array($value));
}

/**
 * 归一化为合法 DICOM UID（VR=UI）：仅数字与点，长度 ≤64。
 * 报告号等非 UID 占位符 → 由占位文本确定性派生一个合法 OID（PACS 网关接入前可稳定关联）。
 */
function dw_uid($s) {
    $s = trim((string)$s);
    if ($s !== '' && strlen($s) <= 64 && preg_match('/^[0-9]+(\.[0-9]+)*$/', $s)) return $s;
    $a = sprintf('%u', crc32('study|' . $s));
    $b = sprintf('%u', crc32('series|' . $s));
    return '1.2.826.0.1.3680043.8.498.' . $a . '.' . $b;
}

/** 归一化为合法 DICOM 模态码（VR=CS，表 0008,0060）：统一走 imaging_modality_code，未识别回退 OT */
function dw_modality($s) {
    $code = imaging_modality_code($s);
    if ($code !== '') return $code;
    $u = strtoupper(trim((string)$s));
    return preg_match('/^[A-Z0-9_ ]+$/', $u) ? $u : 'OT';
}

/** 模态 → SOP Class UID（缺省回退 CT/Secondary Capture） */
function dw_sop_class($modality) {
    $map = array(
        'CT' => '1.2.840.10008.5.1.4.1.1.2',
        'MR' => '1.2.840.10008.5.1.4.1.1.4',
        'US' => '1.2.840.10008.5.1.4.1.1.6.1',
        'CR' => '1.2.840.10008.5.1.4.1.1.1',
        'DX' => '1.2.840.10008.5.1.4.1.1.1.1',
        'MG' => '1.2.840.10008.5.1.4.1.1.1.2',
        'NM' => '1.2.840.10008.5.1.4.1.1.20',
        'PT' => '1.2.840.10008.5.1.4.1.1.128',
    );
    $m = strtoupper(trim((string)$modality));
    return isset($map[$m]) ? $map[$m] : '1.2.840.10008.5.1.4.1.1.7';   // Secondary Capture
}

/**
 * 检查号（0008,0050 AccessionNumber）：申请单号 orders.order_no（如 JC…），
 * 回退就诊号。注意不是报告号（BG…），也不是就诊号（flow_no）。
 */
function dw_accession($ref) {
    if (!empty($ref['order_id'])) {
        $o = PatientRepository::one('SELECT order_no FROM orders WHERE id=?', array((int)$ref['order_id']));
        if ($o && !empty($o['order_no'])) return (string)$o['order_no'];
    }
    return (string)$ref['flow_no'];
}

/** 检查项目（StudyDescription 0008,1030）：开单明细项目名，回退 meta.item_name */
function dw_study_desc($ref) {
    if (!empty($ref['order_item_id'])) {
        $oi = PatientRepository::one('SELECT item_name FROM order_items WHERE id=?', array((int)$ref['order_item_id']));
        if ($oi && !empty($oi['item_name'])) return (string)$oi['item_name'];
    }
    $meta = json_decode((string)$ref['meta_json'], true);
    if (is_array($meta) && !empty($meta['item_name'])) return (string)$meta['item_name'];
    return '';
}

/** 检查时间（StudyDate/Time）：开单明细 登记/执行，回退申请单开单时间 */
function dw_exam_dt($ref) {
    $dt = '';
    if (!empty($ref['order_item_id'])) {
        $oi = PatientRepository::one('SELECT registered_at, executed_at FROM order_items WHERE id=?', array((int)$ref['order_item_id']));
        if ($oi) {
            if (!empty($oi['registered_at'])) $dt = (string)$oi['registered_at'];
            elseif (!empty($oi['executed_at'])) $dt = (string)$oi['executed_at'];
        }
    }
    if ($dt === '' && !empty($ref['order_id'])) {
        $o = PatientRepository::one('SELECT created_at FROM orders WHERE id=?', array((int)$ref['order_id']));
        if ($o && !empty($o['created_at'])) $dt = (string)$o['created_at'];
    }
    if ($dt === '') $dt = (string)$ref['created_at'];
    return $dt;
}

/** 机构名称（InstitutionName 0008,0080）：系统设置 hospital_name */
function dw_institution() {
    $h = trim((string)setting('hospital_name', ''));
    return $h !== '' ? $h : '';
}

/** 患者年龄 → DICOM AS（如 062Y）；无法计算返回空 */
function dw_age($birth) {
    $d = preg_replace('/\D/', '', (string)$birth);
    if (strlen($d) < 8) return '';
    $ts = strtotime(substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2));
    if (!$ts) return '';
    $y = (int)floor((time() - $ts) / (365.25 * 86400));
    return ($y > 0 && $y < 130) ? str_pad((string)$y, 3, '0', STR_PAD_LEFT) . 'Y' : '';
}

/** 取检查日期/时间（DICOM DA/TM） */
function dw_date($s) {
    $ts = strtotime((string)$s);
    return $ts ? date('Ymd', $ts) : '';
}
function dw_time($s) {
    $ts = strtotime((string)$s);
    return $ts ? date('His', $ts) : '';
}

/* ---------- 路由解析 ---------- */
$__sub = defined('CURRENT_API_SUB') ? trim((string)CURRENT_API_SUB, '/') : '';
$__parts = $__sub !== '' ? explode('/', $__sub) : array();
$__seg0 = strtolower(isset($__parts[0]) ? $__parts[0] : '');
$__segs = array_map('rawurldecode', $__parts);

/* ---------- CORS 预检（免鉴权） ---------- */
if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'OPTIONS') {
    dw_cors();
    http_response_code(204);
    exit;
}

/* ---------- 方法约束：本服务仅提供 QIDO-RS / WADO-RS(metadata) 读取 ---------- */
$__method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($__method !== 'GET') {
    header('Allow: GET, OPTIONS');
    dw_error(405, '本 DICOMweb 入向仅支持 GET（QIDO-RS / WADO-RS metadata）');
}

/* ---------- 鉴权（统一网关） ---------- */
$__auth = InboundGuard::authorize('dicomweb', array(
    'enabledKey' => 'integration.inbound.pacs.enabled',
    'ipKey' => 'integration.inbound.pacs.ip_whitelist',
    'tokenKey' => 'integration.inbound.pacs.token',
    'requiredScope' => 'pacs:read',
));
if (!$__auth['ok']) {
    integration_log_inbound('dicomweb', $__seg0, false, $__auth['msg'], '');
    if ($__auth['http'] === 401) header('WWW-Authenticate: Bearer realm="DICOMweb"');
    dw_error($__auth['http'], $__auth['msg']);
}

if ($__seg0 !== '' && $__seg0 !== 'studies') {
    dw_error(404, '不支持的 DICOMweb 路径：' . $__sub);
}

/* ---------- Accept 协商：JSON 端点仅输出 DICOM JSON；字节流端点（取像 / 渲染图）跳过 ----------
 * 实例取像返回 application/dicom、渲染图返回 image/png，标准 WADO-RS 客户端会发送
 * Accept: application/dicom 等，不应按 JSON 约束拒绝（否则代理取像链路 406）。 */
$__accept = isset($_SERVER['HTTP_ACCEPT']) ? trim((string)$_SERVER['HTTP_ACCEPT']) : '';
$__isBytes = (count($__segs) === 6 && strtolower($__segs[2]) === 'series' && $__segs[4] === 'instances')
    || (count($__segs) === 7 && strtolower($__segs[2]) === 'series' && $__segs[4] === 'instances' && $__segs[6] === 'rendered');
if (!$__isBytes && $__accept !== '') {
    $__okType = false;
    foreach (explode(',', $__accept) as $__part) {
        $__t = strtolower(trim(explode(';', $__part)[0]));
        if ($__t === '*/*' || $__t === 'application/*' || $__t === 'application/dicom+json' || $__t === 'application/json') {
            $__okType = true; break;
        }
    }
    if (!$__okType) dw_error(406, '本服务仅支持 Accept: application/dicom+json');
}

/** 单条 imaging_ref → DICOM JSON 检查对象 */
function dw_study_obj($ref, $patient) {
    $studyUid = (string)$ref['study_uid'];
    $series = json_decode((string)$ref['meta_json'], true);
    $seriesCnt = 0;
    if (is_array($series) && !empty($series['series']) && is_array($series['series'])) $seriesCnt = count($series['series']);
    else {
        $uids = json_decode((string)$ref['series_uids'], true);
        $seriesCnt = is_array($uids) ? count($uids) : 0;
    }
    $pname = $patient ? (string)$patient['name'] : '';
    $pbirth = ($patient && !empty($patient['birth_date'])) ? dw_date($patient['birth_date']) : '';
    $psex = ($patient && isset($patient['gender'])) ? (($patient['gender'] === '男') ? 'M' : (($patient['gender'] === '女') ? 'F' : 'O')) : '';
    $page = dw_age($patient && isset($patient['birth_date']) ? $patient['birth_date'] : '');
    $mod = dw_modality((string)$ref['modality']);
    // 本地无实例数（仅存引用）时回源区域 PACS：据检查号解析真实影像数量，
    // 使客户端正确识别「有影像」，并在 WADO 阶段按真实 UID 回源取像。
    $instCnt = (int)$ref['instance_count'];
    $reg = null;
    if ($instCnt <= 0) {
        $reg = dw_region_study($ref);
        if ($reg) { $instCnt = max(1, (int)$reg['instances']); $seriesCnt = max($seriesCnt, (int)$reg['series']); }
    }
    // 设备名 / 机构名：登记解析时由区域 PACS QIDO 写入 meta（StationName/InstitutionName）；
    // 本地引用缺失（如历史数据 / 数据播种）时，回退到区域 PACS 索引，避免链路中丢失设备名。
    $refMeta = json_decode((string)$ref['meta_json'], true);
    if (!is_array($refMeta)) $refMeta = array();
    $station = isset($refMeta['station_name']) ? (string)$refMeta['station_name'] : '';
    $instName = !empty($refMeta['region_name']) ? (string)$refMeta['region_name'] : dw_institution();
    if ($station === '' && $reg === null) $reg = dw_region_study($ref);
    if ($reg) {
        if ($station === '' && !empty($reg['station'])) $station = (string)$reg['station'];
        if (empty($refMeta['region_name']) && !empty($reg['institution'])) $instName = (string)$reg['institution'];
    }
    $obj = array(
        '00080020' => dw_tag('DA', dw_date(dw_exam_dt($ref))),
        '00080030' => dw_tag('TM', dw_time(dw_exam_dt($ref))),
        '00080050' => dw_tag('SH', dw_accession($ref)),                 // 检查号=申请单号
        '00080060' => dw_tag('CS', $mod),                               // Modality
        '00080061' => dw_tag('CS', $mod),                               // ModalitiesInStudy
        '00080080' => dw_tag('LO', $instName),                          // InstitutionName 机构名
        '00081010' => dw_tag('SH', $station),                           // StationName 设备名
        '00081030' => dw_tag('LO', dw_study_desc($ref)),                // StudyDescription 检查项目
        '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
        '00100020' => dw_tag('LO', (string)$ref['patient_no']),         // 患者号
        '00100030' => dw_tag('DA', $pbirth),
        '00100040' => dw_tag('CS', $psex),
        '00101000' => dw_tag('LO', (string)$ref['flow_no']),            // 门诊号（本服务约定）
        '00101010' => dw_tag('AS', $page),                              // 年龄
        '0020000D' => dw_tag('UI', dw_uid($studyUid)),                  // 合法 DICOM UID
        '00201208' => dw_tag('IS', (string)$instCnt),                   // 检查相关实例数（本地无则回源区域 PACS）
        '00201206' => dw_tag('IS', (string)$seriesCnt),                 // NumberOfStudyRelatedSeries（原误用 00201209）
    );
    if ($pname === '') unset($obj['00100010']);   // 无姓名时不输出空 PN
    return $obj;
}

/** series 列表（优先 meta_json.series，回退 series_uids 字符串） */
function dw_series_list($ref) {
    $meta = json_decode((string)$ref['meta_json'], true);
    if (is_array($meta) && !empty($meta['series']) && is_array($meta['series'])) {
        $out = array();
        foreach ($meta['series'] as $s) {
            if (!is_array($s)) continue;
            // 完整透传区域 PACS 的序列元数据（含像素 / 窗宽窗位 / 几何参数），
            // 供实例列表与 metadata 端点输出，避免客户端缺失这些字段。
            $s['uid'] = isset($s['uid']) ? (string)$s['uid'] : '';
            $s['modality'] = dw_modality(isset($s['modality']) && $s['modality'] !== '' ? $s['modality'] : (string)$ref['modality']);
            $s['description'] = isset($s['description']) ? (string)$s['description'] : '';
            $s['instances'] = isset($s['instances']) ? (int)$s['instances'] : 0;
            $out[] = $s;
        }
        if ($out) return $out;
    }
    $uids = json_decode((string)$ref['series_uids'], true);
    if (!is_array($uids)) $uids = array();
    $out = array();
    foreach ($uids as $u) {
        $out[] = array(
            'uid' => is_array($u) ? (isset($u['uid']) ? (string)$u['uid'] : '') : (string)$u,
            'modality' => dw_modality((string)$ref['modality']),
            'description' => '',
            'instances' => 0,
        );
    }
    return $out;
}

/* ---------- GET /studies（QIDO-RS 检查检索）；根地址 /api/dicomweb 亦视为检索 ---------- */
if (count($__segs) <= 1) {
    $where = array("study_uid<>''");
    $args = array();
    $pid = isset($_GET['PatientID']) ? trim((string)$_GET['PatientID']) : '';
    $pname = isset($_GET['PatientName']) ? trim((string)$_GET['PatientName']) : '';
    $psex = strtoupper(trim((string)(isset($_GET['PatientSex']) ? $_GET['PatientSex'] : '')));
    $suid = isset($_GET['StudyInstanceUID']) ? trim((string)$_GET['StudyInstanceUID']) : '';
    $acc = isset($_GET['AccessionNumber']) ? trim((string)$_GET['AccessionNumber']) : '';
    $mod = '';
    foreach (array('ModalitiesInStudy', 'Modality') as $mk) {   // 标准键优先 ModalitiesInStudy
        if (!empty($_GET[$mk])) { $mod = trim((string)$_GET[$mk]); break; }
    }
    $sdate = isset($_GET['StudyDate']) ? trim((string)$_GET['StudyDate']) : '';

    if ($pid !== '') {   // PatientID 支持 QIDO 通配：* → %，? → _
        if (strpos($pid, '*') !== false || strpos($pid, '?') !== false) {
            $where[] = 'patient_no LIKE ?';
            $args[] = str_replace(array('*', '?'), array('%', '_'), $pid);
        } else {
            $where[] = 'patient_no=?';
            $args[] = $pid;
        }
    }
    if ($acc !== '') {   // 与返回的 0008,0050 一致：按申请单号（orders.order_no）匹配
        $where[] = 'order_id IN (SELECT id FROM orders WHERE order_no=?)';
        $args[] = $acc;
    }
    if ($mod !== '') {
        $mods = array_values(array_filter(array_map('dw_modality', explode(',', $mod)), 'strlen'));
        if ($mods) {
            $where[] = 'modality IN (' . implode(',', array_fill(0, count($mods), '?')) . ')';
            $args = array_merge($args, $mods);
        }
    }
    if ($pname !== '') {   // QIDO 通配：* → %，? → _
        $like = str_replace(array('*', '?'), array('%', '_'), $pname);
        $where[] = 'patient_no IN (SELECT patient_no FROM patients WHERE name LIKE ?)';
        $args[] = $like;
    }
    if ($psex !== '') {   // 性别筛选：M/F 精确匹配；O 表示非男非女
        if ($psex === 'M') {
            $where[] = 'patient_no IN (SELECT patient_no FROM patients WHERE gender=?)';
            $args[] = '男';
        } elseif ($psex === 'F') {
            $where[] = 'patient_no IN (SELECT patient_no FROM patients WHERE gender=?)';
            $args[] = '女';
        } elseif ($psex === 'O') {
            $where[] = 'patient_no IN (SELECT patient_no FROM patients WHERE gender NOT IN (?,?))';
            $args[] = '男'; $args[] = '女';
        }
    }
    if ($sdate !== '') {
        if (preg_match('/^(\d{8})-(\d{8})$/', $sdate, $m)) {       // 日期范围
            $where[] = 'date(created_at) BETWEEN ? AND ?';
            $args[] = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
            $args[] = substr($m[2], 0, 4) . '-' . substr($m[2], 4, 2) . '-' . substr($m[2], 6, 2);
        } elseif (preg_match('/^\d{8}$/', $sdate)) {
            $where[] = 'date(created_at)=?';
            $args[] = substr($sdate, 0, 4) . '-' . substr($sdate, 4, 2) . '-' . substr($sdate, 6, 2);
        }
    }
    $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $rows = PatientRepository::q('SELECT * FROM imaging_refs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC', $args);
    if ($suid !== '') {   // StudyInstanceUID 支持原始占位符或归一化后的合法 UID
        $rows = array_values(array_filter($rows, function ($r) use ($suid) {
            return (string)$r['study_uid'] === $suid || dw_uid((string)$r['study_uid']) === $suid;
        }));
    }
    $rows = array_slice($rows, $offset, $limit);
    $out = array();
    foreach ($rows as $r) {
        $p = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array((string)$r['patient_no']));
        $out[] = dw_study_obj($r, $p);
    }
    integration_log_inbound('dicomweb', 'qido/studies', true, '返回 ' . count($out) . ' 条检查', '');
    dw_json($out);
}

$studyUid = isset($__segs[1]) ? $__segs[1] : '';
if ($studyUid === '') dw_error(400, '缺少 StudyInstanceUID');
$ref = PatientRepository::one('SELECT * FROM imaging_refs WHERE study_uid=?', array($studyUid));
if (!$ref) {   // 回退：按归一化 UID 匹配
    foreach (PatientRepository::q("SELECT * FROM imaging_refs WHERE study_uid<>''", array()) as $r) {
        if (dw_uid((string)$r['study_uid']) === $studyUid) { $ref = $r; break; }
    }
}
if (!$ref) dw_error(404, '未找到检查：' . $studyUid);
$patient = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array((string)$ref['patient_no']));

// GET /studies/{uid}
if (count($__segs) === 2) {
    dw_json(array(dw_study_obj($ref, $patient)));
}

$sub = strtolower(isset($__segs[2]) ? $__segs[2] : '');
$seriesList = dw_series_list($ref);
$normStudy = dw_uid((string)$ref['study_uid']);   // 归一化 StudyInstanceUID

/** 序列输出对象（QIDO series / WADO metadata 共用） */
function dw_series_obj($s, $normStudy, $n) {
    $seUid = $s['uid'] !== '' ? dw_uid((string)$s['uid']) : ($normStudy . '.' . $n);
    return array(
        '00080060' => dw_tag('CS', $s['modality']),
        '0008103E' => dw_tag('LO', $s['description']),
        '0020000D' => dw_tag('UI', $normStudy),
        '0020000E' => dw_tag('UI', $seUid),
        '00200011' => dw_tag('IS', (string)$n),
        '00201209' => dw_tag('IS', (string)(int)$s['instances']),
    );
}

/** 单实例 DICOM JSON（metadata 与 instances 共用） */
function dw_instance_obj($ref, $s, $n, $seUid, $i, $normStudy, $pname, $psex, $pbirth, $page, $studyDesc) {
    $item = array(
        // SOP Class UID：优先区域 PACS 透传，缺省按模态推导
        '00080016' => dw_tag('UI', !empty($s['sop_class_uid']) ? (string)$s['sop_class_uid'] : dw_sop_class($s['modality'])),
        '00080018' => dw_tag('UI', $seUid . '.' . $i),              // SOP Instance UID
        '00080020' => dw_tag('DA', dw_date(dw_exam_dt($ref))),
        '00080030' => dw_tag('TM', dw_time(dw_exam_dt($ref))),
        '00080050' => dw_tag('SH', dw_accession($ref)),
        '00080060' => dw_tag('CS', $s['modality']),
        '00080080' => dw_tag('LO', dw_institution()),
        '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
        '00100020' => dw_tag('LO', (string)$ref['patient_no']),
        '00100030' => dw_tag('DA', $pbirth),
        '00100040' => dw_tag('CS', $psex),
        '00101000' => dw_tag('LO', (string)$ref['flow_no']),
        '00101010' => dw_tag('AS', $page),
        '0020000D' => dw_tag('UI', $normStudy),
        '0020000E' => dw_tag('UI', $seUid),
        '00200011' => dw_tag('IS', (string)$n),
        '00200013' => dw_tag('IS', (string)$i),
    );
    if ($pname === '') unset($item['00100010']);
    if ($studyDesc !== '') $item['00081030'] = dw_tag('LO', $studyDesc);
    if ($s['description'] !== '') $item['0008103E'] = dw_tag('LO', $s['description']);

    // ---- 像素 / 几何元数据透传（区域 PACS 登记时采集，客户端据此渲染 / 测量，无需再推断）----
    $has = function ($k) use ($s) { return isset($s[$k]) && $s[$k] !== '' && $s[$k] !== 0 && $s[$k] !== '0'; };
    if ($has('frames_per_instance')) $item['00280008'] = dw_tag('IS', (string)max(1, (int)$s['frames_per_instance']));
    if ($has('rows')) $item['00280010'] = dw_tag('US', (string)(int)$s['rows']);
    if ($has('columns')) $item['00280011'] = dw_tag('US', (string)(int)$s['columns']);
    if ($has('bits_allocated')) $item['00280100'] = dw_tag('US', (string)(int)$s['bits_allocated']);
    if ($has('bits_stored')) $item['00280101'] = dw_tag('US', (string)(int)$s['bits_stored']);
    if (isset($s['pixel_representation'])) $item['00280103'] = dw_tag('US', (string)(int)$s['pixel_representation']);
    if ($has('window_center')) $item['00281050'] = dw_tag('DS', (string)$s['window_center']);
    if ($has('window_width')) $item['00281051'] = dw_tag('DS', (string)$s['window_width']);
    if (isset($s['rescale_intercept'])) $item['00281052'] = dw_tag('DS', (string)$s['rescale_intercept']);
    if (isset($s['rescale_slope'])) $item['00281053'] = dw_tag('DS', (string)$s['rescale_slope']);
    if ($has('pixel_spacing')) $item['00280030'] = dw_tag('DS', (string)$s['pixel_spacing']);
    if ($has('slice_thickness')) $item['00180050'] = dw_tag('DS', (string)$s['slice_thickness']);
    if ($has('orientation')) $item['00200037'] = dw_tag('DS', (string)$s['orientation']);
    return $item;
}

/** 出向区域 PACS 基地址（去掉 {study_uid} 模板与结尾 /studies） */
function dw_outbound_base() {
    $base = trim((string)setting('integration.outbound.pacs.qido_url', ''));
    if ($base === '') $base = trim((string)setting('integration.outbound.pacs.wado_url', ''));
    if ($base === '') return '';
    $base = preg_replace('#/\{?(study_uid|studyUID)\}.*$#i', '', $base);   // 去掉 {study_uid} 模板
    return rtrim(preg_replace('#/studies/?$#i', '', rtrim($base, '/')), '/');
}

/** 出向区域 PACS 鉴权请求头 */
function dw_outbound_headers() {
    $headers = array();
    $scheme = strtolower(trim((string)setting('integration.outbound.pacs.auth_scheme', 'none')));
    $val = trim((string)setting('integration.outbound.pacs.auth_value', ''));
    if ($val !== '') {
        if ($scheme === 'bearer') $headers[] = 'Authorization: Bearer ' . $val;
        elseif ($scheme === 'x-api-key') $headers[] = 'X-API-Key: ' . $val;
        elseif ($scheme === 'basic') $headers[] = 'Authorization: Basic ' . base64_encode($val);
        elseif ($scheme === 'custom') $headers[] = $val;
    }
    return $headers;
}

/** 出向区域 PACS GET（失败返回 null）；$log=true 时落账接口日志·出向（目标=对方系统地址） */
function dw_outbound_get($path, $log = true) {
    $base = dw_outbound_base();
    if ($base === '') return null;
    try {
        $resp = HttpClient::request('GET', $base . $path, array('timeout' => 15, 'headers' => dw_outbound_headers()));
    } catch (Exception $e) {
        if ($log) dw_log_outbound($path, null, $base);
        return null;
    }
    if ($log) dw_log_outbound($path, $resp, $base);
    return $resp;
}

/** 出向区域 PACS 调用落账（日志中心·接口日志 DICOM/PACS · 出向；目标=对方系统地址）
 *  为保持日志简洁，动作名只取“操作类别”（不内嵌超长 UID）；完整路径放入可折叠报文。 */
function dw_log_outbound($path, $resp, $base) {
    if (!function_exists('log_interface')) return;
    $ok = ($resp !== null) && (int)$resp['status'] >= 200 && (int)$resp['status'] < 300;
    $status = ($resp === null) ? '连接失败' : ('HTTP ' . (int)$resp['status']);
    $q = strpos((string)$path, '?') !== false;
    $p = strtok((string)$path, '?');
    // 归为检索（QIDO，带 ? 查询）或取像（WADO）
    $action = ($q && $p === '/studies') ? 'qido/studies' : 'wado/retrieve';
    $label  = ($q && $p === '/studies') ? '检查检索' : '影像取像';
    log_interface('dicom', 'outbound', $action, $ok,
        '区域 PACS ' . $label . '（' . $status . '）', (string)$path, '', $base);
}

/** 取像落账去重：同一 Study 60 秒内至多落一条（一次阅片会产生大量逐序列/逐实例取像，避免刷屏） */
function dw_log_study_once($subpath) {
    if (!class_exists('Cache')) return true;
    if (!preg_match('#/studies/([^/]+)#', (string)$subpath, $m)) return true;   // 无具体 Study（如索引检索）不按 Study 去重
    $key = 'call:dwlog_' . md5($m[1]);
    if (Cache::get($key, null)) return false;
    Cache::set($key, 1, 60);
    return true;
}

/**
 * 区域 PACS 检查索引：一次 QIDO 拉取全部检查，按「检查号(申请单号)」建索引。
 * 本系统仅存引用（本地无真实 UID / 序列元数据）时，据此回源区域 PACS 解析真实 UID 与影像数量。
 */
function dw_region_index() {
    static $idx = null;
    if ($idx !== null) return $idx;
    $ck = 'call:region_index_' . md5(dw_outbound_base());
    if (class_exists('Cache')) {
        $c = Cache::get($ck, null);
        if (is_array($c)) { $idx = $c; return $idx; }
    }
    $idx = array();
    $resp = dw_outbound_get('/studies?' . http_build_query(array('includefield' => 'all', 'limit' => 2000)));
    if ($resp && (int)$resp['status'] >= 200 && (int)$resp['status'] < 300) {
        $arr = json_decode((string)$resp['body'], true);
        if (is_array($arr)) {
            foreach ($arr as $s) {
                if (!is_array($s)) continue;
                $acc = isset($s['00080050']['Value'][0]) ? (string)$s['00080050']['Value'][0] : '';
                $uid = isset($s['0020000D']['Value'][0]) ? (string)$s['0020000D']['Value'][0] : '';
                if ($acc === '' || $uid === '') continue;
                $se = 0;
                foreach (array('00201206', '00201209') as $t) {
                    if (!empty($s[$t]['Value'][0])) { $se = (int)$s[$t]['Value'][0]; break; }
                }
                $inst = !empty($s['00201208']['Value'][0]) ? (int)$s['00201208']['Value'][0] : ($se > 0 ? 1 : 0);
                // 设备名 / 机构名：区域 PACS QIDO 的 StationName / InstitutionName（供本地引用缺失时回退展示）
                $station = !empty($s['00081010']['Value'][0]) ? (string)$s['00081010']['Value'][0] : '';
                $instName = !empty($s['00080080']['Value'][0]) ? (string)$s['00080080']['Value'][0] : '';
                $idx[$acc] = array('uid' => $uid, 'series' => $se, 'instances' => $inst,
                    'station' => $station, 'institution' => $instName);
            }
        }
    }
    if (class_exists('Cache') && $idx) Cache::set($ck, $idx, 120);   // 短时缓存，避免每次检索都回源区域 PACS
    return $idx;
}

/** 按检查号在区域 PACS 索引中解析该检查（返回 uid/series/instances 或 null） */
function dw_region_study($ref) {
    if (!$ref) return null;
    $acc = dw_accession($ref);
    if ($acc === '') return null;
    $idx = dw_region_index();
    return isset($idx[$acc]) ? $idx[$acc] : null;
}

/** WADO-RS 取像代理：本系统仅存引用，实例字节流/渲染图经出向区域 PACS 取回 */
function dw_proxy_pacs($subpath) {
    $base = dw_outbound_base();
    if ($base === '') dw_error(404, '影像本体由区域 PACS 承载，未配置出向 PACS 地址（外部接口 → DICOM/PACS 出向）');
    $url = $base . $subpath;
    // 一次阅片会逐序列/逐实例取像（量极大）：按 Study 维度 60 秒去重落账（每个检查每分钟至多一条）
    $log = dw_log_study_once($subpath);
    $resp = dw_outbound_get($subpath, $log);
    if ($resp === null) dw_error(502, '代理取像失败：无法连接区域 PACS');
    if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) {
        dw_error(502, '区域 PACS 取像失败（HTTP ' . (int)$resp['status'] . '）');
    }
    $ct = 'application/dicom';
    if (preg_match('/content-type:\s*([^\r\n]+)/i', (string)$resp['headers'], $m)) $ct = trim($m[1]);
    if (!headers_sent()) { header('Content-Type: ' . $ct); header('Content-Length: ' . strlen((string)$resp['body'])); }
    echo (string)$resp['body'];
    exit;
}

// GET /studies/{uid}/series（QIDO-RS 序列检索）
if ($sub === 'series' && count($__segs) === 3) {
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) { $n++; $out[] = dw_series_obj($s, $normStudy, $n); }
    if (!$out) {   // 本地无序列元数据：回源区域 PACS（按检查号解析真实 UID）
        $reg = dw_region_study($ref);
        if ($reg) dw_proxy_pacs('/studies/' . rawurlencode($reg['uid']) . '/series');
    }
    dw_json($out);
}

// GET /studies/{uid}/series/{seriesUID}（QIDO-RS 单序列）
if ($sub === 'series' && count($__segs) === 4) {
    $seriesUid = $__segs[3];
    $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $seUid = $s['uid'] !== '' ? dw_uid((string)$s['uid']) : ($normStudy . '.' . $n);
        if ($seUid === $seriesUid || (string)$s['uid'] === $seriesUid) {
            dw_json(array(dw_series_obj($s, $normStudy, $n)));
        }
    }
    dw_error(404, '未找到序列：' . $seriesUid);
}

/** 读取患者展示字段（姓名/性别/出生/年龄/检查项目） */
function dw_patient_ctx($ref) {
    $prow = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array((string)$ref['patient_no']));
    $pname = $prow ? (string)$prow['name'] : '';
    $psex = ($prow && isset($prow['gender'])) ? (($prow['gender'] === '男') ? 'M' : (($prow['gender'] === '女') ? 'F' : 'O')) : '';
    $pbirth = ($prow && !empty($prow['birth_date'])) ? dw_date($prow['birth_date']) : '';
    $page = ($prow && !empty($prow['birth_date'])) ? dw_age($prow['birth_date']) : '';
    return array($pname, $psex, $pbirth, $page, dw_study_desc($ref));
}

// GET /studies/{uid}/series/{seriesUID}/instances（WADO-RS 实例列表）
if ($sub === 'series' && count($__segs) === 5 && $__segs[4] === 'instances') {
    $seriesUid = $__segs[3];
    list($pname, $psex, $pbirth, $page, $studyDesc) = dw_patient_ctx($ref);
    $out = array(); $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $seUid = $s['uid'] !== '' ? dw_uid((string)$s['uid']) : ($normStudy . '.' . $n);
        if ($seUid !== $seriesUid && (string)$s['uid'] !== $seriesUid) continue;
        $count = max(0, (int)$s['instances']);
        for ($i = 1; $i <= $count; $i++) {
            $out[] = dw_instance_obj($ref, $s, $n, $seUid, $i, $normStudy, $pname, $psex, $pbirth, $page, $studyDesc);
        }
        integration_log_inbound('dicomweb', 'wado/instances', true, '返回 ' . count($out) . ' 个实例', '');
        dw_json($out);
    }
    $reg = dw_region_study($ref);   // 本地无该序列：回源区域 PACS
    if ($reg) dw_proxy_pacs('/studies/' . rawurlencode($reg['uid']) . '/series/' . rawurlencode($seriesUid) . '/instances');
    dw_error(404, '未找到序列：' . $seriesUid);
}

// GET /studies/{uid}/series/{seriesUID}/instances/{sopUID}（WADO-RS 实例字节流，经区域 PACS 代理）
if ($sub === 'series' && count($__segs) === 6 && $__segs[4] === 'instances') {
    $fwStudy = $__segs[1];
    if (!$seriesList) { $reg = dw_region_study($ref); if ($reg) $fwStudy = $reg['uid']; }   // 仅有引用：按真实 UID 回源
    dw_proxy_pacs('/studies/' . rawurlencode($fwStudy) . '/series/' . rawurlencode($__segs[3]) . '/instances/' . rawurlencode($__segs[5]));
}

// GET /studies/{uid}/series/{seriesUID}/instances/{sopUID}/rendered（渲染图，经区域 PACS 代理）
if ($sub === 'series' && count($__segs) === 7 && $__segs[4] === 'instances' && $__segs[6] === 'rendered') {
    $fwStudy = $__segs[1];
    if (!$seriesList) { $reg = dw_region_study($ref); if ($reg) $fwStudy = $reg['uid']; }
    dw_proxy_pacs('/studies/' . rawurlencode($fwStudy) . '/series/' . rawurlencode($__segs[3]) . '/instances/' . rawurlencode($__segs[5]) . '/rendered');
}

// GET /studies/{uid}/metadata —— WADO-RS 实例元数据（不含像素）
if ($sub === 'metadata') {
    list($pname, $psex, $pbirth, $page, $studyDesc) = dw_patient_ctx($ref);
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $count = (int)$s['instances'];
        if ($count <= 0) continue;   // 未知实例数不虚报
        $seUid = $s['uid'] !== '' ? dw_uid((string)$s['uid']) : ($normStudy . '.' . $n);
        for ($i = 1; $i <= $count; $i++) {
            $out[] = dw_instance_obj($ref, $s, $n, $seUid, $i, $normStudy, $pname, $psex, $pbirth, $page, $studyDesc);
        }
    }
    if (!$out) {   // 本地无元数据：回源区域 PACS
        $reg = dw_region_study($ref);
        if ($reg) dw_proxy_pacs('/studies/' . rawurlencode($reg['uid']) . '/metadata');
    }
    integration_log_inbound('dicomweb', 'wado/metadata', true, '返回 ' . count($out) . ' 个实例元数据', '');
    dw_json($out);
}

dw_error(404, '不支持的 DICOMweb 操作：' . $__sub);
