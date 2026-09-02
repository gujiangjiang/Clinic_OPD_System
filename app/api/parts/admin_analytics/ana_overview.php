<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_overview.php — 运营总览
 * ============================================================
 * admin_analytics.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function admin_ana_overview() {
    list($start, $end) = ana_range();

    // KPI：门诊人次 + 挂号费
    $patients = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM registrations
        WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?",
        array($start, $end));
    $regFee = (float)AnalyticsRepository::val("SELECT COALESCE(SUM(total),0) FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ?", array($start, $end));

    // 四类项目费 + 合计
    $sums = array('prescription' => 0, 'lab' => 0, 'imaging' => 0, 'procedure' => 0);
    foreach (ana_order_sums($start, $end) as $r) {
        if (isset($sums[$r['t']])) $sums[$r['t']] = (float)$r['s'];
    }
    $projTotal = array_sum($sums);

    json_ok(array(
        'range' => array('start' => $start, 'end' => $end),
        'kpi' => array(
            'patients' => $patients,
            'reg_fee' => round($regFee, 2),
            'drug' => round($sums['prescription'], 2),
            'lab' => round($sums['lab'], 2),
            'imaging' => round($sums['imaging'], 2),
            'procedure' => round($sums['procedure'], 2),
            'proj_total' => round($projTotal, 2),
            'total' => round($regFee + $projTotal, 2),
        ),
    ));
}