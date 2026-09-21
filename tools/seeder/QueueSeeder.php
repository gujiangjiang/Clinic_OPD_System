<?php
/**
 * ============================================================
 * tools/seeder/QueueSeeder.php — 叫号队列测试数据生成器（合并版）
 * ============================================================
 * 合并原 scenarios/call_seed.php（门诊叫号）与 dept_call_seed.php
 * （医技叫号）两个场景，按模式调度：
 *   mode=visit  门诊叫号：为指定科室各创建 N 名「当日已缴费」患者
 *               （registrations + payments），供门诊叫号大屏/悬浮窗测试；
 *   mode=tech   医技叫号：为「现有待就诊/就诊中」患者各开 N 张已缴费单
 *               （检验/检查/处方发药/护理处置），经 VisitFlowEngine 状态机
 *               写入，使检验科/影像科/药房/护士站四个叫号队列出现待办患者。
 *
 * 用法（经 seed.php 调度，参数透传）：
 *   php tools/bin/seed.php --scene=call                  # 门诊叫号（默认科室 2,5，每科室 30）
 *   php tools/bin/seed.php --scene="call=2,5:10"         # 门诊叫号（指定科室与每科室人数）
 *   php tools/bin/seed.php --scene=dept_call             # 医技叫号（默认四类各 20）
 *   php tools/bin/seed.php --scene="dept=lab"            # 医技精细模式：仅检验单
 *   php tools/bin/seed.php --scene="dept=lab,exam:10"    # 医技精细模式（指定类型与数量）
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';
require_once __DIR__ . '/PreflightChecker.php';
require_once __DIR__ . '/VisitFlowEngine.php';

class QueueSeeder extends Seeder {

    protected $label = '叫号队列';

    /** @var array 运行参数（构造时由 argv 解析） */
    private $opt = array(
        'mode' => 'visit',   // visit=门诊叫号 / tech=医技叫号
        'depts' => '',       // visit 模式：科室 ID CSV（默认 2,5）
        'types' => '',       // tech 模式：医技类型 CSV lab/imaging/exam/prescription/disposal（默认四类）
        'count' => 30,       // visit 模式：每科室患者数 / tech 模式：每类开单数
    );

    public function __construct($opt = array()) {
        parent::__construct();
        $this->opt = array_merge($this->opt, $opt);
    }

    /* ==================== 参数解析（seed.php 透传 argv 形如 key=value / 纯值） ====================
     * 兼容原场景位置参数：visit 模式 [depts, count]；tech 模式 [types, count]。 */
    public static function parseArgv($argv) {
        $opt = array('mode' => 'visit', 'depts' => '', 'types' => '', 'count' => 30);
        foreach ((array)$argv as $a) {
            if (strpos($a, '--mode=') === 0) $opt['mode'] = trim(substr($a, 7));
            elseif (strpos($a, '--depts=') === 0) $opt['depts'] = trim(substr($a, 8));
            elseif (strpos($a, '--types=') === 0) $opt['types'] = trim(substr($a, 8));
            elseif (strpos($a, '--count=') === 0) $opt['count'] = max(1, (int)substr($a, 8));
            else {
                // 位置参数（array_slice 后索引从 0 起，勿用 $i>0 判断）
                $val = trim((string)$a);
                if (strpos($val, ':') !== false) {
                    // "2,5:10" / "lab,exam:10" — 值列表:数量
                    list($vals, $cnt) = explode(':', $val, 2);
                    $opt['count'] = max(1, (int)$cnt);
                    if ($opt['mode'] === 'tech' || preg_match('/^[a-z,]+$/i', $vals)) {
                        $opt['types'] = $vals;
                        $opt['mode'] = 'tech';
                    } else {
                        $opt['depts'] = $vals;
                        $opt['mode'] = 'visit';
                    }
                } elseif (preg_match('/^[a-z,]+$/i', $val)) {
                    $opt['types'] = $val;
                    $opt['mode'] = 'tech';
                } elseif ($val !== '') {
                    $opt['depts'] = $val;
                    $opt['mode'] = 'visit';
                }
            }
        }
        return $opt;
    }

    public function run() {
        if ($this->opt['mode'] === 'tech') return $this->runTech();
        return $this->runVisit();
    }

    /* ==================== 门诊叫号（原 call_seed）：当日已缴费新患者 ==================== */
    private function runVisit() {
        $pdo = $this->pdo;
        $deptIds = $this->opt['depts'] !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $this->opt['depts']))))
            : array(2, 5);
        $perDept = $this->opt['count'];
        $today = date('Y-m-d');
        $dayPrefix = date('ymd');

        // 当天患者序号续接：patient_no 为字符串列，MAX 按字典序（99 > 100），
        // 须用数字 MAX（CAST 序号部分），避免复用已存在号段导致唯一冲突
        $patientSeq = (int)DB::val("SELECT MAX(CAST(substr(patient_no,7) AS INTEGER)) FROM patients WHERE patient_no LIKE '" . $dayPrefix . "%'");
        $flowMax = (int)DB::val('SELECT MAX(CAST(substr(flow_no,7) AS INTEGER)) FROM registrations WHERE substr(flow_no,1,6)=?', array($dayPrefix));
        $created = 0;

        foreach ($deptIds as $deptId) {
            $dept = DB::one('SELECT * FROM departments WHERE id=?', array($deptId));
            if (!$dept) { echo "跳过：科室 {$deptId} 不存在\n"; continue; }
            $seq = (int)DB::val('SELECT MAX(visit_seq) FROM registrations WHERE first_dept_id=? AND date(registered_at)=?', array($deptId, $today));
            for ($i = 0; $i < $perDept; $i++) {
                $patientSeq++;
                $flowMax++;
                $seq++;
                $name = $this->pick($this->surnames) . $this->pick($this->givens);
                $gender = mt_rand(0, 1) ? '男' : '女';
                $age = mt_rand(3, 88);
                $birth = date((date('Y') - $age) . '-m-d', mt_rand(0, time()));
                $patientNo = $dayPrefix . sprintf('%02d', $patientSeq);
                // 幂等：患者号已存在（重复运行）则复用；id_card 随机冲突时重试
                if (!(int)DB::val('SELECT COUNT(*) FROM patients WHERE patient_no=?', array($patientNo))) {
                    $ok = false;
                    for ($try = 0; $try < 5 && !$ok; $try++) {
                        try {
                            DB::insert('INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                                $patientNo, $dayPrefix . sprintf('%02d', $patientSeq) . sprintf('%04d', mt_rand(1000, 9999)) . sprintf('%04d', mt_rand(1000, 9999)),
                                $name, $gender, $birth, $age, '汉族', $age > 25 ? '已婚' : '未婚',
                                $this->pick(array('职员', '工人', '教师', '退休', '学生', '自由职业')),
                                '', '本市', '13' . sprintf('%09d', mt_rand(100000000, 999999999)),
                                '否认', '', '', now_str(),
                            ));
                            $ok = true;
                        } catch (Exception $ex) {
                            if (stripos((string)$ex->getMessage(), 'id_card') === false && stripos((string)$ex->getMessage(), 'patient_no') === false) throw $ex;
                        }
                    }
                }
                $flowNo = $dayPrefix . sprintf('%04d', $flowMax);
                $fee = (string)$dept['type'] === 'emergency' ? 50 : 20;
                $regTime = date('Y-m-d H:i:s', mktime(mt_rand(8, 15), mt_rand(0, 59), mt_rand(0, 59), (int)date('m'), (int)date('d'), (int)date('Y')));
                $payTime = date('Y-m-d H:i:s', strtotime($regTime) + mt_rand(120, 900));
                $visitId = (int)DB::insert('INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_id, cashier_name, registered_at, cancel_reason, is_extra, disposition, disposition_detail, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $patientNo, $flowNo, $seq, $deptId, $dept['name'], $deptId, $dept['name'],
                    mt_rand(8, 15) < 12 ? 'am' : 'pm', $this->pick(array('自费', '居民医保', '职工医保')), $fee,
                    'paid', $payTime, 2, '收款员', $regTime, '', 0, '', '', '',
                ));
                DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $visitId, 0, $patientNo, $flowNo, 'visit', $fee, 1, 2, '收款员', $payTime,
                ));
                $created++;
            }
            echo "    ↳ 科室「{$dept['name']}」新增 {$perDept} 名当天已缴费患者（号别 1-{$seq}）\n";
        }
        $this->out('门诊叫号：新增 ' . $created . ' 名当日已缴费患者');
        return $created;
    }

    /* ==================== 医技叫号（原 dept_call_seed）：既有就诊开已缴费单 ==================== */
    private function runTech() {
        $pdo = $this->pdo;
        // 精细模式参数：types=lab（仅检验）/ lab,exam（组合）/ 空=四类随机分布
        $want = $this->opt['types'] === ''
            ? array('lab', 'imaging', 'prescription', 'disposal')
            : array_values(array_filter(explode(',', $this->opt['types'])));
        foreach ($want as $w) {
            if (!in_array($w, array('lab', 'imaging', 'exam', 'prescription', 'disposal'), true)) {
                fwrite(STDERR, "\033[31m[Preflight Error] 未知医技类型「{$w}」（可选：lab / imaging / exam / prescription / disposal，可逗号组合）\033[0m\n");
                exit(1);
            }
        }
        // 规范化别名：exam = imaging（检查单），统一映射到 order_type=imaging
        $want = array_map(function ($w) { return $w === 'exam' ? 'imaging' : $w; }, $want);

        // 前置依赖探测（Preflight）
        $pf = new PreflightChecker();
        $pf->diagnosis();
        if (in_array('lab', $want, true))          $pf->lab();
        if (in_array('imaging', $want, true))      $pf->exam();
        if (in_array('disposal', $want, true))     $pf->disposal();
        if (in_array('prescription', $want, true)) $pf->drugs();

        $n = max(1, min(50, $this->opt['count']));

        // 字典：检验/检查/处置/药品（按需取）
        $labItem  = in_array('lab', $want, true)          ? DB::one("SELECT * FROM lab_items WHERE status='approved' AND is_group=0 ORDER BY id LIMIT 1") : null;
        $examItem = in_array('imaging', $want, true)      ? DB::one("SELECT * FROM exam_items WHERE status='approved' ORDER BY id LIMIT 1") : null;
        $procItem = in_array('disposal', $want, true)     ? DB::one("SELECT * FROM disposal_items WHERE status='approved' AND is_nurse=1 ORDER BY id LIMIT 1") : null;
        $drugItem = in_array('prescription', $want, true) ? DB::one("SELECT * FROM drugs WHERE status='approved' ORDER BY id LIMIT 1") : null;

        $engine = new VisitFlowEngine();
        $plan = array(
            'lab'          => array('lab',          'lab',          $labItem,  $labItem  ? (float)$labItem['price']  : 0, array()),
            'imaging'      => array('imaging',      'imaging',      $examItem, $examItem ? (float)$examItem['price'] : 0, array()),
            'prescription' => array('prescription', 'prescription', $drugItem, $drugItem ? (float)$drugItem['price'] : 0, array()),
            'procedure'    => array('procedure',    'procedure',    $procItem, $procItem ? (float)$procItem['fee']   : 0, array('is_nurse' => 1)),
        );
        $cnt = array('lab' => 0, 'imaging' => 0, 'prescription' => 0, 'procedure' => 0);
        // 每类各挑「尚无该类型待办单」的待就诊/就诊中患者（保证队列新增 N 位新患者）
        foreach ($plan as $key => $cfg) {
            $orderType = $cfg[0];
            if (!in_array($orderType, $want, true)) continue;
            list($orderType, $itemType, $item, $price, $extra) = $cfg;
            $visits = DB::q(
                "SELECT id, patient_no, flow_no, current_dept_id, current_dept_name, visit_seq
                 FROM registrations
                 WHERE status IN ('paid','visiting')
                   AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.visit_id=registrations.id AND o.order_type=? AND o.status IN ('paid','reviewed'))
                 ORDER BY id DESC LIMIT ?",
                array($orderType, $n)
            );
            foreach ($visits as $v) {
                $engine->createPaidOrder($v, $orderType, $itemType, $item, $price, $extra);
                $cnt[$key]++;
            }
        }
        $this->out('医技叫号：检验 +' . $cnt['lab'] . ' ｜ 检查 +' . $cnt['imaging'] . ' ｜ 处方 +' . $cnt['prescription'] . ' ｜ 护理处置 +' . $cnt['procedure']);
        echo "提示：在【叫号管理】为对应医技科室创建大屏诊室，然后用 lab4001/imaging5001/pharmacy6001/nurse3001 登录工作台绑定诊室测试叫号。\n";
        return $cnt['lab'] + $cnt['imaging'] + $cnt['prescription'] + $cnt['procedure'];
    }

    /** @var array 患者姓名池 */
    private $surnames = array('李','王','张','刘','陈','杨','赵','黄','周','吴','徐','孙','马','朱','胡','郭','何','林','罗','郑','梁','谢','宋','唐','许','韩','冯','邓','曹','彭','曾','肖','田','董','袁','潘','蒋','蔡','余','杜');
    private $givens = array('伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','秀英','霞','平','刚','文轩','雨欣','子涵','浩然','诗琪','梦琪','建国','建军','国强','志强','海燕','雪梅','丽华','嘉怡','晓彤','泽宇','子墨','春华','国庆');
}

$opt = QueueSeeder::parseArgv(array_slice($argv, 1));
$s = new QueueSeeder($opt);
$s->run();
