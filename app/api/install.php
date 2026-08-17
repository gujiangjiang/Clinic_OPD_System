<?php
/**
 * ============================================================
 * install.php — 首次安装接口
 * ============================================================
 * 说明：系统未创建管理员前调用本接口完成初始化：
 * 1. 创建管理员（用户名固定 admin，密码由用户设置）
 * 2. 设置医院名称/第二名称/页脚/站点时区（默认取浏览器时区）
 * 3. 上传医院 LOGO（可选，同时用作网站 favicon）
 * ============================================================ */

// 仅允许未安装状态调用
if ((int)DB::val('user', 'SELECT COUNT(*) FROM users') > 0) {
    json_fail('系统已安装，无需重复初始化');
}

CSRF::check();

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

if ($action !== 'save') {
    json_fail('未知操作');
}

// 密码必须原样读取（不 trim），否则含首尾空格的密码会被误删导致长度校验失败
$password = post_raw('password');
$password2 = post_raw('password2');
$hospital = post('hospital_name');
$hospital2 = post('hospital_name2');
$timezone = post('timezone', 'Asia/Shanghai');
$footer = post('footer', '');

// ===== 基础校验 =====
if ($password === '' || strlen($password) < 6) {
    json_fail('管理员密码不能少于6位');
}
if ($password !== $password2) {
    json_fail('两次输入的密码不一致');
}
if ($hospital === '') {
    json_fail('请填写医院名称');
}

// 时区白名单校验（防止写入非法值）
$tzList = DateTimeZone::listIdentifiers();
if (!in_array($timezone, $tzList, true)) {
    $timezone = 'Asia/Shanghai';
}

// ===== LOGO 上传（可选） =====
$logo = '';
if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
    if (isset($res['error'])) {
        json_fail($res['error']);
    }
    $logo = $res['path'];
}

// ===== 创建管理员用户 =====
$adminId = DB::insert('user', 'INSERT INTO users(emp_no, username, password, name, role, theme, status, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
    '0001', 'admin', password_hash($password, PASSWORD_DEFAULT), '系统管理员', 'admin', 'auto', 1, now_str(),
));

// ===== 保存系统设置 =====
set_setting('hospital_name', $hospital);
set_setting('hospital_name2', $hospital2);
set_setting('timezone', $timezone);
set_setting('footer', $footer);
set_setting('logo', $logo);
set_setting('install_time', now_str());

// 站点时区立即生效
date_default_timezone_set($timezone);

json_ok(array('admin_id' => $adminId), '系统安装成功，请使用管理员账号登录');
