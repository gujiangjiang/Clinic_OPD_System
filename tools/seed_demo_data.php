<?php
/**
 * seed_demo_data.php — 演示数据生成器（仅限本地/测试环境使用）
 * 用法：frankenphp php-cli tools/seed_demo_data.php
 * 内容：基础引导（科室/账号/医院设置/基础目录）+ 目录补充 + 患者 +
 *       近30天多状态就诊 + 规范结构化病历（含 3-4 人续写）+
 *       医嘱/报告/体征/转归/诊断证明。
 * 说明：引导段仅在对应表为空时执行；主流程不幂等，请勿重复执行。
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/config/bootstrap.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
DatabaseManager::initAll();
mt_srand(20260825);

/** 本地版默认病历骨架（与 record.php emr_default_data 同构） */
function emr_default_data($patient = null) {
    return array(
        'progress' => array('content' => ''),
        'chief_complaint' => array('symptom' => '', 'duration' => '', 'unit' => '', 'second_symptom' => '', 'second_duration' => '', 'second_unit' => ''),
        'history_present' => array('informant' => '', 'duration' => '', 'unit' => '', 'content' => '', 'arrival_way' => ''),
        'past_history' => array('type' => '否认', 'detail' => ''),
        'allergies' => array('type' => '否认', 'detail' => ''),
        'main_symptoms' => array(
            '全身症状' => '', '呼吸道症状' => '', '消化道症状' => '',
            '皮疹症状' => '', '出血症状' => '', '神经系统症状' => '',
        ),
        'physical_exam' => array(
            '皮肤黏膜' => '', '头部' => '', '胸部' => '', '肺脏及胸膜' => '', '心脏' => '',
            '腹部' => '', '神经反射' => '', '肌力及肌张力' => '', '其它体格检查' => '',
        ),
        'diagnoses' => array(),
        'aux_result' => '',
        'aux_external' => '',
        'disposition_custom' => '',
        'is_leave_hospital' => '否',
        'advice' => '',
    );
}

echo "=== 开始生成演示数据 ===\n";

/* ==================== 0. 基础引导（仅空表时执行） ==================== */
if ((int)DB::val('dept', 'SELECT COUNT(*) FROM departments') === 0) {
    $depts = array(
        array('内科门诊', 'clinic', 20, 30, 30, 1), array('外科门诊', 'clinic', 20, 25, 25, 2),
        array('儿科门诊', 'clinic', 15, 25, 25, 3), array('妇产科门诊', 'clinic', 20, 20, 20, 4),
        array('急诊科', 'emergency', 50, 0, 0, 5), array('绿色通道', 'emergency', 0, 0, 0, 6),
    );
    foreach ($depts as $i => $D) {
        DB::insert('dept', 'INSERT INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,1,?)', array(
            $D[0], $D[1], $D[2], $D[3], $D[4], $D[5], now_str(),
        ));
    }
    echo "引导：已创建 6 个科室\n";
}
$pwdHash = password_hash('admin123', PASSWORD_DEFAULT);
if ((int)DB::val('user', 'SELECT COUNT(*) FROM users') === 0) {
    $users = array(
        array('0001', 'admin', '系统管理员', 'admin', '', '主任技师'),
        array('1001', 'cashier1001', '收款员', 'cashier', '', ''),
        array('2001', 'doctor2001', '张伟', 'doctor', '2,5', '主治医师'),
        array('2002', 'doctor2002', '李娜', 'doctor', '1,3', '主治医师'),
        array('2003', 'doctor2003', '王强', 'doctor', '2,4', '副主任医师'),
        array('2004', 'doctor2004', '赵敏', 'doctor', '1,5', '主任医师'),
        array('2005', 'doctor2005', '刘洋', 'doctor', '3,4', '主治医师'),
        array('2006', 'doctor2006', '钱峰', 'doctor', '1,2,5', '主任医师'),
        array('2007', 'doctor2007', '孙丽', 'doctor', '3,6', '副主任医师'),
        array('2008', 'doctor2008', '马超', 'doctor', '4,6', '主治医师'),
        array('3001', 'nurse3001', '周梅', 'nurse', '', '主管护师'),
        array('4001', 'lab4001', '陈静', 'lab', '', '主管技师'),
        array('5001', 'imaging5001', '黄浩', 'imaging', '', '主管技师'),
        array('6001', 'pharmacy6001', '吴涛', 'pharmacy', '', '主管药师'),
    );
    foreach ($users as $U) {
        DB::insert('user', 'INSERT INTO users(emp_no, username, password, name, role, dept_ids, title, theme, status, pwd_changed, created_at) VALUES(?,?,?,?,?,?,?,\'auto\',1,0,?)', array(
            $U[0], $U[1], $pwdHash, $U[2], $U[3], $U[4], $U[5], now_str(),
        ));
    }
    echo "引导：已创建 " . count($users) . " 个账号（管理员 admin / 其余用户名见 emp_no，初始密码均为 admin123）\n";
}
if (trim((string)DB::val('core', "SELECT svalue FROM settings WHERE skey='hospital_name'")) === '') {
    set_setting('hospital_name', '淮海省人民医院');
    set_setting('hospital_name2', '门诊一体化信息系统');
    set_setting('timezone', 'Asia/Shanghai');
    set_setting('logo', '');
    set_setting('install_time', now_str());
    echo "引导：已写入医院设置\n";
}

