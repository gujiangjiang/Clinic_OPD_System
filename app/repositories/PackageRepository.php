<?php
/**
 * ============================================================
 * PackageRepository.php — 快速开单套餐仓库
 * ============================================================
 * 说明：封装快速开单套餐（packages / package_depts）的 CRUD
 * 与适用范围（科室多对多）相关 SQL，统一经主库
 * DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class PackageRepository extends BaseRepository {

    /** 按 id 取套餐 */
    public static function byId($id) {
        return self::one('SELECT * FROM packages WHERE id=?', array((int)$id));
    }

    /** 套餐列表（按类型/范围/关键字过滤） */
    public static function search($where, $params, $order, $limit = 0) {
        $sql = 'SELECT * FROM packages WHERE ' . $where . ' ORDER BY ' . $order;
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
        return self::q($sql, $params);
    }

    /** 新增套餐 */
    public static function create($data) {
        return self::insertRow('packages', $data);
    }

    /** 更新套餐 */
    public static function update($id, $data) {
        return self::updateRow('packages', $id, $data);
    }

    /** 删除套餐（含适用范围） */
    public static function remove($id) {
        self::exec('DELETE FROM packages WHERE id=?', array((int)$id));
        return self::exec('DELETE FROM package_depts WHERE package_id=?', array((int)$id));
    }

    /** 套餐适用范围（科室 id 列表） */
    public static function deptIdsOf($packageId) {
        $rows = self::q('SELECT dept_id FROM package_depts WHERE package_id=?', array((int)$packageId));
        $out = array();
        foreach ($rows as $r) { $out[] = (int)$r['dept_id']; }
        return $out;
    }

    /** 重置适用范围并批量写入 */
    public static function replaceDepts($packageId, $deptIds) {
        self::exec('DELETE FROM package_depts WHERE package_id=?', array((int)$packageId));
        $seen = array();
        foreach ((array)$deptIds as $deptId) {
            $deptId = (int)$deptId;
            if ($deptId <= 0 || isset($seen[$deptId])) continue;
            $seen[$deptId] = true;
            self::insert('INSERT INTO package_depts(package_id, dept_id) VALUES(?,?)', array((int)$packageId, $deptId));
        }
    }
}