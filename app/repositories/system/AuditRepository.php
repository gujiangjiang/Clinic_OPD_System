<?php
/**
 * ============================================================
 * AuditRepository.php — 审核记录仓库
 * ============================================================
 * 说明：封装审核记录（audits：药品/项目/模板/资料变更提交审核，
 * 以及 profile 变更等审批流）相关 SQL，统一经主库
 * DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class AuditRepository extends BaseRepository {

    /** 写入审核记录 */
    public static function create($data) {
        $cols = array();
        $params = array();
        foreach ($data as $k => $v) { $cols[] = $k; $params[] = $v; }
        $sql = 'INSERT INTO audits(' . implode(',', $cols) . ') VALUES(' . in_placeholders($params) . ')';
        return self::insert($sql, $params);
    }

    /** 按 id 取审核记录 */
    public static function byId($id) {
        return self::one('SELECT * FROM audits WHERE id=?', array((int)$id));
    }

    /** 某业务实体的最近一条待审记录（重复提交合并用） */
    public static function latestPendingByRef($type, $refId) {
        return self::one("SELECT * FROM audits WHERE type=? AND ref_id=? AND status='pending' ORDER BY id DESC LIMIT 1", array($type, (int)$refId));
    }
}