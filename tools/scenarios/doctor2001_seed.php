<?php
/**
 * seed_doctor2001_visits.php — doctor2001（张伟：外科门诊+急诊科）专用测试数据生成器
 * ============================================================
 * 用途：为外科门诊(id=2)与急诊科(id=5)补充完整就诊链测试数据（仅填充数据库）。
 * 就诊状态分布（每科室 ≥25 个患者）：
 *   - 诊毕（finished，近 25 天内含转归）            —— 各 ≥13
 *   - 待就诊（paid，含今日）                        —— 各 ≥7
 *   - 就诊中（visiting，含今日）                    —— 各 ≥5
 *   - 另含少量 pending（今日已挂号未缴费）
 * 完整就诊链约束：
 *   挂号 → （缴费）→ 结构化病历（patient_records + records 双镜像）→ 开单
 *   （检验/检查/处置/处方）→ 缴费 → 执行（检验结果/影像报告/护理执行/发药）
 *   → 多人续写 → 会诊记录 → 诊毕转归；开单必先有病历，不存在空病历开单。
 * 患者来源：约 40% 复用库内既有患者（自带既往就诊 → 便于历史调阅），
 *           60% 新建患者并为其生成 1-2 次「既往就诊」（诊毕，用于历史调阅）。
 * 病历格式：严格按 emr_default_data 结构化模板（chief_complaint/history_present/
 *           past_history/allergies/main_symptoms/physical_exam/diagnoses/advice…），
 *           诊断一律取自 ICD10 标准库真实编码（外科/急诊语义优先）。
 * 幂等性：患者编号/流水号/报告号按库内 MAX 续号，重复执行数据会叠加（建议一次性使用）。
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require dirname(__DIR__, 2) . '/app/config/bootstrap.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
DatabaseManager::initAll();
mt_srand(20260913);

echo "=== doctor2001（外科门诊+急诊科）测试数据生成 ===\n";

/* ==================== 基础引用 ==================== */
// 定位医生：兼容 emp_no=2001（张伟）或旧用户名 doctor2001
$ME = DB::one("SELECT id, name FROM users WHERE emp_no='2001' OR username='doctor2001' ORDER BY id LIMIT 1");
if (!$ME) exit("doctor2001（张伟，工号 2001）不存在，请先运行 seed_test_data 生成账号\n");
$ME_ID = (int)$ME['id'];
$ME_NAME = $ME['name'];

// 协作医生（其他医生续写/会诊接受方）
$others = array();
foreach (DB::q("SELECT id, name, dept_ids FROM users WHERE role='doctor' AND status=1 AND id<>?", array($ME_ID)) as $r) {
    $others[] = $r;
}
if (!count($others)) exit("无其他医生账号\n");

// 科室
$depts = array();
foreach (DB::q("SELECT * FROM departments WHERE id IN (2,5) AND status=1") as $r) $depts[$r['id']] = $r;

// 目录
$labSingles = DB::q("SELECT id, name, price, unit, normal_range FROM lab_items WHERE is_group=0 AND parent_id=0 AND status='approved'");
$exams = DB::q("SELECT id, name, price, category FROM exam_items WHERE status='approved'");
$disps = DB::q("SELECT id, name, fee, is_nurse FROM disposal_items WHERE status='approved'");
$drugs = DB::q("SELECT id, name, price, spec, package_unit, vendor_short, single_dose, frequency, route, is_nurse FROM drugs WHERE status='approved'");

// ICD10：外科/急诊常用诊断池（全部取自标准库真实编码）
$diagPool = array();
foreach (array(
    '急性阑尾炎%', '软组织挫伤', '头皮裂伤', '蜂窝织炎', '甲沟炎', '腰扭伤', '开放性损伤',
    '尺骨%骨折', '桡骨%骨折', '踝%扭伤', '浅表损伤', '挫伤', '脓肿', '裂伤',
) as $kw) {
    foreach (DatabaseManager::q("icd10", "SELECT diagnosis_code, diagnosis_name FROM icd10 WHERE diagnosis_name LIKE ? AND subcategory_code<>'' LIMIT 6", array("%{$kw}%")) as $r) {
        $diagPool[$r['diagnosis_code']] = $r['diagnosis_name'];
    }
}
$diagPool = array_values(array_map(function ($c, $n) { return array('code' => $c, 'name' => $n); }, array_keys($diagPool), $diagPool));
if (!count($diagPool)) exit("ICD10 诊断池为空\n");
echo "诊断池：", count($diagPool), " 个真实编码\n";

