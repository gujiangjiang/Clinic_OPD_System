<?php
/**
 * ============================================================
 * CoreRepository.php — 系统核心仓库（设置/消息/审核）
 * ============================================================
 * 覆盖：settings、messages、sent_messages、audits 的查询与写入。
 * ============================================================ */
class CoreRepository extends BaseRepository {

    /* ---------------- 系统设置 ---------------- */

    /** 读取设置 */
    public static function setting($key) {
        return self::val('SELECT svalue FROM settings WHERE skey=?', array($key));
    }

    /** 写入设置 */
    public static function setSetting($key, $value) {
        self::exec('INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)', array($key, (string)$value));
    }

    /** 全部设置 */
    public static function allSettings() {
        return self::q('SELECT skey, svalue FROM settings');
    }

    /* ---------------- 站内消息 ---------------- */

    /** 新增消息 */
    public static function insertMessage($data) { return self::insertRow('messages', $data); }

    /** 未读消息数 */
    public static function unreadCount($role, $userId) {
        return (int)self::val('SELECT COUNT(*) FROM messages WHERE to_role=? AND to_user_id=? AND is_read=0', array($role, (int)$userId));
    }

    /** 消息列表 */
    public static function messages($role, $userId, $limit = 50) {
        return self::q('SELECT * FROM messages WHERE to_role=? AND to_user_id=? ORDER BY id DESC LIMIT ' . (int)$limit, array($role, (int)$userId));
    }

    /** 全部消息（管理端） */
    public static function allMessages($limit = 200) {
        return self::q('SELECT * FROM messages ORDER BY id DESC LIMIT ' . (int)$limit);
    }

    /** 标记已读 */
    public static function markRead($id) {
        self::exec('UPDATE messages SET is_read=1 WHERE id=?', array((int)$id));
    }

    /** 删除消息 */
    public static function deleteMessage($id) {
        self::exec('DELETE FROM messages WHERE id=?', array((int)$id));
    }

    /** 新增发送日志 */
    public static function insertSentMessage($data) { return self::insertRow('sent_messages', $data); }

    /** 发送日志列表 */
    public static function sentMessages($limit = 100) {
        return self::q('SELECT * FROM sent_messages ORDER BY id DESC LIMIT ' . (int)$limit);
    }

    /* ---------------- 审核中心 ---------------- */

    /** 新增审核记录 */
    public static function insertAudit($data) { return self::insertRow('audits', $data); }

    /** 待审核数 */
    public static function pendingCount() {
        return (int)self::val("SELECT COUNT(*) FROM audits WHERE status='pending'");
    }

    /** 审核列表 */
    public static function audits($status = '', $type = '', $limit = 100) {
        $sql = 'SELECT * FROM audits';
        $params = array();
        $where = array();
        if ($status !== '') { $where[] = 'status=?'; $params[] = $status; }
        if ($type !== '') { $where[] = 'type=?'; $params[] = $type; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
        return self::q($sql, $params);
    }

    /** 审核记录详情 */
    public static function auditById($id) { return self::findById('audits', $id); }

    /** 更新审核状态 */
    public static function updateAudit($id, $data) { return self::updateRow('audits', $id, $data); }
}