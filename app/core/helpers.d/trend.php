<?php
/**
 * ============================================================
 * helpers.d/trend.php — 近 7 天趋势数据
 * ============================================================
 * 说明：统一迭代循环生成近 7 天趋势数据，消除各处重复的 for 循环。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/**
 * 近 7 天趋势数据生成（统一迭代循环，消除 7 处重复的 for 循环）
 * @param callable|array $queries 单 series：callable(string $day) → mixed；
 *                                多 series：array('key' => callable) 如 ['reg'=>fn,'rev'=>fn]
 * @return array ['labels'=>string[], 'data'=>mixed[]] 或 ['labels'=>..., 'reg'=>..., 'rev'=>...]
 */
function trend_7_days($queries) {
    $labels = array();
    $series = array();
    $multi = is_array($queries) && !is_callable($queries);
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $labels[] = substr($day, 5);
        if ($multi) {
            foreach ($queries as $k => $q) {
                $series[$k][] = $q($day);
            }
        } else {
            $series[] = $queries($day);
        }
    }
    $result = array('labels' => $labels);
    if ($multi) {
        foreach ($series as $k => $v) { $result[$k] = $v; }
    } else {
        $result['data'] = $series;
    }
    return $result;
}