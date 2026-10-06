<?php
require APP_ROOT . '/app/includes/ui/role_home.php';
render_role_home(array(
    'title' => '影像科首页',
    'desc' => '今日检查工作概览',
    'cta' => array('/imaging/dashboard', render_icon('nav:imaging') . ' 进入影像科工作台'),
    'api' => '/api/imaging?action=home_stats',
    'stats' => array(
        array('today_items', '今日检查量'),
        array('today_fee', '今日检查费用（元）'),
        array('pending_reg', '待摄片'),
        array('pending_rep', '待出报告'),
        array('item_total', '检查项目总数'),
        array('pending_audit', '待审核项目'),
    ),
    'chart' => array('title' => '近 7 天检查量趋势', 'name' => '检查量'),
    'links' => array(
        array('/imaging/dashboard', render_icon('nav:imaging') . ' 影像科工作台'),
        array('/admin/examitems', render_icon('emr:record') . ' 检查管理'),
        array('/messages', render_icon('emr:consult') . ' 站内消息'),
    ),
    'tips' => array(
        '1. 缴费后的检查项目进入等待 PACS 摄片；登记 / 摄片由 PACS 侧负责',
        '2. 影像产生后即可在【影像科工作台】→「去写报告」填写影像所见与结论并生成报告',
        '3. 暂无影像也可先书写报告（提交时确认）',
        '4. 新增检查项目请到【检查管理】提交，需管理员审核后可用',
    ),
));