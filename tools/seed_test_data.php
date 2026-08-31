<?php
/**
 * tools/seed_test_data.php — 填充测试数据（30+ 患者，多种状态，多医生续写/会诊）
 * ============================================================
 * 用法：~/.local/bin/frankenphp php-cli tools/seed_test_data.php
 * 说明：本脚本仅填充数据库，不修改任何代码文件。
 * ============================================================ */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/config/bootstrap.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
DatabaseManager::initAll();
mt_srand();

$pdo = DatabaseManager::getMain();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== 开始填充测试数据 ===\n\n";

/** 生成 patient_no：YYMMDD + 2位序号 */
function gen_patient_no($seq) {
    return date('ymd') . str_pad($seq, 2, '0', STR_PAD_LEFT);
}

/** 生成 flow_no：YYMMDD + 4位序号 */
function gen_flow_no($seq) {
    return date('ymd') . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/** 生成 order_no */
function gen_order_no($type) {
    $map = ['prescription' => 'CF', 'lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ'];
    $prefix = $map[$type] ?? 'XX';
    return $prefix . date('YmdHis') . str_pad(mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
}

/** 生成 visit_seq（基于科室） */
function next_visit_seq($pdo, $deptId) {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(visit_seq),0) FROM registrations WHERE first_dept_id=? AND date(registered_at)=?");
    $stmt->execute([$deptId, date('Y-m-d')]);
    return (int)$stmt->fetchColumn() + 1;
}

/** 生成身份证号 */
function gen_id_card($patientNo) {
    // 生成 18 位身份证（前17位+校验位）
    $prefix = '110101' . '19' . str_pad(mt_rand(70, 99), 2, '0', STR_PAD_LEFT) . 
              str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT) . 
              str_pad(mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
    $body = $prefix . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
    // 简单校验位
    $weights = [7,9,10,5,8,4,2,1,6,3,7,9,10,5,8,4,2];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) $sum += (int)$body[$i] * $weights[$i];
    $check = '10X98765432'[$sum % 11];
    return $body . $check;
}

// ===== 预加载字典数据 =====

echo "加载字典数据...\n";

