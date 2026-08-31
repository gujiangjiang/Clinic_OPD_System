<?php
/**
 * ============================================================
 * DrugRepository.php — 药品仓库
 * ============================================================
 * 覆盖：drugs（药品信息）、drug_settings（分类/单位/剂型/频次/途径）、
 * inventory_trans（库存流水）的查询与库存变动。
 * ============================================================ */
class DrugRepository extends BaseRepository {

    /** 药品详情 */
    public static function byId($id) {
        return self::findById('drugs', $id);
    }

    /** 按名称精确查（唯一性/查重用） */
    public static function byName($name) {
        return self::one('SELECT id FROM drugs WHERE name=?', array($name));
    }

    /** 已审核药品列表（可选分类筛选） */
    public static function approved($category = '') {
        $sql = "SELECT * FROM drugs WHERE status='approved'";
        $params = array();
        if ($category !== '') { $sql .= " AND category=?"; $params[] = $category; }
        $sql .= ' ORDER BY category, id';
        return self::q($sql, $params);
    }

    /** 全部药品（管理/药房列表） */
    public static function all($category = '') {
        $sql = 'SELECT * FROM drugs';
        $params = array();
        if ($category !== '') { $sql .= " WHERE category=?"; $params[] = $category; }
        $sql .= ' ORDER BY category, id';
        return self::q($sql, $params);
    }

    /** 新增药品 */
    public static function create($data) {
        return self::insertRow('drugs', $data);
    }

    /** 更新药品 */
    public static function update($id, $data) {
        return self::updateRow('drugs', $id, $data);
    }

    /** 库存扣减（原子条件更新防并发竞态）：仅库存充足时扣减，返回影响行数 */
    public static function deductStock($drugId, $qty) {
        return self::exec('UPDATE drugs SET qty = qty - ? WHERE id=? AND qty >= ?', array((int)$qty, (int)$drugId, (int)$qty));
    }

    /** 库存恢复（退费/入库） */
    public static function restoreStock($drugId, $qty) {
        return self::exec('UPDATE drugs SET qty = qty + ? WHERE id=?', array((int)$qty, (int)$drugId));
    }

    /** 库存流水 */
    public static function createInventoryTrans($drugId, $qtyChange, $type, $ref, $operator) {
        return self::insert('INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)',
            array((int)$drugId, (int)$qtyChange, $type, $ref, $operator, now_str()));
    }

    /* ---------------- 药品设置字典 ---------------- */

    /** 设置项列表（分类/包装单位/剂型/频次/途径） */
    public static function settingsByType($stype) {
        return self::q('SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
    }

    /** 设置项查重 */
    public static function settingByName($stype, $name) {
        return self::one("SELECT id FROM drug_settings WHERE stype=? AND name=?", array($stype, $name));
    }

    /** 新增设置项 */
    public static function createSetting($stype, $name, $isNurse = 0) {
        return self::insert("INSERT INTO drug_settings(stype, name, is_nurse, sort) VALUES(?,?,?,0)", array($stype, $name, (int)$isNurse));
    }

    /** 途径 → 绑定计费处置查询 */
    public static function routeBindDisposal($routeName) {
        return self::one("SELECT bind_disposal_item_id FROM drug_settings WHERE stype='route' AND name=? LIMIT 1", array($routeName));
    }
}