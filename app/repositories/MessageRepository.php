<?php
/**
 * ============================================================
 * MessageRepository.php — 站内消息仓库
 * ============================================================
 * 说明：封装站内消息（收件箱/发送日志/未读计数/已读标记/系统消息）
 * 相关 SQL，统一经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class MessageRepository extends BaseRepository {

    /** 发送站内消息（sender 来源：业务钩子/系统/用户） */
    public static function send($data) {
        return self::insert(
            'INSERT INTO messages(from_name, from_user_id, to_role, to_user_id, title, content, print_type, print_url, is_read, msg_type, patient_name, visit_id, link_url, created_at) VALUES(?,?,?,?,?,?,?,?,0,?,?,?,?,?)',
            array(
                (string)(isset($data['from_name']) ? $data['from_name'] : ''),
                (int)(isset($data['from_user_id']) ? $data['from_user_id'] : 0),
                (string)(isset($data['to_role']) ? $data['to_role'] : ''),
                (int)(isset($data['to_user_id']) ? $data['to_user_id'] : 0),
                (string)(isset($data['title']) ? $data['title'] : ''),
                (string)(isset($data['content']) ? $data['content'] : ''),
                (string)(isset($data['print_type']) ? $data['print_type'] : ''),
                (string)(isset($data['print_url']) ? $data['print_url'] : ''),
                (string)(isset($data['msg_type']) ? $data['msg_type'] : 'system'),
                (string)(isset($data['patient_name']) ? $data['patient_name'] : ''),
                (int)(isset($data['visit_id']) ? $data['visit_id'] : 0),
                (string)(isset($data['link_url']) ? $data['link_url'] : ''),
                now_str(),
            )
        );
    }

    /** 标记为已读 */
    public static function markRead($id, $userId) {
        return self::exec('UPDATE messages SET is_read=1 WHERE id=? AND to_user_id=?', array((int)$id, (int)$userId));
    }

    /** 全部标记为已读 */
    public static function markAllRead($userId) {
        return self::exec('UPDATE messages SET is_read=1 WHERE to_user_id=? AND is_read=0', array((int)$userId));
    }

    /** 未读消息数（顶栏/轮询徽章） */
    public static function unreadCount($userId) {
        return (int)self::val('SELECT COUNT(*) FROM messages WHERE to_user_id=? AND is_read=0', array((int)$userId));
    }

    /** 删除消息 */
    public static function delete($id, $userId) {
        return self::exec('DELETE FROM messages WHERE id=? AND to_user_id=?', array((int)$id, (int)$userId));
    }
}