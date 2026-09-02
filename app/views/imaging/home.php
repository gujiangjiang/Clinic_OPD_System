<?php
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '影像科首页',
    'desc' => '今日检查工作概览',
    'cta' => array('/imaging/dashboard', '🩻 进入影像科工作台'),
    'api' => '/api/imaging?action=home_stats',
    'stats' => array(
        array('today_items', '今日检查量'),
        array('today_fee', '今日检查费用（元）'),
        array('pending_reg', '待登记'),
        array('pending_rep', '待出报告'),
        array('item_total', '检查项目总数'),
        array('pending_audit', '待审核项目'),
    ),
    'chart' => array('title' => '近 7 天检查量趋势', 'name' => '检查量'),
    'links' => array(
        array('/imaging/dashboard', '🩻 影像科工作台'),
        array('/admin/examitems', '📋 检查管理'),
        array('/messages', '💬 站内消息'),
    ),
    'tips' => array(
        '1. 缴费后的检查项目在【影像科工作台】→「待登记」列表中',
        '2. 登记后进入「待出报告」，填写影像所见与结论后生成报告',
        '3. 新增检查项目请到【检查管理】提交，需管理员审核后可用',
        '4. 报告撤回需管理员在审核中心处理',
    ),
));