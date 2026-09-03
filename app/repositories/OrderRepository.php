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

    /* ---------------- 明细 ---------------- */

    public static function itemsByOrder($orderId) {
        return self::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array((int)$orderId));
    }

    public static function itemById($id) { return self::findById('order_items', $id); }

    public static function updateItem($id, $data) { return self::updateRow('order_items', $id, $data); }

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

    /** 护士站待处置列表（勾选护士执行 + 已缴费）——deptIds 非空时按就诊科室过滤 */
    public static function nurseTreatments($limit = 100, $deptIds = array()) {
        $sql = "SELECT * FROM order_items WHERE item_type='procedure' AND is_nurse=1 AND status='paid'";
        $params = array();
        if ($deptIds) {
            $ph = implode(',', array_fill(0, count($deptIds), '?'));
            $sql .= " AND visit_id IN (SELECT id FROM registrations WHERE current_dept_id IN ($ph))";
            $params = $deptIds;
        }
        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;
        return self::q($sql, $params);
    }

    /** 护士站待执行医嘱（护士执行处方，待执行/执行中）——deptIds 非空时按就诊科室过滤 */
    public static function nurseMedOrders($limit = 100, $deptIds = array()) {
        $sql = "SELECT oi.*, o.order_no, o.doctor_name AS odoc
             FROM order_items oi JOIN orders o ON o.id=oi.order_id
             WHERE oi.item_type='prescription' AND oi.is_nurse=1 AND oi.status IN ('paid','dispensing')";
        $params = array();
        if ($deptIds) {
            $ph = implode(',', array_fill(0, count($deptIds), '?'));
            $sql .= " AND oi.visit_id IN (SELECT id FROM registrations WHERE current_dept_id IN ($ph))";
            $params = $deptIds;
        }
        $sql .= " ORDER BY oi.id DESC LIMIT " . (int)$limit;
        return self::q($sql, $params);
    }
}