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
        // $statusFilter 仅限内部调用的常量字符串（如 "status IN ('paid','visiting')"），
        // 禁止传入外部输入。如需要动态过滤请改用参数化查询。
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
        return self::insertRow('orders', $data);
    }

    public static function update($id, $data) {
        return self::updateRow('orders', $id, $data);
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

    public static function itemById($id) { return self::findById('order_items', $id); }

    public static function insertItem($data) { return self::insertRow('order_items', $data); }

    public static function updateItem($id, $data) { return self::updateRow('order_items', $id, $data); }

    public static function deleteItemsByOrder($orderId) {
        return self::exec('DELETE FROM order_items WHERE order_id=?', array((int)$orderId));
    }

    /* ---------------- 项目字典（检验/检查/处置） ---------------- */

    public static function labItems($status = '') {
        return self::findAllByField('lab_items', 'status', $status, 'id');
    }

    public static function labItemById($id) { return self::findById('lab_items', $id); }

    public static function examItems($status = '') {
        return self::findAllByField('exam_items', 'status', $status, 'id');
    }

    public static function examItemById($id) { return self::findById('exam_items', $id); }

    public static function disposalItems($status = '') {
        return self::findAllByField('disposal_items', 'status', $status, 'id');
    }

    public static function disposalItemById($id) { return self::findById('disposal_items', $id); }

    public static function categories($ctype = '') {
        return self::findAllByField('item_categories', 'ctype', $ctype, 'sort, id');
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

    /** 护士站待处置列表（勾选护士执行 + 已缴费） */
    public static function nurseTreatments($limit = 100) {
        return self::q("SELECT * FROM order_items WHERE item_type='procedure' AND is_nurse=1 AND status='paid' ORDER BY id DESC LIMIT " . (int)$limit);
    }

    /** 护士站待执行医嘱（护士执行处方，待执行/执行中） */
    public static function nurseMedOrders($limit = 100) {
        return self::q(
            "SELECT oi.*, o.order_no, o.doctor_name AS odoc
             FROM order_items oi JOIN orders o ON o.id=oi.order_id
             WHERE oi.item_type='prescription' AND oi.is_nurse=1 AND oi.status IN ('paid','dispensing')
             ORDER BY oi.id DESC LIMIT " . (int)$limit
        );
    }

    /** 医嘱子处方（同订单同组非主药） */
    public static function itemsByOrderGroup($orderId, $groupNo) {
        return self::q('SELECT * FROM order_items WHERE order_id=? AND group_no=? AND is_parent=0 ORDER BY id', array((int)$orderId, (int)$groupNo));
    }

    /** 今日处方发药数 */
    public static function todayDispensedCount($today) {
        return (int)self::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND status='dispensed' AND date(executed_at)=?", array($today));
    }

    /** 待发药处方数 */
    public static function pendingRxCount() {
        return (int)self::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND status='paid'");
    }
}