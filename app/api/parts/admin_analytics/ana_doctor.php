<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_doctor.php — 医生维度统计
 * ============================================================ */

function admin_ana_doctor() {
    list($start, $end) = ana_range();
    $deptId = (int)req('dept_id', 0);
    $visits = array();
    foreach (AnalyticsRepository::q("SELECT doctor_id, doctor_name, COUNT(*) AS c FROM patient_records WHERE date(created_at) BETWEEN ? AND ? GROUP BY doctor_id, doctor_name", array($start, $end)) as $r) {
        $visits[(int)$r['doctor_id']] = array('name' => $r['doctor_name'], 'c' => (int)$r['c']);
    }
    $revRows = ana_order_sums($start, $end, 'doctor_id > 0', array(), 'doctor_id');
    $stat = array();
    $initRow = function () { return array('visits' => 0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0); };
    $names = array();
    foreach ($visits as $did => $v2) {
        if (!isset($stat[$did])) $stat[$did] = $initRow();
        $stat[$did]['visits'] = $v2['c'];
        $names[$did] = $v2['name'];
    }
    $typeKey = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
    $docDept = array();
    foreach ($revRows as $r) {
        $did = (int)$r['g'];
        if (!isset($stat[$did])) $stat[$did] = $initRow();
        $k = isset($typeKey[$r['t']]) ? $typeKey[$r['t']] : null;
        if ($k) $stat[$did][$k] += (float)$r['s'];
        if (!isset($names[$did])) $names[$did] = '';
    }
    $uids = array_keys($stat);
    if ($uids) {
        $ph = implode(',', array_fill(0, count($uids), '?'));
        foreach (AnalyticsRepository::q("SELECT id, name, emp_no, dept_ids, title FROM users WHERE id IN ($ph)", $uids) as $u2) {
            if (empty($names[(int)$u2['id']])) $names[(int)$u2['id']] = $u2['name'];
            $docDept[(int)$u2['id']] = array('title' => $u2['title'], 'dept_ids' => (string)$u2['dept_ids'], 'emp_no' => (string)$u2['emp_no']);
        }
    }
    $rows = array();
    foreach ($stat as $did => $v2) {
        $row = array_merge(array(
            'doctor_id' => $did,
            'doctor_name' => isset($names[$did]) ? $names[$did] : ('医生#' . $did),
            'emp_no' => isset($docDept[$did]) ? $docDept[$did]['emp_no'] : '',
        ), $v2);
        $row['total'] = round($v2['drug'] + $v2['lab'] + $v2['imaging'] + $v2['procedure'], 2);
        foreach (array('drug', 'lab', 'imaging', 'procedure') as $kk) $row[$kk] = round($row[$kk], 2);
        if ($deptId > 0) {
            $ids = array();
            foreach (explode(',', isset($docDept[$did]) ? $docDept[$did]['dept_ids'] : '') as $x) if ((int)$x > 0) $ids[] = (int)$x;
            if (!in_array($deptId, $ids, true)) continue;
        }
        $row['title'] = isset($docDept[$did]) ? $docDept[$did]['title'] : '';
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) { return $b['total'] <=> $a['total']; });
    json_ok(array('range' => array('start' => $start, 'end' => $end), 'rows' => $rows));
}