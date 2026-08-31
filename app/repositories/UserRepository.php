<?php
/**
 * ============================================================
 * UserRepository.php — 系统用户仓库
 * ============================================================
 * 覆盖：users 表的登录校验数据、角色权限、工号/科室关联、
 * 会话快照字段的读取与更新。
 * ============================================================ */
class UserRepository extends BaseRepository {

    /** 按用户名或工号查用户（登录） */
    public static function byUsernameOrEmpNo($account) {
        $u = self::one('SELECT * FROM users WHERE username=? AND status=1', array($account));
        if (!$u) {
            $u = self::one('SELECT * FROM users WHERE emp_no=? AND status=1', array($account));
        }
        return $u;
    }

    /** 按 id 查用户 */
    public static function byId($id) {
        return self::one('SELECT * FROM users WHERE id=?', array((int)$id));
    }

    /** 用户是否存在（安装检查） */
    public static function anyExists() {
        return (int)self::val('SELECT COUNT(*) FROM users') > 0;
    }

    /** 用户列表（管理端） */
    public static function all($role = '') {
        $sql = 'SELECT * FROM users';
        $params = array();
        if ($role !== '') { $sql .= ' WHERE role=?'; $params[] = $role; }
        $sql .= ' ORDER BY id';
        return self::q($sql, $params);
    }

    /** 按角色查用户（含 dept_ids/工号，供科室/叫号关联） */
    public static function byRole($role) {
        return self::q('SELECT id, name, emp_no, dept_ids, title FROM users WHERE role=?', array($role));
    }

    /** 新增用户 */
    public static function create($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO users($cols) VALUES($phs)", array_values($data));
    }

    /** 更新用户 */
    public static function update($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }

    /** 删除用户 */
    public static function deleteUser($id) {
        return self::exec('DELETE FROM users WHERE id=?', array((int)$id));
    }

    /** 当前科室 */
    public static function currentDept($id) {
        return self::one('SELECT current_dept_id FROM users WHERE id=?', array((int)$id));
    }

    /** 更新当前科室 */
    public static function updateCurrentDept($id, $deptId) {
        self::exec('UPDATE users SET current_dept_id=? WHERE id=?', array((int)$deptId, (int)$id));
    }

    /** 重置登录失败计数并记录登录时间 */
    public static function markLoginSuccess($id) {
        self::exec('UPDATE users SET login_fail_count=0, login_locked_until=NULL, last_login=? WHERE id=?', array(now_str(), (int)$id));
    }

    /** 记录登录失败 */
    public static function markLoginFail($id, $failCount, $lockUntil = null) {
        self::exec('UPDATE users SET login_fail_count=?, login_locked_until=? WHERE id=?',
            array((int)$failCount, $lockUntil === null ? null : $lockUntil, (int)$id));
    }

    /** 医生信息（大屏/工作站展示用） */
    public static function doctorProfile($id) {
        return self::one('SELECT name, emp_no, title, intro, photo FROM users WHERE id=?', array((int)$id));
    }

    /** 批量用户元信息（工号/职称，按 id 列表） */
    public static function metaByIds($ids) {
        if (!$ids) return array();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return self::q("SELECT id, emp_no, title FROM users WHERE id IN ($ph)", array_values($ids));
    }
}