/* ==================== 1. 基础目录 + 目录补充 ==================== */
$labGroups = array(
    array('血液检验', '血常规', 25, array(
        array('白细胞计数(WBC)', '×10⁹/L', 5, '3.5-9.5'), array('红细胞计数(RBC)', '×10¹²/L', 5, '3.8-5.8'),
        array('血红蛋白(HGB)', 'g/L', 8, '115-150'), array('血小板计数(PLT)', '×10⁹/L', 6, '125-350'),
    )),
    array('尿液检验', '尿常规', 20, array(
        array('尿蛋白(PRO)', '-', 4, '阴性'), array('尿糖(GLU)', '-', 4, '阴性'), array('尿潜血(BLD)', '-', 4, '阴性'),
    )),
);
foreach ($labGroups as $G) {
    if (DB::one('lab', 'SELECT id FROM lab_items WHERE name=? AND is_group=1', array($G[1]))) continue;
    $gid = (int)DB::insert('lab', 'INSERT INTO lab_items(category,name,unit,price,normal_range,critical_low,critical_high,description,status,created_at,is_group,parent_id) VALUES(?,?,?,?,?,?,?,?,?,?,1,0)', array(
        $G[0], $G[1], '项', $G[2], '', '', '', '', 'approved', now_str(),
    ));
    foreach ($G[3] as $C) {
        DB::insert('lab', 'INSERT INTO lab_items(category,name,unit,price,normal_range,critical_low,critical_high,description,status,created_at,is_group,parent_id) VALUES(?,?,?,?,?,?,?,?,?,? ,0,?)', array(
            $G[0], $C[0], $C[1], $C[2], $C[3], '', '', '', 'approved', now_str(), $gid,
        ));
    }
}
$labDefs = array(
    array('免疫检验','C反应蛋白(CRP)',30), array('免疫检验','降钙素原(PCT)',120),
    array('生化检验','肝功能十项',65), array('生化检验','肾功能三项',45),
    array('生化检验','电解质五项',40), array('生化检验','空腹血糖',12),
    array('血液检验','凝血功能四项',85), array('血液检验','血型鉴定(ABO+Rh)',35),
    array('微生物检验','肺炎支原体抗体',60), array('免疫检验','甲状腺功能五项',150),
    array('生化检验','心肌酶谱',70), array('血液检验','血沉(ESR)',15),
);
foreach ($labDefs as $L) {
    if (DB::one('lab', 'SELECT id FROM lab_items WHERE name=?', array($L[1]))) continue;
    DB::insert('lab', 'INSERT INTO lab_items(category,name,unit,price,normal_range,critical_low,critical_high,description,status,created_at,is_group,parent_id) VALUES(?,?,?,?,?,?,?,?,?,?,0,0)', array(
        $L[0], $L[1], '项', $L[2], '', '', '', '', 'approved', now_str(),
    ));
}
$examDefs = array(
    array('DR（数字化X线）','胸部正位X线(DR)',80), array('CT','头颅CT平扫',280), array('CT','胸部CT平扫',320),
    array('CT','腹部CT平扫',340), array('MR','头颅MRI平扫',560), array('MR','腰椎MRI平扫',480),
    array('DR','腹部立位X线(DR)',90), array('DR','颈椎正侧位(DR)',110),
    array('超声','腹部彩超',120), array('超声','心脏彩超',180), array('超声','甲状腺彩超',100), array('超声','泌尿系彩超',110),
);
foreach ($examDefs as $E) {
    if (DB::one('lab', 'SELECT id FROM exam_items WHERE name=?', array($E[1]))) continue;
    DB::insert('lab', 'INSERT INTO exam_items(category,name,price,description,status,created_at) VALUES(?,?,?,?,?,?)', array(
        $E[0], $E[1], $E[2], '', 'approved', now_str(),
    ));
}
$dispDefs = array(
    array('清创缝合术(小)',150), array('换药(小)',30), array('静脉输液',12), array('肌肉注射',8),
    array('皮内注射(皮试)',6), array('雾化吸入',20), array('清创缝合术(中)',260), array('石膏固定术',180),
    array('换药(大)',50), array('导尿术',60), array('青霉素皮试',6), array('头孢菌素类皮试',6),
);
foreach ($dispDefs as $D) {
    if (DB::one('disp', 'SELECT id FROM disposal_items WHERE name=?', array($D[0]))) continue;
    DB::insert('disp', 'INSERT INTO disposal_items(name,fee,description,status,created_at) VALUES(?,?,?,?,?)', array(
        $D[0], $D[1], '', 'approved', now_str(),
    ));
}
$skinPenicillin = (int)DB::val('disp', "SELECT id FROM disposal_items WHERE name='青霉素皮试'");
$skinCeph = (int)DB::val('disp', "SELECT id FROM disposal_items WHERE name='头孢菌素类皮试'");
$drugDefs = array(
    array('阿莫西林胶囊','华北制药','华北','口服','0.5g×24粒','2粒','每日三次',12.5,500,0,1,$skinPenicillin),
    array('布洛芬缓释胶囊','中美史克','中美史克','口服','0.3g×20粒','1粒','每日两次',18,400,0,0,0),
    array('硝苯地平缓释片(伲福达)','青岛黄海','青岛黄海','口服','20mg×30片','1片','每日两次',25.5,300,0,0,0),
    array('头孢呋辛酯片','西药','口服','0.25g×12片',32,0,1,$skinCeph),
    array('阿奇霉素分散片','西药','口服','0.25g×6片',28,0,0,0),
    array('左氧氟沙星片','西药','口服','0.5g×7片',36,0,0,0),
    array('奥美拉唑肠溶胶囊','西药','口服','20mg×14粒',26,0,0,0),
    array('蒙脱石散','西药','口服','3g×10袋',22,0,0,0),
    array('氯雷他定片','西药','口服','10mg×6片',19,0,0,0),
    array('美洛昔康片','西药','口服','7.5mg×7片',24,0,0,0),
    array('二甲双胍缓释片','西药','口服','0.5g×30片',21,0,0,0),
    array('青霉素V钾片','西药','口服','0.236g×12片',15,0,1,$skinPenicillin),
    array('0.9%氯化钠注射液','西药','静脉滴注','250ml×1瓶',4,1,0,0),
    array('5%葡萄糖注射液','西药','静脉滴注','250ml×1瓶',4,1,0,0),
    array('硫酸庆大霉素注射液','西药','静脉滴注','8万U×10支',18,1,0,0),
    array('连花清瘟胶囊','中成药','口服','0.35g×24粒',14,0,0,0),
    array('板蓝根颗粒','中成药','口服','10g×20袋',13,0,0,0),
);
foreach ($drugDefs as $D) {
    if (DB::one('drug', 'SELECT id FROM drugs WHERE name=?', array($D[0]))) continue;
    // 兼容两种列布局（含厂商 / 简化）
    if (count($D) === 12) {
        DB::insert('drug', 'INSERT INTO drugs(name,category,vendor,vendor_short,package_unit,spec,form,single_dose,frequency_name,route_name,price,qty,is_rx,is_limited,note,need_nurse,status,created_at,need_skin_test,skin_test_item_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $D[0], '西药', $D[1], $D[2], '盒', $D[4], '', $D[5], $D[6], $D[3], $D[7], $D[8], 1, 0, '', $D[9], 'approved', now_str(), $D[10] > 0 ? 1 : 0, $D[11],
        ));
    } else {
        DB::insert('drug', 'INSERT INTO drugs(name,category,route_name,price,qty,need_nurse,status,created_at,need_skin_test,skin_test_item_id,single_dose,frequency_name,package_unit,spec,is_rx,is_limited,note,vendor,vendor_short,form) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $D[0], $D[1], $D[2], $D[3], $D[4], $D[5], 'approved', now_str(), $D[6] > 0 ? 1 : 0, $D[7],
            '按说明书', '每日两次', '盒', $D[3], 1, 0, '', '', '', '',
        ));
    }
}
$labSingles = array();
foreach (DB::q('lab', "SELECT id, name, price FROM lab_items WHERE is_group=0 AND parent_id=0 AND status='approved'") as $r) $labSingles[] = $r;
$exams = array();
foreach (DB::q('lab', "SELECT id, name, price FROM exam_items WHERE status='approved'") as $r) $exams[] = $r;
$disps = array();
foreach (DB::q('disp', "SELECT id, name, fee FROM disposal_items WHERE status='approved'") as $r) $disps[] = $r;
$drugs = array();
foreach (DB::q('drug', "SELECT id, name, price, spec, package_unit, vendor_short AS company_short, single_dose, frequency_name, route_name, need_nurse FROM drugs WHERE status='approved'") as $r) $drugs[] = $r;
$icdAll = array();
foreach (DB::q('icd10', 'SELECT code, name FROM icd10') as $r) $icdAll[] = $r;
echo "目录就绪：检验单项目 " . count($labSingles) . " / 检查 " . count($exams) . " / 处置 " . count($disps) . " / 药品 " . count($drugs) . " / ICD10 " . count($icdAll) . "\n";

