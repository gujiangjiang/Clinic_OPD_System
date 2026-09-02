<?php
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '药房首页',
    'desc' => '今日发药与药品库存概览',
    'cta' => array('/pharmacy/dashboard', '💊 进入药房工作台'),
    'api' => '/api/pharmacy?action=home_stats',
    'stats' => array(
        array('drug_total', '药品总数'),
        array('today_disp', '今日发药数'),
        array('today_fee', '今日发药金额（元）'),
        array('pending_rx', '待发药处方'),
        array('low_stock', '低库存药品'),
        array('pending_audit', '待审核药品'),
    ),
    'colors' => array('low_stock' => 'var(--danger)'),
    'chart' => array('title' => '近 7 天发药量趋势', 'name' => '发药量'),
    'links' => array(
        array('/pharmacy/dashboard', '💊 药房工作台'),
        array('/admin/drugs', '📋 药品信息'),
        array('/admin/drugsettings', '📦 药品设置'),
        array('/messages', '💬 站内消息'),
    ),
    'tips' => array(
        '1. 缴费后的处方在【药房工作台】→「待发药」中处理',
        '2. 发药后药品库存自动扣减，可在「库存管理」中出入库',
        '3. 低库存药品（≤50）首页红色高亮，请及时补货',
        '4. 新增药品 / 药品设置项需管理员审核后生效',
    ),
));