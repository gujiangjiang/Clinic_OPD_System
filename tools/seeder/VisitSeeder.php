<?php
/**
 * ============================================================
 * tools/seeder/VisitSeeder.php — 患者就诊链生成器（合并版）
 * ============================================================
 * 合并原 scenarios/full_seed.php（清理+配额排片）、demo_seed.php
 * （多医生分诊/诊断证明/退费取消状态）、doctor2001_seed.php
 * （指定医生工号+严格就诊链）三份场景的就诊链生成逻辑，
 * 统一参数化：支持 指定医生工号 / 指定科室 / 时间窗口 / 旧数据清理。
 *
 * 就诊状态分布（每科室）：诊毕（近窗口含转归/续写/会诊）+ 既往诊毕
 * （60-150 天前历史调阅素材）+ 待就诊（含今日）+ 就诊中（含今日）+
 * 待缴费（今日，打印中心「未缴费」灰色态测试）+ 已退费/已取消
 * （打印中心红色删除线/退费链路测试）。
 * 完整就诊链约束：挂号 →（缴费）→ 生命体征 → 结构化病历
 * （patient_records + records 双镜像）→ 开单（检验/检查/处置/处方）
 * → 缴费 → 执行（检验结果/影像报告/影像引用/护理执行/发药）
 * → 多人续写 → 会诊记录 → 诊断证明 → 诊毕转归；开单必先有病历。
 *
 * 用法：
 *   php tools/bin/seed.php --scene=visit              # 就诊链专项（追加式）
 *   php tools/bin/seed.php --scene="doctor=2001"      # 指定医生工号（校验存在且为医生）
 *   php tools/bin/seed.php --scene="dept=2,5"         # 指定科室就诊链
 *   php tools/bin/seed.php --all                      # 全量（字典 + 就诊链，含 --clean）
 *
 * 参数（经 seed.php 透传）：--doctor=工号（空=按科室绑定医生分诊）
 *   --depts=2,5 / --days=N（默认15）/ --clean（清理旧业务数据）/ --count=N（新建患者数，默认30）
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require_once APP_ROOT . '/app/includes/emr_formatter.php';
require __DIR__ . '/Seeder.php';

class VisitSeeder extends Seeder {

    protected $label = '就诊链';

    /** @var array 运行参数（构造时由 argv 解析） */
    private $opt = array(
        'doctor' => '',    // 医生工号（空 = 按科室绑定医生分诊，多医生）
        'depts' => '',     // 科室 ID CSV（空 = 全部临床/急诊科室）
        'days' => 15,      // 时间窗口（天）
        'count' => 30,     // 新建患者数
        'clean' => false,  // 清理旧业务数据
    );

    /** @var array 文案池（合并 demo/doctor2001 场景） */
    private $ccPool = array(
        array('反复头晕头痛', '3', '年', '加重', '1', '周'),
        array('咳嗽咳痰', '5', '天', '伴发热', '1', '天'),
        array('上腹部疼痛', '2', '天', '伴反酸', '6', '小时'),
        array('咽痛伴吞咽困难', '3', '天', '加重', '1', '天'),
        array('腰痛伴尿频尿急', '2', '天', '加重', '4', '小时'),
        array('胸闷气短', '1', '月', '伴心悸', '3', '天'),
        array('腹泻稀水样便', '1', '天', '伴乏力', '5', '小时'),
        array('右下腹持续性疼痛', '6', '小时', '伴恶心呕吐', '2', '小时'),
        array('关节肿痛', '10', '天', '伴晨僵', '3', '天'),
        array('皮疹伴瘙痒', '4', '天', '加重', '1', '天'),
        array('摔伤后左前臂疼痛肿胀', '3', '小时', '伴活动受限', '3', '小时'),
        array('头部外伤后出血', '1', '小时', '伴短暂头晕', '1', '小时'),
        array('右手切割伤后疼痛出血', '30', '分钟', '伴活动受限', '30', '分钟'),
        array('右足踝扭伤后肿痛', '1', '天', '伴行走困难', '1', '天'),
        array('腰背部扭伤后疼痛', '5', '小时', '伴活动受限', '5', '小时'),
        array('右拇指甲周红肿疼痛', '3', '天', '加重', '1', '天'),
        array('左小腿蜂窝织炎红肿热痛', '2', '天', '伴发热', '1', '天'),
        array('腹部撞击伤后腹痛', '4', '小时', '伴腹胀', '4', '小时'),
    );
    private $piTails = array(
        // 现病史结尾（无句号：渲染时后随固定逗号 + 来院途径）
        '无昏迷呕吐，无大小便失禁，伤后未行特殊处理，为求进一步诊治',
        '无寒战高热，无胸闷胸痛，饮食睡眠欠佳，二便正常',
        '伴乏力纳差，无恶心呕吐，睡眠可，小便正常，大便干结',
        '自服药物（具体不详）后症状缓解不明显，为求进一步诊治',
        '自行简单包扎后出血已止，局部肿胀明显',
        '外院初步处理后症状缓解不明显，为求进一步诊治',
        '起病以来精神尚可，胃纳一般，睡眠欠佳，体重无明显变化',
    );
    private $pePool = array(
        '神志清楚，呼吸平稳，心律齐，未闻及杂音，腹平软，无压痛，肝脾肋下未及，双下肢无水肿。',
        '体温正常，咽部充血，双侧扁桃体I度肿大，双肺呼吸音清，未闻及干湿性啰音，腹软无压痛。',
        '神清，精神可，皮肤巩膜无黄染，浅表淋巴结未及肿大，心肺腹未见明显异常，生理反射存在，病理反射未引出。',
        '急性痛苦面容，腹肌紧张，右下腹麦氏点压痛（+），反跳痛（+），肠鸣音亢进。',
        '神志清楚，左前臂肿胀畸形，压痛（+），可及骨擦感，桡动脉搏动可及，指端血运感觉正常。',
        '神清，头皮可见约3cm裂伤创口，创缘整齐，活动性出血已止，双侧瞳孔等大等圆，对光反射灵敏。',
        '神清，右外踝肿胀压痛（+），瘀斑明显，未及骨擦感，足背动脉搏动可及，末梢血运正常。',
        '神清，腰部肌肉紧张，L4/5棘突旁压痛（+），叩击痛（+），双下肢直腿抬高试验（-）。',
        '神清，体温38.2℃，左小腿下段红肿热痛，边界不清，触痛明显，可及波动感，腹股沟淋巴结未及肿大。',
    );
    private $advicePool = array(
        '清淡饮食，多饮水，注意休息，一周后门诊复查。',
        '规律服药，避免劳累，症状加重及时就诊。',
        '低盐低脂饮食，监测血压，不适随诊。',
        '清创换药，定期复查，保持伤口清洁干燥，不适随诊。',
        '患肢制动抬高，一周后门诊复查X线，不适随诊。',
        '建议住院进一步治疗，目前予对症处理，密切观察病情变化。',
    );
    private $progPool = array(
        '患者诉症状较前缓解，继续目前治疗方案，嘱其注意休息，密切观察病情变化。',
        '补充询问病史：患者既往有类似发作史，未系统诊治。今日复查相关指标，调整用药。',
        '查看辅助检查结果回报，结合临床表现，目前诊断明确，继续当前治疗，一周后复诊。',
        '患者诉疼痛较前缓解，创面敷料干燥，继续目前治疗方案，密切观察病情变化。',
        '换药见创面清洁，肉芽组织生长良好，无渗出，继续换药，择期拆线。',
        '会诊医师查看患者后同意目前诊断及处理方案，建议完善检查后择期手术。',
    );
    private $wardPool = array('呼吸内科病区', '心内科病区', '普外科病区', '骨科病区', '急诊观察病区');
    private $hospPool = array('市第一人民医院', '市中心医院', '医科大学附属医院');
    private $deathPool = array('心源性猝死', '多器官功能衰竭', '呼吸衰竭');
    private $otherPool = array('症状缓解后自动离院，随访丢失', '转社区卫生服务中心继续治疗', '症状缓解后要求离院');
    private $conscPool = array('清醒', '清醒', '清醒', '嗜睡', '模糊');

    private $surnames = array('李','王','张','刘','陈','杨','赵','黄','周','吴','徐','孙','马','朱','胡','郭','何','林','罗','郑','梁','谢','宋','唐','许','韩','冯','邓','曹','彭','曾','肖','田','董','袁','潘','蒋','蔡','余','杜');
    private $givens = array('伟','芳','娜','敏','静','丽','强','磊','军','洋','勇','艳','杰','娟','涛','明','超','霞','平','刚','建国','建军','国强','志强','海燕','雪梅','丽华','嘉怡','泽宇','春华','文轩','雨欣','子涵','浩然','诗琪','梦琪','国庆');

    /** @var array 序号续接（避免与既有数据冲突） */
    private $patientSeq = 0;
    private $flowSeq = array();
    private $seqSeq = array();
    private $reportSeq = array();

    /** @var array 运行期上下文（科室/医生池/医技执行人） */
    private $depts = array();
    private $doctors = array();
    private $staff = array('nurse' => '周梅', 'lab' => '陈静', 'imaging' => '黄浩', 'pharmacy' => '吴涛', 'cashier' => '收款员');

    /** @var array|null 指定主诊医生（doctor 参数），null = 按科室绑定分诊 */
    private $me = null;

    /** @var array 字典缓存（开单依赖） */
    private $labSingles = array();
    private $exams = array();
    private $disps = array();
    private $drugs = array();
    private $diagPool = array();

    /** @var array 患者池（就诊轮转） */
    private $patientPool = array();
    private $poolIdx = 0;

    /** @var int 计数器 */
    private $cnt = array('record' => 0, 'order' => 0, 'item' => 0, 'result' => 0, 'vital' => 0, 'consult' => 0, 'cert' => 0, 'visit' => 0);

    public function __construct($opt = array()) {
        parent::__construct();
        $this->opt = array_merge($this->opt, $opt);
    }

    /* ==================== 参数解析（seed.php 透传 argv 形如 key=value，兼容 -- 前缀） ==================== */
    public static function parseArgv($argv) {
        $opt = array('doctor' => '', 'depts' => '', 'days' => 15, 'count' => 30, 'clean' => false);
        foreach ((array)$argv as $a) {
            $a = ltrim((string)$a, '-');   // 统一去掉 -- 前缀（兼容 seed.php 透传的裸参数）
            if (strpos($a, 'doctor=') === 0) $opt['doctor'] = trim(substr($a, 7));
            elseif (strpos($a, 'depts=') === 0) $opt['depts'] = trim(substr($a, 6));
            elseif (strpos($a, 'days=') === 0) $opt['days'] = max(1, (int)substr($a, 5));
            elseif (strpos($a, 'count=') === 0) $opt['count'] = max(1, (int)substr($a, 6));
            elseif ($a === 'clean') $opt['clean'] = true;
        }
        return $opt;
    }

    public function run() {
        $pdo = $this->pdo;
        mt_srand((int)date('Ymd') + 13);   // 当日确定性随机
        $doctorLabel = $this->opt['doctor'] !== '' ? $this->opt['doctor'] : '按科室绑定分诊';
        $deptsLabel = $this->opt['depts'] !== '' ? $this->opt['depts'] : '全部临床/急诊';

        echo "=== 患者就诊链生成（doctor={$doctorLabel} depts={$deptsLabel} days={$this->opt['days']}） ===\n";

        if ($this->opt['clean']) $this->cleanBusinessData();

        /* ---------- 科室/医生池 ---------- */
        $deptIds = $this->opt['depts'] !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $this->opt['depts']))))
            : array();
        foreach ($pdo->query('SELECT * FROM departments WHERE status=1 ORDER BY id') as $r) {
            if ($deptIds && !in_array((int)$r['id'], $deptIds, true)) continue;
            if (!$deptIds && !in_array((string)$r['type'], array('clinic', 'emergency'), true)) continue;
            $this->depts[(int)$r['id']] = $r;
        }
        if (!count($this->depts)) exit("可用科室为空（请先执行 --module=dept）\n");

        foreach ($pdo->query("SELECT id, emp_no, name, dept_ids FROM users WHERE role='doctor' AND status=1 ORDER BY id") as $r) {
            $this->doctors[] = $r;
        }
        if (!count($this->doctors)) exit("无医生账号（请先执行 --module=user）\n");

        // 指定医生：校验存在且为医生角色，全部主诊为该医生
        $this->me = null;
        if ($this->opt['doctor'] !== '') {
            foreach ($this->doctors as $d) {
                if ((string)$d['emp_no'] === $this->opt['doctor'] || (string)$d['name'] === $this->opt['doctor']) { $this->me = $d; break; }
            }
            if (!$this->me) exit("指定医生工号 {$this->opt['doctor']} 不存在或不是医生角色\n");
            echo "    ↳ 主诊医生：{$this->me['name']}（工号 {$this->me['emp_no']}）\n";
        }

        /* ---------- 字典（开单依赖） ---------- */
        $this->labSingles = array();
        foreach ($pdo->query("SELECT id, name, price, unit, normal_range FROM lab_items WHERE is_group=0 AND parent_id=0 AND status='approved'") as $r) $this->labSingles[] = $r;
        $this->exams = array();
        foreach ($pdo->query("SELECT id, name, price, category FROM exam_items WHERE status='approved'") as $r) $this->exams[] = $r;
        $this->disps = array();
        foreach ($pdo->query("SELECT id, name, fee, is_nurse FROM disposal_items WHERE status='approved'") as $r) $this->disps[] = $r;
        $this->drugs = array();
        foreach ($pdo->query("SELECT id, name, price, spec, package_unit, vendor_short, single_dose, frequency, route, is_nurse FROM drugs WHERE status='approved'") as $r) $this->drugs[] = $r;
        $this->diagPool = array();
        foreach (DatabaseManager::q('icd10', "SELECT diagnosis_code, diagnosis_name FROM icd10 WHERE subcategory_code<>'' AND diagnosis_code!='' ORDER BY RANDOM() LIMIT 80") as $r) {
            $this->diagPool[] = array('code' => $r['diagnosis_code'], 'name' => $r['diagnosis_name']);
        }
        if (!count($this->diagPool) || !count($this->labSingles) || !count($this->exams) || !count($this->disps) || !count($this->drugs)) {
            exit("基础字典不完整（请先执行 --module=\"drug lab exam disposal\"）\n");
        }

        /* ---------- 序号续接 ---------- */
        foreach ($pdo->query("SELECT MAX(CAST(substr(patient_no,7) AS INTEGER)) AS m FROM patients WHERE patient_no LIKE '" . date('ymd') . "%'") as $r) {
            $this->patientSeq = (int)$r['m'];
        }
        foreach ($pdo->query("SELECT substr(flow_no,1,6) d, MAX(CAST(substr(flow_no,7) AS INTEGER)) m FROM registrations GROUP BY d") as $r) {
            $this->flowSeq[$r['d']] = (int)$r['m'];
        }
        foreach ($pdo->query("SELECT first_dept_id dp, substr(registered_at,1,10) d, MAX(visit_seq) m FROM registrations GROUP BY dp, d") as $r) {
            $this->seqSeq[$r['dp'] . '_' . $r['d']] = (int)$r['m'];
        }
        foreach ($pdo->query("SELECT substr(report_no,3,8) d, MAX(CAST(substr(report_no,11) AS INTEGER)) m FROM reports GROUP BY d") as $r) {
            $this->reportSeq[$r['d']] = (int)$r['m'];
        }

        /* ---------- 患者：新建 + 复用既有 ---------- */
        $newPatients = array();
        for ($i = 0; $i < $this->opt['count']; $i++) {
            $p = $this->createPatient();
            if ($p) $newPatients[] = $p;
        }
        $oldPatients = array();
        foreach ($pdo->query("SELECT patient_no, gender, birth_date FROM patients WHERE id_card IS NOT NULL AND id_card<>'' ORDER BY RANDOM() LIMIT 20") as $r) {
            $oldPatients[] = array('patient_no' => $r['patient_no'], 'gender' => $r['gender'], 'birth' => $r['birth_date']);
        }
        $pool = array_merge($newPatients, $oldPatients);
        shuffle($pool);
        $this->patientPool = $pool;
        $this->poolIdx = 0;
        echo "患者：新建 " . count($newPatients) . " + 复用既有 " . count($oldPatients) . "\n";

        /* ---------- 就诊排片（每科室配额） ---------- */
        $now = time();
        $quota = array(
            // [status, 挂号时间戳, opts]
            'finished_recent' => 8,
            'finished_far' => 5,
            'paid_today' => 4,
            'visiting_today' => 3,
            'pending_today' => 2,
            'refunded_recent' => 2,
            'cancelled_today' => 1,
            'paid_recent' => 4,
            'visiting_recent' => 2,
        );
        $visitIdx = 0;
        foreach (array_keys($this->depts) as $deptId) {
            for ($i = 0; $i < $quota['finished_recent']; $i++) {
                $ts = $now - mt_rand(1, $this->opt['days']) * 86400 - mt_rand(0, 6 * 3600);
                if ($ts > $now) $ts = $now - mt_rand(600, 6 * 3600);
                $this->makeVisit($this->nextPatient(), $deptId, $ts, 'finished', array(
                    // 覆盖续写/会诊/证明场景：约 2/3 有续写（同/跨医生随机）、
                    // 约 1/2 有会诊、个别出诊断证明，便于观察续写病历与会诊流程
                    'multiDoc' => mt_rand(1, 100) <= 65,
                    'consult' => mt_rand(1, 100) <= 50,
                    'cert' => mt_rand(1, 100) <= 20,
                ));
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['finished_far']; $i++) {
                $this->makeVisit($this->nextPatient(), $deptId, $now - mt_rand(60, 150) * 86400, 'finished', array());
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['paid_today']; $i++) {
                $ts = $now - mt_rand(600, 4 * 3600);
                if ($ts > $now) $ts = $now - 600;
                $this->makeVisit($this->nextPatient(), $deptId, $ts, 'paid');
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['visiting_today']; $i++) {
                $ts = $now - mt_rand(1200, 5 * 3600);
                if ($ts > $now) $ts = $now - 1200;
                $this->makeVisit($this->nextPatient(), $deptId, $ts, 'visiting', array('consult' => $i === 0));
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['pending_today']; $i++) {
                $ts = $now - mt_rand(300, 1200);
                if ($ts > $now) $ts = $now - 300;
                $this->makeVisit($this->nextPatient(), $deptId, $ts, 'pending');
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['refunded_recent']; $i++) {
                $this->makeVisit($this->nextPatient(), $deptId, $now - mt_rand(1, 5) * 86400 - mt_rand(0, 4 * 3600), 'refunded');
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['cancelled_today']; $i++) {
                $ts = $now - mt_rand(1800, 6 * 3600);
                if ($ts > $now) $ts = $now - 1800;
                $this->makeVisit($this->nextPatient(), $deptId, $ts, 'cancelled');
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['paid_recent']; $i++) {
                $this->makeVisit($this->nextPatient(), $deptId, $now - mt_rand(1, 3) * 86400 - mt_rand(0, 5 * 3600), 'paid');
                $visitIdx++;
            }
            for ($i = 0; $i < $quota['visiting_recent']; $i++) {
                $this->makeVisit($this->nextPatient(), $deptId, $now - mt_rand(1, 2) * 86400 - mt_rand(0, 4 * 3600), 'visiting');
                $visitIdx++;
            }
            echo "    ↳ 科室 {$deptId}（{$this->depts[$deptId]['name']}）+{$visitIdx} 条就诊\n";
        }

        // 新建患者补充：前 20 个新建患者再造 1 次更早的既往诊毕就诊（历史调阅素材）
        $extra = 0;
        foreach (array_slice($newPatients, 0, 20) as $p) {
            $deptId = $this->pickDeptId();
            $this->makeVisit($p, $deptId, $now - mt_rand(100, 200) * 86400, 'finished', array());
            $extra++;
        }

        $c = $this->cnt;
        echo "\n汇总：就诊 {$c['visit']}｜病历 {$c['record']}（含续写/会诊/镜像）｜医嘱单 {$c['order']}｜明细 {$c['item']}｜报告 {$c['result']}｜体征 {$c['vital']}｜会诊 {$c['consult']}｜证明 {$c['cert']}｜历史补充 {$extra}\n";
        echo "=== 生成完毕 ===\n";
        return $c['visit'];
    }

    /** 按科室轮转取患者 */
    private function nextPatient() {
        $p = $this->patientPool[$this->poolIdx % count($this->patientPool)];
        $this->poolIdx++;
        return $p;
    }

    /** 随机取一个可用科室 */
    private function pickDeptId() {
        return (int)$this->pick(array_keys($this->depts));
    }

    /** 就诊主诊医生：指定医生优先，否则按科室绑定医生随机分诊 */
    private function pickDoctor($deptId) {
        if ($this->me) return $this->me;
        $bound = array();
        foreach ($this->doctors as $doc) {
            if (in_array((string)$deptId, explode(',', (string)$doc['dept_ids']))) $bound[] = $doc;
        }
        return count($bound) ? $this->pick($bound) : $this->pick($this->doctors);
    }

    /** 清理旧业务数据（字典类数据按唯一键幂等保留；外键从子到父逐表容错清理） */
    private function cleanBusinessData() {
        $tables = array(
            'refund_approvals', 'refund_requests', 'refunds', 'skin_test_results',
            'reports', 'results', 'certificates', 'call_events', 'print_snapshots',
            'imaging_refs', 'order_items', 'orders', 'payments', 'inventory_trans', 'consultations',
            'patient_records', 'records', 'vitals', 'nursing', 'registrations', 'patients',
        );
        $cleaned = 0;
        foreach ($tables as $t) {
            try {
                $n = (int)$this->pdo->exec('DELETE FROM ' . $t);
                if ($n > 0) $cleaned++;
            } catch (Exception $ex) { /* 表不存在或顺序差异：容错跳过 */ }
        }
        $this->out('已清理旧业务数据（' . $cleaned . ' 张表，患者/就诊/病历/开单/缴费/报告重新生成）');
    }

    /** 新建患者（幂等：患者号已存在则复用；id_card 随机冲突重试） */
    private function createPatient() {
        $this->patientSeq++;
        $name = $this->pick($this->surnames) . $this->pick($this->givens);
        $gender = $this->pick(array('男', '男', '女'));
        $age = mt_rand(3, 88);
        $birth = date((intval(date('Y')) - $age) . '-m-d', mt_rand(0, time()));
        $pno = date('ymd') . sprintf('%02d', $this->patientSeq);
        if (!(int)DB::val('SELECT COUNT(*) FROM patients WHERE patient_no=?', array($pno))) {
            $inserted = false;
            for ($retry = 0; $retry < 5 && !$inserted; $retry++) {
                try {
                    DB::insert('INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                        $pno,
                        date('Ymd', strtotime($birth)) . sprintf('%04d', mt_rand(1000, 9999)) . sprintf('%04d', mt_rand(1000, 9999)),
                        $name, $gender, $birth, $age, '汉族', $age > 25 ? '已婚' : '未婚',
                        $this->pick(array('职员', '工人', '农民', '教师', '退休', '学生', '自由职业')),
                        '', '本市', '13' . sprintf('%09d', mt_rand(100000000, 999999999)),
                        '否认', '', '', now_str(),
                    ));
                    $inserted = true;
                } catch (Exception $ex) {
                    if (stripos((string)$ex->getMessage(), 'id_card') === false && stripos((string)$ex->getMessage(), 'patient_no') === false) throw $ex;
                }
            }
            if (!$inserted) return null;
        }
        return array('patient_no' => $pno, 'gender' => $gender, 'birth' => $birth, 'name' => $name);
    }

    /**
     * 造一次完整就诊（严格链路：挂号→缴费→体征→病历→开单→缴费→执行→续写/会诊→诊毕）
     * @param array  $p      patient_no/gender/birth
     * @param int    $deptId 科室 ID
     * @param int    $ts     挂号时间戳
     * @param string $status finished/paid/visiting/pending/refunded/cancelled
     * @param array  $opts   {multiDoc: bool, consult: bool, cert: bool}
     */
    private function makeVisit($p, $deptId, $ts, $status, $opts = array()) {
        $pdo = $this->pdo;
        $dept = $this->depts[$deptId];
        $dayPrefix = date('ymd', $ts);
        $day = date('Y-m-d', $ts);
        $doc = $this->pickDoctor($deptId);
        $docId = (int)$doc['id'];
        $docName = (string)$doc['name'];

        // 挂号（序号续接）
        $this->flowSeq[$dayPrefix] = (isset($this->flowSeq[$dayPrefix]) ? $this->flowSeq[$dayPrefix] : 0) + 1;
        $flowNo = $dayPrefix . sprintf('%04d', $this->flowSeq[$dayPrefix]);
        $skey = $deptId . '_' . $day;
        $this->seqSeq[$skey] = (isset($this->seqSeq[$skey]) ? $this->seqSeq[$skey] : 0) + 1;
        $fee = (string)$dept['type'] === 'emergency' ? 50 : $this->pick(array(10, 20));
        $paid = in_array($status, array('paid', 'visiting', 'finished'), true);
        $payTime = $paid ? date('Y-m-d H:i:s', $ts + mt_rand(120, 900)) : '';
        $disp = ''; $dispDetail = ''; $finishedAt = '';
        if ($status === 'finished') {
            $r2 = mt_rand(1, 100);
            if ($r2 <= 68) { $disp = '自主离院'; }
            elseif ($r2 <= 80) { $disp = '住院'; $dispDetail = $this->pick($this->wardPool); }
            elseif ($r2 <= 88) { $disp = '转院'; $dispDetail = $this->pick($this->hospPool); }
            elseif ($r2 <= 94) { $disp = '死亡'; $dispDetail = $this->pick($this->deathPool); }
            else { $disp = '其他'; $dispDetail = $this->pick($this->otherPool); }
            $finishedAt = date('Y-m-d H:i:s', $ts + mt_rand(3 * 3600, 8 * 3600));
        }
        $visitId = (int)DB::insert('INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_id, cashier_name, registered_at, cancel_reason, is_extra, disposition, disposition_detail, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $p['patient_no'], $flowNo, $this->seqSeq[$skey], $deptId, $dept['name'], $deptId, $dept['name'],
            date('H', $ts) < 12 ? 'am' : 'pm', $this->pick(array('自费', '居民医保', '职工医保')), $fee,
            $status, $payTime, 2, $this->staff['cashier'], date('Y-m-d H:i:s', $ts), '', 0, $disp, $dispDetail, $finishedAt,
        ));
        $this->cnt['visit']++;

        if ($paid) {
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, 0, $p['patient_no'], $flowNo, 'visit', $fee, 1, 2, $this->staff['cashier'], $payTime,
            ));
        }

        // 待缴费/已退费/已取消：只挂号，到此为止（无病历无开单，链路完整）
        if (in_array($status, array('pending', 'refunded', 'cancelled'), true)) return $visitId;
        // 待就诊：尚未接诊，无病历无开单（挂号+缴费即可候诊）
        if ($status === 'paid') return $visitId;

        /* ==================== 生命体征 ==================== */
        $consciousness = $this->pick($this->conscPool);
        $sys = mt_rand(95, 160); $dia = mt_rand(60, 98); $hr = mt_rand(60, 110); $spo2 = mt_rand(94, 100); $resp = mt_rand(14, 22);
        $vTime = date('Y-m-d H:i:s', $ts + mt_rand(300, 1500));
        DB::insert('INSERT INTO vitals(visit_id, patient_no, flow_no, vital_sbp, vital_dbp, vital_heart_rate, vital_pulse, vital_spo2, vital_respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $p['patient_no'], $flowNo, $sys, $dia, $hr, $hr, $spo2, $resp, $docName, $vTime,
        ));
        $this->cnt['vital']++;
        $vitalsText = '血压 ' . $sys . '/' . $dia . 'mmHg；心率 ' . $hr . '次/分；血氧 ' . $spo2 . '%；呼吸 ' . $resp . '次/分';

        /* ==================== 诊断 + 结构化病历（开单前置） ==================== */
        $ccRow = $this->pick($this->ccPool);
        $diagPick = array();
        $used = array();
        for ($q = 0; $q < mt_rand(1, 3); $q++) {
            $dg = $this->pick($this->diagPool);
            if (isset($used[$dg['code']])) continue;
            $used[$dg['code']] = 1;
            $diagPick[] = array(
                'code' => $dg['code'], 'name' => $dg['name'],
                'part' => (mt_rand(1, 100) <= 30 ? $this->pick(array('左侧', '右侧', '右上肢', '左下肢')) : ''),
                'note' => (mt_rand(1, 100) <= 20 ? $this->pick(array('外伤所致', '既往类似发作')) : ''),
                'suspected' => (mt_rand(1, 100) <= 12 ? '是' : ''),
            );
        }
        $emr = emr_default_data(null);
        $emr['chief_complaint'] = array('symptom' => $ccRow[0], 'duration' => $ccRow[1], 'unit' => $ccRow[2], 'second_symptom' => $ccRow[3], 'second_duration' => $ccRow[4], 'second_unit' => $ccRow[5]);
        // 现病史按结构化字段生成：供史者(informant) + 时间(duration) + 单位(unit) + 内容(content)。
        // content 以「前」衔接前面的时间单位（渲染后为 患者自诉1天前…），末尾不带句号——
        // 渲染时 emr_pi_text 会固定拼接「，来院途径」，避免句号/来院重复
        $onset = preg_match('/伤|摔|切|撞|扭|压/', (string)$ccRow[0]) ? '不慎' : '无明显诱因出现';
        $piContent = '前' . $onset . $ccRow[0] .
            ($ccRow[3] !== '' ? '，' . $ccRow[3] . $ccRow[4] . $ccRow[5] : '') .
            '，' . $this->pick($this->piTails);
        $emr['history_present'] = array(
            'informant' => '患者自诉',
            'duration' => $ccRow[1],
            'unit' => $ccRow[2],
            'content' => $piContent,
            'arrival_way' => (string)$dept['type'] === 'emergency' ? $this->pick(array('120接入', '自行来院')) : '自行来院',
        );
        $phType = $this->pick(array('否认', '否认', '承认'));
        $emr['past_history'] = array('type' => $phType, 'detail' => $phType === '承认' ? $this->pick(array('高血压病史5年', '2型糖尿病史3年', '慢性胃炎病史')) : '');
        $emr['allergies'] = array('type' => $this->pick(array('否认', '否认', '承认')), 'detail' => '');
        // 体格检查按结构化键存放：体检描述归入「其它体格检查」（emr_pe_text 输出「其它体格检查：xxx」，
        // 此前误用 content 键导致显示为 content:xxx）
        $emr['physical_exam'] = array(
            '皮肤黏膜' => '', '头部' => '', '胸部' => '', '肺脏及胸膜' => '', '心脏' => '',
            '腹部' => '', '神经反射' => '', '肌力及肌张力' => '', '其它体格检查' => $this->pick($this->pePool),
        );
        $emr['diagnoses'] = $diagPick;
        $emr['advice'] = $this->pick($this->advicePool);
        $recCreated = date('Y-m-d H:i:s', $ts + mt_rand(600, 2700));
        $recUpdated = $status === 'finished' ? date('Y-m-d H:i:s', strtotime($recCreated) + mt_rand(600, 3600)) : $recCreated;

        /* ==================== 开单（先病历后开单）+ 执行 + 报告 ==================== */
        $auxNames = array(); $rxLines = array(); $dispItems = array();
        $visitOrders = array();
        $nOrd = mt_rand(1, 3);
        for ($k = 0; $k < $nOrd; $k++) {
            $otype = $this->pick(array('lab', 'imaging', 'procedure', 'prescription'));
            $created = date('Y-m-d H:i:s', strtotime($recCreated) + mt_rand(120, 1200) * ($k + 1));
            $paidAt = date('Y-m-d H:i:s', strtotime($payTime) + mt_rand(60, 900) * ($k + 1));
            $ostatus = 'paid'; $execBy = ''; $execAt = '';
            if ($status === 'finished') {
                $ostatus = ($otype === 'prescription') ? 'dispensed' : 'done';
                $execBy = ($otype === 'prescription') ? $this->staff['pharmacy'] : (($otype === 'imaging') ? $this->staff['imaging'] : ($otype === 'procedure' ? $this->staff['nurse'] : $this->staff['lab']));
                $execAt = date('Y-m-d H:i:s', strtotime($paidAt) + mt_rand(600, 5400));
            }
            $total = 0; $itemRows = array();
            if ($otype === 'lab') {
                $used2 = array();
                for ($q = 0; $q < mt_rand(1, 3); $q++) {
                    $it = $this->pick($this->labSingles);
                    if (isset($used2[$it['id']])) continue;
                    $used2[$it['id']] = 1;
                    $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array());
                    $total += (float)$it['price'];
                    $auxNames[] = $it['name'];
                }
            } elseif ($otype === 'imaging') {
                $it = $this->pick($this->exams);
                $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'extra' => array(), 'category' => $it['category']);
                $total += (float)$it['price'];
                $auxNames[] = $it['name'];
            } elseif ($otype === 'procedure') {
                $used2 = array();
                for ($q = 0; $q < mt_rand(1, 2); $q++) {
                    $it = $this->pick($this->disps);
                    if (isset($used2[$it['id']])) continue;
                    $used2[$it['id']] = 1;
                    $qty = mt_rand(1, 2);
                    $itemRows[] = array('item_id' => $it['id'], 'item_name' => $it['name'], 'price' => $it['fee'], 'qty' => $qty, 'extra' => array('is_nurse' => $it['is_nurse']));
                    $total += (float)$it['fee'] * $qty;
                    $dispItems[] = array('name' => $it['name'], 'qty' => $qty);
                }
            } else {
                $used2 = array();
                for ($q = 0; $q < mt_rand(1, 4); $q++) {
                    $it = $this->pick($this->drugs);
                    if (isset($used2[$it['id']])) continue;
                    $used2[$it['id']] = 1;
                    $qty = mt_rand(1, 3);
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
            $prefix = array('lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ', 'prescription' => 'CF');
            $orderNo = $prefix[$otype] . date('YmdHis', strtotime($created)) . sprintf('%02d', mt_rand(0, 99));
            while (DB::one('SELECT id FROM orders WHERE order_no=?', array($orderNo))) {
                $orderNo = $prefix[$otype] . date('YmdHis', strtotime($created)) . sprintf('%02d', mt_rand(0, 99));
            }
            $orderId = (int)DB::insert('INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, category_name, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at, refunded_at, done_by, dispensed_at, review_by, reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $p['patient_no'], $flowNo, $otype, $orderNo, '', $docId, $docName, 0, $deptId, $dept['name'],
                $total, $ostatus, $created, $paidAt, '', $ostatus === 'done' ? $execBy : '',
                $ostatus === 'dispensed' ? $execAt : '',
                $ostatus === 'dispensed' ? $this->staff['pharmacy'] : '',
                $ostatus === 'dispensed' ? $execAt : '',
            ));
            $this->cnt['order']++;
            $itemIds = array();
            foreach ($itemRows as $ir) {
                $ex = $ir['extra'];
                $iid = (int)DB::insert('INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, company_short, price, quantity, single_dose, frequency, route, is_nurse, sub_of, group_no, is_parent, parent_item_id, status, doctor_id, doctor_name, executed_by, executed_at, result_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $orderId, $visitId, $p['patient_no'], $flowNo, $otype, $ir['item_id'], $ir['item_name'],
                    isset($ex['spec']) ? $ex['spec'] : '', isset($ex['unit']) ? $ex['unit'] : '', isset($ex['company_short']) ? $ex['company_short'] : '',
                    $ir['price'], $ir['qty'],
                    isset($ex['single_dose']) ? $ex['single_dose'] : '', isset($ex['frequency']) ? $ex['frequency'] : '', isset($ex['route']) ? $ex['route'] : '',
                    isset($ex['is_nurse']) ? $ex['is_nurse'] : 0,
                    0, 0, 1, 0, $ostatus, $docId, $docName,
                    ($ostatus === 'dispensed' || $ostatus === 'done') ? $execBy : '',
                    ($ostatus === 'dispensed' || $ostatus === 'done') ? $execAt : '',
                    0, $created,
                ));
                $itemIds[$ir['item_id']] = $iid;
                $this->cnt['item']++;
            }
            $visitOrders[] = array('id' => $orderId, 'type' => $otype, 'status' => $ostatus, 'itemIds' => $itemIds, 'paid_at' => $paidAt, 'category' => isset($itemRows[0]['category']) ? $itemRows[0]['category'] : '', 'item_name' => $itemRows[0]['item_name']);

            // 执行结果 + 报告 + 影像引用（诊毕就诊才有）
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
                    $resTs = strtotime($execAt) + mt_rand(600, 3600);
                    $findings = $otype === 'imaging' ? $this->pick(array('所见骨质结构完整，未见明显骨折征象。', '双肺纹理增粗，余未见明显异常。', '软组织肿胀，未见明显异物存留。', '未见明显异常。')) : '';
                    $conclusion = $otype === 'imaging' ? $this->pick(array('符合临床诊断，请结合病史。', '未见明显异常，建议必要时复查。', '软组织损伤表现，请结合临床。')) : '';
                    $resultId = (int)DB::insert('INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, findings, conclusion, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                        $itemId, $iid, $visitId, $p['patient_no'], $flowNo, $otype, $valuesJson,
                        $findings, $conclusion, $execBy, 'done', date('Y-m-d H:i:s', $resTs), date('Y-m-d H:i:s', $resTs),
                    ));
                    $dayKey = date('Ymd', $resTs);
                    $this->reportSeq[$dayKey] = (isset($this->reportSeq[$dayKey]) ? $this->reportSeq[$dayKey] : 0) + 1;
                    DB::insert('INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, content, doctor, status, apply_dept, apply_doctor, clinical_diag, apply_time, reg_time, category_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                        $resultId, 'BG' . $dayKey . sprintf('%04d', $this->reportSeq[$dayKey]),
                        $visitId, $p['patient_no'], $flowNo, $otype, '',
                        $execBy, 'done', $dept['name'], $docName, $diagPick[0]['name'],
                        $created, $execAt, $otype === 'imaging' ? $visitOrders[count($visitOrders) - 1]['category'] : '', date('Y-m-d H:i:s', $resTs),
                    ));
                    DB::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $iid));
                    // 影像引用登记（三单匹配链路，报告出具后自动登记）
                    DB::insert('INSERT INTO imaging_refs(order_item_id, order_id, visit_id, patient_no, flow_no, study_uid, series_uids, instance_count, modality, region, meta_json, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                        $iid, $orderId, $visitId, $p['patient_no'], $flowNo,
                        'BG' . $dayKey . sprintf('%04d', $this->reportSeq[$dayKey]), '[]', 0,
                        (strpos($itemRows[0]['item_name'], 'CT') !== false) ? 'CT' : ((strpos($itemRows[0]['item_name'], 'DR') !== false || strpos($itemRows[0]['item_name'], 'X线') !== false) ? 'DR' : ((strpos($itemRows[0]['item_name'], '超声') !== false || strpos($itemRows[0]['item_name'], '彩超') !== false) ? 'US' : 'OT')),
                        'region-pacs', '{}', $execBy, date('Y-m-d H:i:s', $resTs), date('Y-m-d H:i:s', $resTs),
                    ));
                    $this->cnt['result']++;
                }
            }
        }

        // 缴费流水（开单缴费 kind=order）
        foreach ($visitOrders as $vo) {
            $orderTotal = (float)DB::val('SELECT total_amount FROM orders WHERE id=?', array($vo['id']));
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $vo['id'], $p['patient_no'], $flowNo, 'order', $orderTotal, 1, 2, $this->staff['cashier'], $vo['paid_at'],
            ));
        }

        /* ==================== 病历落库（镜像双表） ==================== */
        $printText = emr_print_text($emr, $vitalsText, $consciousness, $auxNames, $rxLines, $dispItems);
        $diagText = emr_diag_text($diagPick);
        $initialId = (int)DB::insert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $p['patient_no'], $flowNo, $deptId, $docId, $docName,
            'initial', 0, $ccRow[0], $ccRow[1], $ccRow[2], '患者自诉', $emr['history_present']['arrival_way'],
            $emr['past_history']['type'], '', '否', (string)$diagPick[0]['code'], (string)$diagPick[0]['name'],
            json_encode($emr, JSON_UNESCAPED_UNICODE), $printText,
            $status === 'finished' ? 'done' : 'draft', $recCreated, $recUpdated,
        ));
        $this->cnt['record']++;
        DB::insert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, past_history, allergy_history, physical_exam, consciousness, preliminary_diagnosis, icd10_code, is_observation, visit_type, doctor_advice, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $p['patient_no'], $flowNo, $deptId, $docId, $docName,
            $ccRow[0], emr_pi_text($emr['history_present']), $emr['past_history']['detail'], '',
            $emr['physical_exam']['其它体格检查'], $consciousness, $diagText, (string)$diagPick[0]['code'],
            (string)$dept['type'] === 'emergency' ? 1 : 0, '初诊', $emr['advice'],
            $status === 'finished' ? 'done' : 'draft', $recCreated, $recUpdated,
        ));

        /* ==================== 会诊（急诊↔门诊，配额内） ==================== */
        if (!empty($opts['consult'])) {
            $cTs = strtotime($recCreated) + mt_rand(300, 1800);
            $others = array();
            foreach ($this->doctors as $d) { if ((int)$d['id'] !== $docId) $others[] = $d; }
            if (count($others)) {
                $cDoc = $this->pick($others);
                $targetDept = (string)$dept['type'] === 'emergency' ? $this->pickClinicDept() : $this->pick(array_keys($this->depts));
                $tDept = isset($this->depts[$targetDept]) ? $this->depts[$targetDept] : $dept;
                $consStatus = $status === 'finished' ? 'done' : $this->pick(array('pending', 'accepted'));
                $acceptedBy = in_array($consStatus, array('accepted', 'done'), true) ? $cDoc['name'] : '';
                $acceptedAt = $acceptedBy !== '' ? date('Y-m-d H:i:s', $cTs + mt_rand(600, 3600)) : '';
                $consFinishedAt = $consStatus === 'done' ? date('Y-m-d H:i:s', $cTs + mt_rand(7200, 18000)) : '';
                $consNo = 'HZ' . date('YmdHis', $cTs) . sprintf('%04d', mt_rand(0, 9999));
                while (DB::one('SELECT id FROM consultations WHERE consult_no=?', array($consNo))) {
                    $consNo = 'HZ' . date('YmdHis', $cTs) . sprintf('%04d', mt_rand(0, 9999));
                }
                DB::insert('INSERT INTO consultations(visit_id, patient_no, flow_no, consult_no, from_dept_id, from_dept_name, from_doctor_id, from_doctor_name, target_dept_id, target_dept_name, description, purpose, status, accepted_by, accepted_at, finished_at, record_id, created_at, finished_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $visitId, $p['patient_no'], $flowNo, $consNo,
                    $deptId, $dept['name'], $docId, $docName,
                    (int)$tDept['id'], $tDept['name'],
                    '患者' . $ccRow[0] . '，' . $diagPick[0]['name'] . '，请贵科会诊协助评估处理方案。',
                    '协助评估诊断及进一步治疗方案。',
                    $consStatus, $acceptedBy, $acceptedAt, $consFinishedAt, 0, date('Y-m-d H:i:s', $cTs),
                    $consStatus === 'done' ? $acceptedBy : '',
                ));
                $this->cnt['consult']++;
            }
        }

        /* ==================== 多名其他医生续写（诊毕 + 配额内） ====================
         * 续写医生池：默认其他医生；50% 概率将首诊医生也加入候选，
         * 形成「同医生续写」与「跨医生续写」两类场景，便于测试续写病历功能 */
        if (!empty($opts['multiDoc'])) {
            $writers = array();
            foreach ($this->doctors as $d) { if ((int)$d['id'] !== $docId) $writers[] = $d; }
            if (mt_rand(1, 100) <= 50) $writers[] = array('id' => $docId, 'name' => $docName);
            shuffle($writers);
            $nWriters = mt_rand(1, 2);
            $wCount = 0;
            $parent = $initialId;
            $wTime = strtotime($recUpdated);
            foreach ($writers as $w) {
                if ($wCount >= $nWriters) break;
                $wCount++;
                $wTime += mt_rand(1800, 7200);
                $wTimeStr = date('Y-m-d H:i:s', $wTime);
                $wDiags = array();
                if (mt_rand(1, 100) <= 60) {
                    $wDiags[] = $diagPick[0];   // 引用首诊主诊断
                    if (mt_rand(1, 100) <= 40) {
                        $dg2 = $this->pick($this->diagPool);
                        $wDiags[] = array('code' => $dg2['code'], 'name' => $dg2['name'], 'part' => '', 'note' => '', 'suspected' => '');
                    }
                }
                $wEmr = emr_default_data(null);
                $wEmr['progress'] = array('content' => $this->pick($this->progPool));
                $wEmr['diagnoses'] = $wDiags;
                $wEmr['advice'] = $this->pick($this->advicePool);
                $wPrint = emr_print_text($wEmr, '', '', array(), array(), array());
                $wDiagText = count($wDiags) ? emr_diag_text($wDiags) : '';
                DB::insert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $visitId, $p['patient_no'], $flowNo, $deptId, (int)$w['id'], (string)$w['name'],
                    'progress', $parent, '', '', '', '患者自诉', '自行来院',
                    '', '', '否',
                    count($wDiags) ? (string)$wDiags[0]['code'] : '',
                    count($wDiags) ? (string)$wDiags[0]['name'] : '',
                    json_encode($wEmr, JSON_UNESCAPED_UNICODE), $wPrint,
                    'done', $wTimeStr, $wTimeStr,
                ));
                DB::insert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, consciousness, preliminary_diagnosis, icd10_code, visit_type, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $visitId, $p['patient_no'], $flowNo, $deptId, (int)$w['id'], (string)$w['name'],
                    '', '', '', $wDiagText, count($wDiags) ? (string)$wDiags[0]['code'] : '', '', 'done', $wTimeStr, $wTimeStr,
                ));
                $this->cnt['record']++;
                $parent = (int)DB::val('SELECT MAX(id) FROM patient_records WHERE visit_id=?', array($visitId));
            }
        }

        /* ==================== 诊断证明（诊毕 + 配额内） ==================== */
        if (!empty($opts['cert']) && $status === 'finished') {
            $cTs = strtotime($recUpdated) + mt_rand(600, 3000);
            $certNo = 'ZM' . date('YmdHis', $cTs) . sprintf('%02d', mt_rand(0, 99));
            while (DB::one('SELECT id FROM certificates WHERE cert_no=?', array($certNo))) {
                $certNo = 'ZM' . date('YmdHis', $cTs) . sprintf('%02d', mt_rand(0, 99));
            }
            DB::insert('INSERT INTO certificates(visit_id, patient_no, flow_no, doctor_id, doctor_name, content, created_at, cert_no, chief_complaint, present_illness, preliminary_diagnosis) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $p['patient_no'], $flowNo, $docId, $docName,
                $this->pick(array('建议休息3天，清淡饮食，规律服药，门诊随访。', '建议休息1周，避免剧烈运动，一周后复查。', '建议多饮水休息，症状加重及时就诊。')),
                date('Y-m-d H:i:s', $cTs), $certNo, $ccRow[0], $emr['history_present']['content'], $diagText,
            ));
            $this->cnt['cert']++;
        }
        return $visitId;
    }

    /** 随机取一个临床科室（会诊目标，无临床科室时回退任一科室） */
    private function pickClinicDept() {
        $clinic = array();
        foreach ($this->depts as $id => $d) {
            if ((string)$d['type'] === 'clinic' || (string)$d['type'] === 'emergency') $clinic[] = (int)$id;
        }
        return count($clinic) ? (int)$this->pick($clinic) : (int)$this->pick(array_keys($this->depts));
    }
}

$opt = VisitSeeder::parseArgv(array_slice($argv, 1));
$s = new VisitSeeder($opt);
$s->run();
