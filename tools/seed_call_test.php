<?php
/**
 * seed_call_test.php — 叫号测试数据生成（仅本地/测试环境使用）
 * 用法：frankenphp php-cli tools/seed_call_test.php
 * 内容：为 doctor2001（张伟）的外科门诊（dept 2）与急诊科（dept 5）
 *       各创建 30 名「当天挂号成功（paid）」的患者，供叫号大屏/悬浮窗测试。
 * 说明：本脚本仅写入本地数据库（data/db/clinic_main.db 已被 .gitignore 忽略），
 *       不产生任何可提交文件；重复执行会继续追加（按当天最大序号续号）。
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/config/bootstrap.php';
DatabaseManager::initAll();

$deptIds = isset($argv[1]) ? array_map('intval', explode(',', (string)$argv[1])) : array(2, 5);
$perDept = isset($argv[2]) ? max(1, (int)$argv[2]) : 30;
$today = date('Y-m-d');
$dayPrefix = date('ymd');

$surnames = array('李','王','张','刘','陈','杨','赵','黄','周','吴','徐','孙','马','朱','胡','郭','何','林','罗','郑','梁','谢','宋','唐','许','韩','冯','邓','曹','彭','曾','肖','田','董','袁','潘','蒋','蔡','余','杜');
$givens = array('伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','秀英','霞','平','刚','文轩','雨欣','子涵','浩然','诗琪','梦琪','建国','建军','国强','志强','海燕','雪梅','丽华','嘉怡','晓彤','泽宇','子墨','春华','国庆');

// 当天患者序号 / 流水号 / 号别计数续接
$patientSeq = (int)substr((string)DB::val("SELECT MAX(patient_no) FROM patients WHERE patient_no LIKE '" . $dayPrefix . "%'"), -2);
$flowMax = (int)DB::val("SELECT MAX(CAST(substr(flow_no,7) AS INTEGER)) FROM registrations WHERE substr(flow_no,1,6)=?", array($dayPrefix));

foreach ($deptIds as $deptId) {
    $dept = DB::one('SELECT * FROM departments WHERE id=?', array($deptId));
    if (!$dept) { echo "跳过：科室 {$deptId} 不存在\n"; continue; }
    $seq = (int)DB::val("SELECT MAX(visit_seq) FROM registrations WHERE first_dept_id=? AND date(registered_at)=?", array($deptId, $today));
    $created = 0;
    for ($i = 0; $i < $perDept; $i++) {
        $patientSeq++;
        $flowMax++;
        $seq++;
        $name = $surnames[array_rand($surnames)] . $givens[array_rand($givens)];
        $gender = (mt_rand(0, 1) ? '男' : '女');
        $age = mt_rand(3, 88);
        $birth = date((date('Y') - $age) . '-m-d', mt_rand(0, time()));
        $patientNo = $dayPrefix . sprintf('%02d', $patientSeq);
        $idCard = $dayPrefix . sprintf('%02d', $patientSeq) . sprintf('%04d', mt_rand(1000, 9999)) . sprintf('%04d', mt_rand(1000, 9999));
        DB::insert('INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $patientNo, $idCard, $name, $gender, $birth, $age, '汉族', $age > 25 ? '已婚' : '未婚',
            array('职员','工人','教师','退休','学生','自由职业')[array_rand(array(0,1,2,3,4,5))],
            '', '本市', '13' . sprintf('%09d', mt_rand(100000000, 999999999)),
            '否认', '', '', now_str(),
        ));
        $flowNo = $dayPrefix . sprintf('%04d', $flowMax);
        $fee = $dept['type'] === 'emergency' ? 50 : 20;
        $hh = mt_rand(8, 15); $mm = mt_rand(0, 59);
        $regTime = date('Y-m-d H:i:s', mktime($hh, $mm, mt_rand(0, 59), (int)date('m'), (int)date('d'), (int)date('Y')));
        $payTime = date('Y-m-d H:i:s', strtotime($regTime) + mt_rand(120, 900));
        $visitId = (int)DB::insert('INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_id, cashier_name, registered_at, cancel_reason, is_extra, disposition, disposition_detail, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $patientNo, $flowNo, $seq, $deptId, $dept['name'], $deptId, $dept['name'],
            $hh < 12 ? 'am' : 'pm', array('自费','居民医保','职工医保')[array_rand(array(0,1,2))], $fee,
            'paid', $payTime, 2, '收款员', $regTime, '', 0, '', '', '',
        ));
        DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, 0, $patientNo, $flowNo, 'visit', $fee, 1, 2, '收款员', $payTime,
        ));
        $created++;
    }
    echo "科室「{$dept['name']}」新增 {$created} 名当天已缴费患者（号别 1-{$seq}）\n";
}
echo "=== 叫号测试数据生成完毕 ===\n";
