<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_dept.php — 科室维度统计
 * ============================================================
 * admin_analytics.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function admin_ana_dept() {
    list($start, $end) = ana_range();
    // 人次/挂号费与项目费按就诊科室归集（共享 SQL 收敛：ana_paid_regs/ana_order_fee_by_dept）
    $regs = ana_paid_regs($start, $end);
    $orderFee = ana_order_fee_by_dept($start, $end);
    // 科室名与类型
    $maps = ana_dept_maps();
    $deptNames = $maps['names'];
    $deptType = $maps['types'];

    $stat = array();
    $initRow = function () { return array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0); };
    foreach ($regs as $d => $v) {
        if (!isset($stat[$d])) $stat[$d] = $initRow();
        $stat[$d]['patients'] += (int)$v['patients'];
        $stat[$d]['reg_fee'] += (float)$v['reg_fee'];
    }
    foreach ($orderFee as $d => $v) {
        if (!isset($stat[$d])) $stat[$d] = $initRow();
        foreach (array('drug', 'lab', 'imaging', 'procedure') as $k) $stat[$d][$k] += (float)$v[$k];
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