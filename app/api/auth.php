<?php
/**
 * ============================================================
 * auth.php — 认证接口
 * ============================================================
 * 接口：/api/auth
 * action=login        登录（POST）
 * action=logout_page  退出并跳转登录页（GET）
 * action=theme        保存主题偏好 auto/light/dark（POST）
 * action=password     修改密码（POST）
 * action=profile      更新个人信息/头像（POST）
 * action=me           获取当前用户信息（GET）
 * ============================================================ */
/* ============================================================
 * 公开动作（登录 / 退出）无需登录即可访问，需在 _init 登录校验前处理
 * ============================================================ */
$__act = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
if ($__act === 'login' || $__act === 'logout_page') {
    CSRF::check();

    /* ---------------- 登录 ---------------- */
    if ($__act === 'login') {
        $username = post('username');
        $password = post('password');
        $next = post('next', '');
        $res = Auth::login($username, $password);
        if ($res !== true) {
            json_fail($res);
        }
        // 登录成功后按角色返回默认首页（防开放重定向：仅允许站内路径）
        if ($next === '' || $next[0] !== '/') {
            $next = Auth::home();
        }
        $u = Auth::user();
        json_ok(array('next' => $next, 'name' => $u['name'], 'role' => $u['role']), '登录成功');
    }

    /* ---------------- 退出（页面跳转） ---------------- */
    Auth::logout();
    header('Location: /login');
    exit;
}

require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 主题偏好（明亮/夜间/自动，跟随用户保存） ---------------- */
    case 'theme':
        $theme = post('theme', 'auto');
        if (!in_array($theme, array('auto', 'light', 'dark'), true)) {
            $theme = 'auto';
        }
        DB::exec('user', 'UPDATE users SET theme=? WHERE id=?', array($theme, Auth::id()));
        Auth::updateSession('theme', $theme);
        json_ok(array('theme' => $theme), '主题设置已保存');
        break;

    /* ---------------- 修改密码 ---------------- */
    case 'password':
        $old = post('old_password');
        $new = post('new_password');
        if (strlen($new) < 6) {
            json_fail('新密码长度不能少于6位');
        }
        $u = DB::one('user', 'SELECT * FROM users WHERE id=?', array(Auth::id()));
        if (!$u || !password_verify($old, $u['password'])) {
            json_fail('原密码不正确');
        }
        DB::exec('user', 'UPDATE users SET password=?, pwd_changed=1 WHERE id=?', array(password_hash($new, PASSWORD_DEFAULT), Auth::id()));
        json_ok(array(), '密码修改成功');
        break;

    /* ---------------- 个人信息（姓名/头像/学历/学位/职称/职务/介绍） ---------------- */
    case 'profile':
        $name = post('name');
        if ($name === '') {
            json_fail('姓名不能为空');
        }
        $fields = array('education', 'degree', 'title', 'position', 'intro');
        $updates = array('name' => $name);
        foreach ($fields as $f) {
            $updates[$f] = post($f);
        }
        // 头像上传（可选）
        $photo = isset($_FILES['photo']) ? Upload::save('photo', 'user/' . Auth::user()['role']) : null;
        if ($photo && isset($photo['error'])) {
            json_fail($photo['error']);
        }
        if ($photo && $photo['ok']) {
            $updates['photo'] = $photo['path'];
        }
        $set = array();
        $params = array();
        foreach ($updates as $k => $v) {
            $set[] = $k . '=?';
            $params[] = $v;
        }
        $params[] = Auth::id();
        DB::exec('user', 'UPDATE users SET ' . implode(',', $set) . ' WHERE id=?', $params);
        Auth::updateSession('name', $name);
        if ($photo && $photo['ok']) {
            Auth::updateSession('photo', $photo['path']);
        }
        json_ok(array(), '个人信息已更新');
        break;

    /* ---------------- 当前用户信息 ---------------- */
    case 'me':
        $u = Auth::user();
        json_ok(array(
            'id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'],
            'role' => $u['role'], 'photo' => $u['photo'], 'theme' => Auth::theme(),
        ));
        break;

    default:
        json_fail('未知操作');
}
