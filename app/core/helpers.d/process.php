<?php
/**
 * ============================================================
 * helpers.d/process.php — 后台进程辅助
 * ============================================================
 * 说明：页面请求触发的异步任务（定时备份 / 病历自动归档 / Outbox 出向 worker /
 * 数据库迁移）统一经 `spawn_background()` 以 nohup 分离启动，避免各文件重复
 * 编写「解析 PHP CLI 路径 + 拼命令 + popen」模板（DRY）。
 *
 * 环境兼容：
 *  - macOS 开发环境：优先 frankenphp 单文件（`<bin> php-cli script.php`）；
 *  - Linux / 宝塔：回退系统 PHP CLI（`<PHP_BINDIR>/php script.php`）；
 *  - 宝塔默认将 popen 列入 disable_functions（PHP 8 下表现为「未定义函数」并抛
 *    Error 致命错误）：本函数双重检测后优雅降级返回 false 并写日志，
 *    绝不让一次后台任务派生失败导致整页 500。
 * ============================================================ */

/**
 * PHP CLI 运行器解析（frankenphp 单文件优先 → 系统 PHP CLI → PATH 回退 php）
 * @return string
 */
function php_cli_runner() {
    static $runner = null;
    if ($runner !== null) return $runner;
    // 1) frankenphp 单文件（macOS 本机 / 静态部署）
    foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
        $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
        if (@is_file($p)) return $runner = $p;
    }
    // 2) 系统 PHP CLI（Linux / 宝塔：/www/server/php/8x/bin/php）
    if (defined('PHP_BINDIR') && @is_file(PHP_BINDIR . '/php')) {
        return $runner = PHP_BINDIR . '/php';
    }
    // 3) PATH 回退
    return $runner = 'php';
}

/**
 * 后台启动 CLI 脚本（nohup 分离、不等待、输出丢弃）
 * @param string $script 脚本绝对路径
 * @param array  $args   额外命令行参数（自动 escapeshellarg）
 * @return bool 进程派生是否成功（不代表脚本执行结果）
 */
function spawn_background($script, $args = array()) {
    if (!is_file($script)) return false;
    // popen 不可用（宝塔 disable_functions 默认禁用）：静默降级，绝不 500。
    // PHP 8 禁用函数表现为未定义（function_exists=false），调用即抛 Error；
    // PHP 7 禁用函数仍存在（调用告警返回 null），故「函数存在 + 禁用列表」双重检测。
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('popen') || in_array('popen', $disabled, true)) {
        error_log('[spawn_background] popen() 不可用（可能被 disable_functions 禁用），跳过后台任务：' . basename($script));
        return false;
    }
    $runner = php_cli_runner();
    // frankenphp 以 php-cli 子命令运行脚本；系统 php 直接运行脚本
    $parts = (stripos(basename($runner), 'frankenphp') !== false)
        ? array($runner, 'php-cli', $script)
        : array($runner, $script);
    foreach ((array)$args as $a) {
        $parts[] = escapeshellarg((string)$a);
    }
    $cmd = 'nohup ' . implode(' ', $parts) . ' > /dev/null 2>&1 &';
    try {
        $fp = @popen($cmd, 'r');
        if ($fp === false) return false;
        @pclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('[spawn_background] 进程派生失败：' . $e->getMessage());
        return false;
    }
}

/**
 * 以非阻塞文件锁执行临界区（跨进程互斥，进程异常退出由内核自动释放）
 * 说明：备份等重任务的手动触发（Web 同步）与定时触发（CLI 后台）共用同一把锁，
 * 避免并发执行导致备份库互相覆盖。
 * @param string   $name 锁名（映射 data/logs/.<name>.lock）
 * @param callable $fn   临界区回调（异常原样向上抛出，锁在 finally 释放）
 * @return bool 是否获得锁并执行（未获得返回 false）
 */
function with_exclusive_lock($name, $fn) {
    if (!preg_match('/^[a-z0-9_\-]+$/i', (string)$name)) return false;
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fp = @fopen($dir . '/.' . $name . '.lock', 'c');
    if (!$fp) return false;
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        return false;
    }
    try {
        call_user_func($fn);
        return true;
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
