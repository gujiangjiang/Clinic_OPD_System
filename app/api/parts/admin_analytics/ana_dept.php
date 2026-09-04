<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_dept.php — 科室维度统计
 * ============================================================
 * admin_analytics.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function admin_ana_dept() {
    list($start, $end) = ana_range();
    // 人次与挂号费：按就诊当前科室归集
    $regs = AnalyticsRepository::q("SELECT current_dept_id AS d, COUNT(*) AS c, COALESCE(SUM(fee),0) AS f
        FROM registrations WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL
        AND date(paid_at) BETWEEN ? AND ? GROUP BY current_dept_id", array($start, $end));
    // 项目费：orders → visit_id → 就诊科室（分散库不能 JOIN，PHP 内存映射）
    $vids = array();
    $oidMap = array();
    $ordRows = AnalyticsRepository::q("SELECT visit_id, order_type, COALESCE(SUM(total_amount),0) AS s FROM orders
        WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?
        GROUP BY visit_id, order_type", array($start, $end));
    foreach ($ordRows as $r) { $vids[(int)$r['visit_id']] = true; $oidMap[] = $r; }
    // 批量取这些就诊的科室
    $visitDept = array();
    if ($vids) {
        $ids = array_keys($vids);
        foreach (array_chunk($ids, 400) as $chunk) {
            $ph = in_placeholders($chunk);
            foreach (AnalyticsRepository::q("SELECT id, current_dept_id FROM registrations WHERE id IN ($ph)", $chunk) as $v) {
                $visitDept[(int)$v['id']] = (int)$v['current_dept_id'];
            }
        }
    }
    // 科室名与类型
    $deptNames = array();
    $deptType = array();
    foreach (AnalyticsRepository::q('SELECT id, name, type FROM departments') as $dd) {
        $deptNames[(int)$dd['id']] = $dd['name'];
        $deptType[(int)$dd['id']] = (string)$dd['type'];
    }

    $stat = array();
    $initRow = function () { return array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0); };
    foreach ($regs as $r) {
        $d = (int)$r['d'];
        if (!isset($stat[$d])) $stat[$d] = $initRow();
        $stat[$d]['patients'] += (int)$r['c'];
        $stat[$d]['reg_fee'] += (float)$r['f'];
    }
    $typeKey = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
    foreach ($oidMap as $r) {
        $vid = (int)$r['visit_id'];
        $d = isset($visitDept[$vid]) ? $visitDept[$vid] : 0;
        if (!isset($stat[$d])) $stat[$d] = $initRow();
        $k = isset($typeKey[$r['order_type']]) ? $typeKey[$r['order_type']] : null;
        if ($k) $stat[$d][$k] += (float)$r['s'];
    }
    $rows = array();
    foreach ($stat as $d => $v) {
        $v['dept_id'] = $d;
        $v['dept_name'] = isset($deptNames[$d]) ? $deptNames[$d] : '未知科室';
        $v['dept_type'] = isset($deptType[$d]) ? $deptType[$d] : 'clinic';
        $v['total'] = round($v['reg_fee'] + $v['drug'] + $v['lab'] + $v['imaging'] + $v['procedure'], 2);
        foreach (array('reg_fee', 'drug', 'lab', 'imaging', 'procedure') as $kk) $v[$kk] = round($v[$kk], 2);
        $rows[] = $v;
    }
    usort($rows, function ($a, $b) { return $b['total'] <=> $a['total']; });
    json_ok(array('range' => array('start' => $start, 'end' => $end), 'rows' => $rows));
}