/* ---------- 2. 医生列表 ---------- */
$doctors = array();
foreach (DB::q('user', "SELECT id, name, title, dept_ids FROM users WHERE role='doctor' AND status=1") as $r) $doctors[] = $r;
$staff = array('nurse' => '周梅', 'lab' => '陈静', 'imaging' => '黄浩', 'pharmacy' => '吴涛', 'cashier' => '收款员');

/* ---------- 3. 工具与计数器 ---------- */
function rnd($a, $b) { return mt_rand($a, $b); }
function pick($arr) { return $arr[array_rand($arr)]; }
$surnames = array('李','王','张','刘','陈','杨','赵','黄','周','吴','徐','孙','马','朱','胡','郭','何','林','罗','郑','梁','谢','宋','唐','许','韩','冯','邓','曹','彭','曾','肖','田','董','袁','潘','蒋','蔡','余','杜');
$givens = array('伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','秀英','霞','平','刚','文轩','雨欣','子涵','浩然','诗琪','梦琪','建国','建军','国强','志强','海燕','雪梅','丽华','嘉怡','晓彤','泽宇','子墨','春华','国庆');
$ccPool = array(
    array('反复头晕头痛', '3', '年', '加重', '1', '周'),
    array('咳嗽咳痰', '5', '天', '伴发热', '1', '天'),
    array('上腹部疼痛', '2', '天', '伴反酸', '6', '小时'),
    array('咽痛伴吞咽困难', '3', '天', '加重', '1', '天'),
    array('腰痛伴尿频尿急', '2', '天', '加重', '4', '小时'),
    array('胸闷气短', '1', '月', '伴心悸', '3', '天'),
    array('腹泻稀水样便', '1', '天', '伴乏力', '5', '小时'),
    array('右下腹疼痛', '8', '小时', '伴恶心呕吐', '2', '小时'),
    array('关节肿痛', '10', '天', '伴晨僵', '3', '天'),
    array('皮疹伴瘙痒', '4', '天', '加重', '1', '天'),
);
$piTails = array(
    '无寒战高热，无胸闷胸痛，饮食睡眠欠佳，二便正常。',
    '伴乏力纳差，无恶心呕吐，睡眠可，小便正常，大便干结。',
    '自服药物（具体不详）后症状缓解不明显，为求进一步诊治来院。',
    '症状呈阵发性发作，与进食无明显关系，休息后可稍缓解。',
    '起病以来精神尚可，胃纳一般，睡眠欠佳，体重无明显变化。',
);
$pePool = array(
    '神志清楚，呼吸平稳，心律齐，未闻及杂音，腹平软，无压痛，肝脾肋下未及，双下肢无水肿。',
    '体温正常，咽部充血，双侧扁桃体I度肿大，双肺呼吸音清，未闻及干湿性啰音，腹软无压痛。',
    '神清，精神可，皮肤巩膜无黄染，浅表淋巴结未及肿大，心肺腹未见明显异常，生理反射存在，病理反射未引出。',
    '急性痛苦面容，腹肌紧张，右下腹麦氏点压痛（+），反跳痛（+），肠鸣音亢进。',
);
$advicePool = array(
    '清淡饮食，多饮水，注意休息，一周后门诊复查。',
    '规律服药，避免劳累，症状加重及时就诊。',
    '低盐低脂饮食，监测血压，不适随诊。',
    '避免接触过敏原，按时服药，三天后复诊评估。',
);
$progPool = array(
    '患者诉症状较前缓解，继续目前治疗方案，嘱其注意休息，密切观察病情变化。',
    '补充询问病史：患者既往有类似发作史，未系统诊治。今日复查相关指标，调整用药。',
    '查看辅助检查结果回报，结合临床表现，目前诊断明确，继续当前治疗，一周后复诊。',
    '患者生命体征平稳，症状有所好转，维持原方案，加强健康宣教，不适随诊。',
);
$wardPool = array('呼吸内科病区','心内科病区','消化内科病区','普外科病区','骨科病区');
$hospPool = array('市第一人民医院','市中心医院','省人民医院','医科大学附属医院');
$deathPool = array('心源性猝死','多器官功能衰竭','呼吸衰竭');
$otherPool = array('症状缓解后自动离院，随访丢失','转社区卫生服务中心继续治疗','家属要求自动出院');
$consciousPool = array('清醒','嗜睡','模糊');

