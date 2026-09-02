<?php
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '检验科首页',
    'desc' => '今日检验工作概览',
    'cta' => array('/lab/dashboard', '🧪 进入检验科工作台'),
    'api' => '/api/lab?action=home_stats',
    'stats' => array(
        array('today_items', '今日检验标本量'),
        array('today_fee', '今日检验费用（元）'),
        array('pending_reg', '待登记'),
        array('pending_rep', '待出报告'),
        array('item_total', '检验项目总数'),
        array('pending_audit', '待审核项目'),
    ),
    'chart' => array('title' => '近 7 天检验量趋势', 'name' => '检验量'),
    'links' => array(
        array('/lab/dashboard', '🧪 检验科工作台'),
        array('/admin/labitems', '📋 检验管理'),
        array('/messages', '💬 站内消息'),
    ),
    'tips' => array(
        '1. 缴费后的检验项目在【检验科工作台】→「待登记」列表中',
        '2. 登记后进入「待出报告」，录入结果后自动生成报告',
        '3. 新增检验项目请到【检验管理】提交，需管理员审核后可用',
        '4. 检验组合管理请在【检验管理】→「检验组合管理」中维护',
    ),
));