// ICD-10 诊断（独立字典库）
$icd10pdo = DatabaseManager::getIcd10();
$icd10Rows = $icd10pdo->query("SELECT code, name FROM icd10 ORDER BY RANDOM() LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$diagPool = [];
foreach ($icd10Rows as $r) $diagPool[] = $r;

// 药品
$drugRows = $pdo->query("SELECT id, name, spec, package_unit, price, single_dose, frequency, route, is_rx, is_skin_test, skin_test_item_id FROM drugs WHERE status='approved'")->fetchAll(PDO::FETCH_ASSOC);
$drugPool = [];
foreach ($drugRows as $r) $drugPool[] = $r;

// 检验项目
$labRows = $pdo->query("SELECT id, category, name, unit, price FROM lab_items WHERE is_group=0 AND status='approved' ORDER BY RANDOM()")->fetchAll(PDO::FETCH_ASSOC);
$labPool = [];
foreach ($labRows as $r) $labPool[] = $r;

// 检查项目
$examRows = $pdo->query("SELECT id, category, name, price FROM exam_items WHERE status='approved' ORDER BY RANDOM()")->fetchAll(PDO::FETCH_ASSOC);
$examPool = [];
foreach ($examRows as $r) $examPool[] = $r;

// 处置项目
$dispRows = $pdo->query("SELECT id, name, fee, is_nurse FROM disposal_items WHERE status='approved' ORDER BY RANDOM()")->fetchAll(PDO::FETCH_ASSOC);
$dispPool = [];
foreach ($dispRows as $r) $dispPool[] = $r;

// 医生
$docRows = $pdo->query("SELECT id, name, dept_ids FROM users WHERE role='doctor'")->fetchAll(PDO::FETCH_ASSOC);
$doctorPool = [];
foreach ($docRows as $r) {
    $depts = explode(',', $r['dept_ids']);
    $doctorPool[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'depts' => $depts];
}

echo "已加载: " . count($diagPool) . " 诊断, " . count($drugPool) . " 药品, " . count($labPool) . " 检验, " . count($examPool) . " 检查, " . count($dispPool) . " 处置, " . count($doctorPool) . " 医生\n\n";

// ===== 患者数据 =====

$surnames = ['王','李','张','刘','陈','杨','黄','赵','周','吴','徐','孙','马','胡','朱','郭','何','罗','高','林','梁','宋','唐','韩','曹','许','邓','冯','萧','程','曹','袁','田','潘','于','蒋','蔡','余','杜','叶','程','苏','魏','吕','丁','任','沈','姚','卢','姜','崔','钟','谭','陆','汪','范','金','石','廖','贾','夏','韦','傅','方','白','邹','孟','熊','秦','邱','江','尹','薛','闫','段','雷','侯','龙','史','陶','黎','贺','顾','毛','郝','龚','邵','万','钱','严','覃','武','戴','莫','孔','向','汤','温','康','施','文','牛','樊','葛','邢','安','齐','易','乔','伍','庞','颜','倪','庄','聂','章','鲁','岳','翟','殷','詹','申','欧','耿','关','兰','焦','俞','左','柳','甘','祝','包','宁','尚','符','舒','阮','柯','纪','梅','童','凌','毕','单','季','裴','霍','涂','成','苗','谷','盛','曲','翁','冉','路','车','华','管','辛','简','时','连','覃','区','阳','贝','曾','沙','赖','班','申','宫','欧','应','佟','浦','芦','牟','聂','龙','庚','尚','楚','司','骆','御','饶','鞠','夏','慎','匡','解','兰','经','荀','攸','甄','景','雍','储','穆','竺','暨','檀','谌','慕容','司徒','欧阳','诸葛','夏侯','上官','司马','东方','令狐','皇甫','公孙','钟离','长孙','宇文','鲜于','闾丘','司空','亓官','司寇','子车','颛孙','端木','巫马','公西','漆雕','乐正','壤驷','公良','拓跋','夹谷','宰父','谷梁','段干','百里','东郭','南门','呼延','羊舌','微生','梁丘','左丘','东门','西门','南宫','第五'];
$givenNames = ['伟','芳','娜','秀英','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','秀兰','霞','平','刚','桂英','文','华','建','飞','玲','斌','龙','玉兰','斌','鹏','桂兰','英','萍','荣','军','香','兰','锋','晶','梅','波','新','斌','辉','红','玲','刚','峰','玲','俊','丽','华','飞','玲','涛','明','洋','勇','艳','杰','娟','涛','明','超','秀兰','霞','平','刚','桂英','文','华','建','飞','玲','斌','龙','玉兰','斌','鹏','桂兰','英','萍','香','锋','晶','梅','波','红','辉','玲','峰','俊','华'];

$deptNames = [1=>'内科门诊',2=>'外科门诊',3=>'儿科门诊',4=>'妇产科门诊',5=>'急诊科',6=>'绿色通道'];
$feeTypes = ['自费','职工医保','居民医保'];
$sessions = ['上午','下午','all'];
$dispositions = ['自主离院','住院','转院','死亡','其他'];
$genders = ['男','女'];
$arrivalWays = ['自行来院','120送诊','社区转诊'];
$informants = ['患者自诉','家属代诉','他人代诉'];

// 完整的体格检查文本（用于 initial 的 physical_exam.content）
$peTexts = [
    '神清，精神可，皮肤巩膜无黄染，浅表淋巴结未及肿大，心肺腹未见明显异常，生理反射存在，病理反射未引出。',
    '神清，精神差，咽部充血，扁桃体II°肿大，双肺呼吸音粗，未闻及干湿啰音，心率齐，各瓣膜听诊区未闻及病理性杂音。',
    '神清，精神可，双肺呼吸音清，未闻及干湿啰音，心率齐，腹软，无压痛、反跳痛及肌紧张，肠鸣音正常。',
    '神清，精神可，心肺腹未见明显异常。脊柱四肢无畸形，活动自如。神经系统检查：肌力V级，肌张力正常，病理征阴性。',
    '神清，精神可，咽部无充血，扁桃体无肿大，双肺呼吸音清，未闻及干湿啰音，心率齐，各瓣膜听诊区未闻及病理性杂音，腹平软，无压痛，肠鸣音正常。',
    '神清，精神可，头颅无畸形，双侧瞳孔等大正圆，对光反射灵敏。颈软，无抵抗。双肺呼吸音清，未闻及干湿啰音。心率齐，律齐。腹平软，无压痛反跳痛。',
];

// 现病史文本
$piTexts = [
    '患者于{time}前无明显诱因出现{complaint}。',
    '患者于{time}前因受凉后出现{complaint}。',
    '患者于{time}前因劳累后出现{complaint}。',
    '患者于{time}前进食不洁食物后出现{complaint}。',
    '患者于{time}前无明显诱因出现{complaint}，伴{second}。',
];

$complaintTemplates = [
    ['symptom'=>'咳嗽咳痰','duration'=>'3','unit'=>'天','diag'=>'J06.9','diagName'=>'急性上呼吸道感染'],
    ['symptom'=>'发热','duration'=>'2','unit'=>'天','second'=>'咽痛','diag'=>'J02.9','diagName'=>'急性咽炎'],
    ['symptom'=>'头痛','duration'=>'5','unit'=>'天','second'=>'恶心','diag'=>'R51','diagName'=>'头痛'],
    ['symptom'=>'腹痛','duration'=>'2','unit'=>'天','second'=>'腹泻','diag'=>'A09.0','diagName'=>'急性肠炎'],
    ['symptom'=>'胸闷','duration'=>'7','unit'=>'天','second'=>'气短','diag'=>'I10','diagName'=>'原发性高血压'],
    ['symptom'=>'关节肿痛','duration'=>'10','unit'=>'天','second'=>'晨僵','diag'=>'M17.9','diagName'=>'膝骨关节炎'],
    ['symptom'=>'头晕','duration'=>'3','unit'=>'天','second'=>'视物模糊','diag'=>'R42','diagName'=>'头晕'],
    ['symptom'=>'上腹痛','duration'=>'1','unit'=>'周','second'=>'反酸','diag'=>'K25.9','diagName'=>'胃溃疡'],
    ['symptom'=>'皮疹','duration'=>'4','unit'=>'天','second'=>'瘙痒','diag'=>'L50.9','diagName'=>'荨麻疹'],
    ['symptom'=>'腰痛','duration'=>'2','unit'=>'周','second'=>'下肢麻木','diag'=>'M54.5','diagName'=>'腰痛'],
    ['symptom'=>'咽痛','duration'=>'3','unit'=>'天','second'=>'发热','diag'=>'J03.9','diagName'=>'急性扁桃体炎'],
    ['symptom'=>'心悸','duration'=>'1','unit'=>'月','second'=>'乏力','diag'=>'E05.9','diagName'=>'甲状腺功能亢进症'],
    ['symptom'=>'多饮多尿','duration'=>'2','unit'=>'月','second'=>'体重下降','diag'=>'E11.9','diagName'=>'2型糖尿病'],
    ['symptom'=>'咳嗽','duration'=>'1','unit'=>'周','second'=>'黄痰','diag'=>'J15.9','diagName'=>'细菌性肺炎'],
    ['symptom'=>'恶心呕吐','duration'=>'1','unit'=>'天','second'=>'腹泻','diag'=>'K52.9','diagName'=>'急性胃肠炎'],
    ['symptom'=>'胸痛','duration'=>'3','unit'=>'天','second'=>'胸闷','diag'=>'I20.0','diagName'=>'不稳定型心绞痛'],
    ['symptom'=>'乏力','duration'=>'1','unit'=>'月','second'=>'头晕','diag'=>'D50.9','diagName'=>'缺铁性贫血'],
    ['symptom'=>'下肢水肿','duration'=>'5','unit'=>'天','second'=>'尿少','diag'=>'N00.9','diagName'=>'急性肾炎综合征'],
    ['symptom'=>'皮肤瘙痒','duration'=>'1','unit'=>'周','second'=>'皮疹','diag'=>'L29.9','diagName'=>'皮肤瘙痒'],
    ['symptom'=>'失眠','duration'=>'2','unit'=>'周','second'=>'头痛','diag'=>'F51.0','diagName'=>'失眠症'],
    ['symptom'=>'便秘','duration'=>'1','unit'=>'月','second'=>'腹胀','diag'=>'K59.0','diagName'=>'便秘'],
    ['symptom'=>'视力下降','duration'=>'1','unit'=>'周','second'=>'眼红','diag'=>'H10.9','diagName'=>'结膜炎'],
    ['symptom'=>'耳鸣','duration'=>'5','unit'=>'天','second'=>'听力下降','diag'=>'H91.9','diagName'=>'听力损失'],
    ['symptom'=>'尿频尿急','duration'=>'3','unit'=>'天','second'=>'排尿痛','diag'=>'N30.0','diagName'=>'急性膀胱炎'],
    ['symptom'=>'肩痛','duration'=>'1','unit'=>'周','second'=>'活动受限','diag'=>'M75.0','diagName'=>'肩周炎'],
    ['symptom'=>'背痛','duration'=>'2','unit'=>'周','second'=>'弯腰困难','diag'=>'M54.9','diagName'=>'背痛'],
    ['symptom'=>'膝关节痛','duration'=>'1','unit'=>'月','second'=>'活动后加重','diag'=>'M17.0','diagName'=>'双膝骨关节炎'],
    ['symptom'=>'咳嗽','duration'=>'1','unit'=>'周','second'=>'喘息','diag'=>'J45.9','diagName'=>'支气管哮喘'],
    ['symptom'=>'发热','duration'=>'3','unit'=>'天','second'=>'咳嗽','diag'=>'J06.9','diagName'=>'急性上呼吸道感染'],
    ['symptom'=>'头痛头晕','duration'=>'2','unit'=>'天','second'=>'恶心','diag'=>'I10','diagName'=>'原发性高血压'],
    ['symptom'=>'腹痛腹泻','duration'=>'1','unit'=>'天','second'=>'发热','diag'=>'A09.0','diagName'=>'急性肠炎'],
    ['symptom'=>'尿痛','duration'=>'2','unit'=>'天','second'=>'尿频','diag'=>'N30.0','diagName'=>'急性膀胱炎'],
    ['symptom'=>'胸闷气短','duration'=>'5','unit'=>'天','second'=>'心悸','diag'=>'I20.0','diagName'=>'不稳定型心绞痛'],
    ['symptom'=>'咳嗽','duration'=>'1','unit'=>'周','second'=>'咽痛','diag'=>'J06.9','diagName'=>'急性上呼吸道感染'],
    ['symptom'=>'发热','duration'=>'1','unit'=>'天','second'=>'咳嗽','diag'=>'J12.9','diagName'=>'病毒性肺炎'],
];

$adviceTexts = [
    '清淡饮食，多饮水，注意休息，一周后门诊复查。',
    '规律服药，避免劳累，症状加重及时就诊。',
    '低盐低脂饮食，监测血压，定期复查。',
    '注意保暖，避免受凉，多饮水，按时服药。',
    '合理饮食，适当运动，定期复查血糖。',
    '避免辛辣刺激食物，戒烟限酒，规律作息。',
    '按时服药，定期复查，不适随诊。',
    '注意休息，避免剧烈运动，遵医嘱服药。',
];

// ===== 执行填充 =====

$pdo->beginTransaction();

try {
    $totalPatients = 35;
    $patientSeq = 1;
    $flowSeq = 1;
    $createdPatients = 0;
    $createdRegs = 0;
    $createdRecords = 0;
    $createdOrders = 0;
    $createdConsults = 0;

    // 先获取当前最大 patient_no 和 flow_no 以避免冲突
    $stmtPat = $pdo->prepare("SELECT MAX(CAST(SUBSTR(patient_no,7) AS INTEGER)) FROM patients WHERE SUBSTR(patient_no,1,6)=?");
    $stmtPat->execute([date('ymd')]);
    $maxPat = (int)$stmtPat->fetchColumn();
    $stmtFlow = $pdo->prepare("SELECT MAX(CAST(SUBSTR(flow_no,7) AS INTEGER)) FROM registrations WHERE SUBSTR(flow_no,1,6)=?");
    $stmtFlow->execute([date('ymd')]);
    $maxFlow = (int)$stmtFlow->fetchColumn();
    if ($maxPat > 0) $patientSeq = $maxPat + 1;
    if ($maxFlow > 0) $flowSeq = $maxFlow + 1;

    echo "起始 patient_no: " . gen_patient_no($patientSeq) . ", flow_no: " . gen_flow_no($flowSeq) . "\n";

    // 状态分布：paid(8) + visiting(10) + finished(17)
    $statusDist = array_merge(
        array_fill(0, 8, 'paid'),
        array_fill(0, 10, 'visiting'),
        array_fill(0, 17, 'finished')
    );

    // 哪些 finished 患者需要多医生续写（索引）
    $multiDoctorIdx = [0, 1, 3, 5, 7, 10, 13, 15];
    // 哪些需要会诊
    $consultIdx = [2, 6, 8, 11, 14];

    for ($i = 0; $i < $totalPatients; $i++) {
        // 随机生成患者信息
        $surname = $surnames[array_rand($surnames)];
        $given = $givenNames[array_rand($givenNames)];
        $name = $surname . $given;
        $gender = $genders[array_rand($genders)];
        $birthY = mt_rand(1940, 2005);
        $birthM = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $birthD = str_pad(mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
        $birthDate = "$birthY-$birthM-$birthD";
        $age = date('Y') - $birthY;
        $patientNo = gen_patient_no($patientSeq++);
        $idCard = gen_id_card($patientNo);
        $phone = '1' . mt_rand(30, 99) . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        $ethnicity = '汉族';
        $marital = mt_rand(0, 3) ? '已婚' : '未婚';
        $occupations = ['教师', '工人', '农民', '职员', '公务员', '退休', '学生', '个体', '无业', '医生', '工程师', '会计'];
        $occupation = $occupations[array_rand($occupations)];

        // 插入患者
        $pdo->prepare("INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $patientNo, $idCard, $name, $gender, $birthDate, $age, $ethnicity, $marital, $occupation, $phone,
            mt_rand(0, 2) ? '否认' : '承认', '', '',
            date('Y-m-d H:i:s', strtotime("-" . mt_rand(1, 60) . " minutes"))
        ]);
        $createdPatients++;

        // 每个患者1-2条就诊记录
        $regCount = mt_rand(1, 2);
        for ($r = 0; $r < $regCount; $r++) {
            $status = $statusDist[array_rand($statusDist)];
            $deptId = array_rand($deptNames);
            $deptName = $deptNames[$deptId];
            $session = $sessions[array_rand($sessions)];
            $feeType = $feeTypes[array_rand($feeTypes)];
            $fee = $deptId === 5 ? 50 : ($deptId === 6 ? 0 : mt_rand(10, 20));
            $flowNo = gen_flow_no($flowSeq++);
            $visitSeq = next_visit_seq($pdo, $deptId);
            // 注册时间：约 80% 今天、20% 昨天（3 天可见范围 = 今天 - 2 天）
            // 使用当天中午 12:00 为基准，避免跨日
            $dayAgo = mt_rand(0, 99) < 80 ? 0 : 1;
            $baseDate = date('Y-m-d', strtotime("-{$dayAgo} day")) . ' 12:00:00';
            $regTime = date('Y-m-d H:i:s', strtotime($baseDate) - mt_rand(0, 43200) + mt_rand(0, 43200));

            // 插入挂号
            $pdo->prepare("INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_name, registered_at, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $patientNo, $flowNo, $visitSeq, $deptId, $deptName, $deptId, $deptName,
                $session, $feeType, $fee, $status,
                $status !== 'paid' ? $regTime : null,
                '收款员', $regTime,
                $status === 'finished' ? date('Y-m-d H:i:s', strtotime($regTime) + mt_rand(600, 7200)) : null
            ]);
            $visitId = $pdo->lastInsertId();
            $createdRegs++;

            // 如果是 finished 或 visiting 状态，创建病历
            if ($status === 'finished' || $status === 'visiting') {
                // 选择主诉模板
                $ct = $complaintTemplates[$i % count($complaintTemplates)];
                $duration = (string)mt_rand(1, 14);
                $unit = mt_rand(0, 1) ? '天' : '周';
                $second = isset($ct['second']) ? $ct['second'] : '';
                $secondDuration = (string)mt_rand(1, 7);
                $secondUnit = '天';

                // 选择诊断
                $diagCode = $ct['diag'];
                $diagName = $ct['diagName'];
                $diag2 = $diagPool[array_rand($diagPool)];

                // 构建 emr_data
                $piContent = $piTexts[array_rand($piTexts)];
                $piContent = str_replace('{time}', $duration . $unit, $piContent);
                $piContent = str_replace('{complaint}', $ct['symptom'], $piContent);
                $piContent = str_replace('{second}', $second, $piContent);

                $emrData = [
                    'chief_complaint' => [
                        'symptom' => $ct['symptom'],
                        'duration' => $duration,
                        'unit' => $unit,
                        'second_symptom' => $second,
                        'second_duration' => $secondDuration,
                        'second_unit' => $secondUnit,
                    ],
                    'history_present' => [
                        'informant' => $informants[array_rand($informants)],
                        'duration' => $duration,
                        'unit' => $unit,
                        'content' => $piContent,
                        'arrival_way' => $arrivalWays[array_rand($arrivalWays)],
                    ],
                    'past_history' => [
                        'type' => mt_rand(0, 2) ? '否认' : '承认',
                        'detail' => mt_rand(0, 2) ? '' : '高血压病史',
                    ],
                    'allergies' => [
                        'type' => mt_rand(0, 3) ? '否认' : '承认',
                        'detail' => '',
                    ],
                    'main_symptoms' => [
                        '全身症状' => mt_rand(0, 3) ? '' : '发热',
                        '呼吸道症状' => mt_rand(0, 3) ? '' : '咳嗽',
                        '消化道症状' => mt_rand(0, 3) ? '' : '',
                        '皮疹症状' => mt_rand(0, 3) ? '' : '',
                        '出血症状' => mt_rand(0, 3) ? '' : '',
                        '神经系统症状' => mt_rand(0, 3) ? '' : '',
                    ],
                    'physical_exam' => [
                        'content' => $peTexts[array_rand($peTexts)],
                    ],
                    'diagnoses' => [
                        ['code' => $diagCode, 'name' => $diagName, 'part' => '', 'note' => '', 'suspected' => mt_rand(0, 2) ? '' : '是'],
                        ['code' => $diag2['code'], 'name' => $diag2['name'], 'part' => '', 'note' => '', 'suspected' => mt_rand(0, 2) ? '' : '是'],
                    ],
                    'aux_result' => '',
                    'aux_external' => '',
                    'disposition_custom' => '',
                    'is_leave_hospital' => '否',
                    'advice' => $adviceTexts[array_rand($adviceTexts)],
                    'progress' => ['content' => ''],
                ];

                $emrJson = json_encode($emrData, JSON_UNESCAPED_UNICODE);
                $recordStatus = $status === 'finished' ? 'done' : 'draft';

                // 构造 emr_print_text
                $printText = "主诉：" . $ct['symptom'] . $duration . $unit;
                if ($second) $printText .= "伴" . $second . $secondDuration . $secondUnit;
                $printText .= "\n现病史：" . $piContent;
                $printText .= "\n既往史：" . ($emrData['past_history']['type']);
                $printText .= "\n" . $emrData['past_history']['detail'] ? "：" . $emrData['past_history']['detail'] : '';
                $printText .= "\n体格检查：" . $emrData['physical_exam']['content'];
                $printText .= "\n初步诊断：" . $diagName . "，" . $diag2['name'];
                $printText .= "\n嘱托：" . $emrData['advice'];

                // 插入 patient_records（initial，医生为张伟）
                $pdo->prepare("INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $visitId, $patientNo, $flowNo, $deptId, 3, '张伟', 'initial', 0,
                    $emrJson, $printText, $recordStatus, $regTime, $regTime
                ]);
                $initialRecordId = $pdo->lastInsertId();
                $createdRecords++;

                // 插入 records（flat mirror）
                $pdo->prepare("INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, past_history, allergy_history, physical_exam, consciousness, preliminary_diagnosis, icd10_code, visit_type, doctor_advice, status, created_at, updated_at, patient_record_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $visitId, $patientNo, $flowNo, $deptId, 3, '张伟',
                    $ct['symptom'] . $duration . $unit, $piContent,
                    $emrData['past_history']['type'] . ($emrData['past_history']['detail'] ? '：' . $emrData['past_history']['detail'] : ''),
                    '', $emrData['physical_exam']['content'], '清醒',
                    $diagName . '，' . $diag2['name'], $diagCode, '初诊',
                    $emrData['advice'], $recordStatus, $regTime, $regTime, $initialRecordId
                ]);
                $createdRecords++;

                // 如果是 finished 且需要多医生续写
                $hasMultiDoc = in_array($i, $multiDoctorIdx) && $status === 'finished';
                $lastRecordId = $initialRecordId;

                if ($hasMultiDoc) {
                    // 1-2个其他医生续写
                    $otherDocs = array_filter($doctorPool, fn($d) => $d['id'] !== 3 && in_array($deptId, $d['depts']));
                    $otherDocs = array_values($otherDocs);
                    if (count($otherDocs) > 0) {
                        $numProgress = mt_rand(1, min(2, count($otherDocs)));
                        for ($p = 0; $p < $numProgress; $p++) {
                            $doc = $otherDocs[$p % count($otherDocs)];
                            $prContent = '查看辅助检查结果回报，结合临床表现，目前诊断明确，继续当前治疗，' . 
                                (mt_rand(0, 1) ? '一周后复诊。' : '建议调整用药方案，三天后复诊。');
                            $prDiag = $diagPool[array_rand($diagPool)];
                            $prEmr = [
                                'progress' => ['content' => $prContent],
                                'chief_complaint' => ['symptom'=>'','duration'=>'','unit'=>'','second_symptom'=>'','second_duration'=>'','second_unit'=>''],
                                'history_present' => ['informant'=>'','duration'=>'','unit'=>'','content'=>'','arrival_way'=>''],
                                'past_history' => ['type'=>'否认','detail'=>''],
                                'allergies' => ['type'=>'否认','detail'=>''],
                                'main_symptoms' => ['全身症状'=>'','呼吸道症状'=>'','消化道症状'=>'','皮疹症状'=>'','出血症状'=>'','神经系统症状'=>''],
                                'physical_exam' => ['皮肤黏膜'=>'','头部'=>'','胸部'=>'','肺脏及胸膜'=>'','心脏'=>'','腹部'=>'','神经反射'=>'','肌力及肌张力'=>'','其它体格检查'=>''],
                                'diagnoses' => [
                                    ['code'=>$diagCode,'name'=>$diagName,'part'=>'','note'=>'','suspected'=>''],
                                    ['code'=>$prDiag['code'],'name'=>$prDiag['name'],'part'=>'','note'=>'','suspected'=>'']
                                ],
                                'aux_result'=>'','aux_external'=>'','disposition_custom'=>'','is_leave_hospital'=>'否',
                                'advice'=>$adviceTexts[array_rand($adviceTexts)],
                            ];
                            $prJson = json_encode($prEmr, JSON_UNESCAPED_UNICODE);
                            $prPrint = "病程记录：" . $prContent . "\n" . "初步诊断：" . $diagName . "，" . $prDiag['name'];
                            $prTime = date('Y-m-d H:i:s', strtotime($regTime) + mt_rand(3600, 3600 * ($p + 2)));
                            $pdo->prepare("INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                                $visitId, $patientNo, $flowNo, $deptId, $doc['id'], $doc['name'],
                                'progress', $lastRecordId, $prJson, $prPrint, 'done', $prTime, $prTime
                            ]);
                            $lastRecordId = $pdo->lastInsertId();
                            $createdRecords++;

                            // records mirror
                            $pdo->prepare("INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, preliminary_diagnosis, icd10_code, visit_type, doctor_advice, status, created_at, updated_at, patient_record_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                                $visitId, $patientNo, $flowNo, $deptId, $doc['id'], $doc['name'],
                                '', $prContent, $diagName . '，' . $prDiag['name'], $diagCode,
                                '复诊', $prEmr['advice'], 'done', $prTime, $prTime, $lastRecordId
                            ]);
                            $createdRecords++;
                        }
                    }
                }

                // 创建订单（finished 患者）
                if ($status === 'finished' || $status === 'visiting') {
                    // 处方
                    if (mt_rand(0, 1)) {
                        $drug = $drugPool[array_rand($drugPool)];
                        $qty = mt_rand(1, 3);
                        $total = round((float)$drug['price'] * $qty, 2);
                        $orderNo = gen_order_no('prescription');
                        $pdo->prepare("INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $visitId, $patientNo, $flowNo, 'prescription', $orderNo, 3, '张伟', $initialRecordId, $deptId, $deptName,
                            $total, $status === 'finished' ? 'dispensed' : 'paid', $regTime,
                            $status === 'finished' ? $regTime : null
                        ]);
                        $orderId = $pdo->lastInsertId();
                        $createdOrders++;
                        $pdo->prepare("INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, price, quantity, single_dose, frequency, route, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $orderId, $visitId, $patientNo, $flowNo, 'prescription', $drug['id'], $drug['name'],
                            $drug['spec'], $drug['package_unit'], $drug['price'], $qty,
                            $drug['single_dose'], $drug['frequency'], $drug['route'],
                            $status === 'finished' ? 'dispensed' : 'paid', 3, '张伟', $regTime
                        ]);
                        // 如果需要皮试，添加皮试处置
                        if ((int)$drug['is_skin_test'] > 0 && (int)$drug['skin_test_item_id'] > 0) {
                            $skinItemId = (int)$drug['skin_test_item_id'];
                            $skinItem = $pdo->prepare("SELECT name, fee FROM disposal_items WHERE id=?");
                            $skinItem->execute([$skinItemId]);
                            $skinItem = $skinItem->fetch(PDO::FETCH_ASSOC);
                            if ($skinItem) {
                                $pdo->prepare("INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, price, quantity, status, doctor_id, doctor_name, sub_of, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                                    $orderId, $visitId, $patientNo, $flowNo, 'procedure', $skinItemId, $skinItem['name'],
                                    $skinItem['fee'], 1, $status === 'finished' ? 'done' : 'paid', 3, '张伟', 0, $regTime
                                ]);
                            }
                        }
                    }

                    // 检验
                    if (mt_rand(0, 1)) {
                        $lab = $labPool[array_rand($labPool)];
                        $orderNo = gen_order_no('lab');
                        $pdo->prepare("INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $visitId, $patientNo, $flowNo, 'lab', $orderNo, 3, '张伟', $initialRecordId, $deptId, $deptName,
                            $lab['price'], $status === 'finished' ? 'done' : 'paid', $regTime,
                            $status === 'finished' ? $regTime : null
                        ]);
                        $orderId = $pdo->lastInsertId();
                        $createdOrders++;
                        $pdo->prepare("INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, unit, price, quantity, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $orderId, $visitId, $patientNo, $flowNo, 'lab', $lab['id'], $lab['name'],
                            $lab['unit'], $lab['price'], 1, $status === 'finished' ? 'done' : 'paid', 3, '张伟', $regTime
                        ]);
                    }

                    // 检查
                    if (mt_rand(0, 1)) {
                        $exam = $examPool[array_rand($examPool)];
                        $orderNo = gen_order_no('imaging');
                        $pdo->prepare("INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $visitId, $patientNo, $flowNo, 'imaging', $orderNo, 3, '张伟', $initialRecordId, $deptId, $deptName,
                            $exam['price'], $status === 'finished' ? 'done' : 'paid', $regTime,
                            $status === 'finished' ? $regTime : null
                        ]);
                        $orderId = $pdo->lastInsertId();
                        $createdOrders++;
                        $pdo->prepare("INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, price, quantity, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $orderId, $visitId, $patientNo, $flowNo, 'imaging', $exam['id'], $exam['name'],
                            $exam['price'], 1, $status === 'finished' ? 'done' : 'paid', 3, '张伟', $regTime
                        ]);
                    }

                    // 处置
                    if (mt_rand(0, 1)) {
                        $dp = $dispPool[array_rand($dispPool)];
                        $orderNo = gen_order_no('procedure');
                        $pdo->prepare("INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $visitId, $patientNo, $flowNo, 'procedure', $orderNo, 3, '张伟', $initialRecordId, $deptId, $deptName,
                            $dp['fee'], $status === 'finished' ? 'done' : 'paid', $regTime,
                            $status === 'finished' ? $regTime : null
                        ]);
                        $orderId = $pdo->lastInsertId();
                        $createdOrders++;
                        $pdo->prepare("INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, price, quantity, status, doctor_id, doctor_name, created_at, executed_by, executed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                            $orderId, $visitId, $patientNo, $flowNo, 'procedure', $dp['id'], $dp['name'],
                            $dp['fee'], 1, $status === 'finished' ? 'done' : 'paid', 3, '张伟', $regTime,
                            $status === 'finished' ? '护士' : null, $status === 'finished' ? $regTime : null
                        ]);
                    }
                }

                // 会诊（finished 患者，指定索引）
                $hasConsult = in_array($i, $consultIdx) && $status === 'finished';
                if ($hasConsult) {
                    $targetDeptId = $deptId === 2 ? 5 : ($deptId === 5 ? 2 : (mt_rand(0, 1) ? 1 : 2));
                    $targetDeptName = $deptNames[$targetDeptId] ?? '外科门诊';
                    $consultNo = 'HZ' . date('YmdHis') . str_pad(mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO consultations(visit_id, patient_no, flow_no, consult_no, from_dept_id, from_dept_name, from_doctor_id, from_doctor_name, target_dept_id, target_dept_name, description, purpose, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                        $visitId, $patientNo, $flowNo, $consultNo, $deptId, $deptName, 3, '张伟', $targetDeptId, $targetDeptName,
                        $ct['symptom'] . '待查，请协助诊治。', '请贵科会诊协助诊疗。',
                        mt_rand(0, 2) ? 'done' : 'pending', $regTime
                    ]);
                    $consultId = $pdo->lastInsertId();
                    $createdConsults++;

                    // 如果会诊已完成，添加会诊病历（由其他医生书写）
                    $consultRow = $pdo->query("SELECT status FROM consultations WHERE id=" . (int)$consultId)->fetch(PDO::FETCH_ASSOC);
                    if ($consultRow && $consultRow['status'] === 'done') {
                        // 找目标科室的医生（排除发起人张伟 id=3，会诊病历由目标科室其他医生书写）
                        $targetDocs = array_values(array_filter($doctorPool, function ($d) use ($targetDeptId) {
                            return $d['id'] !== 3 && in_array($targetDeptId, $d['depts']);
                        }));
                        if (count($targetDocs) > 0) {
                            $tdoc = $targetDocs[array_rand($targetDocs)];
                            $consDiag = $diagPool[array_rand($diagPool)];
                            $consEmr = [
                                'progress' => ['content' => '会诊意见：结合患者病史及辅助检查，' . $ct['symptom'] . '考虑' . $consDiag['name'] . '，建议' . (mt_rand(0, 1) ? '继续目前治疗' : '调整治疗方案') . '。'],
                                'chief_complaint' => ['symptom'=>'','duration'=>'','unit'=>'','second_symptom'=>'','second_duration'=>'','second_unit'=>''],
                                'history_present' => ['informant'=>'','duration'=>'','unit'=>'','content'=>'','arrival_way'=>''],
                                'past_history' => ['type'=>'否认','detail'=>''],
                                'allergies' => ['type'=>'否认','detail'=>''],
                                'main_symptoms' => ['全身症状'=>'','呼吸道症状'=>'','消化道症状'=>'','皮疹症状'=>'','出血症状'=>'','神经系统症状'=>''],
                                'physical_exam' => ['皮肤黏膜'=>'','头部'=>'','胸部'=>'','肺脏及胸膜'=>'','心脏'=>'','腹部'=>'','神经反射'=>'','肌力及肌张力'=>'','其它体格检查'=>''],
                                'diagnoses' => [['code'=>$consDiag['code'],'name'=>$consDiag['name'],'part'=>'','note'=>'','suspected'=>'']],
                                'aux_result'=>'','aux_external'=>'','disposition_custom'=>'','is_leave_hospital'=>'否',
                                'advice'=>'遵医嘱治疗，定期复查。',
                            ];
                            $consEmrJson = json_encode($consEmr, JSON_UNESCAPED_UNICODE);
                            $consPrint = "会诊意见：" . $consEmr['progress']['content'];
                            $consTime = date('Y-m-d H:i:s', strtotime($regTime) + mt_rand(7200, 14400));
                            $pdo->prepare("INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at, consultation_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                                $visitId, $patientNo, $flowNo, $targetDeptId, $tdoc['id'], $tdoc['name'],
                                'progress', $lastRecordId, $consEmrJson, $consPrint, 'done', $consTime, $consTime, $consultId
                            ]);
                            $consRecordId = $pdo->lastInsertId();
                            $createdRecords++;
                            // 更新会诊：记录 accepted_by/accepted_at/finished_by/finished_at
                            $pdo->prepare("UPDATE consultations SET accepted_by=?, accepted_at=?, finished_by=?, finished_at=? WHERE id=?")->execute([
                                $tdoc['name'], $consTime, $tdoc['name'], $consTime, $consultId
                            ]);
                            // records mirror
                            $pdo->prepare("INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, preliminary_diagnosis, icd10_code, visit_type, doctor_advice, status, created_at, updated_at, patient_record_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                                $visitId, $patientNo, $flowNo, $targetDeptId, $tdoc['id'], $tdoc['name'],
                                $consDiag['name'], $consDiag['code'], '会诊', $consEmr['advice'], 'done', $consTime, $consTime, $consRecordId
                            ]);
                            $createdRecords++;
                        }
                    }
                }
            }

            // 更新 finished 患者的 disposition
            if ($status === 'finished') {
                $dispType = $dispositions[array_rand($dispositions)];
                $pdo->prepare("UPDATE registrations SET disposition=?, disposition_detail=? WHERE id=?")->execute([
                    $dispType, $dispType === '自主离院' ? '' : '已安排' . $dispType, $visitId
                ]);
            }
        }

        if ($i % 10 === 9) echo "已处理 " . ($i + 1) . "/" . $totalPatients . " 个患者...\n";
    }

    $pdo->commit();
    echo "\n=== 填充完成 ===\n";
    echo "患者: $createdPatients, 就诊: $createdRegs, 病历/镜像: $createdRecords, 订单: $createdOrders, 会诊: $createdConsults\n";
    echo "建议重启服务器后使用用户 doctor2001（张伟）登录测试。\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "错误: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}