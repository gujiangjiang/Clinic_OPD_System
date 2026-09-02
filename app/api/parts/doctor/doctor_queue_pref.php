<?php
/**
 * ============================================================
 * parts/doctor/doctor_queue_pref.php — 候诊偏好
 * ============================================================ */

function doctor_read_queue_pref($u) {
    $_SESSION['queue_pref'] = array(
        'seen' => post('seen', 0) ? 1 : 0,
        'today' => post('today', 0) ? 1 : 0,
        'consult' => post('consult', 0) ? 1 : 0,
    );
    json_ok();
    return;
}