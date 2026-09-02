<?php
/**
 * ============================================================
 * parts/doctor/doctor_depts.php — 医生关联科室列表
 * ============================================================
 * doctor_read.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function doctor_read_depts($u) {
    $ids = user_dept_ids($u);
    $curDeptId = current_dept_id($u);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $list = EmrRepository::q("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id", $ids);
    } else {
        $list = array();
    }
    json_ok(array(
        'list' => array_map(function ($d) {
            return array(
                'id' => (int)$d['id'],
                'name' => $d['name'],
                'type' => $d['type'],
                'limited' => dept_is_limited($d) ? 1 : 0,
            );
        }, $list),
        'current' => $curDeptId,
    ));
    return;
}