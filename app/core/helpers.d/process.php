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
