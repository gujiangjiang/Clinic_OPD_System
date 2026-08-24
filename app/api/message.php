<?php
/**
 * ============================================================
 * message.php — 站内消息接口
 * ============================================================
 * 说明：通知方式为【纯站内消息 + 打印提醒】：
 * 开单/报告完成/处置完成/发药完成等事件写入 messages 表，
 * 目标用户实时轮询提醒；含打印类型的消息附打印按钮。
 * 可见性规则（v2.5.30 修复越权可见）：
 *   定向消息（to_user_id=收件人id）→ 仅收件人可见；
 *   角色广播（to_user_id=0 且 to_role=角色）→ 该角色全员可见。
 *   旧逻辑 `to_role=? OR to_user_id=?` 会让同角色所有用户
 *   看到全部定向消息（如王强看到李娜/赵敏的开单医生通知）。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ---------------- 未读消息数（铃铛角标）+ 最新未读消息ID（用于前端检测新消息） ---------------- */
    case 'unread_count':
        $count = (int)DB::val('core', 'SELECT COUNT(*) FROM messages WHERE is_read=0 AND (to_user_id=? OR (to_user_id=0 AND to_role=?))',
            array($u['id'], $u['role']));
        // latest_id：当前用户未读消息中的最大 ID（0 表示无未读）。
        // 前端轮询时比较该值是否增大，从而判断「是否有新消息到达」，
        // 比单纯比较数量更准确（避免多端已读导致的计数波动误判）。
        $latestId = (int)DB::val('core', 'SELECT MAX(id) FROM messages WHERE is_read=0 AND (to_user_id=? OR (to_user_id=0 AND to_role=?))',
            array($u['id'], $u['role']));
        json_ok(array('count' => $count, 'latest_id' => $latestId));
        break;

    /* ---------------- 消息列表（最近50条，面板用） ---------------- */
    case 'list':
        $list = DB::q('core', 'SELECT * FROM messages WHERE (to_user_id=? OR (to_user_id=0 AND to_role=?)) ORDER BY id DESC LIMIT 50',
            array($u['id'], $u['role']));
        obfList($list);
        json_ok(array('list' => $list));
        break;

    /* ---------------- 标记已读 ---------------- */
    case 'read':
        $id = (int)post('id');
        DB::exec('core', 'UPDATE messages SET is_read=1 WHERE id=? AND (to_user_id=? OR (to_user_id=0 AND to_role=?))', array($id, $u['id'], $u['role']));
        json_ok();
        break;

    /* ---------------- 全部消息（消息中心页面） ---------------- */
    case 'all':
        $list = DB::q('core', 'SELECT * FROM messages WHERE (to_user_id=? OR (to_user_id=0 AND to_role=?)) ORDER BY id DESC LIMIT 200',
            array($u['id'], $u['role']));
        obfList($list);
        json_ok(array('list' => $list));
        break;

    /* ---------------- 删除单条消息 ---------------- */
    case 'delete':
        $id = (int)post('id');
        DB::exec('core', 'DELETE FROM messages WHERE id=? AND (to_user_id=? OR (to_user_id=0 AND to_role=?))', array($id, $u['id'], $u['role']));
        json_ok(array(), '消息已删除');
        break;

    /* ---------------- 一键清空所有消息 ---------------- */
    case 'clear_all':
        DB::exec('core', 'DELETE FROM messages WHERE (to_user_id=? OR (to_user_id=0 AND to_role=?))', array($u['id'], $u['role']));
        json_ok(array(), '已清空所有消息');
        break;

    /* ---------------- 标记全部已读（一次性，避免前端逐个异步请求的竞态问题） ---------------- */
    case 'read_all':
        DB::exec('core', 'UPDATE messages SET is_read=1 WHERE is_read=0 AND (to_user_id=? OR (to_user_id=0 AND to_role=?))',
            array($u['id'], $u['role']));
        json_ok(array(), '已全部标记为已读');
        break;

    default:
        json_fail('未知操作');
}

/** 消息行内的 visit_id 输出为混淆串（前端跳转病历页原样透传，后端 did 解码） */
function obfList(&$list) {
    foreach ($list as &$m) {
        if (!empty($m['visit_id'])) $m['visit_id'] = oid((int)$m['visit_id']);
    }
}
