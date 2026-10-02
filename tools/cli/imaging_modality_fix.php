<?php
/**
 * ============================================================
 * tools/cli/imaging_modality_fix.php — 影像引用模态码修正（历史脏数据）
 * ============================================================
 * 说明：历史种子/登记把 MRI 等项目误存为 OT，导致 PACS/FHIR/DICOMweb
 * 模态分类错误。本脚本按「现有模态 → 检查项目名」识别并回填合法 DICOM
 * 模态码（MRI/磁共振→MR、超声→US、X线→DX 等），未识别保持原值。
 *
 * 用法：frankenphp php-cli tools/cli/imaging_modality_fix.php [--dry-run]
 * ============================================================ */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

$dry = in_array('--dry-run', $argv, true);
$rows = DB::q("SELECT ir.id, ir.modality, oi.item_name FROM imaging_refs ir LEFT JOIN order_items oi ON oi.id=ir.order_item_id");
$plan = array();
foreach ($rows as $r) {
    $cur = strtoupper(trim((string)$r['modality']));
    $code = imaging_modality_code($r['modality']);
    if ($code === '' || $code === 'OT') {
        $byName = imaging_modality_code($r['item_name']);
        if ($byName !== '') $code = $byName;
    }
    if ($code === '' || $code === $cur) continue;
    $plan[] = array('id' => (int)$r['id'], 'from' => (string)$r['modality'], 'to' => $code, 'item' => (string)$r['item_name']);
}
echo '待修正: ' . count($plan) . PHP_EOL;
foreach (array_slice($plan, 0, 12) as $p) {
    echo '  #' . $p['id'] . ' ' . $p['from'] . ' -> ' . $p['to'] . '（' . $p['item'] . '）' . PHP_EOL;
}
if ($dry) { echo '（dry-run，未写库）' . PHP_EOL; exit(0); }
$n = 0;
foreach ($plan as $p) {
    DB::exec('UPDATE imaging_refs SET modality=? WHERE id=?', array($p['to'], $p['id']));
    $n++;
}
echo '已修正: ' . $n . PHP_EOL;
exit(0);
