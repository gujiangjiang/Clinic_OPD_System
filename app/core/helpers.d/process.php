<?php
/**
 * ============================================================
 * helpers.d/process.php — 后台进程辅助
 * ============================================================
 * 说明：页面请求触发的异步任务（定时备份 / 病历自动归档 / Outbox 出向 worker /
 * 数据库迁移）统一经 `spawn_background()` 以 nohup 分离启动，避免各文件重复
 * 编写「解析 frankenphp 路径 + 拼命令 + popen」模板（DRY）。
 * ============================================================ */

/**
 * PHP CLI 运行器解析（frankenphp 单文件优先，PATH 回退 frankenphp）
 * @return string
 */
function php_cli_runner() {
    static $runner = null;
    if ($runner !== null) return $runner;
    foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
        $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
        if (is_file($p)) return $runner = $p;
    }
    return $runner = 'frankenphp';   // PATH 回退
}

/**
 * 后台启动 CLI 脚本（nohup 分离、不等待、输出丢弃）
 * @param string $script 脚本绝对路径
 * @param array  $args   额外命令行参数（自动 escapeshellarg）
 * @return bool 进程派生是否成功（不代表脚本执行结果）
 */
function spawn_background($script, $args = array()) {
    if (!is_file($script)) return false;
    $parts = array(php_cli_runner(), 'php-cli', $script);
    foreach ((array)$args as $a) {
        $parts[] = escapeshellarg((string)$a);
    }
    $cmd = 'nohup ' . implode(' ', $parts) . ' > /dev/null 2>&1 &';
    $fp = @popen($cmd, 'r');
    if ($fp === false) return false;
    @pclose($fp);
    return true;
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