/* ==================== 文案池（外科/急诊语义） ==================== */
$ccPool = array(
    array('右下腹持续性疼痛', '6', '小时', '伴恶心呕吐', '2', '小时'),
    array('摔伤后左前臂疼痛肿胀', '3', '小时', '伴活动受限', '3', '小时'),
    array('头部外伤后出血', '1', '小时', '伴短暂头晕', '1', '小时'),
    array('右手切割伤后疼痛出血', '30', '分钟', '伴活动受限', '30', '分钟'),
    array('右足踝扭伤后肿痛', '1', '天', '伴行走困难', '1', '天'),
    array('腰背部扭伤后疼痛', '5', '小时', '伴活动受限', '5', '小时'),
    array('右拇指甲周红肿疼痛', '3', '天', '加重', '1', '天'),
    array('左小腿蜂窝织炎红肿热痛', '2', '天', '伴发热', '1', '天'),
    array('腹部撞击伤后腹痛', '4', '小时', '伴腹胀', '4', '小时'),
    array('右髋部摔伤后疼痛', '1', '天', '伴行走困难', '1', '天'),
);
$piTails = array(
    '无昏迷呕吐，无大小便失禁，伤后未行特殊处理，为求进一步诊治来院。',
    '伴乏力纳差，无寒战高热，精神尚可，睡眠饮食欠佳，二便正常。',
    '自行简单包扎后出血已止，局部肿胀明显，前来就诊。',
    '外院初步处理后症状缓解不明显，今来我院求进一步诊治。',
    '起病以来精神尚可，胃纳一般，体重无明显变化，二便正常。',
);
$pePool = array(
    '神志清楚，急性痛苦面容，右下腹麦氏点压痛（+），反跳痛（+），肌紧张（+），肠鸣音减弱。',
    '神志清楚，左前臂肿胀畸形，压痛（+），可及骨擦感，桡动脉搏动可及，指端血运感觉正常。',
    '神清，头皮可见约3cm裂伤创口，创缘整齐，活动性出血已止，双侧瞳孔等大等圆，对光反射灵敏。',
    '神清，右手食指掌侧可见切割伤口，深达皮下，创缘整齐，指端血运感觉正常，主动屈伸受限。',
    '神清，右外踝肿胀压痛（+），瘀斑明显，未及骨擦感，足背动脉搏动可及，末梢血运正常。',
    '神清，腰部肌肉紧张，L4/5棘突旁压痛（+），叩击痛（+），双下肢直腿抬高试验（-）。',
    '神清，右拇指甲周红肿，甲缘可见脓性分泌物，压痛明显，指端血运正常。',
    '神清，体温38.2℃，左小腿下段红肿热痛，边界不清，触痛明显，可及波动感，腹股沟淋巴结未及肿大。',
);
$advicePool = array(
    '清创换药，定期复查，保持伤口清洁干燥，不适随诊。',
    '患肢制动抬高，一周后门诊复查X线，不适随诊。',
    '清淡饮食，避免剧烈运动，遵医嘱按时服药，症状加重及时就诊。',
    '建议住院进一步治疗，目前予对症处理，密切观察病情变化。',
    '卧床休息，轴线翻身，腰围保护下适当活动，两周后复诊。',
);
$progPool = array(
    '患者诉疼痛较前缓解，创面敷料干燥，继续目前治疗方案，密切观察病情变化。',
    '查看辅助检查结果回报，结合体征，目前诊断明确，调整抗感染方案，继续治疗。',
    '换药见创面清洁，肉芽组织生长良好，无渗出，继续换药，择期拆线。',
    '患者生命体征平稳，症状好转，复查血常规较前改善，维持现方案，不适随诊。',
    '会诊医师查看患者后同意目前诊断及处理方案，建议完善检查后择期手术。',
);
$wardPool = array('普外科病区', '骨科病区', '急诊观察病区');
$hospPool = array('市第一人民医院', '医科大学附属医院');
$conscPool = array('清醒', '清醒', '清醒', '嗜睡');

/* ==================== 工具 ==================== */
function rnd($a, $b) { return mt_rand($a, $b); }
function pick($arr) { return $arr[array_rand($arr)]; }
function nowday($ts) { return date('Y-m-d H:i:s', $ts); }

$surnames = array('李','王','张','刘','陈','杨','赵','黄','周','吴','徐','孙','马','朱','胡','郭','何','林','罗','郑','梁','谢','宋','唐','许','韩','冯','邓','曹','彭','曾','肖','田','董','袁','潘','蒋','蔡','余','杜');
$givens = array('伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','霞','平','刚','建国','建军','国强','志强','海燕','雪梅','丽华','嘉怡','泽宇','春华');

/* 号段续号（按库内 MAX，patient_no 为字符串列需用数字 MAX 避免字典序 99>100） */
$patientSeq = 0;
foreach (DB::q("SELECT MAX(CAST(substr(patient_no,7) AS INTEGER)) AS m FROM patients WHERE patient_no LIKE '" . date('ymd') . "%'") as $r) {
    $patientSeq = (int)$r['m'];
}
$flowSeq = array();
foreach (DB::q("SELECT substr(flow_no,1,6) d, MAX(CAST(substr(flow_no,7) AS INTEGER)) m FROM registrations GROUP BY d") as $r) {
    $flowSeq[$r['d']] = (int)$r['m'];
}
$seqSeq = array();
foreach (DB::q("SELECT first_dept_id dp, substr(registered_at,1,10) d, MAX(visit_seq) m FROM registrations GROUP BY dp, d") as $r) {
    $seqSeq[$r['dp'] . '_' . $r['d']] = (int)$r['m'];
}
$reportSeq = array();
foreach (DB::q("SELECT substr(report_no,3,8) d, MAX(CAST(substr(report_no,11) AS INTEGER)) m FROM reports GROUP BY d") as $r) {
    $reportSeq[$r['d']] = (int)$r['m'];
}

