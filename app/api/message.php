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

    /* ---------------- 发送消息：通讯录（按角色分组，排除自己，仅启用账号） ---------------- */
    case 'contacts':
        $rows = DB::q('user', 'SELECT id, name, emp_no, role FROM users WHERE status=1 AND id<>? ORDER BY role, name', array($u['id']));
        $groups = array();
        foreach ($rows as $r) {
            if (!isset($groups[$r['role']])) {
                $groups[$r['role']] = array('role' => $r['role'], 'role_name' => Auth::roleName($r['role']), 'users' => array());
            }
            $groups[$r['role']]['users'][] = array('id' => (int)$r['id'], 'name' => $r['name'], 'emp_no' => (string)$r['emp_no']);
        }
        json_ok(array('groups' => array_values($groups), 'is_admin' => $u['role'] === 'admin'));
        break;

    /* ---------------- 发送消息（管理员可多选群发；普通用户单选 + 30 秒限流） ---------------- */
    case 'send':
        $title = trim((string)post('title', ''));
        $content = trim((string)post('content', ''));
        $recipients = json_decode((string)post('recipients', '[]'), true);
        if ($title === '' || mb_strlen($title) > 50) json_fail('请填写标题（50 字以内）');
        if ($content === '' || mb_strlen($content) > 500) json_fail('请填写内容（500 字以内）');
        if (!is_array($recipients) || !count($recipients)) json_fail('请选择接收者');

        $recipients = array_values(array_unique(array_map('intval', $recipients)));
        // 普通用户：仅允许单选 + 30 秒限流（后端强制，防技术手段批量发送）
        if ($u['role'] !== 'admin') {
            if (count($recipients) > 1) json_fail('每次只能发送给一位用户');
            $last = DB::val('core', "SELECT created_at FROM messages WHERE from_user_id=? AND msg_type='user' ORDER BY id DESC LIMIT 1", array($u['id']));
            if ($last !== '' && $last !== null) {
                $elapsed = time() - strtotime($last);
                if ($elapsed < 30) {
                    json_fail('发送太频繁，请 ' . (30 - $elapsed) . ' 秒后再试');
                }
            }
        }
        // 校验收件人（启用账号且非本人）
        // 注意参数顺序：SQL 中 id<>? 在前、IN 在后，绑定须为 [本人id, ...收件人ids]
        $ph = implode(',', array_fill(0, count($recipients), '?'));
        $params = array_merge(array($u['id']), $recipients);
        $valid = array();
        foreach (DB::q('user', "SELECT id, role, name FROM users WHERE status=1 AND id<>? AND id IN ($ph)", $params) as $r) {
            $valid[(int)$r['id']] = $r;
        }
        if (!count($valid)) json_fail('接收者不存在或不可用');
        $names = array();
        foreach ($valid as $uid => $r) {
            send_msg($r['role'], $uid, $title, $content, '', '', array('msg_type' => 'user'));
            $names[] = $r['name'];
        }
        // 发送日志（独立于收件消息行）：删除/清空已发送不影响接收者查看
        DB::insert('core', 'INSERT INTO sent_messages(sender_id, sender_name, title, content, recipients, recipient_count, created_at) VALUES(?,?,?,?,?,?,?)', array(
            $u['id'], $u['name'], $title, $content, implode('、', $names), count($valid), now_str(),
        ));
        json_ok(array('count' => count($valid)), '消息已发送给 ' . count($valid) . ' 位用户');
        break;

    /* ---------------- 已发送列表（发送日志，仅本人） ---------------- */
    case 'sent_list':
        $list = DB::q('core', 'SELECT * FROM sent_messages WHERE sender_id=? ORDER BY id DESC LIMIT 200', array($u['id']));
        json_ok(array('list' => $list));
        break;

    /* ---------------- 删除已发送记录（多选，仅本人日志，不影响接收者） ---------------- */
    case 'sent_delete':
        $ids = json_decode((string)post('ids', '[]'), true);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : array()))));
        if (!count($ids)) json_fail('请选择要删除的记录');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $u['id'];
        DB::exec('core', "DELETE FROM sent_messages WHERE id IN ($ph) AND sender_id=?", $params);
        json_ok(array(), '已删除 ' . count($ids) . ' 条发送记录');
        break;

    /* ---------------- 清空已发送记录（仅本人日志，不影响接收者） ---------------- */
    case 'sent_clear':
        DB::exec('core', 'DELETE FROM sent_messages WHERE sender_id=?', array($u['id']));
        json_ok(array(), '已清空所有发送记录');
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
