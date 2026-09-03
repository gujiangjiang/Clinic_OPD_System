<?php
/**
 * ============================================================
 * ConsultationRepository.php — 会诊仓库
 * ============================================================
 * 说明：原查询/状态流转方法（byId/byCode/byVisit/activeByVisit/
 * doingByVisit/byTargetDeptSince/updateStatus/countByConsultNo/
 * updateConsultNo）全库无调用，会诊业务均以内联 SQL 直调继承的
 * q/one/val/exec/insert。仅保留仍被引用的 statusById，
 * 避免双份 SQL 维护。
 * ============================================================ */
class ConsultationRepository extends BaseRepository {

    /** 会诊状态 */
    public static function statusById($id) {
        return self::one('SELECT status FROM consultations WHERE id=?', array((int)$id));
    }
}
