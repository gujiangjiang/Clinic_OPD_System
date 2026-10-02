<?php
/**
 * ============================================================
 * tools/cli/imaging_refs_cleanup.php — 影像引用清理（历史脏数据）
 * ============================================================
 * 说明：FHIR ImagingStudy 仅应承载「影像检查」；历史种子数据曾把检验类
 * 申请单也登记进 imaging_refs（modality=OT），导致 PACS/ImagingStudy 混入
 * 检验数据。本脚本删除「关联申请单非 imaging 类型」的影像引用，使数据符合
 * 「影像 → ImagingStudy / 检验 → Observation」的标准资源划分。
 *
 * 用法：frankenphp php-cli tools/cli/imaging_refs_cleanup.php [--dry-run]
 * ============================================================ */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

$dry = in_array('--dry-run', $argv, true);

$bad = (int)DB::val(
    "SELECT COUNT(*) FROM imaging_refs ir LEFT JOIN orders o ON o.id=ir.order_id WHERE o.id IS NULL OR o.order_type<>'imaging'"
);
echo '待清理（非影像申请单的引用）: ' . $bad . PHP_EOL;

if ($bad === 0) {
    echo '无需清理。' . PHP_EOL;
    exit(0);
}
if ($dry) {
    echo '（dry-run，未执行删除）' . PHP_EOL;
    exit(0);
}
$n = (int)DB::exec(
    "DELETE FROM imaging_refs WHERE id IN (SELECT ir.id FROM imaging_refs ir LEFT JOIN orders o ON o.id=ir.order_id WHERE o.id IS NULL OR o.order_type<>'imaging')"
);
echo '已删除: ' . $n . PHP_EOL;
exit(0);
