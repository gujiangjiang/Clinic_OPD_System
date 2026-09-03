<?php
/**
 * ============================================================
 * parts/doctor/doctor_get_available_rooms.php — 可用诊室
 * ============================================================ */

function doctor_read_get_available_rooms($u) {
    $deptId = (int)get('dept_id');
    if ($deptId <= 0) json_fail('请先选择科室');
    // 科室归属校验：仅可查看本人关联科室的诊室，防止跨科室越权
    if (!in_array($deptId, user_dept_ids($u), true)) {
        json_fail('无权查看该科室诊室');
    }
    $rows = EmrRepository::q("SELECT * FROM clinic_rooms WHERE dept_id=? AND room_type='doctor' ORDER BY id", array($deptId));
    $list = array();
    foreach ($rows as $room) {
        $isOnline = (!empty($room['screen_last_heartbeat']) && (time() - strtotime($room['screen_last_heartbeat'])) <= 30);
        if (!$isOnline) {
            $status = 'offline'; $text = '大屏离线，请联系管理员'; $sel = false;
        } elseif ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
            $status = 'occupied'; $text = $room['current_doctor_name'] . ' 正在坐诊'; $sel = false;
        } else {
            $status = ($room['current_doctor_id'] == $u['id']) ? 'bound' : 'available';
            $text = $status === 'bound' ? '已绑定' : '在线空闲'; $sel = true;
        }
        $list[] = array(
            'id' => (int)$room['id'], 'name' => $room['room_name'],
            'status' => $status, 'status_text' => $text, 'selectable' => $sel,
        );
    }
    $myBound = EmrRepository::one("SELECT * FROM clinic_rooms WHERE current_doctor_id=? ORDER BY id DESC LIMIT 1", array($u['id']));
    json_ok(array('list' => $list, 'bound' => $myBound ? array('id' => (int)$myBound['id'], 'name' => $myBound['room_name']) : null));
    return;
}