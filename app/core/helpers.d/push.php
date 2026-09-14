<?php
/**
 * ============================================================
 * helpers.d/push.php — 实时推送事件队列（SSE 长连接支撑）
 * ============================================================
 * 说明：为叫号大屏、站内消息、危急值提醒等提供事件发布 API。
 * 写侧（生产者）调用 push_emit() 写入 push_events 队列；
 * 读侧（大屏/各工作站）通过 /api/push 建立 SSE 长连接按通道
 * 订阅事件（毫秒级感知，替代/补充固定间隔轮询）。
 *
 * 通道约定：
 *   scr:{screen_token}  叫号大屏（房间维度，令牌鉴权）
 *   msg:{user_id}       站内消息/危急值提醒（用户维度）
 *   room:{room_id}      诊室叫号面板（医生/医技调用面板）
 *   dept:{dept_id}      科室候诊队列（工作台候诊列表）
 * ============================================================ */

/**
 * 发布一条推送事件（写队列；失败静默，不影响主流程）
 * @param string $channel  订阅通道（见通道约定）
 * @param mixed  $payload  事件负载（数组/字符串，数组自动 JSON 编码）
 */
function push_emit($channel, $payload) {
    try {
        $data = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
        DB::insert('INSERT INTO push_events(channel, payload, created_at) VALUES(?,?,datetime(\'now\',\'localtime\'))',
            array($channel, $data));
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[push_emit] ' . $ex->getMessage());
    }
}

/**
 * 发布诊室叫号事件：一次发布到大屏（scr:）、诊室（room:）、科室（dept:）三个通道
 * @param array  $room     clinic_rooms 行（需含 id/dept_id/screen_token）
 * @param mixed  $payload  事件负载（{action, room_id, visit_id, flow_no, ...}）
 */
function push_room_event($room, $payload) {
    if (!is_array($room)) return;
    $rid = (int)$room['id'];
    $dept = (int)$room['dept_id'];
    $tok = isset($room['screen_token']) ? (string)$room['screen_token'] : '';
    if ($tok !== '') push_emit('scr:' . $tok, $payload);
    if ($rid > 0) push_emit('room:' . $rid, $payload);
    if ($dept > 0) push_emit('dept:' . $dept, $payload);
}

/**
 * 清理过期推送事件（保留最近 N 小时；由低频入口惰性触发，防队列无限膨胀）
 * @param int $keepHours 保留小时数，默认 24
 */
function push_purge($keepHours = 24) {
    try {
        $keepHours = max(1, (int)$keepHours);
        DB::exec("DELETE FROM push_events WHERE created_at < datetime('now','localtime','-" . $keepHours . " hours')");
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[push_purge] ' . $ex->getMessage());
    }
}