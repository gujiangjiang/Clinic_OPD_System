<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_helpers.php — 运营分析公共辅助
 * ============================================================
 * admin_analytics.php 拆分出的共享辅助函数，逻辑与原文件逐字一致。
 * ============================================================ */

/** 校验并规范化日期范围（缺省=今天；start>end 自动交换）；
 *  用 req() 同时兼容 GET（前端 Clinic.get）与 POST 参数；
 *  跨度上限统一走 date_span_clamp('ana')（366 天，与全站日期范围钳制同源） */
function ana_range() {
    $tz = new DateTimeZone(date_default_timezone_get());
    $end = req('end', date('Y-m-d'));
    $start = req('start', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
    if ($start > $end) { $t = $start; $start = $end; $end = $t; }
    list($start, $end) = date_span_clamp('ana', $start, $end);
    try {
        $ds = new DateTime($start, $tz);
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
    $sql .= ' GROUP BY order_type' . ($groupExpr !== '' ? ',' . $groupExpr : '');
    $rows = AnalyticsRepository::q($sql, array_merge(array($start, $end), $extraParams));
    return $rows;
}

/** 已缴费就诊按当前科室归集（人次 + 挂号费）：[dept_id => [patients, reg_fee]]（dept/custom 共用） */
function ana_paid_regs($start, $end) {
    $regs = AnalyticsRepository::q("SELECT current_dept_id AS d, COUNT(*) AS c, COALESCE(SUM(fee),0) AS f
        FROM registrations WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL
        AND date(paid_at) BETWEEN ? AND ? GROUP BY current_dept_id", array($start, $end));
    $stat = array();
    foreach ($regs as $r) {
        $d = (int)$r['d'];
        if (!isset($stat[$d])) $stat[$d] = array('patients' => 0, 'reg_fee' => 0.0);
        $stat[$d]['patients'] += (int)$r['c'];
        $stat[$d]['reg_fee'] += (float)$r['f'];
    }
    return $stat;
}

/**
 * 项目费按就诊当前科室归集（orders → visit_id → 就诊科室；
 * 分散库不能 JOIN，PHP 内存映射 + array_chunk 批量取科室）：
 * [dept_id => [drug/lab/imaging/procedure => sum]]（dept/custom 共用）
 */
function ana_order_fee_by_dept($start, $end) {
    $vids = array();
    $ordRows = AnalyticsRepository::q("SELECT visit_id, order_type, COALESCE(SUM(total_amount),0) AS s FROM orders
        WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?
        GROUP BY visit_id, order_type", array($start, $end));
    foreach ($ordRows as $r) { $vids[(int)$r['visit_id']] = true; }
    $visitDept = array();
    if ($vids) {
        foreach (array_chunk(array_keys($vids), 400) as $chunk) {
            $ph = in_placeholders($chunk);
            foreach (AnalyticsRepository::q("SELECT id, current_dept_id FROM registrations WHERE id IN ($ph)", $chunk) as $v) {
                $visitDept[(int)$v['id']] = (int)$v['current_dept_id'];
            }
        }
    }
    $typeKey = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
    $stat = array();
    foreach ($ordRows as $r) {
        $vid = (int)$r['visit_id'];
        $d = isset($visitDept[$vid]) ? $visitDept[$vid] : 0;
        if (!isset($stat[$d])) $stat[$d] = array('drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
        $k = isset($typeKey[$r['order_type']]) ? $typeKey[$r['order_type']] : null;
        if ($k) $stat[$d][$k] += (float)$r['s'];
    }
    return $stat;
}

/** 科室名/类型映射（dept 维度统计展示用）：[names: id=>name, types: id=>type] */
function ana_dept_maps() {
    $names = array(); $types = array();
    foreach (AnalyticsRepository::q('SELECT id, name, type FROM departments') as $dd) {
        $names[(int)$dd['id']] = $dd['name'];
        $types[(int)$dd['id']] = (string)$dd['type'];
    }
    return array('names' => $names, 'types' => $types);
}