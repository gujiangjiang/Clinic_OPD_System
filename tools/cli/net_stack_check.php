<?php
/**
 * ============================================================
 * tools/cli/net_stack_check.php — 网络栈诊断 CLI（出向集成地址可达性排查）
 * ============================================================
 * 用途：在部署机上直接定位「门诊 ↔ HIS / PACS / 检验中心」出向互调失败的
 * 网络层原因：DNS 解析（A/AAAA）+ IPv4/IPv6 双栈 TCP 连通性 + 中文结论与建议。
 * 典型场景：目标域名仅有 AAAA 记录（纯 IPv6），而本机（如 Docker bridge 容器）
 * 没有 IPv6 出口 → 浏览器能访问域名，但系统间互调填域名必失败。
 * 用法：~/.local/bin/frankenphp php-cli tools/cli/net_stack_check.php
 *       无参数：诊断接口管理已配置的全部出向端点（FHIR/PACS/HIS/LIS/存证）
 *       传参：  php net_stack_check.php <url> [url2...] 诊断指定地址
 * 退出码：全部可达 0，存在不可达 1（便于脚本化巡检）。
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

echo '门诊一体化 网络栈诊断 | PHP ' . PHP_VERSION . ' / ' . PHP_OS . ' / ' . PHP_SAPI . PHP_EOL;

// 目标地址：命令行参数优先；无参数时读「接口管理」出向配置
$urls = array_slice($argv, 1);
if (!$urls) {
    if (!ConfigStore::isSystemInstalled()) {
        exit('系统未安装，无出向配置可诊断；请传入要诊断的地址。' . PHP_EOL);
    }
    $keys = array(
        'integration.outbound.fhir.remote_endpoint',
        'integration.outbound.pacs.qido_url',
        'integration.outbound.pacs.wado_url',
        'integration.outbound.his.gateway_url',
        'integration.outbound.lis.order_url',
        'evid_endpoint',
    );
    foreach ($keys as $k) {
        $v = trim((string)setting($k, ''));
        if ($v !== '') $urls[] = $v;
    }
    if (!$urls) exit('接口管理未配置任何出向端点；请传入要诊断的地址。' . PHP_EOL);
}

$adviceNoV6 = '建议(三选一): ① 系统间互调地址改用内网 IPv4（如 http://10.0.0.91:端口）; '
    . '② 容器部署时为容器加 hosts 映射（compose: extra_hosts: ["域名=10.0.0.91"]）后重启; '
    . '③ 为容器启用 IPv6 出口或配置内网 DNS 分离解析（见 docs/DEPLOY.md《IPv6 域名互调》）。';
$allOk = true;

foreach ($urls as $i => $url) {
    if ($i > 0) echo PHP_EOL;
    echo '目标: ' . $url . PHP_EOL;
    $d = NetworkDiag::describe($url);
    $allOk = $allOk && !empty($d['ok']);
    echo '  解析: A×' . count($d['a']) . ($d['a'] ? ' (' . implode(', ', $d['a']) . ')' : '')
        . '  AAAA×' . count($d['aaaa']) . ($d['aaaa'] ? ' (' . implode(', ', $d['aaaa']) . ')' : '') . PHP_EOL;
    if ($d['is_ip'] !== '') echo '  类型: IP 直连 (IPv' . $d['is_ip'] . ')，不经过域名解析' . PHP_EOL;
    if ($d['v4']) echo '  IPv4: ' . $d['a'][0] . ':' . $d['port'] . ' → ' . $d['v4']['detail'] . PHP_EOL;
    if ($d['v6']) echo '  IPv6: [' . $d['aaaa'][0] . ']:' . $d['port'] . ' → ' . $d['v6']['detail'] . PHP_EOL;
    echo '  结论: ' . $d['verdict'] . PHP_EOL;
    if (empty($d['ok'])) {
        if ($d['v4'] === null && $d['v6'] && !$d['v6']['ok']) echo '  ' . $adviceNoV6 . PHP_EOL;
        elseif (!$d['a'] && !$d['aaaa']) echo '  建议: 检查本机 /etc/resolv.conf 与 hosts 配置。' . PHP_EOL;
        elseif ($d['v4'] && !$d['v4']['ok'] && $d['v6'] && !$d['v6']['ok']) {
            echo '  建议: 双栈均不可达，检查本机网络、路由与防火墙放行端口 ' . $d['port'] . '。' . PHP_EOL;
        }
    }
}

echo PHP_EOL . ($allOk ? '结果: 全部可达' : '结果: 存在不可达目标（出向互调失败时优先按上述建议处理）') . PHP_EOL;
exit($allOk ? 0 : 1);