$patientSeq = 0;
foreach (DB::q('patient', "SELECT MAX(patient_no) m FROM patients WHERE patient_no LIKE '" . date('ymd') . "%'") as $r) {
    $patientSeq = (int)substr((string)$r['m'], -2);
}
$flowSeq = array();
foreach (DB::q('patient', "SELECT substr(flow_no,1,6) d, MAX(CAST(substr(flow_no,7) AS INTEGER)) m FROM registrations GROUP BY d") as $r) {
    $flowSeq[$r['d']] = (int)$r['m'];
}
$seqSeq = array();
foreach (DB::q('patient', "SELECT first_dept_id dp, substr(register_time,1,10) d, MAX(visit_seq) m FROM registrations GROUP BY dp, d") as $r) {
    $seqSeq[$r['dp'] . '_' . $r['d']] = (int)$r['m'];
}
$reportSeq = array();
foreach (DB::q('lab', "SELECT substr(report_no,3,8) d, MAX(CAST(substr(report_no,11) AS INTEGER)) m FROM reports GROUP BY d") as $r) {
    $reportSeq[$r['d']] = (int)$r['m'];
}

/* ---------- 4. 患者 ---------- */
$NEW_PATIENTS = 48;
$patientIds = array();
foreach (DB::q('patient', 'SELECT id FROM patients') as $r) $patientIds[] = (int)$r['id'];
$basePatient = count($patientIds);
for ($i = 0; $i < $NEW_PATIENTS; $i++) {
    $patientSeq++;
    $name = pick($surnames) . pick($givens);
    $gender = pick(array('男', '女'));
    $age = rnd(3, 88);
    $birth = date((intval(date('Y')) - $age) . '-m-d', mt_rand(0, time()));
    $pid = (int)DB::insert('patient', 'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, past_history_type, past_history_detail, allergies, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        date('ymd') . sprintf('%02d', $patientSeq),
        date('ymd') . sprintf('%02d', $patientSeq) . sprintf('%04d', rnd(1000, 9999)) . sprintf('%04d', rnd(1000, 9999)),
        $name, $gender, $birth, $age, '汉族', $age > 25 ? '已婚' : '未婚',
        pick(array('职员','工人','教师','退休','学生','自由职业')),
        '', '本市', '13' . sprintf('%09d', rnd(100000000, 999999999)),
        pick(array('否认','承认')), '', '', now_str(),
    ));
    $patientIds[] = $pid;
}
echo "患者：{$basePatient} + {$NEW_PATIENTS} = " . count($patientIds) . "\n";

