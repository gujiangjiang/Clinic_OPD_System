<?php
/**
 * logout.php — 退出登录
 * 说明：销毁会话并跳转到登录页。
 */
Auth::logout();
header('Location: /login');
exit;
