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
     * 实时校验当前会话用户仍存在且启用。
     * 会话快照可能滞后：管理员停用(status=0)或删除用户后，既有会话
     * 若不校验，其全部接口/页面仍可用。此处按用户 ID 实时读库，
     * 失活即强制登出并返回 false；同时将角色/关联科室实时同步回会话快照，
     * 保证管理员降权 / 移除科室授权后既有会话即时生效（不残留原权限）。
     * @return bool 是否仍为有效启用用户
     */
    public static function assertActive() {
        $u = self::user();
        if (!$u) return false;
        try {
            $row = DB::one('SELECT id, status, role, dept_ids FROM users WHERE id=?', array((int)$u['id']));
        } catch (Exception $ex) {
            return false;
        }
        if (!$row || (int)$row['status'] !== 1) {
            self::logout();
            return false;
        }
        // 权限字段实时刷新（角色 / 关联科室）
        foreach (array('role', 'dept_ids') as $f) {
            if ((string)(isset($u[$f]) ? $u[$f] : '') !== (string)(isset($row[$f]) ? $row[$f] : '')) {
                $_SESSION['auth_user'][$f] = $row[$f];
            }
        }
        return true;
    }

    /**
     * 登录校验（完整安全流程）：
     * ① 验证码前置（off/auto/force 三模式，比对即销毁防重放）→
     * ② 账号状态精准拦截（安全锁定 vs 管理员停用，区分话术、不比对密码不计数）→
     * ③ 密码校验与防爆破（原子递增失败计数，达阈值自动锁定 status=0 +
     *    lock_reason='password_error_locked' 并向管理员发送安全告警站内信）→
     * ④ 成功清零（失败计数/锁定归因/IP 失败频控/验证码状态）。
     *
     * @param string $account     用户名或工号
     * @param string $password    密码（原样，不 trim）
     * @param string $captchaCode 客户端提交的验证码（未提交为空串）
     * @param bool   $captchaFlag 客户端本地失败标记（前端 Storage 驱动）
     * @return bool|string true 成功；字符串为错误提示（已按场景精确区分）
     */
    public static function login($account, $password, $captchaCode = '', $captchaFlag = false) {
        if ($account === '' || $password === '') {
            return '请输入用户名和密码';
        }

        /* ==================== ① 验证码前置 ==================== */
        // auto 模式需嗅探目标用户历史失败次数（用户不存在则 0，不产生枚举信息——
        // 判定结果仅用于内部是否要求验证码，话术不区分该维度）
        $mode = LoginSecurity::mode();
        $userFail = 0;
        if ($mode === 'auto') {
            $probe = DB::one('SELECT login_fail_count FROM users WHERE username=?', array($account));
            if (!$probe) $probe = DB::one('SELECT login_fail_count FROM users WHERE emp_no=?', array($account));
            $userFail = $probe ? (int)$probe['login_fail_count'] : 0;
        }
        if (LoginSecurity::needCaptcha($mode, $captchaFlag, $userFail)) {
            // 比对即销毁（防重放：同一验证码无论对错仅可消耗一次）
            $ok = ($captchaCode !== '') ? LoginSecurity::captchaCheck($captchaCode) : false;
            if (!$ok) {
                LoginSecurity::ipFailRecord();
                return $captchaCode === '' ? '请先输入验证码' : '验证码错误或已过期，请刷新后重试';
            }
        }

        /* ==================== ② 账号查询与状态拦截 ==================== */
        // 支持用户名或工号登录：用户名优先精确匹配；
        // 用户名强制英文字母开头（见用户管理保存校验），与纯数字工号天然不冲突。
        // 注意：不再按 status=1 过滤——停用/锁定用户必须走精准话术拦截（③前阻断），
        // 且不进行密码比对、不累加错误次数（安全锁定/停用账号不是有效爆破目标）。
        $u = DB::one('SELECT * FROM users WHERE username=?', array($account));
        if (!$u) {
            $u = DB::one('SELECT * FROM users WHERE emp_no=?', array($account));
        }
        if (!$u) {
            // 情况 A：工号/用户名不存在——仅记录 IP/Session 失败频次，用户表无行可计
            LoginSecurity::ipFailRecord();
            return '用户名或密码错误';
        }
        if ((int)$u['status'] === 0) {
            // 情况：账号不可用——按 lock_reason 精准区分话术（不比对密码、不计数）
            if ((string)$u['lock_reason'] === 'password_error_locked') {
                return '该账号因密码连续输入错误过多已被系统安全锁定，请联系管理员协助解锁';
            }
            return '该账号已被管理员停用，无法登录';
        }

        /* ==================== ③ 密码校验与防爆破 ==================== */
        if (!password_verify($password, $u['password'])) {
            LoginSecurity::ipFailRecord();
            $threshold = LoginSecurity::lockCount();
            // 原子递增失败计数（避免并发登录请求的 read-modify-write 竞态覆盖计数）
            DB::exec('UPDATE users SET login_fail_count = login_fail_count + 1 WHERE id=?', array((int)$u['id']));
            $cnt = (int)DB::val('SELECT login_fail_count FROM users WHERE id=?', array((int)$u['id']));
            if ($cnt >= $threshold) {
                // 达到阈值：自动安全锁定（条件更新仅首个请求锁定成功——
                // 并发请求不会重复发送管理员告警站内信）
                $ip = LoginSecurity::clientIp();
                $now = now_str();
                $locked = DB::exec(
                    "UPDATE users SET status=0, lock_reason='password_error_locked', locked_at=?, lock_ip=?, login_locked_until=NULL
                     WHERE id=? AND status=1",
                    array($now, $ip, (int)$u['id'])
                );
                if ($locked) {
                    // 高优先级安全告警站内信 → 全体管理员（含直达解锁页面链接）
                    send_msg('admin', 0, '【安全告警】账号密码连续错误已自动锁定',
                        '用户「' . $u['name'] . '」（工号 ' . $u['emp_no'] . '，' . self::roleName($u['role']) . '）于 ' . $now .
                        ' 因密码连续输入错误达到 ' . $cnt . ' 次，已被系统自动安全锁定。来源 IP：' . $ip .
                        '。请核实后前往【用户管理】解锁。',
                        '', '', array('link_url' => '/admin/users?edit_user_id=' . (int)$u['id']));
                }
                return '密码连续错误已达上限，账号已自动锁定，请联系管理员解锁';
            }
            // 未达阈值：提示剩余可尝试次数
            return '用户名或密码错误，还可尝试 ' . ($threshold - $cnt) . ' 次';
        }

        /* ==================== ④ 登录成功处理 ==================== */
        // 清零失败计数与锁定归因字段（历史 login_locked_until 一并失效）
        DB::exec(
            'UPDATE users SET login_fail_count=0, login_locked_until=NULL, lock_reason=NULL, locked_at=NULL, last_login=? WHERE id=?',
            array(now_str(), (int)$u['id'])
        );
        // 清除当前客户端 IP/Session 的失败频次与验证码状态
        LoginSecurity::ipFailClear();
        LoginSecurity::captchaClear();
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
            DB::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE current_doctor_id=?',
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