/* ---------- 5. 就诊（近30天 + 今天） ---------- */
$today = date('Y-m-d');
$visits = array();
$visitCount = 0;
for ($d = 30; $d >= 0; $d--) {
    $day = date('Y-m-d', strtotime("-{$d} days"));
    $dayPrefix = date('ymd', strtotime($day));
    $n = ($d === 0) ? 11 : rnd(2, 6);
    for ($j = 0; $j < $n; $j++) {
        $dept = pick(array(1, 1, 2, 2, 3, 4, 5, 6));
        $deptRow = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($dept));
        if (!$deptRow) continue;
        $docPool = array();
        foreach ($doctors as $doc) {
            if (in_array((string)$dept, explode(',', (string)$doc['dept_ids']))) $docPool[] = $doc;
        }
        if (!count($docPool)) $docPool = $doctors;
        $doc = pick($docPool);
        $status = ($d === 0)
            ? pick(array('pending', 'paid', 'paid', 'visiting', 'visiting', 'finished'))
            : ((mt_rand(1, 100) <= 92) ? 'finished' : pick(array('paid', 'refunded', 'cancelled')));
        $ts = strtotime($day . ' ' . sprintf('%02d:%02d', rnd(8, 16), rnd(0, 59)));
        $regTime = date('Y-m-d H:i:s', $ts);
        $flowSeq[$dayPrefix] = (isset($flowSeq[$dayPrefix]) ? $flowSeq[$dayPrefix] : 0) + 1;
        $flowNo = $dayPrefix . sprintf('%04d', $flowSeq[$dayPrefix]);
        $skey = $dept . '_' . $day;
        $seqSeq[$skey] = (isset($seqSeq[$skey]) ? $seqSeq[$skey] : 0) + 1;
        $visitSeq = $seqSeq[$skey];
        $fee = $deptRow['type'] === 'emergency' ? 50 : pick(array(10, 20));
        $payTime = '';
        if (in_array($status, array('paid', 'visiting', 'finished'), true)) {
            $payTime = date('Y-m-d H:i:s', $ts + rnd(180, 1800));
        }
        $disp = ''; $dispDetail = '';
        if ($status === 'finished' && $d <= 12) {
            $r2 = mt_rand(1, 100);
            if ($r2 <= 68) { $disp = '自主离院'; }
            elseif ($r2 <= 80) { $disp = '住院'; $dispDetail = pick($wardPool); }
            elseif ($r2 <= 88) { $disp = '转院'; $dispDetail = pick($hospPool); }
            elseif ($r2 <= 94) { $disp = '死亡'; $dispDetail = pick($deathPool); }
            else { $disp = '其他'; $dispDetail = pick($otherPool); }
        }
        $pid = pick($patientIds);
        $prow = DB::one('patient', 'SELECT patient_no FROM patients WHERE id=?', array($pid));
        if (!$prow) continue;
        $visitId = (int)DB::insert('patient', 'INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, payment_time, cashier_id, cashier_name, register_time, cancel_reason, is_extra, disposition, disposition_detail) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $prow['patient_no'], $flowNo, $visitSeq, $dept, $deptRow['name'], $dept, $deptRow['name'],
            pick(array('上午','下午')), pick(array('自费','居民医保','职工医保','自费')), $fee,
            $status, $payTime, 2, $staff['cashier'], $regTime, '', 0, $disp, $dispDetail,
        ));
        $visitCount++;
        $visits[] = array(
            'id' => $visitId, 'patient_no' => $prow['patient_no'], 'flow_no' => $flowNo,
            'dept' => $dept, 'dept_name' => $deptRow['name'], 'doctor' => $doc,
            'status' => $status, 'reg_time' => $regTime, 'pay_time' => $payTime, 'ts' => $ts,
        );
    }
}
echo "就诊生成：{$visitCount} 条\n";

/* ---------- 6. 缴费流水（kind=visit） ---------- */
foreach ($visits as $v) {
    if ($v['pay_time'] !== '') {
        DB::insert('order', 'INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
            $v['id'], 0, $v['patient_no'], $v['flow_no'], 'visit',
            DB::val('patient', 'SELECT fee FROM registrations WHERE id=?', array($v['id'])),
            1, 2, $staff['cashier'], $v['pay_time'],
        ));
    }
}

