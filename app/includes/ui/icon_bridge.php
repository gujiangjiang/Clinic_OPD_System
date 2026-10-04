<?php
/**
 * ============================================================
 * icon_bridge.php — 图标映射前端桥接（独立页面专用）
 * ============================================================
 * 说明：layout 已统一注入；screen/call/viewer 等独立整页
 * （不经 layout 渲染）需单独注入 OPD_ICON_SVGS 与 icons.js。
 * 调用方保证 bootstrap 已加载（IconHelper 可用）。
 * ============================================================ */
?>
<script>window.OPD_ICON_SVGS=<?php echo json_encode(IconHelper::allSvg()); ?>;</script>
<script src="/assets/js/components/icons.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>