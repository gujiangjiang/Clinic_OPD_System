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

/** DICOM JSON 响应输出 */
function dw_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/dicom+json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** DICOM JSON 错误 */
function dw_error($status, $msg) {
    dw_json(array('error' => $msg), $status);
}

/** DICOM 标签组装（VR + Value） */
function dw_tag($vr, $value) {
    if (is_array($value)) return array('vr' => $vr, 'Value' => array_values($value));
    return array('vr' => $vr, 'Value' => array($value));
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

/* ---------- 鉴权（统一网关） ---------- */
$__auth = InboundGuard::authorize('dicomweb', array(
    'enabledKey' => 'integration.inbound.pacs.enabled',
    'ipKey' => 'integration.inbound.pacs.ip_whitelist',
    'tokenKey' => 'integration.inbound.pacs.token',
    'requiredScope' => 'pacs:read',
));
if (!$__auth['ok']) {
    integration_log_inbound('dicomweb', $__seg0, false, $__auth['msg'], '');
    if ($__auth['http'] === 401) header('WWW-Authenticate: Basic realm="DICOMweb"');
    dw_error($__auth['http'], $__auth['msg']);
}

if ($__seg0 !== 'studies') {
    dw_error(404, '不支持的 DICOMweb 路径：' . $__sub);
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
    $obj = array(
        '00080020' => dw_tag('DA', dw_date($ref['created_at'])),
        '00080030' => dw_tag('TM', dw_time($ref['created_at'])),
        '00080050' => dw_tag('SH', (string)$ref['flow_no']),
        '00080061' => dw_tag('CS', $ref['modality'] !== '' ? (string)$ref['modality'] : 'OT'),
        '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
        '00100020' => dw_tag('LO', (string)$ref['patient_no']),
        '0020000D' => dw_tag('UI', $studyUid),
        '00201208' => dw_tag('IS', (string)(int)$ref['instance_count']),
        '00201209' => dw_tag('IS', (string)$seriesCnt),
    );
    return $obj;
}

/** series 列表（优先 meta_json.series，回退 series_uids 字符串） */
function dw_series_list($ref) {
    $meta = json_decode((string)$ref['meta_json'], true);
    if (is_array($meta) && !empty($meta['series']) && is_array($meta['series'])) {
        $out = array();
        foreach ($meta['series'] as $s) {
            if (!is_array($s)) continue;
            $out[] = array(
                'uid' => isset($s['uid']) ? (string)$s['uid'] : '',
                'modality' => isset($s['modality']) ? (string)$s['modality'] : (string)$ref['modality'],
                'description' => isset($s['description']) ? (string)$s['description'] : '',
                'instances' => isset($s['instances']) ? (int)$s['instances'] : 0,
            );
        }
        if ($out) return $out;
    }
    $uids = json_decode((string)$ref['series_uids'], true);
    if (!is_array($uids)) $uids = array();
    $out = array();
    foreach ($uids as $u) {
        $out[] = array(
            'uid' => is_array($u) ? (isset($u['uid']) ? (string)$u['uid'] : '') : (string)$u,
            'modality' => (string)$ref['modality'],
            'description' => '',
            'instances' => 0,
        );
    }
    return $out;
}

/* ---------- GET /studies ---------- */
if (count($__segs) === 1) {
    $where = array("study_uid<>''");
    $args = array();
    $pid = isset($_GET['PatientID']) ? trim((string)$_GET['PatientID']) : '';
    $suid = isset($_GET['StudyInstanceUID']) ? trim((string)$_GET['StudyInstanceUID']) : '';
    $mod = isset($_GET['Modality']) ? trim((string)$_GET['Modality']) : '';
    $sdate = isset($_GET['StudyDate']) ? trim((string)$_GET['StudyDate']) : '';
    if ($pid !== '') { $where[] = 'patient_no=?'; $args[] = $pid; }
    if ($suid !== '') { $where[] = 'study_uid=?'; $args[] = $suid; }
    if ($mod !== '') { $where[] = 'modality=?'; $args[] = $mod; }
    if ($sdate !== '' && preg_match('/^\d{8}$/', $sdate)) {
        $where[] = 'date(created_at)=?';
        $args[] = substr($sdate, 0, 4) . '-' . substr($sdate, 4, 2) . '-' . substr($sdate, 6, 2);
    }
    $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $rows = PatientRepository::q('SELECT * FROM imaging_refs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $args);
    $out = array();
    foreach ($rows as $r) {
        $p = PatientRepository::one('SELECT name FROM patients WHERE patient_no=?', array((string)$r['patient_no']));
        $out[] = dw_study_obj($r, $p);
    }
    integration_log_inbound('dicomweb', 'qido/studies', true, '返回 ' . count($out) . ' 条检查', '');
    dw_json($out);
}

$studyUid = isset($__segs[1]) ? $__segs[1] : '';
if ($studyUid === '') dw_error(400, '缺少 StudyInstanceUID');
$ref = PatientRepository::one('SELECT * FROM imaging_refs WHERE study_uid=?', array($studyUid));
if (!$ref) dw_error(404, '未找到检查：' . $studyUid);
$patient = PatientRepository::one('SELECT name FROM patients WHERE patient_no=?', array((string)$ref['patient_no']));

// GET /studies/{uid}
if (count($__segs) === 2) {
    dw_json(array(dw_study_obj($ref, $patient)));
}

$sub = strtolower(isset($__segs[2]) ? $__segs[2] : '');
$seriesList = dw_series_list($ref);

// GET /studies/{uid}/series
if ($sub === 'series' && count($__segs) === 3) {
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $out[] = array(
            '00080060' => dw_tag('CS', $s['modality'] !== '' ? $s['modality'] : 'OT'),
            '0008103E' => dw_tag('LO', $s['description']),
            '0020000E' => dw_tag('UI', $s['uid'] !== '' ? $s['uid'] : ($studyUid . '.' . $n)),
            '00200011' => dw_tag('IS', (string)$n),
            '00201209' => dw_tag('IS', (string)(int)$s['instances']),
        );
    }
    dw_json($out);
}

// GET /studies/{uid}/series/{seriesUID}
if ($sub === 'series' && count($__segs) === 4) {
    $seriesUid = $__segs[3];
    foreach ($seriesList as $s) {
        if ((string)$s['uid'] === $seriesUid) {
            dw_json(array(array(
                '00080060' => dw_tag('CS', $s['modality'] !== '' ? $s['modality'] : 'OT'),
                '0008103E' => dw_tag('LO', $s['description']),
                '0020000E' => dw_tag('UI', (string)$s['uid']),
                '00201209' => dw_tag('IS', (string)(int)$s['instances']),
            )));
        }
    }
    dw_error(404, '未找到序列：' . $seriesUid);
}

// GET /studies/{uid}/metadata —— WADO-RS 实例元数据（按序列合成代表实例，不含像素）
if ($sub === 'metadata') {
    $pname = $patient ? (string)$patient['name'] : '';
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $seriesUid = $s['uid'] !== '' ? $s['uid'] : ($studyUid . '.' . $n);
        $instances = max(1, (int)$s['instances']);
        for ($i = 1; $i <= $instances; $i++) {
            $sop = $seriesUid . '.' . $i;
            $out[] = array(
                '00080016' => dw_tag('UI', '1.2.840.10008.5.1.4.1.1.2'),   // SOP Class UID (CT Image Storage)
                '00080018' => dw_tag('UI', $sop),                          // SOP Instance UID
                '00080020' => dw_tag('DA', dw_date($ref['created_at'])),
                '00080060' => dw_tag('CS', $s['modality'] !== '' ? $s['modality'] : 'OT'),
                '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
                '00100020' => dw_tag('LO', (string)$ref['patient_no']),
                '0020000D' => dw_tag('UI', $studyUid),
                '0020000E' => dw_tag('UI', $seriesUid),
                '00200013' => dw_tag('IS', (string)$i),
            );
        }
    }
    integration_log_inbound('dicomweb', 'wado/metadata', true, '返回 ' . count($out) . ' 个实例元数据', '');
    dw_json($out);
}

dw_error(404, '不支持的 DICOMweb 操作：' . $__sub);
