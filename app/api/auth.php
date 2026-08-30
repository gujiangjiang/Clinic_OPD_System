<?php
/**
 * ============================================================
 * auth.php — 认证接口
 * ============================================================
 * 接口：/api/auth
 * action=login        登录（POST，支持用户名或工号）
 * action=logout_page  退出并跳转登录页（GET）
 * action=theme        保存主题偏好 auto/light/dark（POST）
 * action=sidebar      保存侧边栏偏好 expand 展开 / mini 缩小（POST）
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
        // 密码必须原样读取（不 trim），保证与入库密码逐字一致
        $password = post_raw('password');
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
        // 管理员首次登录（未修改过默认密码）：站内消息提醒修改密码，点击跳转 /password
        // 去重不含 is_read 条件——只要发过一次就不再重发（已读/清空后登录不再重复打扰）
        if ($u['role'] === 'admin' && (int)DB::val('SELECT pwd_changed FROM users WHERE id=?', array($u['id'])) === 0) {
            $exist = DB::one("SELECT id FROM messages WHERE to_user_id=? AND title=? LIMIT 1",
                array((int)$u['id'], '修改管理员密码提醒'));
            if (!$exist) {
                DB::insert("INSERT INTO messages(from_name, to_role, to_user_id, title, content, is_read, msg_type, link_url, created_at) VALUES(?,?,?,?,?,0,'system',?,?)",
                    array('系统', 'admin', (int)$u['id'], '修改管理员密码提醒',
                        '为保障系统安全，建议您尽快修改管理员默认密码。点击此消息前往修改。',
                        '/password', now_str()));
            }
        }
        json_ok(array('next' => $next, 'name' => $u['name'], 'role' => $u['role']), '登录成功');
    }

    /* ---------------- 退出（页面跳转） ---------------- */
    Auth::logout();
    header('Location: /login');
    exit;
}

