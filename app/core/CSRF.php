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
        $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            json_fail('安全校验失败，请刷新页面后重试');
        }
    }
}
