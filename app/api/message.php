<?php
/**
 * ============================================================
 * message.php — 站内消息接口
 * ============================================================
 * 说明：通知方式为【纯站内消息 + 打印提醒】：
 * 开单/报告完成/处置完成/发药完成等事件写入 messages 表，
 * 目标角色用户实时轮询提醒；含打印类型的消息附打印按钮。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ---------------- 未读消息数（铃铛角标） ---------------- */
    case 'unread_count':
        $count = (int)DB::val('core', 'SELECT COUNT(*) FROM messages WHERE is_read=0 AND (to_role=? OR to_user_id=?)',
            array($u['role'], $u['id']));
        json_ok(array('count' => $count));
        break;

    /* ---------------- 消息列表（最近50条，面板用） ---------------- */
    case 'list':
        $list = DB::q('core', 'SELECT * FROM messages WHERE to_role=? OR to_user_id=? ORDER BY id DESC LIMIT 50',
            array($u['role'], $u['id']));
        obfList($list);
        json_ok(array('list' => $list));
        break;

    /* ---------------- 标记已读 ---------------- */
    case 'read':
        $id = (int)post('id');
        DB::exec('core', 'UPDATE messages SET is_read=1 WHERE id=? AND (to_role=? OR to_user_id=?)', array($id, $u['role'], $u['id']));
        json_ok();
        break;

    /* ---------------- 全部消息（消息中心页面） ---------------- */
    case 'all':
        $list = DB::q('core', 'SELECT * FROM messages WHERE to_role=? OR to_user_id=? ORDER BY id DESC LIMIT 200',
            array($u['role'], $u['id']));
        obfList($list);
        json_ok(array('list' => $list));
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
