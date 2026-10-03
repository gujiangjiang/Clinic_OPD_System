<?php
/**
 * ============================================================
 * tools/schema/repair_imaging_uids.php — 影像引用 UID 修复（一次性）
 * ============================================================
 * 背景：历史影像引用曾以「报告号」（BG…）作为 study_uid 占位（伪造 UID），
 * 与区域 PACS 的真实 StudyInstanceUID 不一致。本脚本按检查号（申请单号）
 * 向出向区域 PACS 解析真实 UID 并升级引用；无法解析的占位引用可选择性删除。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/schema/repair_imaging_uids.php
 *   ~/.local/bin/frankenphp php-cli tools/schema/repair_imaging_uids.php --delete-unresolved
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

if (!ConfigStore::isSystemInstalled()) exit("系统未安装，跳过\n");
if (!ImagingRegionResolver::configured()) {
    exit("未配置出向区域 PACS（接口管理 → DICOM/PACS），无法解析真实 UID，结束\n");
}
$deleteUnresolved = in_array('--delete-unresolved', $argv, true);

$rows = OrderRepository::q("SELECT ir.*, o.order_no FROM imaging_refs ir LEFT JOIN orders o ON o.id=ir.order_id",
    array());
$upgraded = 0; $refreshed = 0; $already = 0; $unresolved = 0; $deleted = 0; $details = array();
foreach ($rows as $r) {
    $uid = (string)$r['study_uid'];
    $meta = json_decode((string)$r['meta_json'], true);
    $needMeta = !(is_array($meta) && !empty($meta['region_name']) && !empty($meta['station_name']));
    if (ImagingRegionResolver::isRealUid($uid) && !$needMeta) { $already++; continue; }
    $itemId = (int)$r['order_item_id'];
    $resolved = null;
    if ($itemId > 0) {
        try { $resolved = ImagingRegionResolver::registerForItem($itemId); } catch (Exception $e) { $resolved = null; }
    }
    if ($resolved && ImagingRegionResolver::isRealUid($resolved['study_uid'])) {
        if (ImagingRegionResolver::isRealUid($uid)) { $refreshed++; }
        else { $upgraded++; }
        $details[] = '  · #' . $r['id'] . ' ' . (string)$r['patient_no'] . ' ' . $uid . ' → ' . $resolved['study_uid'];
    } else {
        $unresolved++;
        $details[] = '  · #' . $r['id'] . ' ' . (string)$r['patient_no'] . ' ' . $uid . ' → 区域 PACS 查无（申请单号 ' . (string)$r['order_no'] . '）';
        if ($deleteUnresolved) {
            ImagingRepository::exec('DELETE FROM imaging_refs WHERE id=?', array((int)$r['id']));
            $deleted++;
        }
    }
}

echo "影像引用 UID 修复完成：\n";
echo '  已是真实 UID：' . $already . " 条\n";
echo '  升级为真实 UID：' . $upgraded . " 条\n";
echo '  补充区域机构名 / 设备名（区域 PACS）：' . $refreshed . " 条\n";
echo '  区域 PACS 无法解析：' . $unresolved . " 条" . ($deleteUnresolved ? "（已删除 $deleted 条占位引用）" : "（保留；可加 --delete-unresolved 删除）") . "\n";
foreach ($details as $d) echo $d . "\n";
