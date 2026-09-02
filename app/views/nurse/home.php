<?php
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '护士站首页',
    'desc' => '今日处置执行概览',
    'cta' => array('/nurse/dashboard', '💉 进入护士工作站'),
    'api' => '/api/nurse?action=home_stats',
    'stats' => array(
        array('today_done', '今日处置执行数'),
        array('pending_exec', '待执行处置'),
        array('today_fee', '今日处置费用（元）'),
        array('disp_total', '处置项目总数'),
    ),
    'chart' => array('title' => '近 7 天处置执行趋势', 'name' => '处置执行'),
    'links' => array(
        array('/nurse/dashboard', '💉 护士工作站'),
        array('/messages', '💬 站内消息'),
    ),
    'tips' => array(
        '1. 缴费后的处置 / 医嘱在【护士工作站】中执行',
        '2. 生命体征录入后医生端实时同步',
        '3. 静脉输液等途径的处方会自动标记「护士站执行」',
    ),
));