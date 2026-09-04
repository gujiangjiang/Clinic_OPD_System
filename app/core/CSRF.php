<?php
/**
 * ============================================================
 * CSRF.php v1.0.0 — CSRF 跨站请求伪造防护
 * ============================================================
 * 说明：
 * 1. 每个会话生成随机令牌，注入到页面 body 的 data-csrf
 * 2. ajax.js 在每次 POST 时自动附加 csrf_token 字段
 * 3. 所有 POST 接口统一调用 CSRF::check() 校验
 * ============================================================ */
class CSRF {

    /** 获取当前会话令牌（不存在则生成） */
    public static function token() {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    /**
     * 校验 POST 请求中的 CSRF 令牌
     * 校验失败直接输出 JSON 错误并终止
     */
    public static function check() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        // 同源校验（纵深防御）：POST 请求的 Origin/Referer 必须为本站，
        // 防 SameSite 不可靠场景下的跨站 POST 伪造（站点自身无跨域 API）
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        $scheme = self::isHttps() ? 'https' : 'http';
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
        if ($origin !== '' && $origin !== $scheme . '://' . $host && $origin !== 'null') {
            json_fail('安全校验失败，请刷新页面后重试');
        }
        if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
            $ref = (string)$_SERVER['HTTP_REFERER'];
            if ($ref !== '' && stripos($ref, $scheme . '://' . $host) !== 0) {
                json_fail('安全校验失败，请刷新页面后重试');
            }
        }
        $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            json_fail('安全校验失败，请刷新页面后重试');
        }
    }

    /** 当前请求是否为 HTTPS（兼容反向代理 X-Forwarded-Proto） */
    private static function isHttps() {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') return true;
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0) return true;
        return false;
    }
}
