<?php
/**
 * ============================================================
 * tools/lint/php-server-check.php — 开发服务器健康监测与自愈重启
 * ============================================================
 * 说明：每次任务完成前的守护检查（AGENTS.md 铁律）：探测本地开发
 * 服务器（默认端口 8000，PORT 环境变量可覆盖，8080 作为回退探测）是否
 * 存活，若已挂则自动以 nohup 后台重启并验证恢复，避免调试/冒烟测试中
 * 误杀开发服务器后工作区失联。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/lint/php-server-check.php
 *   或 npm run server:ensure
 * 退出码：0 = 服务器存活（或已成功重启）；1 = 重启失败。
 * ============================================================ */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$ROOT = dirname(dirname(__DIR__));
$PUBLIC = $ROOT . '/public';

/* ---------- 探测目标端口：PORT 环境变量优先，默认 8000，8080 回退 ---------- */
$ports = array();
$envPort = (int)getenv('PORT');
if ($envPort > 0) {
    $ports[] = $envPort;
}
$ports[] = 8000;
$ports[] = 8080;
$ports = array_values(array_unique($ports));

/** 探测单端口是否存活（能建立连接并收到任意 HTTP 响应即视为存活） */
function server_alive($port) {
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 2, 'ignore_errors' => true, 'method' => 'GET', 'header' => "Connection: close\r\n"),
    ));
    $resp = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
    if ($resp !== false) return true;
    // 无响应但连接可达（如仅监听未握手）→ 仍视为存活，避免误杀
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

/** 查找可用的 PHP 静态服务器二进制 */
function find_runner() {
    foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
        $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
        if (is_file($p)) return $p;
    }
    return 'frankenphp';
}

/* ---------- 依次探测：找到存活端口即输出状态并退出 ---------- */
$alivePort = 0;
foreach ($ports as $port) {
    if (server_alive($port)) {
        $alivePort = $port;
        break;
    }
}
if ($alivePort > 0) {
    echo 'PHP 开发服务器存活：http://127.0.0.1:' . $alivePort . "/\n";
    exit(0);
}

/* ---------- 服务器已挂：自动重启（后台 nohup + 幂等监听） ---------- */
$targetPort = $envPort > 0 ? $envPort : 8000;
$runner = find_runner();
$cmd = 'nohup ' . $runner . ' php-server --root ' . escapeshellarg($PUBLIC) . ' --listen 0.0.0.0:' . $targetPort . ' > /dev/null 2>&1 &';
@exec($cmd);

// 等待启动（最多 10 秒）
for ($i = 0; $i < 10; $i++) {
    usleep(500000);
    if (server_alive($targetPort)) {
        echo 'PHP 开发服务器已重启：http://127.0.0.1:' . $targetPort . "/\n";
        exit(0);
    }
}

fwrite(STDERR, 'PHP 开发服务器重启失败（端口 ' . $targetPort . '），请检查日志' . "\n");
exit(1);