/* ---------- 7. 生命体征 + 医嘱 + 病历（含多人续写） ---------- */
$multiLeft = 8;
$recordCount = 0; $orderCount = 0; $itemCount = 0; $resultCount = 0; $vitalCount = 0; $certCount = 0;
foreach ($visits as $v) {
    $ts = $v['ts'];
    $doc = $v['doctor'];
    $finished = $v['status'] === 'finished';
    $hasRecord = in_array($v['status'], array('finished', 'visiting'), true) || ($v['status'] === 'paid' && mt_rand(1, 100) <= 15);

    /* --- 医嘱 --- */
    $visitOrders = array();
    $nOrd = $hasRecord ? rnd(1, 3) : (mt_rand(1, 100) <= 40 ? 1 : 0);
    $auxNames = array(); $rxLines = array(); $dispItems = array();
    for ($k = 0; $k < $nOrd; $k++) {
        $otype = pick(array('lab', 'imaging', 'procedure', 'prescription'));
        $prefix = array('lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ', 'prescription' => 'CF');
        $created = date('Y-m-d H:i:s', $ts + rnd(600, 3600));
        $paidAt = ''; $ostatus = 'open'; $execBy = ''; $execAt = '';
        if ($v['pay_time'] !== '') {
            $paidAt = date('Y-m-d H:i:s', strtotime($v['pay_time']) + rnd(120, 1200));
            $ostatus = 'paid';
            if ($finished) {
                $ostatus = ($otype === 'prescription') ? 'dispensed' : 'done';
                $execBy = ($otype === 'prescription') ? $staff['pharmacy'] : (($otype === 'imaging') ? $staff['imaging'] : ($otype === 'procedure' ? $staff['nurse'] : $staff['lab']));
                $execAt = date('Y-m-d H:i:s', strtotime($paidAt) + rnd(600, 5400));
            }
        }
        $total = 0; $itemRows = array();
        if ($otype === 'lab') {
            $n2 = rnd(1, 3);
            $used = array();
            for ($q = 0; $q < $n2; $q++) {
                $it = pick($labSingles);
                if (isset($used[$it['id']])) continue;
                $used[$it['id']] = 1;
                $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array());
                $total += (float)$it['price'];
                $auxNames[] = $it['name'];
            }
        } elseif ($otype === 'imaging') {
            $it = pick($exams);
            $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array());
            $total += (float)$it['price'];
            $auxNames[] = $it['name'];
        } elseif ($otype === 'procedure') {
            $n2 = rnd(1, 2);
            $used = array();
            for ($q = 0; $q < $n2; $q++) {
                $it = pick($disps);
                if (isset($used[$it['id']])) continue;
                $used[$it['id']] = 1;
                $qty = rnd(1, 3);
                $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['fee'], 'qty' => $qty, 'extra' => array());
                $total += (float)$it['fee'] * $qty;
                $dispItems[] = array('name' => $it['name'], 'qty' => $qty);
            }
        } else {
            $n2 = rnd(1, 4);
            $used = array();
            for ($q = 0; $q < $n2; $q++) {
                $it = pick($drugs);
                if (isset($used[$it['id']])) continue;
                $used[$it['id']] = 1;
                $qty = rnd(1, 3);
                $itemRows[] = array(
                    'item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => $qty,
                    'extra' => array(
                        'spec' => $it['spec'], 'unit_name' => $it['package_unit'], 'company_short' => $it['company_short'],
                        'single_dose' => $it['single_dose'], 'frequency_name' => $it['frequency_name'],
                        'route_name' => $it['route_name'], 'need_nurse' => $it['need_nurse'],
                    ),
                );
                $total += (float)$it['price'] * $qty;
                $rxLines[] = '<div class="ef-rx-line">' . $it['name'] . '　' . $it['single_dose'] . '　' . $it['frequency_name'] . '　' . $it['route_name'] . '　×' . $qty . '</div>';
            }
        }
        if (!count($itemRows)) continue;
        do {
            $orderNo = $prefix[$otype] . date('YmdHis', $ts) . sprintf('%02d', rnd(0, 99));
        } while (DB::one('order', 'SELECT id FROM orders WHERE order_no=?', array($orderNo)));
        $orderId = (int)DB::insert('order', 'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, cat_name, doctor_id, doctor_name, total_amount, status, created_at, paid_at, refunded_at, done_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $v['id'], $v['patient_no'], $v['flow_no'], $otype, $orderNo, '', $doc['id'], $doc['name'],
            $total, $ostatus, $created, $paidAt, '', $ostatus === 'done' ? $execBy : '',
        ));
        $orderCount++;
        $itemIds = array();
        foreach ($itemRows as $ir) {
            $ex = $ir['extra'];
            $iid = (int)DB::insert('order', 'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit_name, company_short, price, quantity, single_dose, frequency_name, route_name, need_nurse, sub_of, group_no, is_parent, parent_item_id, status, doctor_id, doctor_name, executed_by, executed_at, result_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $orderId, $v['id'], $v['patient_no'], $v['flow_no'], $otype, $ir['item_id'], $ir['item_name'],
                isset($ex['spec']) ? $ex['spec'] : '', isset($ex['unit_name']) ? $ex['unit_name'] : '', isset($ex['company_short']) ? $ex['company_short'] : '',
                $ir['price'], $ir['qty'],
                isset($ex['single_dose']) ? $ex['single_dose'] : '', isset($ex['frequency_name']) ? $ex['frequency_name'] : '', isset($ex['route_name']) ? $ex['route_name'] : '',
                isset($ex['need_nurse']) ? $ex['need_nurse'] : 0,
                0, 0, 1, 0, $ostatus, $doc['id'], $doc['name'],
                ($ostatus === 'dispensed' || $ostatus === 'done') ? $execBy : '',
                ($ostatus === 'dispensed' || $ostatus === 'done') ? $execAt : '',
                0, $created,
            ));
            $itemIds[$ir['item_id']] = $iid;
            $itemCount++;
        }
        $visitOrders[] = array('id' => $orderId, 'type' => $otype, 'status' => $ostatus, 'paid_at' => $paidAt, 'itemIds' => $itemIds, 'created' => $created);
        if ($ostatus === 'done' && ($otype === 'lab' || $otype === 'imaging')) {
            foreach ($itemIds as $itemId => $iid) {
                $vals = array();
                foreach (DB::q('lab', 'SELECT id, normal_range FROM lab_items WHERE id=?', array($itemId)) as $li) {
                    if ($li['normal_range'] !== '') {
                        $parts = explode('-', $li['normal_range']);
                        if (count($parts) === 2 && is_numeric($parts[0])) {
                            $vals[$itemId] = round((float)$parts[0] + ((float)$parts[1] - (float)$parts[0]) * (mt_rand() / mt_getrandmax()), 1);
                        }
                    }
                    break;
                }
                $valuesJson = count($vals) ? json_encode(array('values' => $vals), JSON_UNESCAPED_UNICODE) : '{}';
                $resTs = strtotime($paidAt) + rnd(1800, 7200);
                $resultId = (int)DB::insert('lab', 'INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, findings, conclusion, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $itemId, $iid, $v['id'], $v['patient_no'], $v['flow_no'], $otype, $valuesJson,
                    $otype === 'imaging' ? pick(array('未见明显异常','所见骨质结构完整','双肺纹理增粗')) : '',
                    $otype === 'imaging' ? pick(array('符合临床诊断','请结合临床','建议必要时复查')) : '',
                    $execBy, 'done', date('Y-m-d H:i:s', $resTs), date('Y-m-d H:i:s', $resTs),
                ));
                $dayKey = date('Ymd', $resTs);
                $reportSeq[$dayKey] = (isset($reportSeq[$dayKey]) ? $reportSeq[$dayKey] : 0) + 1;
                DB::insert('lab', 'INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, content, doctor, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $resultId, 'BG' . $dayKey . sprintf('%04d', $reportSeq[$dayKey]),
                    $v['id'], $v['patient_no'], $v['flow_no'], $otype, '',
                    $execBy, 'done', date('Y-m-d H:i:s', $resTs),
                ));
                DB::exec('order', 'UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $iid));
                $resultCount++;
            }
        }
    }

    if (!$hasRecord) continue;

    /* --- 生命体征（首诊医生） --- */
    $vitalsText = '';
    $consciousness = pick($consciousPool);
    if (mt_rand(1, 100) <= 85) {
        $sys = rnd(95, 165); $dia = rnd(60, 100); $hr = rnd(58, 108); $spo2 = rnd(93, 100); $resp = rnd(13, 21);
        $vTime = date('Y-m-d H:i:s', $ts + rnd(300, 2400));
        DB::insert('nurse', 'INSERT INTO vitals(visit_id, patient_no, flow_no, bp_systolic, bp_diastolic, heart_rate, pulse, spo2, respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $v['id'], $v['patient_no'], $v['flow_no'], $sys, $dia, $hr, $hr, $spo2, $resp, $doc['name'], $vTime,
        ));
        $vitalsText = '血压 ' . $sys . '/' . $dia . 'mmHg；心率 ' . $hr . '次/分；血氧 ' . $spo2 . '%；呼吸 ' . $resp . '次/分';
        $vitalCount++;
    }

    /* --- 首诊病历 --- */
    $cc = pick($ccPool);
    $diagPick = array();
    $used = array();
    for ($q = 0; $q < rnd(1, 3); $q++) {
        $dg = pick($icdAll);
        if (isset($used[$dg['code']])) continue;
        $used[$dg['code']] = 1;
        $diagPick[] = array(
            'code' => $dg['code'], 'name' => $dg['name'],
            'part' => (mt_rand(1, 100) <= 25 ? pick(array('左侧','右侧','右上肢')) : ''),
            'note' => (mt_rand(1, 100) <= 20 ? pick(array('中指挫擦伤','既往类似发作')) : ''),
            'suspected' => (mt_rand(1, 100) <= 15 ? '是' : ''),
        );
    }
    $emr = emr_default_data(null);
    $emr['chief_complaint'] = array('symptom' => $cc[0], 'duration' => $cc[1], 'unit' => $cc[2], 'second_symptom' => $cc[3], 'second_duration' => $cc[4], 'second_unit' => $cc[5]);
    $emr['history_present'] = array('content' => '患者于' . $cc[1] . $cc[2] . '前无明显诱因出现' . $cc[0] . '，' . $cc[3] . $cc[4] . $cc[5] . '，' . pick($piTails));
    $phType = pick(array('否认', '承认'));
    $emr['past_history'] = array('type' => $phType, 'detail' => $phType === '承认' ? pick(array('高血压病史5年','2型糖尿病史3年','慢性胃炎病史')) : '');
    $emr['allergies'] = array('type' => pick(array('否认','承认')), 'detail' => '');
    $emr['physical_exam'] = array('content' => pick($pePool));
    $emr['diagnoses'] = $diagPick;
    $emr['advice'] = pick($advicePool);
    $recCreated = date('Y-m-d H:i:s', $ts + rnd(900, 4500));
    $recUpdated = $finished ? date('Y-m-d H:i:s', strtotime($recCreated) + rnd(600, 3600)) : $recCreated;
    $printText = emr_print_text($emr, $vitalsText, $consciousness, $auxNames, $rxLines, $dispItems);
    $diagText = emr_diag_text($diagPick);
    $initialId = (int)DB::insert('medical', 'INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, main_symptom, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergies, is_leave_hospital, primary_icd10, primary_diagnosis, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        $v['id'], $v['patient_no'], $v['flow_no'], $v['dept'], $doc['id'], $doc['name'],
        'initial', 0, $cc[0], $cc[1], $cc[2], '患者自诉', '自行来院',
        $phType, '', '否', (string)$diagPick[0]['code'], (string)$diagPick[0]['name'],
        json_encode($emr, JSON_UNESCAPED_UNICODE), $printText,
        $finished ? 'done' : 'draft', $recCreated, $recUpdated,
    ));
    $recordCount++;
    DB::insert('medical', 'INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, past_history, allergy_history, physical_exam, consciousness, initial_diagnosis, diagnosis_code, is_observation, visit_type, advice, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        $v['id'], $v['patient_no'], $v['flow_no'], $v['dept'], $doc['id'], $doc['name'],
        $cc[0], $emr['history_present']['content'], $emr['past_history']['detail'], '',
        $emr['physical_exam']['content'], $consciousness, $diagText, (string)$diagPick[0]['code'],
        $emr['is_leave_hospital'] === '是' ? 1 : 0, '初诊', $emr['advice'],
        $finished ? 'done' : 'draft', $recCreated, $recUpdated,
    ));

    /* --- 多人续写（额外 2-3 名医生，共 3-4 人书写） --- */
    $needMulti = ($finished && mt_rand(1, 100) <= 45);
    if ($needMulti) {
        $writers = $doctors;
        shuffle($writers);
        $nWriters = rnd(2, 3);
        $usedW = array($doc['id'] => 1);
        $parent = $initialId;
        $wTime = strtotime($recUpdated);
        $wCount = 0;
        foreach ($writers as $w) {
            if ($wCount >= $nWriters) break;
            if (isset($usedW[$w['id']])) continue;
            $usedW[$w['id']] = 1;
            $wCount++;
            $wTime += rnd(1800, 7200);
            $wTimeStr = date('Y-m-d H:i:s', $wTime);
            $wVitals = '';
            if (mt_rand(1, 100) <= 60) {
                $sys = rnd(95, 160); $dia = rnd(60, 98); $hr = rnd(60, 105); $spo2 = rnd(93, 100); $resp = rnd(13, 21);
                DB::insert('nurse', 'INSERT INTO vitals(visit_id, patient_no, flow_no, bp_systolic, bp_diastolic, heart_rate, pulse, spo2, respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
                    $v['id'], $v['patient_no'], $v['flow_no'], $sys, $dia, $hr, $hr, $spo2, $resp, $w['name'], $wTimeStr,
                ));
                $wVitals = '血压 ' . $sys . '/' . $dia . 'mmHg；心率 ' . $hr . '次/分；血氧 ' . $spo2 . '%；呼吸 ' . $resp . '次/分';
                $vitalCount++;
            }
            $wDiags = array();
            if (mt_rand(1, 100) <= 60) {
                $wDiags[] = $diagPick[0];   // 引用首诊主诊断（产生「引用」场景）
                if (mt_rand(1, 100) <= 40) {
                    $dg2 = pick($icdAll);
                    $wDiags[] = array('code' => $dg2['code'], 'name' => $dg2['name'], 'part' => '', 'note' => '', 'suspected' => '');
                }
            }
            $wEmr = emr_default_data(null);
            $wEmr['progress'] = array('content' => pick($progPool));
            $wEmr['diagnoses'] = $wDiags;
            $wEmr['advice'] = pick($advicePool);
            $wPrint = emr_print_text($wEmr, $wVitals, '', array(), array(), array());
            $wDiagText = count($wDiags) ? emr_diag_text($wDiags) : '';
            DB::insert('medical', 'INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, main_symptom, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergies, is_leave_hospital, primary_icd10, primary_diagnosis, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $v['id'], $v['patient_no'], $v['flow_no'], $v['dept'], $w['id'], $w['name'],
                'progress', $parent, '', '', '', '患者自诉', '自行来院',
                '', '', '否',
                count($wDiags) ? (string)$wDiags[0]['code'] : '',
                count($wDiags) ? (string)$wDiags[0]['name'] : '',
                json_encode($wEmr, JSON_UNESCAPED_UNICODE), $wPrint,
                'done', $wTimeStr, $wTimeStr,
            ));
            DB::insert('medical', 'INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, consciousness, initial_diagnosis, diagnosis_code, visit_type, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $v['id'], $v['patient_no'], $v['flow_no'], $v['dept'], $w['id'], $w['name'],
                '', '', '', $wDiagText, count($wDiags) ? (string)$wDiags[0]['code'] : '', '', 'done', $wTimeStr, $wTimeStr,
            ));
            $recordCount++;
            $parent = (int)DB::val('medical', 'SELECT MAX(id) FROM patient_records WHERE visit_id=?', array($v['id']));
        }
    }

    /* --- 诊断证明（少量诊毕就诊） --- */
    if ($finished && $certCount < 6 && mt_rand(1, 100) <= 8) {
        $certCount++;
        $cTs = strtotime($recUpdated) + rnd(600, 3000);
        do {
            $certNo = 'ZM' . date('YmdHis', $cTs) . sprintf('%02d', rnd(0, 99));
        } while (DB::one('medical', 'SELECT id FROM certificates WHERE cert_no=?', array($certNo)));
        DB::insert('medical', 'INSERT INTO certificates(visit_id, patient_no, flow_no, doctor_id, doctor_name, content, created_at, cert_no, chief_complaint, present_illness, initial_diagnosis) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $v['id'], $v['patient_no'], $v['flow_no'], $doc['id'], $doc['name'],
            pick(array('建议休息3天，清淡饮食，规律服药，门诊随访。','建议休息1周，避免剧烈运动，一周后复查。','建议多饮水休息，症状加重及时就诊。')),
            date('Y-m-d H:i:s', $cTs), $certNo, $cc[0], $emr['history_present']['content'], $diagText,
        ));
    }
}
echo "病历：{$recordCount} 条（含多人续写）｜ 医嘱单：{$orderCount} ｜ 明细：{$itemCount} ｜ 报告：{$resultCount} ｜ 体征：{$vitalCount} ｜ 证明：{$certCount}\n";
echo "=== 生成完毕 ===\n";