require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 忘记密码：提交重置申请（通知管理员审核，需求25） ---------------- */
    case 'forgot':
        $me = Auth::user();
        $row = DB::one('SELECT id, username, name, emp_no, role FROM users WHERE id=?', array($me['id']));
        if (!$row) json_fail('用户不存在');
        // 防重复申请：已有待审核的密码重置申请时不再重复提交
        $pending = DB::one("SELECT id FROM audits WHERE type='pwd_reset' AND ref_id=? AND status='pending'", array($row['id']));
        if ($pending) json_fail('您已提交过密码重置申请，请耐心等待管理员审核');
        // 已通过审核但尚未设置新密码时，引导直接设置
        $approved = DB::one("SELECT id FROM audits WHERE type='pwd_reset' AND ref_id=? AND status='approved'", array($row['id']));
        if ($approved) json_fail('您的密码重置申请已通过审核，请直接在站内消息中点击【设置新密码】完成重置');
        DB::insert("INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)", array(
            'pwd_reset', (int)$row['id'],
            '密码重置申请：' . $row['name'],
            '用户「' . $row['name'] . '」（工号 ' . $row['emp_no'] . '，角色 ' . Auth::roleName($row['role']) . '）忘记登录密码，申请重置为初始密码，请审核',
            'pending', $row['name'], (int)$row['id'], now_str(),
        ));
        // 通知管理员
        send_msg('admin', 0, '密码重置申请',
            '用户「' . $row['name'] . '」忘记密码，已提交重置申请，请在【审核中心】处理', '', '');
        json_ok(array(), '已通知管理员，审核通过后将为您重置密码为初始密码');
        break;

    /* ---------------- 重置密码（管理员审核通过后调用，无需验证原密码） ---------------- */
    case 'reset_password':
        $new = post_raw('new_password');
        if (strlen($new) < 6) json_fail('新密码长度不能少于6位');
        // 必须有管理员已批准且未使用的密码重置申请
        $appr = DB::one("SELECT id FROM audits WHERE type='pwd_reset' AND ref_id=? AND status='approved' ORDER BY id DESC", array(Auth::id()));
        if (!$appr) json_fail('没有已通过审核的密码重置申请，请先在【修改密码】页提交申请');
        DB::exec('UPDATE users SET password=?, pwd_changed=1 WHERE id=?', array(password_hash($new, PASSWORD_DEFAULT), Auth::id()));
        DB::exec("UPDATE audits SET status='used', note=? WHERE id=?", array('用户已设置新密码', $appr['id']));
        json_ok(array(), '密码修改成功');
        break;

    /* ---------------- 主题偏好（明亮/夜间/自动，跟随用户保存） ---------------- */
    case 'theme':
        $theme = post('theme', 'auto');
        if (!in_array($theme, array('auto', 'light', 'dark'), true)) {
            $theme = 'auto';
        }
        DB::exec('UPDATE users SET theme=? WHERE id=?', array($theme, Auth::id()));
        Auth::updateSession('theme', $theme);
        json_ok(array('theme' => $theme), '主题设置已保存');
        break;

    /* ---------------- 侧边栏偏好（展开/缩小仅图标，跟随用户保存） ---------------- */
    case 'sidebar':
        $sidebar = post('sidebar', 'expand');
        if (!in_array($sidebar, array('expand', 'mini'), true)) {
            $sidebar = 'expand';
        }
        DB::exec('UPDATE users SET sidebar=? WHERE id=?', array($sidebar, Auth::id()));
        Auth::updateSession('sidebar', $sidebar);
        json_ok(array('sidebar' => $sidebar), '侧边栏设置已保存');
        break;

    /* ---------------- 打印偏好：自动打印（跟随用户保存，服务端持久化） ---------------- */
    case 'print_auto':
        $value = (int)post('value', 0) === 1 ? 1 : 0;
        DB::exec('UPDATE users SET print_auto=? WHERE id=?', array($value, Auth::id()));
        Auth::updateSession('print_auto', $value);
        json_ok(array('print_auto' => $value), $value ? '已开启自动打印' : '已关闭自动打印');
        break;

    /* ---------------- 修改密码 ---------------- */
    case 'password':
        $old = post_raw('old_password');
        $new = post_raw('new_password');
        if (strlen($new) < 6) {
            json_fail('新密码长度不能少于6位');
        }
        $u = DB::one('SELECT * FROM users WHERE id=?', array(Auth::id()));
        if (!$u || !password_verify($old, $u['password'])) {
            json_fail('原密码不正确');
        }
        DB::exec('UPDATE users SET password=?, pwd_changed=1 WHERE id=?', array(password_hash($new, PASSWORD_DEFAULT), Auth::id()));
        json_ok(array(), '密码修改成功');
        break;

    /* ---------------- 个人资料：即时保存（仅头像/主题，无需审核） ---------------- */
    case 'profile_save':
        $updates = array();
        // 头像上传（即时生效）
        $photo = isset($_FILES['photo']) ? Upload::save('photo', 'user/' . Auth::user()['role']) : null;
        if ($photo && isset($photo['error'])) {
            json_fail($photo['error']);
        }
        if ($photo && $photo['ok']) {
            $updates['photo'] = $photo['path'];
        }
        // 主题（即时生效，可单独保存）
        $theme = post('theme', '');
        if (in_array($theme, array('auto', 'light', 'dark'), true)) {
            $updates['theme'] = $theme;
        }
        if (!$updates) {
            json_fail('没有需要保存的变更');
        }
        $set = array();
        $params = array();
        foreach ($updates as $k => $v) {
            $set[] = $k . '=?';
            $params[] = $v;
        }
        $params[] = Auth::id();
        DB::exec('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?', $params);
        if ($photo && $photo['ok']) Auth::updateSession('photo', $photo['path']);
        if ($theme !== '') Auth::updateSession('theme', $theme);
        json_ok(array(), '资料已保存');
        break;

    /* ---------------- 个人资料：提交审核（学历/学位/个人介绍/头像） ----------------
     * 说明：学历/学位/个人介绍/头像属于需管理员审核的字段，提交后写入审核池
     * （type=profile_update），审核通过才生效；已有待审核申请时禁止重复提交。
     * 头像上传后暂不写入 users.photo，审核通过才生效；拒绝时自动删除已上传文件。 */
    case 'profile_submit':
        $me = Auth::user();
        // 防重复：已有待审核的个人资料申请
        $pending = DB::one("SELECT id FROM audits WHERE type='profile_update' AND ref_id=? AND status='pending'", array($me['id']));
        if ($pending) {
            json_fail('您已提交过个人资料审核申请，请等待管理员审核');
        }
        // 收集需审核字段的新值（仅在提交了对应字段时才纳入，避免仅换头像时误清空其他字段）
        $updates = array();
        $titleParts = array();
        $cur = DB::one('SELECT education, degree, intro FROM users WHERE id=?', array($me['id']));
        if (isset($_POST['education'])) {
            $edu = post('education', '');
            if (($cur && $cur['education'] !== $edu) || $edu !== '') {
                $updates['education'] = $edu;
                $titleParts[] = '学历→' . ($edu !== '' ? $edu : '（清除）');
            }
        }
        if (isset($_POST['degree'])) {
            $deg = post('degree', '');
            if (($cur && $cur['degree'] !== $deg) || $deg !== '') {
                $updates['degree'] = $deg;
                $titleParts[] = '学位→' . ($deg !== '' ? $deg : '（清除）');
            }
        }
        if (isset($_POST['intro'])) {
            $intro = post('intro', '');
            if (($cur && $cur['intro'] !== $intro) || $intro !== '') {
                $updates['intro'] = $intro;
                $titleParts[] = '个人介绍更新';
            }
        }
        // 头像（可选）：上传后暂不入库，审核通过才写入 users.photo
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $res = Upload::save('photo', 'user/' . $me['role']);
            if (isset($res['error'])) json_fail($res['error']);
            $updates['photo'] = $res['path'];
            $titleParts[] = '头像更新';
        }
        if (!$updates) {
            json_fail('没有需要提交审核的变更');
        }
        // 写入审核池：data 存新值 JSON，ref_id=用户ID
        $dataJson = json_encode($updates, JSON_UNESCAPED_UNICODE);
        DB::insert("INSERT INTO audits(type, ref_id, title, content, data, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)", array(
            'profile_update', (int)$me['id'],
            '个人资料修改申请：' . $me['name'],
            implode('；', $titleParts),
            $dataJson, 'pending', $me['name'], (int)$me['id'], now_str(),
        ));
        // 通知管理员（会话快照不含 emp_no，从库取）
        $meRow = DB::one('SELECT emp_no FROM users WHERE id=?', array($me['id']));
        send_msg('admin', 0, '个人资料审核申请',
            '用户「' . $me['name'] . '」（工号 ' . ($meRow ? $meRow['emp_no'] : '') . '）申请修改个人资料：' . implode('；', $titleParts) .
            '，请在【审核中心】处理', '', '');
        json_ok(array(), '已提交审核，审核通过后生效');
        break;

    /* ---------------- 个人信息（兼容旧接口，改为即时+审核混合提示） ---------------- */
    case 'profile':
        // 旧逻辑保留：仅保存即时字段（头像/主题），学历/学位/介绍需走 profile_submit 审核
        $updates = array();
        $photo = isset($_FILES['photo']) ? Upload::save('photo', 'user/' . Auth::user()['role']) : null;
        if ($photo && isset($photo['error'])) {
            json_fail($photo['error']);
        }
        if ($photo && $photo['ok']) {
            $updates['photo'] = $photo['path'];
        }
        $theme = post('theme', '');
        if (in_array($theme, array('auto', 'light', 'dark'), true)) {
            $updates['theme'] = $theme;
        }
        if ($updates) {
            $set = array();
            $params = array();
            foreach ($updates as $k => $v) {
                $set[] = $k . '=?';
                $params[] = $v;
            }
            $params[] = Auth::id();
            DB::exec('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?', $params);
            if ($photo && $photo['ok']) Auth::updateSession('photo', $photo['path']);
            if ($theme !== '') Auth::updateSession('theme', $theme);
        }
        json_ok(array(), '资料已保存');
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
