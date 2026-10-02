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

/** 归一化为合法 DICOM 模态码（VR=CS，表 0008,0060）；非码值（如中文分类名）→ 尽力映射，否则 OT */
function dw_modality($s) {
    $s = trim((string)$s);
    if ($s === '') return 'OT';
    $u = strtoupper($s);
    if (preg_match('/^[A-Z0-9_ ]+$/', $u)) return $u;
    $map = array(
        'MRI' => 'MR', '磁共振' => 'MR', '核磁' => 'MR',
        'CT' => 'CT', '超声' => 'US', '彩超' => 'US', 'B超' => 'US',
        'DR' => 'DR', 'CR' => 'CR', 'X线' => 'DX', 'X射线' => 'DX',
        '钼靶' => 'MG', '核医学' => 'NM', 'PET' => 'PT',
    );
    foreach ($map as $k => $v) {
        if (mb_stripos($s, $k, 0, 'UTF-8') !== false) return $v;
    }
    return 'OT';
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
    $pbirth = ($patient && !empty($patient['birth_date'])) ? dw_date($patient['birth_date']) : '';
    $psex = ($patient && isset($patient['gender'])) ? (($patient['gender'] === '男') ? 'M' : (($patient['gender'] === '女') ? 'F' : 'O')) : '';
    $page = dw_age($patient && isset($patient['birth_date']) ? $patient['birth_date'] : '');
    $mod = dw_modality((string)$ref['modality']);
    $obj = array(
        '00080020' => dw_tag('DA', dw_date($ref['created_at'])),
        '00080030' => dw_tag('TM', dw_time($ref['created_at'])),
        '00080050' => dw_tag('SH', dw_accession($ref)),                 // 检查号=申请单号
        '00080060' => dw_tag('CS', $mod),                               // Modality
        '00080061' => dw_tag('CS', $mod),                               // ModalitiesInStudy
        '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
        '00100020' => dw_tag('LO', (string)$ref['patient_no']),         // 患者号
        '00100030' => dw_tag('DA', $pbirth),
        '00100040' => dw_tag('CS', $psex),
        '00101000' => dw_tag('LO', (string)$ref['flow_no']),            // 门诊号（本服务约定）
        '00101010' => dw_tag('AS', $page),                              // 年龄
        '0020000D' => dw_tag('UI', dw_uid($studyUid)),                  // 合法 DICOM UID
        '00201208' => dw_tag('IS', (string)(int)$ref['instance_count']),// 检查相关实例数
        '00201209' => dw_tag('IS', (string)$seriesCnt),                 // 检查相关序列数
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
                'modality' => dw_modality(isset($s['modality']) && $s['modality'] !== '' ? $s['modality'] : (string)$ref['modality']),
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
            'modality' => dw_modality((string)$ref['modality']),
            'description' => '',
            'instances' => 0,
        );
    }
    return $out;
}

/* ---------- GET /studies（QIDO-RS 检查检索） ---------- */
if (count($__segs) === 1) {
    $where = array("study_uid<>''");
    $args = array();
    $pid = isset($_GET['PatientID']) ? trim((string)$_GET['PatientID']) : '';
    $pname = isset($_GET['PatientName']) ? trim((string)$_GET['PatientName']) : '';
    $suid = isset($_GET['StudyInstanceUID']) ? trim((string)$_GET['StudyInstanceUID']) : '';
    $acc = isset($_GET['AccessionNumber']) ? trim((string)$_GET['AccessionNumber']) : '';
    $mod = '';
    foreach (array('ModalitiesInStudy', 'Modality') as $mk) {   // 标准键优先 ModalitiesInStudy
        if (!empty($_GET[$mk])) { $mod = trim((string)$_GET[$mk]); break; }
    }
    $sdate = isset($_GET['StudyDate']) ? trim((string)$_GET['StudyDate']) : '';

    if ($pid !== '') { $where[] = 'patient_no=?'; $args[] = $pid; }
    if ($acc !== '') { $where[] = 'flow_no=?'; $args[] = $acc; }   // 尽力：就诊号
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

// GET /studies/{uid}/series（QIDO-RS 序列检索）
if ($sub === 'series' && count($__segs) === 3) {
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) { $n++; $out[] = dw_series_obj($s, $normStudy, $n); }
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

// GET /studies/{uid}/metadata —— WADO-RS 实例元数据（不含像素）
if ($sub === 'metadata') {
    $pname = $patient ? (string)$patient['name'] : '';
    $prow = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array((string)$ref['patient_no']));
    $psex = ($prow && isset($prow['gender'])) ? (($prow['gender'] === '男') ? 'M' : (($prow['gender'] === '女') ? 'F' : 'O')) : '';
    $pbirth = ($prow && !empty($prow['birth_date'])) ? dw_date($prow['birth_date']) : '';
    $page = ($prow && !empty($prow['birth_date'])) ? dw_age($prow['birth_date']) : '';
    $meta = json_decode((string)$ref['meta_json'], true);
    $studyDesc = (is_array($meta) && !empty($meta['item_name'])) ? (string)$meta['item_name'] : '';
    $out = array();
    $n = 0;
    foreach ($seriesList as $s) {
        $n++;
        $count = (int)$s['instances'];
        if ($count <= 0) continue;   // 未知实例数不虚报
        $seUid = $s['uid'] !== '' ? dw_uid((string)$s['uid']) : ($normStudy . '.' . $n);
        $sopClass = dw_sop_class($s['modality']);
        for ($i = 1; $i <= $count; $i++) {
            $item = array(
                '00080016' => dw_tag('UI', $sopClass),                     // SOP Class UID（按模态）
                '00080018' => dw_tag('UI', $seUid . '.' . $i),             // SOP Instance UID
                '00080020' => dw_tag('DA', dw_date($ref['created_at'])),
                '00080030' => dw_tag('TM', dw_time($ref['created_at'])),
                '00080050' => dw_tag('SH', dw_accession($ref)),            // 检查号=申请单号
                '00080060' => dw_tag('CS', $s['modality']),
                '00100010' => dw_tag('PN', array('Alphabetic' => $pname)),
                '00100020' => dw_tag('LO', (string)$ref['patient_no']),
                '00100030' => dw_tag('DA', $pbirth),
                '00100040' => dw_tag('CS', $psex),
                '00101000' => dw_tag('LO', (string)$ref['flow_no']),       // 门诊号
                '00101010' => dw_tag('AS', $page),                         // 年龄
                '0020000D' => dw_tag('UI', $normStudy),
                '0020000E' => dw_tag('UI', $seUid),
                '00200011' => dw_tag('IS', (string)$n),
                '00200013' => dw_tag('IS', (string)$i),
            );
            if ($studyDesc !== '') $item['00081030'] = dw_tag('LO', $studyDesc);   // StudyDescription
            if ($s['description'] !== '') $item['0008103E'] = dw_tag('LO', $s['description']);
            $out[] = $item;
        }
    }
    integration_log_inbound('dicomweb', 'wado/metadata', true, '返回 ' . count($out) . ' 个实例元数据', '');
    dw_json($out);
}

dw_error(404, '不支持的 DICOMweb 操作：' . $__sub);
