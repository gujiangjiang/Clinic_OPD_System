<?php
/**
 * ============================================================
 * SkinTestRepository.php — 皮试结果仓库
 * ============================================================
 * 说明：封装皮试结果（skin_test_results：阳性/阴性，药房/护士站
 * 皮试单回执）相关 SQL，统一经主库 DatabaseManager::getMain()
 * 预编译参数绑定。
 * ============================================================ */
class SkinTestRepository extends BaseRepository {

    /** 按就诊查询皮试结果（正序，按时间流展示） */
    public static function byVisit($visitId) {
        return self::q('SELECT * FROM skin_test_results WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    }

    /** 该就诊某药品皮试结果（判阴/判阳/重复执行） */
    public static function latestByVisitDrug($visitId, $drugId) {
        return self::one('SELECT * FROM skin_test_results WHERE visit_id=? AND drug_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$drugId));
    }

    /** 新增皮试结果 */
    public static function create($data) {
        return self::insertRow('skin_test_results', $data);
    }
}