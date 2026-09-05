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
 * @param bool  $autoRecall 是否智能：优先重呼最早「待自动重呼」的过号患者
 *                          （mini 版「下一位」传 true；完整版「下一位」与过号自动接续传 false）
 * @return array      成功：array('visit'=>array(visit_code,name,flow_no,visit_seq), 'name'=>..)
 *                    失败：array('error'=>消息)
 */
function doctor_call_claim_next_tx($u, $room, $autoRecall = false) {
    $deptId = (int)$room['dept_id'];
    // 智能下一位：若存在「待自动重呼」的过号患者，优先重呼最早过号者
    if ($autoRecall) {
        $missed = QueueRepository::deptMissedAutoForRoom($room, 1);
        if ($missed) {
            return doctor_call_recall_missed_tx($u, $room, $missed[0]);
        }
    }
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
    ), 'name' => $next['pname'], 'is_recall' => false);
}

/**
 * 事务内重呼过号患者：清除其全部 miss 标记 + 记录 recall_missed（此后不再自动重呼）+
 * 设为当前就诊（大屏播报）
 * @param array $u     当前登录用户
 * @param array $room  已校验归属的诊室行
 * @param array $visit 已校验属于本科室的就诊行（含 pname 等患者字段）
 * @return array  array('visit'=>array(visit_code,name,flow_no,visit_seq), 'name'=>..)
 */
function doctor_call_recall_missed_tx($u, $room, $visit) {
    $visitId = (int)$visit['id'];
    $now = now_str();
    QueueRepository::exec("DELETE FROM call_events WHERE visit_id=? AND action='miss'", array($visitId));
    QueueRepository::insert(
        "INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at)
         VALUES(?,?,?,?,?,?,?,'recall_missed',?)",
        array($visitId, $visit['flow_no'], $visit['patient_no'], (int)$room['dept_id'], (int)$room['id'], $u['id'], $u['name'], $now)
    );
    QueueRepository::exec(
        "UPDATE clinic_rooms SET current_visit_id=?, current_flow_no=?, current_called_at=?,
         last_call_action='recall_missed', last_call_at=?, updated_at=? WHERE id=?",
        array($visitId, $visit['flow_no'], $now, $now, $now, (int)$room['id'])
    );
    return array('visit' => array(
        'visit_code' => oid($visitId),
        'name' => $visit['pname'],
        'flow_no' => $visit['flow_no'],
        'visit_seq' => (int)$visit['visit_seq'],
    ), 'name' => $visit['pname'], 'is_recall' => true);
}
