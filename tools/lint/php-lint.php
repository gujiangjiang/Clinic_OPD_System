<?php
/**
 * ============================================================
 * PHP 语法检查辅助脚本
 * ============================================================
 * 说明：本机可能未安装 php CLI，借助 FrankenPHP 的 php-cli 执行本脚本，
 * 使用 PHP 内置 tokenizer 对项目全部 PHP 文件做语法校验（等价于 php -l）。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/lint/php-lint.php
 *   php tools/lint/php-lint.php
 *   或 npm run lint
 */

// 项目根目录（本文件位于 tools/lint/，上两级）
$ROOT = dirname(dirname(__DIR__));

$files = array_merge(
    glob($ROOT . '/*.php') ?: array()
);
// 递归收集 app、public、tools 目录下的全部 PHP 文件（glob ** 不递归，需用 DIT）
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app'));
foreach ($rii as $f) { if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getRealPath(); }
$rii2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/public'));
foreach ($rii2 as $f) { if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getRealPath(); }
$rii3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/tools'));
foreach ($rii3 as $f) { if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getRealPath(); }
$files = array_unique(array_filter($files, 'is_file'));

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

// ---------- 版本管理守护：ICD-10 诊断字典库必须始终纳入版本控制 ----------
// 该库在 .gitignore 中以 `!data/db/icd10.db` 显式反忽略并纳入版本管理，
// 但工作区文件一旦被删除，`git add -A` 会把删除动作一并暂存（历史已发生过一次）。
// 此处校验「文件存在 + 已被 git 跟踪」，任一不满足即让 lint 失败，杜绝再次丢失。
$icd10Rel = 'data/db/icd10.db';
$icd10Abs = $ROOT . '/' . $icd10Rel;
$guards = array();
if (!is_file($icd10Abs)) {
    $guards[] = "$icd10Rel 不存在（ICD-10 诊断字典库被删除）";
} else {
    $gitVer = @shell_exec('git --version 2>/dev/null');
    if ($gitVer !== null && strpos($gitVer, 'git version') !== false && is_dir($ROOT . '/.git')) {
        $tracked = @shell_exec('git -C ' . escapeshellarg($ROOT) . ' ls-files --error-unmatch ' . escapeshellarg($icd10Rel) . ' 2>/dev/null');
        if (trim((string)$tracked) === '') {
            $guards[] = "$icd10Rel 未纳入版本控制（禁止 git rm 该文件，须恢复跟踪）";
        }
    }
}
foreach ($guards as $g) {
    echo "Guard: " . $g . "\n";
}
$bad += count($guards);

if ($bad === 0) {
    echo "All PHP files OK (" . count($files) . " files)\n";
    exit(0);
}
echo "$bad file(s) with errors\n";
exit(1);
