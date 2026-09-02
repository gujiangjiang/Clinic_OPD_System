<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_helpers.php — 运营分析公共辅助
 * ============================================================
 * admin_analytics.php 拆分出的共享辅助函数，逻辑与原文件逐字一致。
 * ============================================================ */

/** 校验并规范化日期范围（缺省=今天；start>end 自动交换）；
 *  用 req() 同时兼容 GET（前端 Clinic.get）与 POST 参数 */
function ana_range() {
    $tz = new DateTimeZone(date_default_timezone_get());
    $end = req('end', date('Y-m-d'));
    $start = req('start', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
    if ($start > $end) { $t = $start; $start = $end; $end = $t; }
    // 防御性上限：最多跨 366 天（自定义趋势图可读性 & 性能）
    try {
        $ds = new DateTime($start, $tz); $de = new DateTime($end, $tz);
        if ((int)$ds->diff($de)->format('%a') > 366) {
            $start = $de->modify('-366 days')->format('Y-m-d');
        }
        return array($ds->format('Y-m-d'), $end);
    } catch (Exception $e) {
        return array(date('Y-m-d'), date('Y-m-d'));
    }
}

/** 项目费按类型汇总（SQL 片段复用）：返回 [type => SUM] */
function ana_order_sums($start, $end, $extraWhere = '', $extraParams = array(), $groupExpr = '') {
    $sql = "SELECT order_type AS t" . ($groupExpr !== '' ? ',' . $groupExpr . ' AS g' : '') .
        ", COALESCE(SUM(total_amount),0) AS s FROM orders
          WHERE status NOT IN ('refunded','cancelled')
          AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?" .
          ($extraWhere !== '' ? ' AND ' . $extraWhere : '');
    if ($groupExpr !== '') $sql .= ' GROUP BY order_type' . ($groupExpr !== '' ? ',' . $groupExpr : '');
    else $sql .= ' GROUP BY order_type';
    $rows = AnalyticsRepository::q($sql, array_merge(array($start, $end), $extraParams));
    return $rows;
}