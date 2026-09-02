<?php
/**
 * ============================================================
 * parts/doctor/doctor_home_stats.php — 医生首页统计
 * ============================================================
 * doctor_read.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function doctor_read_home_stats($u) {
    $uid = (int)$u['id'];
    $today = date('Y-m-d');
    // 今日接诊人次（本人）
    $todayVisits = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $today));
    // 今日开单金额（本人、已缴费、排除退费取消）
    $sums = array('drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
    foreach (EmrRepository::q("SELECT order_type, COALESCE(SUM(total_amount),0) s FROM orders WHERE doctor_id=? AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=? GROUP BY order_type", array($uid, $today)) as $r) {
        if (isset($sums[$r['order_type']])) $sums[$r['order_type']] = round((float)$r['s'], 2);
    }
    // 我的草稿病历（待完成接诊）
    $drafts = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND status='draft'", array($uid));
    // 今日门诊人次（全部科室）
    $todayReg = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=?", array($today));
    // 近7天本人接诊趋势
    $trend = trend_7_days(function ($day) use ($uid) {
        return (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $day));
    });
    json_ok(array(
        'kpi' => array('today_visits' => $todayVisits, 'today_reg' => $todayReg, 'total' => round(array_sum($sums), 2),
            'drug' => $sums['drug'], 'lab' => $sums['lab'], 'imaging' => $sums['imaging'], 'procedure' => $sums['procedure'], 'drafts' => $drafts),
        'trend' => $trend,
    ));
    return;
}