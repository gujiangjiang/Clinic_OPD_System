<?php
/**
 * ============================================================
 * PHP 语法检查辅助脚本
 * ============================================================
 * 说明：本机可能未安装 php CLI，借助 FrankenPHP 的 php-cli 执行本脚本，
 * 使用 PHP 内置 tokenizer 对项目全部 PHP 文件做语法校验（等价于 php -l）。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/php-lint.php
 *   或 npm run lint
 */

$files = array_merge(
    glob(__DIR__ . '/../app/**/*.php') ?: array(),
    glob(__DIR__ . '/../public/**/*.php') ?: array(),
    array(__DIR__ . '/../router.php'),
);
$files = array_filter($files, 'is_file');

$bad = 0;
foreach ($files as $f) {
    $code = file_get_contents($f);
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        echo "Syntax error in " . basename(dirname(dirname($f))) . '/' . basename($f) . ": " . $e->getMessage() . "\n";
        $bad++;
    }
}

if ($bad === 0) {
    echo "All PHP files OK (" . count($files) . " files)\n";
    exit(0);
}
echo "$bad file(s) with errors\n";
exit(1);
