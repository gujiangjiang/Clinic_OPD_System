<?php
/**
 * ============================================================
 * DeptRepository.php — 科室与诊室仓库
 * ============================================================
 * 覆盖：departments（科室）、clinic_rooms（诊室/叫号大屏）的
 * 查询、新增、更新、删除与配置管理。
 * ============================================================ */
class DeptRepository extends BaseRepository {

    /* ---------------- 科室 ---------------- */

    /** 全部科室 */
    public static function all($status = '') {
        $sql = 'SELECT * FROM departments';
        $params = array();
        if ($status !== '') { $sql .= ' WHERE status=?'; $params[] = $status; }
        $sql .= ' ORDER BY sort, id';
        return self::q($sql, $params);
    }

    /** 科室详情 */
    public static function byId($id) {
        return self::one('SELECT * FROM departments WHERE id=?', array((int)$id));
    }

    /** 启用科室详情 */
    public static function activeById($id) {
        return self::one('SELECT * FROM departments WHERE id=? AND status=1', array((int)$id));
    }

    /** 科室名称列表（批量补名） */
    public static function namesByIds($ids) {
        if (!$ids) return array();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::q("SELECT id, name FROM departments WHERE id IN ($ph)", array_values($ids));
        $map = array();
        foreach ($rows as $r) $map[(int)$r['id']] = (string)$r['name'];
        return $map;
    }

    /** 新增科室 */
    public static function create($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO departments($cols) VALUES($phs)", array_values($data));
    }

    /** 更新科室 */
    public static function update($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE departments SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    /** 删除科室 */
    public static function deleteDept($id) {
        return self::exec('DELETE FROM departments WHERE id=?', array((int)$id));
    }

    /** 科室名查重 */
    public static function byName($name) {
        return self::one('SELECT id FROM departments WHERE name=?', array($name));
    }

    /* ---------------- 诊室 / 叫号大屏 ---------------- */

    /** 诊室列表（可选科室筛选） */
    public static function rooms($deptId = 0) {
        $sql = 'SELECT * FROM clinic_rooms';
        $params = array();
        if ((int)$deptId > 0) { $sql .= ' WHERE dept_id=?'; $params[] = (int)$deptId; }
        $sql .= ' ORDER BY id';
        return self::q($sql, $params);
    }

    /** 诊室详情 */
    public static function roomById($id) {
        return self::one('SELECT * FROM clinic_rooms WHERE id=?', array((int)$id));
    }

    /** 新增诊室 */
    public static function createRoom($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO clinic_rooms($cols) VALUES($phs)", array_values($data));
    }

    /** 更新诊室 */
    public static function updateRoom($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE clinic_rooms SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    /** 删除诊室 */
    public static function deleteRoom($id) {
        return self::exec('DELETE FROM clinic_rooms WHERE id=?', array((int)$id));
    }
}