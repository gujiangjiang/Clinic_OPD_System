<?php
/**
 * ============================================================
 * PHP 内置服务器路由脚本 v1.0.0
 * ============================================================
 * 说明：仅用于本地/沙箱预览（php -S 0.0.0.0:8000 router.php）。
 * 生产环境请使用 Nginx（见 nginx.conf.example）。
 *
 * 规则：
 * 1. /assets/ 与 /uploads/ 下的真实文件 → 直接返回（静态资源）
 * 2. 其余请求 → 转发到 public/index.php 统一分发
 *
 * 安全：data/、app/ 等敏感目录不在此白名单内，
 * 即使直接访问 URL 也无法下载文件。
 */

/* ---------- 静态资源：内置服务器 docroot 不在 public，需自行读取返回 ---------- */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 安全：医院 LOGO 页面内以 base64 Data URI 内联显示（不引用 URL），
// 因此禁止直接访问 /uploads/logo/，防止通过 URL 探测/抓取 LOGO 文件
if (strpos($path, '/uploads/logo/') === 0) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$isStatic = strpos($path, '/assets/') === 0 || strpos($path, '/uploads/') === 0;
if ($isStatic) {
    // 依次在 public 目录与根目录查找真实文件
    $candidates = array(
        __DIR__ . '/public' . $path,
        __DIR__ . $path,
    );
    foreach ($candidates as $file) {
        if (is_file($file)) {
            // 根据扩展名返回正确的 Content-Type（避免浏览器将 JS/CSS 当文本下载）
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mimes = array(
                'css'  => 'text/css; charset=utf-8',
                'js'   => 'application/javascript; charset=utf-8',
                'json' => 'application/json; charset=utf-8',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'svg'  => 'image/svg+xml',
                'webp' => 'image/webp',
                'ico'  => 'image/x-icon',
                'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            );
            $ct = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
            // 缓存资源，避免每次请求都重新读取
            header('Content-Type: ' . $ct);
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            return true;
        }
    }
    // 静态路径但文件不存在 → 404
    http_response_code(404);
    echo 'Not Found';
    return true;
}

/* ---------- 动态请求统一走单入口 ---------- */
require __DIR__ . '/public/index.php';
