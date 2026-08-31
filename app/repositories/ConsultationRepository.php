<?php
/**
 * ============================================================
 * ConsultationRepository.php — 会诊仓库
 * ============================================================
 * 覆盖：consultations（会诊主表）的查询与状态流转。
 * ============================================================ */
class ConsultationRepository extends BaseRepository {

    /** 按 id 查会诊 */
    public static function byId($id) {
        return self::one('SELECT * FROM consultations WHERE id=?', array((int)$id));
    }

    /** 会诊详情（oid code 解码后） */
    public static function byCode($code) {
        $id = did($code);
        return $id > 0 ? self::byId($id) : null;
    }

    /** 按就诊查会诊（升序） */
    public static function byVisit($visitId) {
        return self::q('SELECT * FROM consultations WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    }

    /** 就诊进行中会诊（pending/doing） */
    public static function activeByVisit($visitId) {
        return self::q("SELECT * FROM consultations WHERE visit_id=? AND status IN ('pending','doing')", array((int)$visitId));
    }

    /** 就诊进行中的单条会诊（done 之外取最新） */
    public static function doingByVisit($visitId) {
        return self::one("SELECT id FROM consultations WHERE visit_id=? AND status='doing' LIMIT 1", array((int)$visitId));
    }

    /** 会诊状态 */
    public static function statusById($id) {
        return self::one('SELECT status FROM consultations WHERE id=?', array((int)$id));
    }

    /** 目标科室近 N 天会诊列表 */
    public static function byTargetDeptSince($deptId, $since) {
        return self::q(
            "SELECT id, visit_id, status, created_at, accepted_by, record_id FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC",
            array((int)$deptId, $since)
        );
    }

    /** 更新会诊状态 */
    public static function updateStatus($id, $status, $extra = array()) {
        $set = array('status=?');
        $params = array($status);
        if (isset($extra['accepted_by'])) { $set[] = 'accepted_by=?'; $params[] = $extra['accepted_by']; }
        if (isset($extra['accepted_at'])) { $set[] = 'accepted_at=?'; $params[] = $extra['accepted_at']; }
        if (isset($extra['finished_at'])) { $set[] = 'finished_at=?'; $params[] = $extra['finished_at']; }
        $params[] = (int)$id;
        return self::exec('UPDATE consultations SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    /** 会诊单号查重 */
    public static function countByConsultNo($no) {
        return (int)self::val('SELECT COUNT(*) FROM consultations WHERE consult_no=?', array($no));
    }

    /** 补充会诊单号 */
    public static function updateConsultNo($id, $no) {
        self::exec('UPDATE consultations SET consult_no=? WHERE id=?', array($no, (int)$id));
    }
}