$staff = array('nurse' => '周梅', 'lab' => '陈静', 'imaging' => '黄浩', 'pharmacy' => '吴涛', 'cashier' => '收款员');

/* ==================== 患者：40% 复用既有 + 60% 新建（附既往就诊） ==================== */
$N_NEW = 30;   // 新建患者
$newPatients = array();
for ($i = 0; $i < $N_NEW; $i++) {
    $patientSeq++;
    $name = pick($surnames) . pick($givens);
    $gender = pick(array('男', '男', '女'));
    $age = rnd(14, 82);
    $birth = date((intval(date('Y')) - $age) . '-m-d', mt_rand(0, time()));
    $pno = date('ymd') . sprintf('%02d', $patientSeq);
    // 幂等：患者号已存在（重复运行/与其它场景撞号）则复用，不重复创建；
    // id_card 随机冲突时重试（更换随机段）
    $hasP = (int)DB::val("SELECT COUNT(*) FROM patients WHERE patient_no=?", array($pno));
    if (!$hasP) {
        $inserted = false;
        for ($retry = 0; $retry < 5 && !$inserted; $retry++) {
            try {
                DB::insert('INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $pno,
                    date('Ymd', strtotime($birth)) . sprintf('%04d', rnd(1000, 9999)) . sprintf('%04d', rnd(1000, 9999)),
                    $name, $gender, $birth, $age, '汉族', $age > 25 ? '已婚' : '未婚',
                    pick(array('职员','工人','农民','教师','退休','自由职业')),
                    '', '本市', '13' . sprintf('%09d', rnd(100000000, 999999999)),
                    '否认', '', '', now_str(),
                ));
                $inserted = true;
            } catch (Exception $ex) {
                if (stripos((string)$ex->getMessage(), 'id_card') === false && stripos((string)$ex->getMessage(), 'patient_no') === false) throw $ex;
            }
        }
    }
    $newPatients[] = array('patient_no' => $pno, 'gender' => $gender, 'birth' => $birth, 'name' => $name);
}
$oldPatients = array();
foreach (DB::q("SELECT patient_no, gender, birth_date FROM patients WHERE id_card IS NOT NULL AND id_card<>'' ORDER BY RANDOM() LIMIT 20") as $r) {
    $oldPatients[] = $r;
}
echo "患者：新建 {$N_NEW} + 复用既有 ", count($oldPatients), "\n";

/* ==================== 就诊生成主流程 ====================
 * 状态配额（每科室）：
 *   finished ≥13（其中 8 个分布在近 25 天、5 个分布在 25-90 天前做「既往就诊」）
 *   paid ≥7（其中 4 个为今日）  visiting ≥5（其中 3 个为今日）  pending 1-2（今日）
 * 新建患者先造 1 次「既往诊毕就诊」（更早时间），本次再造当前状态就诊 → 历史调阅双记录。
 */
$stats = array(2 => array('finished' => 0, 'paid' => 0, 'visiting' => 0, 'pending' => 0), 5 => array('finished' => 0, 'paid' => 0, 'visiting' => 0, 'pending' => 0));
$cc = $recordCount = $orderCount = $itemCount = $resultCount = $vitalCount = $consCount = $todayCount = 0;

/**
 * 造一次完整就诊（严格链路：挂号→缴费→病历→开单→执行→续写/会诊→诊毕）
 * @param array $p      patient_no/gender/birth
 * @param int   $deptId 2/5
 * @param int   $ts     挂号时间戳
 * @param string $status finished/paid/visiting/pending
 * @param array $opts   {pastVisit: bool, multiDoc: bool, consult: bool, orderSeed: int}
 */
