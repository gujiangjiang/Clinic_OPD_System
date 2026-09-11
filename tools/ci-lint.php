<?php
/**
 * ============================================================
 * ci-lint.php — CI 全量 PHP 语法检查（php -l）+ Markdown 报告
 * ============================================================
 * 用法：php tools/ci-lint.php [报告输出路径]
 *   默认输出 php-lint-report.md（本机亦可手动运行自查）
 * 说明：供 GitHub Actions 矩阵（PHP 7.2 / 7.4 / 8.2 / 8.5）调用，
 * 对全部 PHP 源码逐文件执行 php -l；任一文件语法错误 → 进程退出码 1
 * （CI 任务标红）。运行时数据目录（data/、public/uploads/）自动排除。
 * 退出码：0 全部通过；1 存在语法错误。
 * ============================================================ */

$root = dirname(__DIR__);
$reportPath = isset($argv[1]) ? trim((string)$argv[1]) : 'php-lint-report.md';
if ($reportPath === '') $reportPath = 'php-lint-report.md';

/* ---------- 收集全部 PHP 源码文件（排除运行时/非源码目录） ---------- */
$exclude = array('/.git/', '/data/', '/public/uploads/');
$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $path = str_replace('\\', '/', (string)$f->getPathname());
    foreach ($exclude as $ex) {
        if (strpos($path, $ex) !== false) continue 2;
    }
    $files[] = $path;
}
sort($files);

/* ---------- PHP 可执行文件定位 ----------
 * CI 环境：setup-php 安装的 php（PHP_BINARY 即当前解释器）；
 * 本机经 frankenphp 运行时无系统 php，给出明确指引后退出。 */
$phpBin = 'php';
$cand = (string)PHP_BINARY;
if ($cand !== '' && basename($cand) === 'php') {
    $phpBin = escapeshellarg($cand);   // 优先用当前解释器，避免 PATH 歧义
} else {
    $probe = array();
    $code = 0;
    exec('php -v 2>&1', $probe, $code);
    if ($code !== 0) {
        echo "本工具依赖系统 php 执行 php -l（供 CI 使用）。本机未检测到 php，\n";
        echo "请使用：npm run lint（frankenphp php-cli tools/php-lint.php）。\n";
        exit(2);
    }
}

/* ---------- 逐文件 php -l ---------- */
$pass = 0;
$failures = array();
foreach ($files as $file) {
    $out = array();
    $code = 0;
    exec($phpBin . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code === 0) {
        $pass++;
        continue;
    }
    $failures[] = array(
        'file' => ltrim(substr($file, strlen($root)), '/'),
        'msg' => trim(implode(' ', $out)),
    );
}

/* ---------- Markdown 报告 ---------- */
$ok = count($failures) === 0;
$lines = array();
$lines[] = '# PHP 语法检查报告';
$lines[] = '';
$lines[] = '- PHP 版本：' . PHP_VERSION;
$lines[] = '- 检查时间：' . gmdate('Y-m-d H:i:s') . ' UTC';
$lines[] = '- 检查文件：' . count($files) . ' 个';
$lines[] = '- 通过：' . $pass;
$lines[] = '- 失败：' . count($failures);
$lines[] = '- 结论：' . ($ok ? '✅ 全部通过' : ('❌ ' . count($failures) . ' 个文件存在语法错误'));
$lines[] = '';
if (!$ok) {
    $lines[] = '## 失败明细';
    $lines[] = '';
    foreach ($failures as $f) {
        $lines[] = '### ' . $f['file'];
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $f['msg'];
        $lines[] = '```';
        $lines[] = '';
    }
} else {
    $lines[] = '全部 PHP 文件在当前 PHP 版本下语法检查通过。';
    $lines[] = '';
}

$report = implode("\n", $lines);
echo $report;

$dir = dirname($reportPath);
if ($dir !== '' && $dir !== '.' && !is_dir($dir)) @mkdir($dir, 0777, true);
file_put_contents($reportPath, $report);

exit($ok ? 0 : 1);
