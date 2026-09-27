<?php
/**
 * views/partials/header.php — 公共页头（品牌 + 导航）
 * 变量：$site（站点名）、$user（当前用户，可空）、$active（当前导航）
 */
$pvActive = isset($active) ? $active : '';
$pvUser = isset($user) ? $user : null;
$pvTitle = isset($pageTitle) ? $pageTitle . ' · ' . $site : $site;
$pvHosp = PvSettings::get('hospital_name', '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pvw_e($pvTitle); ?></title>
<link rel="stylesheet" href="<?php echo pvw_asset('css/base.css'); ?>">
<?php if (!empty($extraCss)) { foreach ((array)$extraCss as $c) { ?>
<link rel="stylesheet" href="<?php echo pvw_asset('css/' . $c); ?>">
<?php } } ?>
<script>window.PV_BOOT = <?php echo json_encode(array(
    'api'    => pvw_url('api'),
    'viewer' => pvw_url('viewer'),
    'site'   => $site,
), JSON_UNESCAPED_UNICODE); ?>;</script>
</head>
<body class="<?php echo pvw_e(isset($bodyClass) ? $bodyClass : ''); ?>">
<header class="pv-topbar">
    <a class="pv-brand" href="<?php echo pvw_e(pvw_url('search')); ?>">
        <span class="pv-logo">🩻</span>
        <span class="pv-brand-name"><?php echo pvw_e($site); ?></span>
        <?php if ($pvHosp !== '') { ?><span class="pv-brand-hosp"><?php echo pvw_e($pvHosp); ?></span><?php } ?>
    </a>
    <nav class="pv-nav">
        <a class="<?php echo $pvActive === 'search' ? 'active' : ''; ?>" href="<?php echo pvw_e(pvw_url('search')); ?>">研究检索</a>
        <?php if ($pvUser && $pvUser['role'] === 'admin') { ?>
        <a class="<?php echo $pvActive === 'admin' ? 'active' : ''; ?>" href="<?php echo pvw_e(pvw_url('admin')); ?>">管理设置</a>
        <?php } ?>
    </nav>
    <div class="pv-user">
        <?php if ($pvUser) { ?>
        <span class="pv-user-name"><?php echo pvw_e($pvUser['display_name'] !== '' ? $pvUser['display_name'] : $pvUser['username']); ?><?php echo $pvUser['role'] === 'admin' ? ' · 管理员' : ''; ?></span>
        <a class="pv-btn pv-btn-ghost pv-btn-sm" href="<?php echo pvw_e(pvw_url('logout')); ?>">退出</a>
        <?php } ?>
    </div>
</header>
<main class="pv-main">
