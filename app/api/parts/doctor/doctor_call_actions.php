<?php
/**
 * ============================================================
 * parts/doctor/doctor_call_actions.php — 医生叫号动作（下一位/过号/再次叫号）
 * ============================================================
 * 说明：医生工作站叫号悬浮窗的后端动作，全部落库（clinic_rooms 当前就诊 +
 * call_events 事件表），大屏端仅按 token 读取并回库校验，防前端篡改。
 * 多医生并发叫号时，认领在同一事务内完成（号源池查询排除已认领患者 +
 * 事件表双保险），保证同一患者不会被两位医生重复叫号。
 * ============================================================ */

/**
 * 校验并返回当前医生绑定的诊室（医生诊室类型）
 * @return array|null 诊室行；未绑定/无权返回 null
 */
function doctor_bound_room($u, $roomId) {
    if ((int)$roomId <= 0) return null;
    $room = QueueRepository::one("SELECT * FROM clinic_rooms WHERE id=? AND room_type='doctor'", array((int)$roomId));
    if (!$room) return null;
    if ((int)$room['current_doctor_id'] !== (int)$u['id']) return null;
    return $room;
}

/**
 * 事务内认领号源池首位为当前就诊（调用方需已开启事务，成功时已写库未提交）
 * @param array $u    当前登录用户
 * @param array $room 已校验归属的诊室行
 * @return array      成功：array('visit'=>array(visit_code,name,flow_no,visit_seq), 'name'=>..)
 *                    失败：array('error'=>消息)
 */
function doctor_call_claim_next_tx($u, $room) {
    $deptId = (int)$room['dept_id'];
    $next = QueueRepository::deptPoolNextForRoom($room);
    if (!$next) return array('error' => '暂无候诊患者，请稍候');
    $visitId = (int)$next['id'];
    // 双保险：该就诊若已被其他医生认领（并发场景事务内串行写，此处兜底拦截）
    $claimed = (int)QueueRepository::val("SELECT COUNT(*) FROM call_events WHERE visit_id=? AND action='call'", array($visitId));
    if ($claimed > 0) return array('error' => '该患者已被其他医生叫号，队列已刷新');
    $now = now_str();
    QueueRepository::exec(
        "UPDATE clinic_rooms SET current_visit_id=?, current_flow_no=?, current_called_at=?,
         last_call_action='call_next', last_call_at=?, updated_at=? WHERE id=?",
        array($visitId, $next['flow_no'], $now, $now, $now, (int)$room['id'])
    );
    QueueRepository::insert(
        "INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at)
         VALUES(?,?,?,?,?,?,?,'call',?)",
        array($visitId, $next['flow_no'], $next['patient_no'], $deptId, (int)$room['id'], $u['id'], $u['name'], $now)
    );
    return array('visit' => array(
        'visit_code' => oid($visitId),
        'name' => $next['pname'],
        'flow_no' => $next['flow_no'],
        'visit_seq' => (int)$next['visit_seq'],
    ), 'name' => $next['pname']);
}
