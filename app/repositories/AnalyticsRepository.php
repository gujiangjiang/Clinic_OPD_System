<?php
/**
 * ============================================================
 * AnalyticsRepository.php — 运营统计分析仓库
 * ============================================================
 * 覆盖：挂号/缴费/开单/患者等运营维度的聚合统计 SQL，
 * 供管理端运营分析与各角色首页 KPI。
 * ============================================================ */
class AnalyticsRepository extends BaseRepository {

    /** 今日挂号数 */
    public static function regToday($today) {
        return (int)self::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status IN ('paid','visiting','finished')", array($today));
    }

    /** 今日候诊数 */
    public static function waitingToday($today) {
        return (int)self::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status='paid'", array($today));
    }

    /** 今日挂号费收入（payments kind=visit） */
    public static function visitFeeToday($today) {
        return (float)self::val("SELECT COALESCE(SUM(total),0) FROM payments WHERE kind='visit' AND date(created_at)=?", array($today));
    }

    /** 今日开单收入 */
    public static function orderFeeToday($today) {
        return (float)self::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today));
    }

    /** 今日退费金额 */
    public static function refundToday($today) {
        return (float)self::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='refunded' AND date(refunded_at)=?", array($today));
    }

    /** 待审核数 */
    public static function pendingAudits() {
        return (int)self::val("SELECT COUNT(*) FROM audits WHERE status='pending'");
    }

    /** 低库存药品数 */
    public static function lowStockDrugs() {
        return (int)self::val("SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty<=50");
    }

    /** 科室数 */
    public static function deptCount() {
        return (int)self::val('SELECT COUNT(*) FROM departments');
    }

    /** 用户数 */
    public static function userCount() {
        return (int)self::val('SELECT COUNT(*) FROM users');
    }

    /** 未读消息数 */
    public static function unreadMessages($role, $userId) {
        return (int)self::val('SELECT COUNT(*) FROM messages WHERE to_role=? AND to_user_id=? AND is_read=0', array($role, (int)$userId));
    }

    /** 近 N 天每日挂号趋势 */
    public static function regTrend($days) {
        $labels = array(); $data = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $data[] = (int)self::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status IN ('paid','visiting','finished')", array($day));
        }
        return array('labels' => $labels, 'data' => $data);
    }

    /** 近 N 天每日开单收入趋势 */
    public static function orderFeeTrend($days) {
        $labels = array(); $data = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $data[] = (float)self::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($day));
        }
        return array('labels' => $labels, 'data' => $data);
    }

    /** 近 N 天每日开单量趋势 */
    public static function orderCountTrend($days) {
        $labels = array(); $data = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $data[] = (int)self::val("SELECT COUNT(*) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($day));
        }
        return array('labels' => $labels, 'data' => $data);
    }

    /** 各科室挂号量分布（今日） */
    public static function regByDeptToday($today) {
        return self::q(
            "SELECT r.first_dept_id, r.first_dept_name, COUNT(*) AS c FROM registrations r
             WHERE date(r.registered_at)=? AND r.status IN ('paid','visiting','finished')
             GROUP BY r.first_dept_id, r.first_dept_name",
            array($today)
        );
    }

    /** 各医生接诊量（近 N 天） */
    public static function visitByDoctor($days) {
        $since = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        return self::q(
            "SELECT doctor_id, doctor_name, COUNT(*) AS c FROM patient_records WHERE date(created_at)>=? GROUP BY doctor_id, doctor_name",
            array($since)
        );
    }

    /** 转归分布 */
    public static function dispositionDist() {
        return self::q("SELECT disposition, COUNT(*) AS c FROM registrations WHERE disposition<>'' GROUP BY disposition");
    }

    /** 各类型开单金额（近 N 天） */
    public static function orderTypeFee($days) {
        $since = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        return self::q(
            "SELECT order_type, COALESCE(SUM(total_amount),0) AS s FROM orders
             WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)>=?
             GROUP BY order_type",
            array($since)
        );
    }

    /** 开单按医生聚合（近 N 天） */
    public static function orderByDoctor($days) {
        $since = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        return self::q(
            "SELECT doctor_id, doctor_name, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s FROM orders
             WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)>=?
             GROUP BY doctor_id, doctor_name",
            array($since)
        );
    }
}