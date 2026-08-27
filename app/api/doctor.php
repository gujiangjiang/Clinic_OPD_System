<?php
/**
 * ============================================================
 * doctor.php — 医生工作站接口 — 分发入口
 * ============================================================
 * 说明：按功能拆分到 parts/（沿用 admin parts 模式）：
 *   parts/doctor_read.php  读取（home_stats/depts/list/call_queue/
 *                          queue_list/queue_pref/report_detail/
 *                          get_available_rooms）
 *   parts/doctor_write.php 写入（set_dept/take/add_slot/bind_room/
 *                          unbind_room/room_heartbeat）
 * 本文件保留公共引导、共享函数与动作分发。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 当前医生关联科室ID列表（用于权限校验与科室回退） */
function doctor_dept_ids($u) {
    $ids = array();
    // 会话快照中的 dept_ids 可能为 NULL（如管理员登录医生端接口时），
    // 先判空再拆分，避免 PHP 8 的 Undefined key / Deprecated 告警污染 JSON
    foreach (explode(',', isset($u['dept_ids']) ? (string)$u['dept_ids'] : '') as $id) {
        if ((int)$id > 0) $ids[] = (int)$id;
    }
    return $ids;
}

/** 科室是否为限号（门诊且上/下午号源数量 > 0；急诊与 0 号源为不限号） */
function dept_is_limited($d) {
    return $d['type'] === 'clinic' && ((int)$d['am_quota'] > 0 || (int)$d['pm_quota'] > 0);
}

require __DIR__ . '/parts/doctor_read.php';
require __DIR__ . '/parts/doctor_write.php';

switch ($action) {
    case 'home_stats':
    case 'depts':
    case 'list':
    case 'call_queue':
    case 'queue_list':
    case 'queue_pref':
    case 'report_detail':
    case 'get_available_rooms':
        doctor_part_read($action);
        break;

    case 'set_dept':
    case 'take':
    case 'add_slot':
    case 'bind_room':
    case 'unbind_room':
    case 'room_heartbeat':
        doctor_part_write($action);
        break;

    default:
        json_fail('未知操作');
}
