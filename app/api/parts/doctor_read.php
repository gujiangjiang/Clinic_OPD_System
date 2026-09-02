<?php
/**
 * ============================================================
 * parts/doctor_read.php — 医生端：读取（分发器）
 * ============================================================
 * 按动作拆分为 parts/doctor/*.php，本文件仅保留函数分发，
 * 逻辑与拆分前完全一致。
 * ============================================================ */

require __DIR__ . '/doctor/doctor_home_stats.php';
require __DIR__ . '/doctor/doctor_depts.php';
require __DIR__ . '/doctor/doctor_call_queue.php';
require __DIR__ . '/doctor/doctor_queue_list.php';
require __DIR__ . '/doctor/doctor_queue_pref.php';
require __DIR__ . '/doctor/doctor_report_detail.php';
require __DIR__ . '/doctor/doctor_get_available_rooms.php';

function doctor_part_read($action) {
    $u = Auth::user();

    if ($action === 'home_stats') { doctor_read_home_stats($u); return; }
    if ($action === 'depts') { doctor_read_depts($u); return; }
    if ($action === 'call_queue') { doctor_read_call_queue($u); return; }
    if ($action === 'queue_list') { doctor_read_queue_list($u); return; }
    if ($action === 'queue_pref') { doctor_read_queue_pref($u); return; }
    if ($action === 'report_detail') { doctor_read_report_detail($u); return; }
    if ($action === 'get_available_rooms') { doctor_read_get_available_rooms($u); return; }
}