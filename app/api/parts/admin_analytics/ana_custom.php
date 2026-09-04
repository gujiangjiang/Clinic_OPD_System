<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_custom.php — 自定义统计
 * ============================================================ */

function admin_ana_custom() {
    list($start, $end) = ana_range();
    $groupBy = post('group_by', 'day');
    if (!in_array($groupBy, array('day', 'month', 'year', 'dept', 'doctor'), true)) $groupBy = 'day';
    $metricList = explode(',', post('metrics', 'patients,total'));
    $allow = array('patients', 'reg_fee', 'drug', 'lab', 'imaging', 'procedure', 'total');
    $metrics = array_values(array_intersect($allow, array_map('trim', $metricList)));
    if (!$metrics) $metrics = array('patients', 'total');
    $fmtMap = array('day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y');
    $timeExpr = function ($col) use ($fmtMap, $groupBy) {
        $fmt = $fmtMap[$groupBy];
        return "strftime('$fmt', $col)";
    };
    if ($groupBy === 'day' || $groupBy === 'month' || $groupBy === 'year') {
        $tePaid = $timeExpr('paid_at');
        $tePay = $timeExpr('created_at');
        $teReg = $timeExpr('paid_at');
        $labelSet = array();
        foreach (AnalyticsRepository::q("SELECT DISTINCT $tePaid AS g FROM orders WHERE paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
        foreach (AnalyticsRepository::q("SELECT DISTINCT $tePay AS g FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
        foreach (AnalyticsRepository::q("SELECT DISTINCT $teReg AS g FROM registrations WHERE paid_at IS NOT NULL AND status IN ('paid','visiting','finished') AND date(paid_at) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
        ksort($labelSet);
        $labels = array_keys($labelSet);
        $idx = array_flip($labels);
        $cols = array();
        foreach ($metrics as $mk) $cols[$mk] = array_fill(0, count($labels), 0);
        $add = function ($metric, $g, $v) use (&$cols, $idx) {
            if (!isset($cols[$metric]) || !isset($idx[$g])) return;
            $cols[$metric][$idx[$g]] += $v;
        };
        $needOrder = array_intersect($metrics, array('drug', 'lab', 'imaging', 'procedure', 'total'));
        if ($needOrder) {
            $ge = $timeExpr('paid_at');
            foreach (AnalyticsRepository::q("SELECT order_type AS t, $ge AS g, COALESCE(SUM(total_amount),0) AS s FROM orders
                WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY t, g",
                array($start, $end)) as $r) {
                $k = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
                $tk = isset($k[$r['t']]) ? $k[$r['t']] : null;
                foreach ($metrics as $mk) {
                    if ($mk === 'total' || $mk === $tk) $add($mk, $r['g'], (float)$r['s']);
                }
            }
        }
        if (in_array('reg_fee', $metrics, true)) {
            foreach (AnalyticsRepository::q("SELECT $tePay AS g, COALESCE(SUM(total),0) AS s FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
                $add('reg_fee', $r['g'], (float)$r['s']);
            }
        }
        if (in_array('patients', $metrics, true)) {
            foreach (AnalyticsRepository::q("SELECT $teReg AS g, COUNT(*) AS c FROM registrations WHERE paid_at IS NOT NULL AND status IN ('paid','visiting','finished') AND date(paid_at) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
                $add('patients', $r['g'], (int)$r['c']);
            }
        }
        $rows = array();
        foreach ($labels as $li => $lb) {
            $row = array('label' => $lb);
            foreach ($metrics as $mk) $row[$mk] = in_array($mk, array('patients'), true) ? (int)$cols[$mk][$li] : round((float)$cols[$mk][$li], 2);
            $rows[] = $row;
        }
        json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $rows));
    }
    if ($groupBy === 'dept') {
        $rows = array();
        $regs = AnalyticsRepository::q("SELECT current_dept_id AS d, COUNT(*) AS c, COALESCE(SUM(fee),0) AS f FROM registrations WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY current_dept_id", array($start, $end));
        $deptNames = array();
        foreach (AnalyticsRepository::q('SELECT id, name FROM departments') as $dd) $deptNames[(int)$dd['id']] = $dd['name'];
        $stat = array();
        foreach ($regs as $r) {
            $d = (int)$r['d'];
            if (!isset($stat[$d])) $stat[$d] = array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
            $stat[$d]['patients'] += (int)$r['c'];
            $stat[$d]['reg_fee'] += (float)$r['f'];
        }
        $vd = array(); $map = array();
        foreach (AnalyticsRepository::q("SELECT visit_id, order_type, COALESCE(SUM(total_amount),0) AS s FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY visit_id, order_type", array($start, $end)) as $r) { $vd[(int)$r['visit_id']] = true; $map[] = $r; }
        $vdept = array();
        if ($vd) {
            $ids = array_keys($vd);
            foreach (array_chunk($ids, 400) as $chunk) {
                $ph = in_placeholders($chunk);
                foreach (AnalyticsRepository::q("SELECT id, current_dept_id FROM registrations WHERE id IN ($ph)", $chunk) as $v3) $vdept[(int)$v3['id']] = (int)$v3['current_dept_id'];
            }
        }
        $tk = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
        foreach ($map as $r) {
            $vid = (int)$r['visit_id'];
            $d = isset($vdept[$vid]) ? $vdept[$vid] : 0;
            if (!isset($stat[$d])) $stat[$d] = array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
            $k = isset($tk[$r['order_type']]) ? $tk[$r['order_type']] : null;
            if ($k) $stat[$d][$k] += (float)$r['s'];
        }
        foreach ($stat as $d => $v) {
            $v['total'] = $v['reg_fee'] + $v['drug'] + $v['lab'] + $v['imaging'] + $v['procedure'];
            $row = array('label' => isset($deptNames[$d]) ? $deptNames[$d] : '未知科室');
            foreach ($metrics as $mk) $row[$mk] = $mk === 'patients' ? (int)$v[$mk] : round((float)$v[$mk], 2);
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) { return ($b['total'] ?? 0) <=> ($a['total'] ?? 0); });
        json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $rows));
    }
    $rows = array();
    foreach (AnalyticsRepository::q("SELECT doctor_id, doctor_name, COUNT(*) AS c FROM patient_records WHERE date(created_at) BETWEEN ? AND ? GROUP BY doctor_id, doctor_name", array($start, $end)) as $r) {
        $rows[(int)$r['doctor_id']] = array('label' => $r['doctor_name'], 'patients' => (int)$r['c'], 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0, 'total' => 0.0);
    }
    foreach (ana_order_sums($start, $end, 'doctor_id > 0', array(), 'doctor_id') as $r) {
        $did = (int)$r['g'];
        if (!isset($rows[$did])) $rows[$did] = array('label' => '医生#' . $did, 'patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0, 'total' => 0.0);
        $k = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
        if (isset($k[$r['t']])) $rows[$did][$k[$r['t']]] += (float)$r['s'];
        if (isset($k[$r['t']])) $rows[$did]['total'] += (float)$r['s'];
    }
    $out = array();
    foreach ($rows as $did => $v) {
        $row = array('label' => $v['label']);
        foreach ($metrics as $mk) $row[$mk] = $mk === 'patients' ? (int)$v[$mk] : round((float)$v[$mk], 2);
        $out[] = $row;
    }
    usort($out, function ($a, $b) { return ($b['total'] ?? $b['patients'] ?? 0) <=> ($a['total'] ?? $a['patients'] ?? 0); });
    json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $out));
}