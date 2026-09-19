<?php
/**
 * ============================================================
 * tools/seed_test_data.php — 完整测试数据生成（轻量级代理入口）
 * ============================================================
 * v8.17 起 tools/ 重构为模块化造数架构：实际逻辑位于
 *   tools/scenarios/full_seed.php（FullTestScenario 全量场景）与
 *   tools/seeder/（各领域 Seeder 工厂）。
 * 本文件保留根目录兼容入口，委托统一 CLI tools/bin/seed.php --all。
 *
 * 推荐直接使用统一 CLI：
 *   ~/.local/bin/frankenphp php-cli tools/bin/seed.php --all
 *   php tools/bin/seed.php --scene=demo / --module=drug ...
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

// 定位 PHP CLI 二进制（frankenphp 下 PHP_BINARY 为空，回退 $_SERVER['_']）
$bin = '';
if (PHP_BINARY !== '') $bin = PHP_BINARY;
elseif (isset($_SERVER['_']) && $_SERVER['_'] !== '') $bin = $_SERVER['_'];
elseif (PHP_BINDIR !== '' && is_file(PHP_BINDIR . '/php')) $bin = PHP_BINDIR . '/php';
if ($bin === '') $bin = 'php';
$runner = stripos(basename($bin), 'frankenphp') !== false ? escapeshellarg($bin) . ' php-cli' : escapeshellarg($bin);

$cmd = $runner . ' ' . escapeshellarg(__DIR__ . '/bin/seed.php') . ' --all';
passthru($cmd . ' 2>&1', $code);
exit((int)$code);