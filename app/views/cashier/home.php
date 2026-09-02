<?php
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '收费处首页',
    'desc' => '今日挂号收费概览',
    'cta' => array('/cashier/register', '🎫 进入挂号收费'),
    'api' => '/api/cashier?action=home_stats',
    'stats' => array(
        array('reg_today', '今日挂号数'),
        array('reg_fee', '今日挂号费收入（元）'),
        array('paid_today', '今日缴费金额（元）'),
        array('refund_today', '今日退费金额（元）'),
        array('waiting', '今日待就诊'),
    ),
    'colors' => array('refund_today' => 'var(--danger)'),
    'chart' => array('title' => '近 7 天缴费收入趋势', 'name' => '缴费收入'),
    'links' => array(
        array('/cashier/register', '🎫 挂号收费'),
        array('/cashier/regmanage', '📋 挂号管理'),
        array('/cashier/paymanage', '💳 缴费与退费'),
        array('/messages', '💬 站内消息'),
    ),
    'tips' => array(
        '1. 【挂号收费】完成挂号并缴费，自动打印挂号凭条',
        '2. 有身份证可自助选择费用类别（自费 / 医保），无身份证仅可挂急诊且自费',
        '3. 【缴费与退费】处理开单缴费与退费，退费需选择原因',
        '4. 【挂号管理】按天查询挂号记录，支持补打凭条',
    ),
));