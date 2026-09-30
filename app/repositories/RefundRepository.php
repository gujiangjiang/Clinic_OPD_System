<?php
/**
 * ============================================================
 * RefundRepository.php — 退费仓库
 * ============================================================
 * 说明：封装退费申请审批流（refund_requests / refund_approvals）
 * 与退费流水（refunds）相关 SQL，统一经主库 DatabaseManager::getMain()
 * 预编译参数绑定。缴费流水写入见 CashierRepository::createPayment。
 * ============================================================ */
class RefundRepository extends BaseRepository {

    /** 按 id 取退费申请 */
    public static function requestById($id) {
        return self::one('SELECT * FROM refund_requests WHERE id=?', array((int)$id));
    }

    /** 新增退费申请 */
    public static function insertRequest($data) {
        return self::insertRow('refund_requests', $data);
    }

    /** 更新退费申请状态 */
    public static function updateRequestStatus($id, $status) {
        return self::exec('UPDATE refund_requests SET status=? WHERE id=?', array($status, (int)$id));
    }

    /** 该申请的全部审批意见（按时间正序） */
    public static function approvalsOf($requestId) {
        return self::q('SELECT * FROM refund_approvals WHERE request_id=? ORDER BY id ASC', array((int)$requestId));
    }

    /** 新增审批意见 */
    public static function insertApproval($data) {
        return self::insertRow('refund_approvals', $data);
    }

    /** 该申请是否已全部审批完毕（pending 意见数） */
    public static function pendingApprovalCount($requestId) {
        return (int)self::val("SELECT COUNT(*) FROM refund_approvals WHERE request_id=? AND verdict='pending'", array((int)$requestId));
    }

    /** 退费流水（按缴费批次号） */
    public static function refundsByPayment($paymentNo) {
        return self::q('SELECT * FROM refunds WHERE payment_no=? ORDER BY id DESC', array($paymentNo));
    }
}