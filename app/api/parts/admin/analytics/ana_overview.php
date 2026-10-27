<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_overview.php — 运营总览
 * ============================================================
 * admin_analytics.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function admin_ana_overview() {
    list($start, $end) = ana_range();

    // KPI：门诊人次 + 挂号费（day_range 边界：local/utc 双模式正确）
    $ts0 = day_range($start); $te1 = day_range($end);
    $patients = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM registrations
        WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?",
        array($ts0[0], $te1[1]));
    $regFee = (float)AnalyticsRepository::val("SELECT COALESCE(SUM(total_amount),0) FROM payments WHERE kind='visit' AND created_at >= ? AND created_at < ?", array($ts0[0], $te1[1]));

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