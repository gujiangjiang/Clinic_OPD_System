<?php
/**
 * ============================================================
 * UserRepository.php — 系统用户仓库
 * ============================================================
 * 覆盖：users 表的登录校验数据、角色权限、工号/科室关联、
 * 会话快照字段的读取与更新。
 * ============================================================ */
class UserRepository extends BaseRepository {

    /** 当前科室 */
    public static function currentDept($id) {
        return self::one('SELECT current_dept_id FROM users WHERE id=?', array((int)$id));
    }

    /** 更新当前科室 */
    public static function updateCurrentDept($id, $deptId) {
        self::exec('UPDATE users SET current_dept_id=? WHERE id=?', array((int)$deptId, (int)$id));
    }

    /** 医生信息（大屏/工作站展示用） */
    public static function doctorProfile($id) {
        return self::one('SELECT name, emp_no, title, intro, photo FROM users WHERE id=?', array((int)$id));
    }
}