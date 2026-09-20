<?php
/**
 * ============================================================
 * push.php v1.0.0 — 实时推送 SSE 长连接端点（EventSource 订阅）
 * ============================================================
 * 说明：为叫号大屏 / 站内消息 / 危急值提醒等提供毫秒级事件推送，
 * 替代（或显著降低）固定间隔轮询：
 *   GET /api/push?channel=scr:{token}   叫号大屏（房间令牌鉴权）
 *   GET /api/push?channel=msg:{uid}     站内消息/危急值提醒（登录会话，限本人）
 *   GET /api/push?channel=room:{id}     诊室叫号面板（登录会话）
 *   GET /api/push?channel=dept:{id}     科室候诊队列（登录会话）
 * 事件格式：SSE 标准（id + data 行，data 为 JSON 字符串）；
 * 连接默认 50 秒后正常断开（EventSource 自动重连续流），每 20 秒发心跳注释。
 * 注意：认证完成后立即 session_write_close() 释放会话锁，
 * 长连接期间不阻塞同用户的其它 AJAX 请求。
 * ============================================================ */

$channel = isset($_GET['channel']) ? trim((string)$_GET['channel']) : '';
$cursor  = isset($_GET['cursor'])  ? max(0, (int)$_GET['cursor']) : 0;
if ($channel === '' || strpos($channel, ':') === false) {
    http_response_code(400);
    json_response(false, '缺少 channel 参数');
    exit;
}

/* ---------- 鉴权 ---------- */
$chan = $channel;
if (strpos($channel, 'scr:') === 0) {
    // 大屏通道：按房间令牌鉴权（与 /api/screen 同源）
    $token = substr($channel, 4);
    $room = $token !== '' ? QueueRepository::roomByToken($token) : null;
    if (!$room) { http_response_code(403); exit; }
    $chan = 'scr:' . $token;
} else {
    // 其余通道：需登录会话；msg 通道仅限本人
    if (!Auth::check()) { http_response_code(403); exit; }
    $uid = (int)Auth::id();
    if (strpos($channel, 'msg:') === 0 && $channel !== 'msg:' . $uid) { http_response_code(403); exit; }
    $chan = $channel;
}
// 认证完成，释放会话锁（长连接期间不阻塞同用户并发请求）
if (function_exists('Session::closeReadOnly')) { Session::closeReadOnly(); }
if (function_exists('session_write_close')) { session_write_close(); }
if (function_exists('session_abort')) { @session_abort(); }

/* ---------- SSE 流式输出 ---------- */
set_time_limit(0);
@ignore_user_abort(false);
@ob_end_flush();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // 关闭 Nginx 代理缓冲，保证逐行推送
echo "retry: 2000\n\n";
flush();

$deadline = time() + 50;       // 单次连接存活约 50 秒，超时断开由客户端重连
$last = $cursor;
$lastHeartbeat = time();
$loops = 0;
while (time() < $deadline) {
    try {
        $rows = DB::q('SELECT id, payload FROM push_events WHERE channel=? AND id>? ORDER BY id ASC LIMIT 20', array($chan, $last));
        foreach ($rows as $r) {
            $last = (int)$r['id'];
            echo 'id: ' . $last . "\n";
            echo 'data: ' . $r['payload'] . "\n\n";
        }
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[push] ' . $ex->getMessage());
    }
    // 心跳注释（SSE 协议：冒号开头行被浏览器忽略）
    if (time() - $lastHeartbeat >= 20) {
        echo ": ping\n\n";
        $lastHeartbeat = time();
    }
    flush();
    if (connection_aborted()) break;
    // 队列清理：每 ~150 轮（约 2.5 分钟）惰性清除 24 小时前的历史事件
    if (++$loops % 150 === 0) push_purge();
    usleep(900000);
}
echo ": eof\n\n";
flush();