function makeVisit($p, $deptId, $ts, $status, $opts = array()) {
    global $depts, $flowSeq, $seqSeq, $reportSeq, $staff, $ME_ID, $ME_NAME, $others, $stats,
           $diagPool, $ccPool, $piTails, $pePool, $advicePool, $progPool, $wardPool, $hospPool, $conscPool,
           $labSingles, $exams, $disps, $drugs,
           $cc, $recordCount, $orderCount, $itemCount, $resultCount, $vitalCount, $consCount;

    $dept = $depts[$deptId];
    $day = date('Y-m-d', $ts);
    $dayPrefix = date('ymd', $ts);

    // 挂号
    $flowSeq[$dayPrefix] = (isset($flowSeq[$dayPrefix]) ? $flowSeq[$dayPrefix] : 0) + 1;
    $flowNo = $dayPrefix . sprintf('%04d', $flowSeq[$dayPrefix]);
    $skey = $deptId . '_' . $day;
    $seqSeq[$skey] = (isset($seqSeq[$skey]) ? $seqSeq[$skey] : 0) + 1;
    $fee = $dept['type'] === 'emergency' ? 50 : 20;
    $paid = in_array($status, array('paid', 'visiting', 'finished'), true);
    $payTime = $paid ? nowday($ts + rnd(120, 900)) : '';
    $disp = ''; $dispDetail = ''; $finishedAt = '';
    if ($status === 'finished') {
        $r2 = mt_rand(1, 100);
        if ($r2 <= 72) { $disp = '自主离院'; }
        elseif ($r2 <= 84) { $disp = '住院'; $dispDetail = pick($wardPool); }
        elseif ($r2 <= 92) { $disp = '转院'; $dispDetail = pick($hospPool); }
        else { $disp = '其他'; $dispDetail = '症状缓解后要求离院'; }
        $finishedAt = nowday($ts + rnd(3 * 3600, 8 * 3600));
    }
    $visitId = (int)DB::insert('INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_id, cashier_name, registered_at, cancel_reason, is_extra, disposition, disposition_detail, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        $p['patient_no'], $flowNo, $seqSeq[$skey], $deptId, $dept['name'], $deptId, $dept['name'],
        date('H', $ts) < 12 ? 'am' : 'pm', pick(array('自费', '居民医保', '职工医保')), $fee,
        $status, $payTime, 2, $staff['cashier'], nowday($ts), '', 0, $disp, $dispDetail, $finishedAt,
    ));
    $stats[$deptId][$status] = (isset($stats[$deptId][$status]) ? $stats[$deptId][$status] : 0) + 1;

    if ($paid) {
        DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, 0, $p['patient_no'], $flowNo, 'visit', $fee, 1, 2, $staff['cashier'], $payTime,
        ));
    }

    // pending：只挂号，到此为止（无病历无开单，链路完整）
    if ($status === 'pending') return $visitId;

    // ==================== 病历（开单前置） ====================
    $hasRecord = in_array($status, array('visiting', 'finished'), true);
    if (!$hasRecord) {
        // paid（待就诊）：尚未接诊，无病历无开单 —— 链路完整（挂号+缴费即可候诊）
        return $visitId;
    }

    $ccRow = pick($ccPool);
    $cc++;
    $diagPick = array();
    $used = array();
    for ($q = 0; $q < rnd(1, 3); $q++) {
        $dg = pick($diagPool);
        if (isset($used[$dg['code']])) continue;
        $used[$dg['code']] = 1;
        $diagPick[] = array(
            'code' => $dg['code'], 'name' => $dg['name'],
            'part' => (mt_rand(1, 100) <= 30 ? pick(array('左侧', '右侧', '右上肢', '左下肢')) : ''),
            'note' => (mt_rand(1, 100) <= 20 ? pick(array('外伤所致', '既往类似发作')) : ''),
            'suspected' => (mt_rand(1, 100) <= 12 ? '是' : ''),
        );
    }

    // 生命体征
    $vitalsText = '';
    $consciousness = pick($conscPool);
    $sys = rnd(95, 160); $dia = rnd(60, 98); $hr = rnd(60, 110); $spo2 = rnd(94, 100); $resp = rnd(14, 22);
    $vTime = nowday($ts + rnd(300, 1500));
    DB::insert('INSERT INTO vitals(visit_id, patient_no, flow_no, vital_sbp, vital_dbp, vital_heart_rate, vital_pulse, vital_spo2, vital_respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
        $visitId, $p['patient_no'], $flowNo, $sys, $dia, $hr, $hr, $spo2, $resp, $ME_NAME, $vTime,
    ));
    $vitalCount++;
    $vitalsText = '血压 ' . $sys . '/' . $dia . 'mmHg；心率 ' . $hr . '次/分；血氧 ' . $spo2 . '%；呼吸 ' . $resp . '次/分';

    // 首诊结构化病历（emr_default_data 模板）
    $emr = emr_default_data(null);
    $emr['chief_complaint'] = array('symptom' => $ccRow[0], 'duration' => $ccRow[1], 'unit' => $ccRow[2], 'second_symptom' => $ccRow[3], 'second_duration' => $ccRow[4], 'second_unit' => $ccRow[5]);
    $emr['history_present'] = array('content' => '患者于' . $ccRow[1] . $ccRow[2] . '前' . pick(array('不慎摔伤', '被重物砸伤', '切割伤', '无明显诱因出现', '外伤后出现')) . $ccRow[0] . '，' . $ccRow[3] . $ccRow[4] . $ccRow[5] . '，' . pick($piTails), 'informant' => '患者自诉', 'arrival_way' => $deptId == 5 ? pick(array('120接入', '自行来院')) : '自行来院');
    $phType = pick(array('否认', '否认', '承认'));
    $emr['past_history'] = array('type' => $phType, 'detail' => $phType === '承认' ? pick(array('高血压病史5年', '2型糖尿病史3年', '慢性胃炎病史')) : '');
    $emr['allergies'] = array('type' => pick(array('否认', '否认', '承认')), 'detail' => '承认' === '承认' ? '' : '');
    $emr['physical_exam'] = array('content' => pick($pePool));
    $emr['diagnoses'] = $diagPick;
    $emr['advice'] = pick($advicePool);
    $recCreated = nowday($ts + rnd(600, 2700));
    $recUpdated = $status === 'finished' ? nowday(strtotime($recCreated) + rnd(600, 3600)) : $recCreated;

    // ==================== 开单（先病历后开单） ====================
    $auxNames = array(); $rxLines = array(); $dispItems = array();
    $visitOrders = array();
    $nOrd = rnd(1, 3);
    for ($k = 0; $k < $nOrd; $k++) {
        $otypes = array('lab', 'imaging', 'procedure', 'prescription');
        $otype = pick($otypes);
        $created = nowday(strtotime($recCreated) + rnd(120, 1200) * ($k + 1));
        $paidAt = nowday(strtotime($payTime) + rnd(60, 900) * ($k + 1));
        $ostatus = 'paid'; $execBy = ''; $execAt = '';
        if ($status === 'finished') {
            $ostatus = ($otype === 'prescription') ? 'dispensed' : 'done';
            $execBy = ($otype === 'prescription') ? $staff['pharmacy'] : (($otype === 'imaging') ? $staff['imaging'] : ($otype === 'procedure' ? $staff['nurse'] : $staff['lab']));
            $execAt = nowday(strtotime($paidAt) + rnd(600, 5400));
        }
        $total = 0; $itemRows = array();
        if ($otype === 'lab') {
            $used2 = array();
            for ($q = 0; $q < rnd(1, 3); $q++) {
                $it = pick($labSingles);
                if (isset($used2[$it['id']])) continue;
                $used2[$it['id']] = 1;
                $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array());
                $total += (float)$it['price'];
                $auxNames[] = $it['name'];
            }
        } elseif ($otype === 'imaging') {
            $it = pick($exams);
            $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array(), 'category' => $it['category']);
            $total += (float)$it['price'];
            $auxNames[] = $it['name'];
        } elseif ($otype === 'procedure') {
            $used2 = array();
            for ($q = 0; $q < rnd(1, 2); $q++) {
                $it = pick($disps);
                if (isset($used2[$it['id']])) continue;
                $used2[$it['id']] = 1;
                $qty = rnd(1, 2);
                $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['fee'], 'qty' => $qty, 'extra' => array('is_nurse' => $it['is_nurse']));
                $total += (float)$it['fee'] * $qty;
                $dispItems[] = array('name' => $it['name'], 'qty' => $qty);
            }
        } else {
            $used2 = array();
            for ($q = 0; $q < rnd(1, 4); $q++) {
                $it = pick($drugs);
                if (isset($used2[$it['id']])) continue;
                $used2[$it['id']] = 1;
                $qty = rnd(1, 3);
                $itemRows[] = array(
                    'item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => $qty,
                    'extra' => array(
                        'spec' => $it['spec'], 'unit' => $it['package_unit'], 'company_short' => $it['vendor_short'],
                        'single_dose' => $it['single_dose'], 'frequency' => $it['frequency'],
                        'route' => $it['route'], 'is_nurse' => $it['is_nurse'],
                    ),
                );
                $total += (float)$it['price'] * $qty;
                $rxLines[] = '<div class="ef-rx-line">' . $it['name'] . '　' . $it['single_dose'] . '　' . $it['frequency'] . '　' . $it['route'] . '　×' . $qty . '</div>';
            }
        }
        if (!count($itemRows)) continue;
        do {
            $orderNo = array('lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ', 'prescription' => 'CF')[$otype] . date('YmdHis', strtotime($created)) . sprintf('%02d', rnd(0, 99));
        } while (DB::one('SELECT id FROM orders WHERE order_no=?', array($orderNo)));
        $orderId = (int)DB::insert('INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, category_name, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at, refunded_at, done_by, dispensed_at, review_by, reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $p['patient_no'], $flowNo, $otype, $orderNo, '', $ME_ID, $ME_NAME, 0, $deptId, $dept['name'],
            $total, $ostatus, $created, $paidAt, '', $ostatus === 'done' ? $execBy : '',
            $ostatus === 'dispensed' ? $execAt : '',
            $ostatus === 'dispensed' ? $staff['pharmacy'] : '',
            $ostatus === 'dispensed' ? $execAt : '',
        ));
        $orderCount++;
        $itemIds = array();
        foreach ($itemRows as $ir) {
            $ex = $ir['extra'];
            $iid = (int)DB::insert('INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, company_short, price, quantity, single_dose, frequency, route, is_nurse, sub_of, group_no, is_parent, parent_item_id, status, doctor_id, doctor_name, executed_by, executed_at, result_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $orderId, $visitId, $p['patient_no'], $flowNo, $otype, $ir['item_id'], $ir['item_name'],
                isset($ex['spec']) ? $ex['spec'] : '', isset($ex['unit']) ? $ex['unit'] : '', isset($ex['company_short']) ? $ex['company_short'] : '',
                $ir['price'], $ir['qty'],
                isset($ex['single_dose']) ? $ex['single_dose'] : '', isset($ex['frequency']) ? $ex['frequency'] : '', isset($ex['route']) ? $ex['route'] : '',
                isset($ex['is_nurse']) ? $ex['is_nurse'] : 0,
                0, 0, 1, 0, $ostatus, $ME_ID, $ME_NAME,
                ($ostatus === 'dispensed' || $ostatus === 'done') ? $execBy : '',
                ($ostatus === 'dispensed' || $ostatus === 'done') ? $execAt : '',
                0, $created,
            ));
            $itemIds[$ir['item_id']] = $iid;
            $itemCount++;
        }
        $visitOrders[] = array('id' => $orderId, 'type' => $otype, 'status' => $ostatus, 'itemIds' => $itemIds, 'exec_by' => $execBy, 'exec_at' => $execAt, 'paid_at' => $paidAt, 'category' => isset($ir['category']) ? $ir['category'] : '');

        // 执行结果 + 报告（诊毕就诊才有）
        if ($ostatus === 'done' && ($otype === 'lab' || $otype === 'imaging')) {
            foreach ($itemIds as $itemId => $iid) {
                $vals = array();
                if ($otype === 'lab') {
                    $li = DB::one('SELECT normal_range FROM lab_items WHERE id=?', array($itemId));
                    if ($li && $li['normal_range'] !== '') {
                        $parts = explode('-', $li['normal_range']);
                        if (count($parts) === 2 && is_numeric($parts[0])) {
                            $vals[$itemId] = round((float)$parts[0] + ((float)$parts[1] - (float)$parts[0]) * (mt_rand() / mt_getrandmax()), 1);
                        }
                    }
                }
                $valuesJson = $otype === 'lab' && count($vals) ? json_encode(array('values' => $vals), JSON_UNESCAPED_UNICODE) : '{}';
                $resTs = strtotime($execAt) + rnd(600, 3600);
                $findings = $otype === 'imaging' ? pick(array('所见骨质结构完整，未见明显骨折征象。', '双肺纹理增粗，余未见明显异常。', '软组织肿胀，未见明显异物存留。', '未见明显异常。')) : '';
                $conclusion = $otype === 'imaging' ? pick(array('符合临床诊断，请结合病史。', '未见明显异常，建议必要时复查。', '软组织损伤表现，请结合临床。')) : '';
                $resultId = (int)DB::insert('INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, findings, conclusion, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $itemId, $iid, $visitId, $p['patient_no'], $flowNo, $otype, $valuesJson,
                    $findings, $conclusion, $execBy, 'done', nowday($resTs), nowday($resTs),
                ));
                $dayKey = date('Ymd', $resTs);
                $reportSeq[$dayKey] = (isset($reportSeq[$dayKey]) ? $reportSeq[$dayKey] : 0) + 1;
                DB::insert('INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, content, doctor, status, apply_dept, apply_doctor, clinical_diag, apply_time, reg_time, category_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $resultId, 'BG' . $dayKey . sprintf('%04d', $reportSeq[$dayKey]),
                    $visitId, $p['patient_no'], $flowNo, $otype, '',
                    $execBy, 'done', $dept['name'], $ME_NAME, $diagPick[0]['name'],
                    $created, $execAt, $otype === 'imaging' ? ($itemRows[0]['category'] ?: '') : '', nowday($resTs),
                ));
                DB::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $iid));
                // 影像引用登记（三单匹配链路）
                DB::insert('INSERT INTO imaging_refs(order_item_id, order_id, visit_id, patient_no, flow_no, study_uid, series_uids, instance_count, modality, region, meta_json, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $iid, $orderId, $visitId, $p['patient_no'], $flowNo,
                    'BG' . $dayKey . sprintf('%04d', $reportSeq[$dayKey]), '[]', 0,
                    (strpos($itemRows[0]['item_name'], 'CT') !== false) ? 'CT' : (strpos($itemRows[0]['item_name'], 'DR') !== false || strpos($itemRows[0]['item_name'], 'X线') !== false ? 'DR' : (strpos($itemRows[0]['item_name'], '超声') !== false || strpos($itemRows[0]['item_name'], '彩超') !== false ? 'US' : 'OT')),
                    'region-pacs', '{}', $execBy, nowday($resTs), nowday($resTs),
                ));
                $resultCount++;
            }
        }
    }

    // 缴费流水（开单缴费 kind=order）
    foreach ($visitOrders as $vo) {
        if ($vo['status'] !== 'open') {
            $orderTotal = (float)DB::val('SELECT total_amount FROM orders WHERE id=?', array($vo['id']));
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $vo['id'], $p['patient_no'], $flowNo, 'order', $orderTotal, 1, 2, $staff['cashier'], $vo['paid_at'],
            ));
        }
    }

    // 病历落库（镜像双表）
    $printText = emr_print_text($emr, $vitalsText, $consciousness, $auxNames, $rxLines, $dispItems);
    $diagText = emr_diag_text($diagPick);
    $initialId = (int)DB::insert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        $visitId, $p['patient_no'], $flowNo, $deptId, $ME_ID, $ME_NAME,
        'initial', 0, $ccRow[0], $ccRow[1], $ccRow[2], '患者自诉', $emr['history_present']['arrival_way'],
        $emr['past_history']['type'], '', '否', (string)$diagPick[0]['code'], (string)$diagPick[0]['name'],
        json_encode($emr, JSON_UNESCAPED_UNICODE), $printText,
        $status === 'finished' ? 'done' : 'draft', $recCreated, $recUpdated,
    ));
    $recordCount++;
    DB::insert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, past_history, allergy_history, physical_exam, consciousness, preliminary_diagnosis, icd10_code, is_observation, visit_type, doctor_advice, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
        $visitId, $p['patient_no'], $flowNo, $deptId, $ME_ID, $ME_NAME,
        $ccRow[0], $emr['history_present']['content'], $emr['past_history']['detail'], '',
        $emr['physical_exam']['content'], $consciousness, $diagText, (string)$diagPick[0]['code'],
        $deptId == 5 ? 1 : 0, '初诊', $emr['advice'],
        $status === 'finished' ? 'done' : 'draft', $recCreated, $recUpdated,
    ));

    // 会诊（急诊→外科 或 外科→相关科，配额内）
    if (!empty($opts['consult'])) {
        $cTs = strtotime($recCreated) + rnd(300, 1800);
        $cDoc = pick($others);
        $targetDept = $deptId == 5 ? 2 : 5;
        $tDept = DB::one('SELECT id, name FROM departments WHERE id=?', array($targetDept));
        $consStatus = $status === 'finished' ? 'done' : pick(array('pending', 'accepted'));
        $acceptedBy = in_array($consStatus, array('accepted', 'done'), true) ? $cDoc['name'] : '';
        $acceptedAt = $acceptedBy !== '' ? nowday($cTs + rnd(600, 3600)) : '';
        $finishedAt = $consStatus === 'done' ? nowday($cTs + rnd(7200, 18000)) : '';
        do {
            $consNo = 'HZ' . date('YmdHis', $cTs) . sprintf('%04d', rnd(0, 9999));
        } while (DB::one('SELECT id FROM consultations WHERE consult_no=?', array($consNo)));
        DB::insert('INSERT INTO consultations(visit_id, patient_no, flow_no, consult_no, from_dept_id, from_dept_name, from_doctor_id, from_doctor_name, target_dept_id, target_dept_name, description, purpose, status, accepted_by, accepted_at, finished_at, record_id, created_at, finished_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $p['patient_no'], $flowNo, $consNo,
            $deptId, $dept['name'], $ME_ID, $ME_NAME,
            $tDept['id'], $tDept['name'],
            '患者' . $ccRow[0] . '，' . $diagPick[0]['name'] . '，请贵科会诊协助评估处理方案。',
            '协助评估诊断及进一步治疗方案。',
            $consStatus, $acceptedBy, $acceptedAt, $finishedAt, 0, nowday($cTs),
            $consStatus === 'done' ? $acceptedBy : '',
        ));
        $consCount++;
    }

    // 多名其他医生续写（仅诊毕 + 配额内）
    if (!empty($opts['multiDoc'])) {
        $writers = $others;
        shuffle($writers);
        $nWriters = rnd(1, 2);
        $usedW = array($ME_ID => 1);
        $wCount = 0;
        $parent = $initialId;
        $wTime = strtotime($recUpdated);
        foreach ($writers as $w) {
            if ($wCount >= $nWriters) break;
            if (isset($usedW[$w['id']])) continue;
            $usedW[$w['id']] = 1;
            $wCount++;
            $wTime += rnd(1800, 7200);
            $wTimeStr = nowday($wTime);
            $wDiags = array();
            if (mt_rand(1, 100) <= 60) {
                $wDiags[] = $diagPick[0];   // 引用首诊主诊断
                if (mt_rand(1, 100) <= 40) {
                    $dg2 = pick($diagPool);
                    $wDiags[] = array('code' => $dg2['code'], 'name' => $dg2['name'], 'part' => '', 'note' => '', 'suspected' => '');
                }
            }
            $wEmr = emr_default_data(null);
            $wEmr['progress'] = array('content' => pick($progPool));
            $wEmr['diagnoses'] = $wDiags;
            $wEmr['advice'] = pick($advicePool);
            $wPrint = emr_print_text($wEmr, '', '', array(), array(), array());
            $wDiagText = count($wDiags) ? emr_diag_text($wDiags) : '';
            DB::insert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $p['patient_no'], $flowNo, $deptId, $w['id'], $w['name'],
                'progress', $parent, '', '', '', '患者自诉', '自行来院',
                '', '', '否',
                count($wDiags) ? (string)$wDiags[0]['code'] : '',
                count($wDiags) ? (string)$wDiags[0]['name'] : '',
                json_encode($wEmr, JSON_UNESCAPED_UNICODE), $wPrint,
                'done', $wTimeStr, $wTimeStr,
            ));
            DB::insert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, consciousness, preliminary_diagnosis, icd10_code, visit_type, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $p['patient_no'], $flowNo, $deptId, $w['id'], $w['name'],
                '', '', '', $wDiagText, count($wDiags) ? (string)$wDiags[0]['code'] : '', '', 'done', $wTimeStr, $wTimeStr,
            ));
            $recordCount++;
            $parent = (int)DB::val('SELECT MAX(id) FROM patient_records WHERE visit_id=?', array($visitId));
        }
    }
    return $visitId;
}

