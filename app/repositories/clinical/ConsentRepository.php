<?php
/**
 * ============================================================
 * ConsentRepository.php — 知情同意书仓库
 * ============================================================
 * 说明：封装知情同意书（开具/列表/详情/删除/已开具校验）相关 SQL，
 * 统一经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class ConsentRepository extends BaseRepository {

    /** 按 id 取同意书 */
    public static function byId($id) {
        return self::one('SELECT * FROM consents WHERE id=?', array((int)$id));
    }

    /** 该就诊全部同意书（按开具时间倒序） */
    public static function byVisit($visitId) {
        return self::q('SELECT * FROM consents WHERE visit_id=? ORDER BY id DESC', array((int)$visitId));
    }

    /** 该就诊某类同意书数量（防重复开具） */
    public static function countByVisitTitle($visitId, $title) {
        return (int)self::val('SELECT COUNT(*) FROM consents WHERE visit_id=? AND title=?', array((int)$visitId, $title));
    }

    /** 新增同意书 */
    public static function create($data) {
        return self::insertRow('consents', $data);
    }

    /** 更新同意书（编辑重存：刷新正文与病历快照） */
    public static function update($id, $data) {
        return self::updateRow('consents', $id, $data);
    }

    /** 删除同意书 */
    public static function remove($id) {
        return self::exec('DELETE FROM consents WHERE id=?', array((int)$id));
    }
}