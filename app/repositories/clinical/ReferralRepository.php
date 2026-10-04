<?php
/**
 * ============================================================
 * ReferralRepository.php — 转科记录仓库
 * ============================================================
 * 说明：封装转科（转诊）记录相关 SQL，统一经主库
 * DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class ReferralRepository extends BaseRepository {

    /** 新增转科记录 */
    public static function create($data) {
        return self::insertRow('referrals', $data);
    }

    /** 该就诊转科历史（倒序） */
    public static function byVisit($visitId) {
        return self::q('SELECT * FROM referrals WHERE visit_id=? ORDER BY id DESC', array((int)$visitId));
    }

    /** 按 id 取转科记录 */
    public static function byId($id) {
        return self::one('SELECT * FROM referrals WHERE id=?', array((int)$id));
    }
}