<?php
/**
 * ============================================================
 * EmrTemplateRepository.php — 病历模板仓库
 * ============================================================
 * 说明：封装病历模板库（emr_templates / emr_template_depts）的
 * CRUD 与适用范围（科室多对多）相关 SQL，统一经主库
 * DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class EmrTemplateRepository extends BaseRepository {

    /** 按 id 取模板 */
    public static function byId($id) {
        return self::one('SELECT * FROM emr_templates WHERE id=?', array((int)$id));
    }

    /** 模板列表（按类型/范围/状态/关键字过滤） */
    public static function search($where, $params, $order, $limit = 0) {
        $sql = 'SELECT * FROM emr_templates WHERE ' . $where . ' ORDER BY ' . $order;
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
        return self::q($sql, $params);
    }

    /** 模板计数 */
    public static function countRows($where, $params) {
        return (int)self::val('SELECT COUNT(*) FROM emr_templates WHERE ' . $where, $params);
    }

    /** 新增模板 */
    public static function create($data) {
        return self::insertRow('emr_templates', $data);
    }

    /** 更新模板 */
    public static function update($id, $data) {
        return self::updateRow('emr_templates', $id, $data);
    }

    /** 删除模板（含适用范围） */
    public static function remove($id) {
        self::exec('DELETE FROM emr_templates WHERE id=?', array((int)$id));
        return self::exec('DELETE FROM emr_template_depts WHERE template_id=?', array((int)$id));
    }

    /** 模板适用范围（科室 id 列表） */
    public static function deptIdsOf($templateId) {
        $rows = self::q('SELECT dept_id FROM emr_template_depts WHERE template_id=?', array((int)$templateId));
        $out = array();
        foreach ($rows as $r) { $out[] = (int)$r['dept_id']; }
        return $out;
    }

    /** 重置适用范围并批量写入 */
    public static function replaceDepts($templateId, $deptIds) {
        self::exec('DELETE FROM emr_template_depts WHERE template_id=?', array((int)$templateId));
        foreach ((array)$deptIds as $deptId) {
            $deptId = (int)$deptId;
            if ($deptId <= 0) continue;
            self::insert('INSERT OR IGNORE INTO emr_template_depts(template_id, dept_id) VALUES(?,?)', array((int)$templateId, $deptId));
        }
    }
}