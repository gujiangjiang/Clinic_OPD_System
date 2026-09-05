<?php
/**
 * ============================================================
 * parts/doctor/doctor_call_panel.php — 医生叫号悬浮窗数据
 * ============================================================ */

function doctor_read_call_panel($u) {
    // 当前医生绑定的诊室（一人一块屏）
    $room = QueueRepository::one(
        "SELECT * FROM clinic_rooms WHERE current_doctor_id=? AND room_type='doctor' ORDER BY id DESC LIMIT 1",
        array((int)$u['id'])
    );
    if (!$room) json_ok(array('bound' => false));
    // 叫号会话日期维护（跨天规则）
    $room = QueueRepository::roomQueueRefresh($room);
    $deptId = (int)$room['dept_id'];
    $dept = DeptRepository::one('SELECT * FROM departments WHERE id=?', array($deptId));
    $current = QueueRepository::roomCurrentVisit($room);
    // 号源池分页：悬浮窗完整显示 + 滚动分段加载（默认 20 / 上限 200）
    $poolLimit = max(1, min(200, (int)get('limit', 20)));
    $poolOffset = max(0, (int)get('offset', 0));
    $pool = QueueRepository::deptPoolForRoom($room, $poolLimit, $poolOffset);
    $poolCount = QueueRepository::deptPoolCountForRoom($room);
    $next = $pool ? $pool[0] : null;
    $missed = QueueRepository::deptMissedForRoom($room, 5);
    $fmt = function ($r, $missedFlag = 0) {
        if (!$r) return null;
        return array(
            'visit_code' => oid((int)$r['id']),
            'name' => $r['pname'],
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'visit_seq' => (int)$r['visit_seq'],
            'flow_no' => $r['flow_no'],
            'status' => $r['status'],
            'missed' => (int)$missedFlag,
        );
    };
    $curFmt = $fmt($current);
    if ($curFmt) $curFmt['called_at'] = (string)$room['current_called_at'];
    json_ok(array(
        'bound' => true,
        'room' => array('id' => (int)$room['id'], 'name' => $room['room_name'], 'dept_id' => $deptId, 'dept_name' => $dept ? $dept['name'] : ''),
        'current' => $curFmt,
        'next' => $fmt($next),
        'pool' => array_map(function ($r) use ($fmt) { return $fmt($r, 0); }, $pool),
        'pool_count' => $poolCount,
        'loaded' => $poolOffset + count($pool),
        'has_more' => ($poolOffset + count($pool)) < $poolCount,
        'missed' => array_map(function ($r) use ($fmt) { return $fmt($r, 1); }, $missed),
        'servertime' => now_str(),
    ));
    return;
}
