<?php
/**
 * ============================================================
 * 系统唯一入口 index.php v1.0.0
 * ============================================================
 * 说明：所有动态请求（页面 + AJAX 接口）均由此文件分发。
 * 1. 加载启动引导
 * 2. 检测系统是否已安装并初始化数据库（首次自动建库迁移）
 * 3. /api/xxx 请求 → 分发到对应接口文件
 * 4. 其他请求   → 交给 Router 分发到模块页面
 *
 * 注意：Nginx 配置中所有请求都应转发到此文件
 * （见根目录 nginx.conf.example）。
 */

/* ---------- 启动环境 ---------- */
require dirname(__DIR__) . '/app/config/bootstrap.php';

/* ---------- 自动创建数据库并执行迁移（幂等：仅应用未执行的迁移版本） ---------- */
DatabaseManager::initAll();

/* ---------- 解析请求路径 ---------- */
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = rtrim($uri, '/') ?: '/';

/* ---------- AJAX 接口分发 ---------- */
if (preg_match('#^/api/([a-z0-9_]+)$#i', $uri, $m)) {
    $apiName = $m[1];
    $apiFile = API_PATH . '/' . $apiName . '.php';
    if (!is_file($apiFile)) {
        json_response(false, '接口不存在');
    }
    // 定义当前接口名，供 _init.php 权限校验使用
    define('CURRENT_API', $apiName);
    require $apiFile;
    exit;
}

/* ---------- 页面路由分发 ---------- */
Router::dispatch($uri);
