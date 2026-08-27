<?php
/**
 * ============================================================
 * Auth.php v1.0.0 — 用户认证与角色权限
 * ============================================================
 * 说明：
 * 1. 登录验证（password_hash / password_verify，bcrypt）
 * 2. 登录成功重置会话 ID 防会话固定
 * 3. 当前用户信息保存在会话快照（含角色），供权限校验
 * 4. 角色：admin 管理员 / cashier 挂号收费 / doctor 医生 /
 *    nurse 护士 / lab 检验 / imaging 影像 / pharmacy 药房
 * ============================================================ */
class Auth {

    /** 获取当前登录用户（会话快照；未登录返回 null） */
    public static function user() {
        return isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null;
    }

    /** 当前用户 ID */
    public static function id() {
        $u = self::user();
        return $u ? (int)$u['id'] : 0;
    }

    /** 是否已登录 */
    public static function check() {
        return self::user() !== null;
    }

    /**
     * 登录校验
     * @return bool|string true 成功；字符串为错误提示
     */
    public static function login($account, $password) {
        if ($account === '' || $password === '') {
            return '请输入用户名和密码';
        }
        // 支持用户名或工号登录：用户名优先精确匹配；
        // 用户名强制英文字母开头（见用户管理保存校验），与纯数字工号天然不冲突，
        // 即使工号含字母也因用户名优先而保持确定性。
        $u = DB::one('user', 'SELECT * FROM users WHERE username=? AND status=1', array($account));
        if (!$u) {
            $u = DB::one('user', 'SELECT * FROM users WHERE emp_no=? AND status=1', array($account));
        }
        if (!$u) {
            return '用户名/工号或密码错误';
        }
        // 登录锁定检查：连续失败 5 次锁定 15 分钟
        $lockUntil = isset($u['login_locked_until']) ? $u['login_locked_until'] : '';
        if ($lockUntil !== '' && strtotime($lockUntil) > time()) {
            $remain = ceil((strtotime($lockUntil) - time()) / 60);
            return '账号已被锁定，请 ' . $remain . ' 分钟后再试';
        }
        // 锁定时间已过则自动解锁
        if ($lockUntil !== '' && strtotime($lockUntil) <= time()) {
            DB::exec('user', 'UPDATE users SET login_fail_count=0, login_locked_until=NULL WHERE id=?', array((int)$u['id']));
        }
        if (!password_verify($password, $u['password'])) {
            // 密码错误：递增失败计数，连续 5 次锁定 15 分钟
            $newCount = (int)$u['login_fail_count'] + 1;
            $lockedUntil = $newCount >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            DB::exec('user', 'UPDATE users SET login_fail_count=?, login_locked_until=? WHERE id=?',
                array($newCount, $lockedUntil, (int)$u['id']));
            return '用户名/工号或密码错误';
        }
        // 登录成功：重置失败计数
        DB::exec('user', 'UPDATE users SET login_fail_count=0, login_locked_until=NULL, last_login=? WHERE id=?', array(now_str(), (int)$u['id']));
        // 防会话固定：登录后重置会话 ID
        session_regenerate_id(true);
        $_SESSION['auth_user'] = array(
            'id'       => (int)$u['id'],
            'username' => $u['username'],
            'name'     => $u['name'],
            'role'     => $u['role'],
            'dept_ids' => $u['dept_ids'],
            'photo'    => $u['photo'],
            'theme'    => $u['theme'] ? $u['theme'] : 'auto',
            'sidebar'  => isset($u['sidebar']) && $u['sidebar'] ? $u['sidebar'] : 'expand',
        );
        return true;
    }

    /** 退出登录 */
    public static function logout() {
        // 退出前释放绑定的诊室大屏：医生退出登录后，叫号大屏自动取消关联
        $u = self::user();
        if ($u) {
            DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE current_doctor_id=?',
                array(now_str(), (int)$u['id']));
        }
        unset($_SESSION['auth_user']);
        session_regenerate_id(true);
    }

    /** 是否具备指定角色（admin 拥有全部角色权限，可访问所有功能） */
    public static function isRole($role) {
        $u = self::user();
        return $u && ($u['role'] === $role || $u['role'] === 'admin');
    }

    /** 当前用户主题偏好（auto/light/dark） */
    public static function theme() {
        $u = self::user();
        return $u ? ($u['theme'] ? $u['theme'] : 'auto') : 'auto';
    }

    /** 当前用户侧边栏偏好（expand 展开 / mini 缩小仅图标），旧会话无此字段时回退 expand */
    public static function sidebar() {
        $u = self::user();
        return ($u && isset($u['sidebar']) && $u['sidebar'] === 'mini') ? 'mini' : 'expand';
    }

    /** 更新会话快照中的字段（如修改主题/姓名后即时生效） */
    public static function updateSession($field, $value) {
        if (isset($_SESSION['auth_user'])) {
            $_SESSION['auth_user'][$field] = $value;
        }
    }

    /** 按角色返回默认首页（登录后跳转目标） */
    public static function home() {
        $u = self::user();
        if (!$u) return '/login';
        $map = array(
            'admin'    => '/admin/dashboard',
            'cashier'  => '/cashier/home',
            'doctor'   => '/doctor/home',
            'nurse'    => '/nurse/home',
            'lab'      => '/lab/home',
            'imaging'  => '/imaging/home',
            'pharmacy' => '/pharmacy/home',
        );
        return isset($map[$u['role']]) ? $map[$u['role']] : '/login';
    }

    /** 角色中文名 */
    public static function roleName($role) {
        $map = array(
            'admin'    => '系统管理员',
            'cashier'  => '挂号收费员',
            'doctor'   => '医生',
            'nurse'    => '护士',
            'lab'      => '检验技师',
            'imaging'  => '影像技师',
            'pharmacy' => '药剂师',
        );
        return isset($map[$role]) ? $map[$role] : $role;
    }
}