/* ==================== 排片计划 ====================
 * 每科室：finished 13（8 近25天 + 5 远期既往）/ paid 8（4 今日）/ visiting 5（3 今日）/ pending 1
 * 新建患者：先造 1 次远期既往就诊（诊毕）再造本次就诊 → 同患者至少 2 次就诊历史
 */
$now = time();
$patients = array();   // 打乱的新建+复用患者池
$np = array_merge($newPatients, array_map(function ($o) {
    return array('patient_no' => $o['patient_no'], 'gender' => $o['gender'], 'birth' => $o['birth_date']);
}, $oldPatients));
shuffle($np);
$pi = 0;
function nextPatient() { global $np, $pi; return $np[$pi++ % count($np)]; }

foreach (array(2, 5) as $deptId) {
    // --- 8 个近 25 天诊毕（含多人续写/会诊配额） ---
    for ($i = 0; $i < 8; $i++) {
        $p = nextPatient();
        $ts = $now - rnd(1, 25) * 86400 - rnd(0, 6 * 3600);
        makeVisit($p, $deptId, $ts, 'finished', array(
            'multiDoc' => $i < 4,
            'consult' => $i === 2 || $i === 5,
        ));
    }
    // --- 5 个远期既往就诊（60-150 天前，诊毕，简单链路）——历史调阅素材 ---
    for ($i = 0; $i < 5; $i++) {
        $p = nextPatient();
        $ts = $now - rnd(60, 150) * 86400;
        makeVisit($p, $deptId, $ts, 'finished', array());
    }
    // --- 今日：4 待就诊 + 3 就诊中 + 1 已挂号未缴费 ---
    for ($i = 0; $i < 4; $i++) {
        $p = nextPatient();
        $ts = $now - rnd(600, 4 * 3600);
        makeVisit($p, $deptId, $ts, 'paid');
    }
    for ($i = 0; $i < 3; $i++) {
        $p = nextPatient();
        $ts = $now - rnd(1200, 5 * 3600);
        makeVisit($p, $deptId, $ts, 'visiting', array('consult' => $i === 0));
    }
    makeVisit(nextPatient(), $deptId, $now - rnd(300, 1200), 'pending');
    // --- 非今日：4 待就诊 + 2 就诊中（近 1-3 天，补足配额且让工作台有延续性） ---
    for ($i = 0; $i < 4; $i++) {
        $p = nextPatient();
        makeVisit($p, $deptId, $now - rnd(1, 3) * 86400 - rnd(0, 5 * 3600), 'paid');
    }
    for ($i = 0; $i < 2; $i++) {
        $p = nextPatient();
        makeVisit($p, $deptId, $now - rnd(1, 2) * 86400 - rnd(0, 4 * 3600), 'visiting');
    }
    echo "科室 {$deptId}（{$depts[$deptId]['name']}）：诊毕={$stats[$deptId]['finished']} 待就诊={$stats[$deptId]['paid']} 就诊中={$stats[$deptId]['visiting']} 待缴费={$stats[$deptId]['pending']}\n";
}

// 新建患者补充：为前 20 个新建患者再造 1 次更早的既往诊毕就诊（历史调阅素材）
$extra = 0;
foreach (array_slice($newPatients, 0, 20) as $p) {
    makeVisit($p, 2, $now - rnd(100, 200) * 86400, 'finished', array());
    $extra++;
}

echo "\n汇总：病历 {$recordCount}｜医嘱单 {$orderCount}｜明细 {$itemCount}｜报告 {$resultCount}｜体征 {$vitalCount}｜会诊 {$consCount}｜历史补充 {$extra}\n";
echo "=== 生成完毕 ===\n";
