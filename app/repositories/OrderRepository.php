<?php
/**
 * ============================================================
 * OrderRepository.php — 开单/医嘱仓库
 * ============================================================
 * 覆盖：orders（开单主表）、order_items（明细）、lab_items、
 * exam_items、disposal_items、item_categories、lab_group_members、
 * results、reports 的开单、状态流转与关联查询。
 * ============================================================ */
class OrderRepository extends BaseRepository {

    /* ---------------- 订单 ---------------- */

    public static function byId($id) {
        return self::one('SELECT * FROM orders WHERE id=?', array((int)$id));
    }

    public static function byNo($orderNo) {
        return self::one('SELECT id FROM orders WHERE order_no=?', array($orderNo));
    }

    public static function byVisit($visitId, $statusFilter = '') {
        $sql = 'SELECT * FROM orders WHERE visit_id=?';
        $params = array((int)$visitId);
        if ($statusFilter !== '') { $sql .= " AND $statusFilter"; }
        $sql .= ' ORDER BY id DESC';
        return self::q($sql, $params);
    }

    public static function byDoctorDate($doctorId, $date) {
        return self::q(
            "SELECT order_type, COALESCE(SUM(total_amount),0) s FROM orders WHERE doctor_id=? AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=? GROUP BY order_type",
            array((int)$doctorId, $date)
        );
    }

    /** 插入订单，返回自增 id */
    public static function create($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO orders($cols) VALUES($phs)", array_values($data));
    }

    public static function update($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE orders SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    public static function deleteOrder($id) {
        return self::exec('DELETE FROM orders WHERE id=?', array((int)$id));
    }

    /* ---------------- 明细 ---------------- */

    public static function itemsByOrder($orderId) {
        return self::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array((int)$orderId));
    }

    public static function itemsByVisit($visitId) {
        return self::q('SELECT * FROM order_items WHERE visit_id=? ORDER BY id', array((int)$visitId));
    }

    public static function itemById($id) {
        return self::one('SELECT * FROM order_items WHERE id=?', array((int)$id));
    }

    public static function insertItem($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO order_items($cols) VALUES($phs)", array_values($data));
    }

    public static function updateItem($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE order_items SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    public static function deleteItemsByOrder($orderId) {
        return self::exec('DELETE FROM order_items WHERE order_id=?', array((int)$orderId));
    }

    /* ---------------- 项目字典（检验/检查/处置） ---------------- */

    public static function labItems($status = '') {
        $sql = 'SELECT * FROM lab_items';
        $params = array();
        if ($status !== '') { $sql .= " WHERE status=?"; $params[] = $status; }
        $sql .= ' ORDER BY id';
        return self::q($sql, $params);
    }

    public static function labItemById($id) {
        return self::one('SELECT * FROM lab_items WHERE id=?', array((int)$id));
    }

    public static function examItems($status = '') {
        $sql = 'SELECT * FROM exam_items';
        $params = array();
        if ($status !== '') { $sql .= " WHERE status=?"; $params[] = $status; }
        $sql .= ' ORDER BY id';
        return self::q($sql, $params);
    }

    public static function examItemById($id) {
        return self::one('SELECT * FROM exam_items WHERE id=?', array((int)$id));
    }

    public static function disposalItems($status = '') {
        $sql = 'SELECT * FROM disposal_items';
        $params = array();
        if ($status !== '') { $sql .= " WHERE status=?"; $params[] = $status; }
        $sql .= ' ORDER BY id';
        return self::q($sql, $params);
    }

    public static function disposalItemById($id) {
        return self::one('SELECT * FROM disposal_items WHERE id=?', array((int)$id));
    }

    public static function categories($ctype = '') {
        $sql = 'SELECT * FROM item_categories';
        $params = array();
        if ($ctype !== '') { $sql .= " WHERE ctype=?"; $params[] = $ctype; }
        $sql .= ' ORDER BY sort, id';
        return self::q($sql, $params);
    }

    /** 检验组合成员（组合项目 id → 成员列表） */
    public static function labGroupMembers($groupId) {
        return self::q('SELECT item_id FROM lab_group_members WHERE group_id=?', array((int)$groupId));
    }

    /* ---------------- 结果 / 报告 ---------------- */

    public static function resultsByVisit($visitId) {
        return self::q('SELECT * FROM results WHERE visit_id=? ORDER BY id', array((int)$visitId));
    }

    public static function reportIdsByResultIds($resultIds) {
        $ph = implode(',', array_fill(0, count($resultIds), '?'));
        return self::q("SELECT result_id, MAX(id) AS rid FROM reports WHERE result_id IN ($ph) AND status<>'withdrawn' GROUP BY result_id", $resultIds);
    }

    /* ---------------- 统计聚合 ---------------- */

    /** 今日处置执行数 */
    public static function todayDisposalDone($today) {
        return (int)self::val("SELECT COUNT(*) FROM order_items WHERE item_type='procedure' AND status='done' AND date(executed_at)=?", array($today));
    }

    /** 待执行处置数 */
    public static function pendingDisposal() {
        return (int)self::val("SELECT COUNT(*) FROM order_items WHERE item_type='procedure' AND status='paid'");
    }

    /** 今日处置收入 */
    public static function todayDisposalFee($today) {
        return (float)self::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_type='procedure' AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today));
    }
}