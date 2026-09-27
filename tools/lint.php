<?php
/**
 * ============================================================
 * tools/lint.php — PHP 语法检查（基于 tokenizer，无需系统 php）
 * ============================================================
 * 用法：~/.local/bin/frankenphp php-cli tools/lint.php
 * 扫描 app/、public/、views/ 与根入口 index.php，遇语法错误输出并返回非 0。
 * ============================================================ */
$root = dirname(__DIR__);
$targets = array($root . '/app', $root . '/public', $root . '/views', $root . '/index.php');

$files = array();
$collect = function ($path) use (&$collect, &$files) {
    if (is_file($path)) { if (substr($path, -4) === '.php') $files[] = $path; return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') continue;
        $collect($path . '/' . $f);
    }
};
foreach ($targets as $t) $collect($t);
sort($files);

$bad = 0;
foreach ($files as $f) {
    $code = file_get_contents($f);
    try {
        token_get_all($code, TOKEN_PARSE);   // 语法错误抛出 ParseError
    } catch (ParseError $e) {
        $bad++;
        echo 'FAIL ' . str_replace($root . '/', '', $f) . ' : ' . $e->getMessage() . PHP_EOL;
    }
}
if ($bad) {
    echo PHP_EOL . 'Found ' . $bad . ' file(s) with syntax errors.' . PHP_EOL;
    exit(1);
}
echo 'All PHP files OK (' . count($files) . ' files)' . PHP_EOL;
