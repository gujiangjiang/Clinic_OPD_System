<?php
/**
 * doctor/home.php — 医生首页
 * 展示个人今日接诊、开单收入、待办与近7天接诊趋势。
 */
require APP_ROOT . '/app/includes/role_home.php';
render_role_home(array(
    'title' => '医生首页',
    'desc' => '个人今日工作概览',
    'cta' => array('/doctor/emr', '🩺 进入医生工作站'),
    'api' => '/api/doctor?action=home_stats',
    'stats' => array(
        array('today_visits', '今日接诊人次'),
        array('total', '今日开单金额（元）'),
        array('drug', '药费（处方）'),
        array('lab', '检验费'),
        array('imaging', '检查费'),
        array('procedure', '处置费'),
        array('today_reg', '今日门诊人次'),
        array('drafts', '我的待完成病历'),
    ),
    'chart' => array('title' => '近 7 天接诊趋势', 'name' => '接诊人次'),
    'links' => array(
        array('/doctor/emr', '🩺 医生工作站'),
        array('/doctor/dashboard', '🖥️ 旧工作站'),
        array('/messages', '💬 站内消息'),
        array('/profile', '👤 个人信息'),
    ),
    'tips' => array(
        '1. 进入【医生工作站】后自动弹出候诊列表，选择患者即可开始书写病历',
        '2. 书写病历需完善主诉 / 现病史 / 初步诊断并保存，方可开单 / 打印 / 开具诊断证明',
        '3. 开检验 / 检查 / 处置 / 处方请点击病历右侧大纲栏分区「＋」',
        '4. 同一次挂号可多医生续写接诊，开单项目按医生归档，删除仅限本人',
    ),
));