<?php
/**
 * ============================================================
 * Session.php v1.0.0 — 会话管理
 * ============================================================
 * 说明：统一初始化 PHP Session：
 * 1. Session 文件保存到 data/session（避免与业务目录混杂）
 * 2. Cookie 仅 HttpOnly、SameSite=Lax，降低 XSS/CSRF 风险
 * 3. 登录成功后由 Auth 调用 session_regenerate_id 防会话固定
 * ============================================================ */
class Session {

    /** 启动会话（幂等） */
    public static function start() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = DATA_DIR . '/session';
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }
        ini_set('session.save_path', $path);
        session_name('HIS_SID');
        // secure 判定兼容反向代理（Nginx 终结 TLS 时经 X-Forwarded-Proto 传递）
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        session_start();
    }
}
