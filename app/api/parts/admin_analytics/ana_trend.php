<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_trend.php — 总览趋势（日序列）
 * ============================================================
 * admin_analytics.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function admin_ana_trend() {
    list($start, $end) = ana_range();
    // 日期轴（含无数据日补零）
    $labels = array();
    $days = array();
    try {
        $d = new DateTime($start);
        $de = new DateTime($end);
        while ($d <= $de) {
            $k = $d->format('Y-m-d');
            $days[$k] = count($labels);
            $labels[] = $d->format('m-d');
            $d->modify('+1 day');
        }
    } catch (Exception $e) { }

    $blank = array_fill(0, count($labels), 0);
    $series = array(
        'total' => $blank, 'drug' => $blank, 'lab' => $blank,
        'imaging' => $blank, 'procedure' => $blank, 'patients' => $blank,
    );
    // 项目费日序列
    foreach (ana_order_sums($start, $end, '', array(), "strftime('%Y-%m-%d', paid_at)") as $r) {
        $g = $r['g']; if (!isset($days[$g])) continue;
        $i = $days[$g];
        if ($r['t'] === 'prescription') $series['drug'][$i] += round((float)$r['s'], 2);
        elseif ($r['t'] === 'lab') $series['lab'][$i] += round((float)$r['s'], 2);
        elseif ($r['t'] === 'imaging') $series['imaging'][$i] += round((float)$r['s'], 2);
        elseif ($r['t'] === 'procedure') $series['procedure'][$i] += round((float)$r['s'], 2);
        $series['total'][$i] += round((float)$r['s'], 2);
    }
    // 挂号费日序列（并入 total，不单列折线避免过密）
    foreach (AnalyticsRepository::q("SELECT strftime('%Y-%m-%d', created_at) AS g, COALESCE(SUM(total),0) AS s
        FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
        if (!isset($days[$r['g']])) continue;
        $series['total'][$days[$r['g']]] += round((float)$r['s'], 2);
    }
    // 人次日序列
    foreach (AnalyticsRepository::q("SELECT date(paid_at) AS g, COUNT(*) AS c FROM registrations
        WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY g",
        array($start, $end)) as $r) {
        if (!isset($days[$r['g']])) continue;
        $series['patients'][$days[$r['g']]] = (int)$r['c'];
    }

    json_ok(array('range' => array('start' => $start, 'end' => $end), 'labels' => $labels, 'series' => $series));
}