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

    /** 设置项查重 */
    public static function settingByName($stype, $name) {
        return self::one("SELECT id FROM drug_settings WHERE stype=? AND name=?", array($stype, $name));
    }

    /** 新增设置项 */
    public static function createSetting($stype, $name, $isNurse = 0) {
        return self::insert("INSERT INTO drug_settings(stype, name, is_nurse, sort) VALUES(?,?,?,0)", array($stype, $name, (int)$isNurse));
    }
}