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
 * （见根目录 docs/nginx.conf.example）。
 */

/* ---------- 启动环境 ---------- */
require dirname(__DIR__) . '/app/config/bootstrap.php';

/* ---------- 自动创建数据库并执行迁移（幂等：仅应用未执行的迁移版本） ----------
 * 仅在系统已安装时初始化主库：安装向导完成第 2 步（选择数据库）之前，
 * 绝不因一次访问而自动创建 clinic_main.db。 */
if (ConfigStore::isSystemInstalled()) {
    DatabaseManager::initAll();
}

/* ---------- 解析请求路径 ---------- */
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = rtrim($uri, '/') ?: '/';

/* ---------- PWA 资源（manifest / 图标 / Service Worker） ---------- */
// 动态资源（清单含医院名称、图标含 LOGO）由 PHP 生成；SW 从静态文件读出
if ($uri === '/manifest.webmanifest' || $uri === '/pwa-icon.png') {
    require APP_ROOT . '/app/includes/pwa.php';
    exit;
}
if ($uri === '/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    $swFile = APP_ROOT . '/public/assets/js/sw.js';
    if (is_file($swFile)) {
        readfile($swFile);
    }
    exit;
}

/* ---------- AJAX 接口分发 ---------- */
if (preg_match('#^/api/([a-z0-9_]+)$#i', $uri, $m)) {
    $apiName = $m[1];
    // 数据库迁移/切换锁定：除迁移状态接口外，全站 API 拦截至锁定页
    // （running=迁移中 / done=迁移完成待确认切换）
    require_once APP_ROOT . '/app/core/MigrationRunner.php';
    if (MigrationRunner::isLocked() && $apiName !== 'migration') {
        require APP_ROOT . '/app/includes/migrating_lock.php';
        exit;
    }
    $apiFile = API_PATH . '/' . $apiName . '.php';
    if (!is_file($apiFile)) {
        json_response(false, '接口不存在');
    }
    // 定义当前接口名，供 _init.php 权限校验使用
    define('CURRENT_API', $apiName);
    require $apiFile;
    exit;
}

/* ---------- 数据库迁移/切换锁定：全站页面拦截 ---------- */
require_once APP_ROOT . '/app/core/MigrationRunner.php';
if (MigrationRunner::isLocked()) {
    require APP_ROOT . '/app/includes/migrating_lock.php';
    exit;
}

/* ---------- 定时自动备份调度（到点且当日未备份 → 后台启动，不阻塞页面） ---------- */
(function () {
    $hour = ConfigStore::get('backup.hour', '');
    if ($hour === '') return;
    if (date('H:i') < $hour) return;                       // 未到点
    if (ConfigStore::get('backup.last_date', '') === date('Y-m-d')) return;   // 今日已备份
    $script = APP_ROOT . '/tools/cli/db_backup_run.php';
    if (!is_file($script)) return;
    // 检查是否已有备份任务在跑（简单锁：backup.running 标记 + 5 分钟超时）
    $lockAt = ConfigStore::get('backup.running', '');
    if ($lockAt !== '' && (time() - (int)$lockAt) < 300) return;
    ConfigStore::set('backup.running', (string)time());
    $runner = '';
    foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
        $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
        if (is_file($p)) { $runner = $p; break; }
    }
    if ($runner === '') $runner = 'frankenphp';
    $cmd = 'nohup ' . $runner . ' php-cli ' . $script . ' > /dev/null 2>&1 &';
    @pclose(@popen($cmd, 'r'));
})();

/* ---------- 页面路由分发 ---------- */
Router::dispatch($uri);
