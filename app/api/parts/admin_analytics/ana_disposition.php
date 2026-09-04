<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_disposition.php — 转归查询
 * ============================================================ */

function admin_ana_disposition() {
    $type = trim((string)get('type', '全部'));
    $sql = 'SELECT r.id AS visit_id, r.registered_at, r.flow_no, r.disposition, r.disposition_detail, ' .
        'COALESCE(NULLIF(r.current_dept_name, \'\'), r.first_dept_name) AS dept_name, ' .
        'p.name AS pname, p.gender, p.birth_date, p.id_card ' .
        'FROM registrations r JOIN patients p ON p.patient_no=r.patient_no ' .
        "WHERE r.status='finished' AND r.disposition<>''";
    $params = array();
    if ($type !== '' && $type !== '全部') {
        $sql .= ' AND r.disposition=?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 200';
    $rows = array();
    $vids = array();
    foreach (AnalyticsRepository::q($sql, $params) as $r) {
        $r['doctor_name'] = '';
        $vids[] = (int)$r['visit_id'];
        $rows[] = $r;
    }
    if ($vids) {
        $ph = in_placeholders($vids);
        $docMap = array();
        foreach (AnalyticsRepository::q("SELECT visit_id, doctor_name FROM patient_records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
            if (!isset($docMap[(int)$pr['visit_id']])) $docMap[(int)$pr['visit_id']] = (string)$pr['doctor_name'];
        }
        foreach ($rows as &$r) {
            $vid = (int)$r['visit_id'];
            if (!isset($docMap[$vid])) {
                foreach (AnalyticsRepository::q("SELECT visit_id, doctor_name FROM records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
                    if ((int)$pr['visit_id'] === $vid) { $docMap[$vid] = (string)$pr['doctor_name']; break; }
                }
            }
            $r['doctor_name'] = isset($docMap[$vid]) ? $docMap[$vid] : '';
        }
        unset($r);
    }
    $rowsOut = array();
    foreach ($rows as $r) {
        $rowsOut[] = array(
            'registered_at' => (string)$r['registered_at'],
            'flow_no' => (string)$r['flow_no'],
            'dept_name' => (string)$r['dept_name'],
            'doctor_name' => (string)$r['doctor_name'],
            'disposition' => (string)$r['disposition'],
            'disposition_detail' => (string)$r['disposition_detail'],
            'pname' => (string)$r['pname'],
            'gender' => (string)$r['gender'],
            'id_card' => (string)(isset($r['id_card']) ? $r['id_card'] : ''),
            'age_fmt' => age_format($r['birth_date'], $r['registered_at']),
        );
    }
    json_ok(array('list' => $rowsOut));
}