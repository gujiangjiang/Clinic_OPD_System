<?php
/**
 * ============================================================
 * tools/seeder/FhirDemoSeeder.php — FHIR/HL7 全链路验证种子生成器
 * ============================================================
 * 说明：面向 FHIR R4 接口层 / HL7 MLLP / 开放网关的「开箱即用」真实链路
 * 验证数据。生成 3 套完整连贯的门诊临床全流程数据（确定性、幂等可重跑）：
 *   患者建档（有效身份证号/门诊号/姓名）
 *   → 门诊挂号与接诊（Encounter）
 *   → 医生诊断（Condition 挂载标准 ICD-10）
 *   → 处方医嘱（MedicationRequest，含发药）
 *   → 影像检查与影像报告 + 真实 DICOM StudyInstanceUID / Series 明细
 *      （供 ImagingStudy 与 PACS Viewer 调用）
 *   → 检验开立与结果录入（Observation），其中 2 套含触发阈值的危急值
 *      （自动写入 critical_values 流水）
 * 同时写入 FHIR/HL7/HIS/LIS 入向演示凭证，便于联调手册一键验证。
 *
 * 执行：php tools/bin/seed.php --module=fhir  （或 --scene=fhir / --all）
 * 前置：需已执行基础字典模块（dept/user/lab/exam/drug）。
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

class FhirDemoSeeder extends Seeder {

    protected $label = 'FHIR 全链路种子';

    /** 固定患者号前缀（幂等清理定位用） */
    const PREFIX = 'FHD';

    /** 3 套临床旅程定义 */
    private $journeys = array(
        array(
            'seq' => 1, 'name' => '张伟', 'gender' => '男', 'age' => 45,
            'id_card' => '320102198001150011',
            'dept_id' => 1, 'diag_code' => 'J06.900', 'diag_name' => '急性上呼吸道感染',
            'lab' => array(
                array('name' => '白细胞计数(WBC)', 'value' => '45.0', 'flag' => 'HH'),
                array('name' => '血红蛋白(HGB)', 'value' => '150', 'flag' => 'N'),
                array('name' => '血小板计数(PLT)', 'value' => '210', 'flag' => 'N'),
            ),
            'exam' => array('name' => '胸部CT平扫', 'category' => 'CT',
                'findings' => '双肺纹理增粗，右肺上叶见斑片状高密度影，边界模糊。',
                'conclusion' => '右肺上叶炎症可能，建议抗炎治疗后复查。',
                'series' => array(
                    array('desc' => '胸部CT平扫-肺窗', 'modality' => 'CT', 'instances' => 128),
                    array('desc' => '胸部CT平扫-纵隔窗', 'modality' => 'CT', 'instances' => 96),
                )),
            'drugs' => array(array('name' => '阿莫西林胶囊', 'qty' => 2), array('name' => '布洛芬缓释胶囊', 'qty' => 1)),
            'critical' => true,
        ),
        array(
            'seq' => 2, 'name' => '李娜', 'gender' => '女', 'age' => 38,
            'id_card' => '320102198603220028',
            'dept_id' => 4, 'diag_code' => 'K29.500', 'diag_name' => '慢性胃炎',
            'lab' => array(
                array('name' => '谷丙转氨酶(ALT)', 'value' => '156', 'flag' => 'H'),
                array('name' => '总胆红素(TBIL)', 'value' => '18.5', 'flag' => 'H'),
                array('name' => '白蛋白(ALB)', 'value' => '42', 'flag' => 'N'),
            ),
            'exam' => array('name' => '腹部彩超', 'category' => '超声',
                'findings' => '肝脾大小形态正常，胆囊壁毛糙，胰腺未见明显异常。',
                'conclusion' => '胆囊壁毛糙，请结合临床。',
                'series' => array(
                    array('desc' => '腹部彩超-肝胆胰脾', 'modality' => 'US', 'instances' => 12),
                )),
            'drugs' => array(array('name' => '奥美拉唑肠溶胶囊', 'qty' => 1)),
            'critical' => false,
        ),
        array(
            'seq' => 3, 'name' => '王强', 'gender' => '男', 'age' => 62,
            'id_card' => '320102196305110037',
            'dept_id' => 5, 'diag_code' => 'I10.x00', 'diag_name' => '特发性（原发性）高血压',
            'lab' => array(
                array('name' => '钾(K)', 'value' => '7.2', 'flag' => 'HH'),
                array('name' => '钠(NA)', 'value' => '140', 'flag' => 'N'),
                array('name' => '空腹血糖', 'value' => '25.6', 'flag' => 'HH'),
            ),
            'exam' => array('name' => '头颅MRI平扫', 'category' => 'MR',
                'findings' => '双侧基底节区见多发点状长T1长T2信号，FLAIR呈高信号。',
                'conclusion' => '双侧基底节区多发腔隙性缺血灶，建议结合临床。',
                'series' => array(
                    array('desc' => '头颅MRI-T1WI', 'modality' => 'MR', 'instances' => 88),
                    array('desc' => '头颅MRI-T2WI', 'modality' => 'MR', 'instances' => 92),
                    array('desc' => '头颅MRI-FLAIR', 'modality' => 'MR', 'instances' => 90),
                )),
            'drugs' => array(array('name' => '硝苯地平缓释片', 'qty' => 2)),
            'critical' => true,
        ),
    );

    /** 报告序号（同名日期递增，避免唯一冲突） */
    private $reportSeq = 0;

    public function run() {
        $pdo = $this->pdo;

        // 需先具备基础字典
        $deptCount = (int)$pdo->query('SELECT COUNT(*) FROM departments WHERE status=1')->fetchColumn();
        $docCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='doctor' AND status=1")->fetchColumn();
        if ($deptCount === 0 || $docCount === 0) {
            fwrite(STDERR, "缺少科室/医生字典，请先执行：--module=\"dept user\"\n");
            return 0;
        }
        if (!(int)$pdo->query('SELECT COUNT(*) FROM lab_items')->fetchColumn()) {
            fwrite(STDERR, "缺少检验字典，请先执行：--module=lab\n");
            return 0;
        }
        if (!(int)$pdo->query('SELECT COUNT(*) FROM exam_items')->fetchColumn()) {
            fwrite(STDERR, "缺少检查字典，请先执行：--module=exam\n");
            return 0;
        }
        if (!(int)$pdo->query('SELECT COUNT(*) FROM drugs')->fetchColumn()) {
            fwrite(STDERR, "缺少药品字典，请先执行：--module=drug\n");
            return 0;
        }

        $this->clean();
        foreach ($this->journeys as $j) {
            try {
                $this->makeJourney($j);
            } catch (Exception $ex) {
                fwrite(STDERR, '  ! 旅程生成失败（' . $j['name'] . '）：' . $ex->getMessage() . "\n");
            }
        }
        $this->provisionCredentials();
        $this->out('已生成 3 套 FHIR/HL7 全链路验证数据（含 DICOM UID/Series 与危急值），并写入演示凭证');
        return count($this->journeys);
    }

    /** 幂等清理：按固定患者号前缀清除既有 FHIR 演示数据 */
    private function clean() {
        $like = self::PREFIX . '%';
        $tables = array(
            'refund_approvals', 'refund_requests', 'refunds', 'skin_test_results',
            'critical_values', 'reports', 'results', 'call_events', 'print_snapshots',
            'imaging_refs', 'order_items', 'orders', 'payments', 'inventory_trans',
            'consultations', 'patient_records', 'records', 'vitals', 'nursing_records',
            'registrations', 'certificates',
        );
        foreach ($tables as $t) {
            try {
                $this->pdo->prepare("DELETE FROM $t WHERE patient_no LIKE ?")->execute(array($like));
            } catch (Exception $ex) { /* 表结构差异容错 */ }
        }
        try {
            $this->pdo->prepare("DELETE FROM patients WHERE patient_no LIKE ?")->execute(array($like));
        } catch (Exception $ex) {}
        try {
            $this->pdo->prepare("DELETE FROM messages WHERE patient_name IN ('张伟','李娜','王强') AND msg_type='critical'")->execute(array());
        } catch (Exception $ex) {}
    }

    /** 生成一次完整旅程 */
    private function makeJourney($j) {
        $pdo = $this->pdo;
        $seq = (int)$j['seq'];
        $patientNo = self::PREFIX . sprintf('%04d', $seq);
        $flowNo = 'FHD' . date('ymd') . sprintf('%04d', $seq);
        $birth = date((intval(date('Y')) - (int)$j['age']) . '-m-d', strtotime('2000-01-01') + $seq * 86400 * 37);

        // 医生：取与科室绑定者，回退首个医生
        $did = (int)$j['dept_id'];
        $doc = $pdo->query("SELECT id, name FROM users WHERE role='doctor' AND status=1 AND (','||dept_ids||',') LIKE '%," . $did . ",%' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$doc) $doc = $pdo->query("SELECT id, name FROM users WHERE role='doctor' AND status=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $docId = (int)$doc['id'];
        $docName = (string)$doc['name'];
        $dept = $pdo->query('SELECT id, name, fee, type FROM departments WHERE id=' . $did)->fetch(PDO::FETCH_ASSOC);
        if (!$dept) $dept = $pdo->query('SELECT id, name, fee, type FROM departments WHERE status=1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $deptName = (string)$dept['name'];
        $deptId = (int)$dept['id'];

        $now = time();
        $regTs = $now - 7200;             // 挂号 2 小时前
        $recTs = $now - 5400;             // 接诊
        $payTs = $now - 4800;             // 缴费
        $fmt = function ($ts) { return date('Y-m-d H:i:s', $ts); };

        /* ---------- 患者 ---------- */
        $patientId = (int)DB::insert(
            'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, has_past_history, past_history, allergy_history, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($patientNo, $j['id_card'], $j['name'], $j['gender'], $birth, (int)$j['age'], '汉族',
                ((int)$j['age'] > 25 ? '已婚' : '未婚'), '职员', '', '本市', '13' . sprintf('%09d', 100000000 + $seq),
                '否认', '', '', $fmt($regTs))
        );

        /* ---------- 挂号 + 缴费 ---------- */
        $fee = (float)$dept['fee'] > 0 ? (float)$dept['fee'] : 20.0;
        // 就诊序号：取该科室当日已有最大序号 +1，避免与既有就诊链冲突
        //（唯一索引 idx_registrations_dept_date_seq：(first_dept_id, date, visit_seq)）
        $visitSeq = 1 + (int)DB::val('SELECT COALESCE(MAX(visit_seq),0) FROM registrations WHERE first_dept_id=? AND date(registered_at)=?',
            array($deptId, date('Y-m-d', $regTs)));
        $visitId = (int)DB::insert(
            'INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, paid_at, cashier_id, cashier_name, registered_at, disposition, disposition_detail, finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($patientNo, $flowNo, $visitSeq, $deptId, $deptName, $deptId, $deptName,
                (date('H', $regTs) < 12 ? 'am' : 'pm'), '居民医保', $fee, 'finished',
                $fmt($regTs + 120), 1, '收款员', $fmt($regTs), '自主离院', '', $fmt($now - 600))
        );
        DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total_amount, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
            array($visitId, 0, $patientNo, $flowNo, 'visit', $fee, 1, 1, '收款员', $fmt($regTs + 120)));

        /* ---------- 生命体征 ---------- */
        DB::insert('INSERT INTO vitals(visit_id, patient_no, flow_no, vital_sbp, vital_dbp, vital_heart_rate, vital_pulse, vital_spo2, vital_respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            array($visitId, $patientNo, $flowNo, 118 + $seq, 76 + $seq, 72 + $seq, 72 + $seq, 98, 18, $docName, $fmt($recTs - 300)));

        /* ---------- 结构化病历 + 诊断（Condition） ---------- */
        $emr = emr_default_data(null);
        $emr['chief_complaint'] = array('symptom' => '发热咳嗽', 'duration' => '3', 'unit' => '天', 'second_symptom' => '', 'second_duration' => '', 'second_unit' => '');
        $emr['history_present'] = array('informant' => '患者自诉', 'duration' => '3', 'unit' => '天', 'content' => '前无明显诱因出现发热咳嗽，伴乏力，为求进一步诊治', 'arrival_way' => '自行来院');
        $emr['diagnoses'] = array(array('code' => $j['diag_code'], 'name' => $j['diag_name'], 'part' => '', 'note' => '', 'suspected' => ''));
        $emr['advice'] = '清淡饮食，规律服药，一周后门诊复查。';
        $emrJson = json_encode($emr, JSON_UNESCAPED_UNICODE);
        $recordId = (int)DB::insert(
            'INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, dept_name, hospital_name, hospital_name2, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($visitId, $patientNo, $flowNo, $deptId, $docId, $docName, $deptName,
                setting('hospital_name', ''), setting('hospital_name2', ''), 'initial', 0,
                '发热咳嗽', '3', '天', '患者自诉', '自行来院', '否认', '', '否',
                $j['diag_code'], $j['diag_name'], $emrJson, '初步诊断：' . $j['diag_name'], 'done', $fmt($recTs), $fmt($recTs + 600))
        );

        /* ---------- 检验开立 + 结果 + 报告（Observation） ---------- */
        $critRow = null;
        $labItems = array();
        foreach ($j['lab'] as $l) {
            $it = $pdo->prepare('SELECT * FROM lab_items WHERE name=? LIMIT 1');
            $it->execute(array($l['name']));
            $item = $it->fetch(PDO::FETCH_ASSOC);
            if (!$item) continue;
            $labItems[] = array('row' => $item, 'value' => $l['value'], 'flag' => $l['flag']);
        }
        if ($labItems) {
            $labTotal = 0;
            foreach ($labItems as $li) $labTotal += (float)$li['row']['price'];
            $labOrderNo = gen_unique_no('JY', 'orders', 'order_no');
            $labCreated = $fmt($recTs + 300);
            $labOrderId = (int)DB::insert(
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at, executed_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $patientNo, $flowNo, 'lab', $labOrderNo, $docId, $docName, $recordId, $deptId, $deptName, $labTotal, 'done', $labCreated, $fmt($payTs + 60), '陈静'));
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total_amount, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $labOrderId, $patientNo, $flowNo, 'order', $labTotal, count($labItems), 1, '收款员', $fmt($payTs + 60)));

            $labReportResultIds = array();
            foreach ($labItems as $li) {
                $item = $li['row'];
                $orderItemId = (int)DB::insert(
                    'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, unit, price, quantity, status, doctor_id, doctor_name, executed_by, executed_at, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($labOrderId, $visitId, $patientNo, $flowNo, 'lab', (int)$item['id'], (string)$item['name'], (string)$item['unit'], (float)$item['price'], 1,
                        'done', $docId, $docName, '陈静', $fmt($now - 1800), $labCreated));
                $valuesJson = json_encode(array(
                    'value' => (string)$li['value'],
                    'unit' => (string)$item['unit'],
                    'ref_range' => (string)$item['normal_range'],
                    'flag' => (string)$li['flag'],
                ), JSON_UNESCAPED_UNICODE);
                $resultId = (int)DB::insert(
                    'INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, executed_by, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                    array((int)$item['id'], $orderItemId, $visitId, $patientNo, $flowNo, 'lab', $valuesJson, '陈静', 'done', $fmt($now - 1800), $fmt($now - 1800)));
                DB::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $orderItemId));
                $labReportResultIds[] = $resultId;
                if (crit_row_hit($li['value'], $item['critical_low'], $item['critical_high'])) {
                    $critRow = array('item' => $item, 'value' => $li['value'], 'flag' => $li['flag']);
                }
            }
            $labReportId = insert_report(array(
                'result_id' => $labReportResultIds ? (int)reset($labReportResultIds) : 0,
                'report_no' => next_report_no('lab'),
                'visit_id' => $visitId, 'patient_no' => $patientNo, 'flow_no' => $flowNo,
                'type' => 'lab', 'doctor' => '陈静', 'status' => 'done',
                'apply_dept_name' => $deptName, 'apply_doctor_name' => $docName,
                'clinical_diagnosis' => $j['diag_name'], 'applied_at' => $labCreated,
                'registered_at' => $fmt($now - 2400),
            ));

            /* ---------- 危急值流水（critical_values） ---------- */
            if ($j['critical'] && $critRow) {
                $item = $critRow['item'];
                $critItem = array(
                    'name' => (string)$item['name'], 'value' => (string)$critRow['value'],
                    'unit' => (string)$item['unit'], 'normal_range' => (string)$item['normal_range'],
                    'critical_low' => (string)$item['critical_low'], 'critical_high' => (string)$item['critical_high'],
                    'is_critical' => 1, 'flag' => (string)$critRow['flag'],
                );
                $snapshot = array('item_name' => (string)$item['name'], 'rows' => array($critItem), 'findings' => '', 'conclusion' => '');
                CriticalValueRepository::create(array(
                    'source' => 'lab', 'report_id' => (int)$labReportId, 'result_id' => $labReportResultIds ? (int)reset($labReportResultIds) : 0,
                    'visit_id' => $visitId, 'patient_no' => $patientNo, 'flow_no' => $flowNo,
                    'patient_name' => $j['name'], 'patient_gender' => $j['gender'], 'birth_date' => $birth, 'patient_age' => $j['age'] . '岁',
                    'item_name' => (string)$item['name'],
                    'items_json' => json_encode(array($critItem), JSON_UNESCAPED_UNICODE),
                    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    'from_dept_name' => '检验科', 'from_user_id' => 0, 'from_user_name' => 'LIS',
                    'to_doctor_id' => $docId, 'to_doctor_name' => $docName,
                    'status' => 'pending', 'created_at' => $fmt($now - 1700),
                ));
            }
        }

        /* ---------- 影像开立 + 报告 + DICOM 引用（ImagingStudy） ---------- */
        $examName = $j['exam']['name'];
        $ex = $pdo->prepare('SELECT * FROM exam_items WHERE name=? LIMIT 1');
        $ex->execute(array($examName));
        $exam = $ex->fetch(PDO::FETCH_ASSOC);
        if ($exam) {
            $imgOrderNo = gen_unique_no('JC', 'orders', 'order_no');
            $imgCreated = $fmt($recTs + 420);
            $imgOrderId = (int)DB::insert(
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, category_name, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at, executed_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $patientNo, $flowNo, 'imaging', $imgOrderNo, $j['exam']['category'], $docId, $docName, $recordId, $deptId, $deptName, (float)$exam['price'], 'done', $imgCreated, $fmt($payTs + 120), '黄浩'));
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total_amount, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $imgOrderId, $patientNo, $flowNo, 'order', (float)$exam['price'], 1, 1, '收款员', $fmt($payTs + 120)));
            $imgOrderItemId = (int)DB::insert(
                'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, price, quantity, status, doctor_id, doctor_name, executed_by, executed_at, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($imgOrderId, $visitId, $patientNo, $flowNo, 'imaging', (int)$exam['id'], (string)$exam['name'], (float)$exam['price'], 1,
                    'done', $docId, $docName, '黄浩', $fmt($now - 1200), $imgCreated));
            $imgResultId = (int)DB::insert(
                'INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, findings, conclusion, executed_by, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array((int)$exam['id'], $imgOrderItemId, $visitId, $patientNo, $flowNo, 'imaging', '{}',
                    $j['exam']['findings'], $j['exam']['conclusion'], '黄浩', 'done', $fmt($now - 1200), $fmt($now - 1200)));
            DB::exec('UPDATE order_items SET result_id=? WHERE id=?', array($imgResultId, $imgOrderItemId));
            $imgReportId = insert_report(array(
                'result_id' => $imgResultId, 'report_no' => next_report_no('imaging'),
                'visit_id' => $visitId, 'patient_no' => $patientNo, 'flow_no' => $flowNo,
                'type' => 'imaging', 'doctor' => '黄浩', 'status' => 'done',
                'content' => $j['exam']['conclusion'],
                'apply_dept_name' => $deptName, 'apply_doctor_name' => $docName,
                'clinical_diagnosis' => $j['diag_name'], 'applied_at' => $imgCreated, 'category_name' => $j['exam']['category'],
            ));
            // DICOM StudyInstanceUID / Series 明细（真实 UID 结构，供 PACS Viewer 联调）
            $studyUid = $this->dicomUid('STUDY-' . $patientNo . '-' . $examName);
            $series = array();
            $seriesUids = array();
            $instances = 0;
            $n = 0;
            foreach ($j['exam']['series'] as $s) {
                $n++;
                $uid = $studyUid . '.' . $n;
                $series[] = array('uid' => $uid, 'modality' => $s['modality'], 'description' => $s['desc'], 'instances' => (int)$s['instances']);
                $seriesUids[] = $uid;
                $instances += (int)$s['instances'];
            }
            DB::insert('INSERT INTO imaging_refs(order_item_id, order_id, visit_id, patient_no, flow_no, study_uid, series_uids, instance_count, modality, region, meta_json, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($imgOrderItemId, $imgOrderId, $visitId, $patientNo, $flowNo,
                    $studyUid, json_encode($seriesUids, JSON_UNESCAPED_UNICODE), $instances, $j['exam']['series'][0]['modality'],
                    'region-pacs', json_encode(array('series' => $series, 'accession' => $imgReportId), JSON_UNESCAPED_UNICODE),
                    '黄浩', $fmt($now - 1200), $fmt($now - 1200)));
        }

        /* ---------- 处方医嘱（MedicationRequest） ---------- */
        $rxItems = array();
        foreach ($j['drugs'] as $d) {
            $dq = $pdo->prepare('SELECT * FROM drugs WHERE name=? LIMIT 1');
            $dq->execute(array($d['name']));
            $drug = $dq->fetch(PDO::FETCH_ASSOC);
            if ($drug) $rxItems[] = array('row' => $drug, 'qty' => max(1, (int)$d['qty']));
        }
        if ($rxItems) {
            $rxTotal = 0;
            foreach ($rxItems as $ri) $rxTotal += (float)$ri['row']['price'] * $ri['qty'];
            $rxOrderNo = gen_unique_no('CF', 'orders', 'order_no');
            $rxCreated = $fmt($recTs + 480);
            $rxExec = $fmt($now - 1000);
            $rxOrderId = (int)DB::insert(
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, paid_at, executed_by, dispensed_at, review_by, reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $patientNo, $flowNo, 'prescription', $rxOrderNo, $docId, $docName, $recordId, $deptId, $deptName, $rxTotal, 'dispensed', $rxCreated, $fmt($payTs + 180), '吴涛', $rxExec, '吴涛', $fmt($now - 1400)));
            DB::insert('INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total_amount, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $rxOrderId, $patientNo, $flowNo, 'order', $rxTotal, count($rxItems), 1, '收款员', $fmt($payTs + 180)));
            foreach ($rxItems as $ri) {
                $drug = $ri['row'];
                DB::insert('INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, unit_type, pack_size, company_short, price, quantity, single_dose, frequency, route, is_nurse, is_parent, status, doctor_id, doctor_name, executed_by, executed_at, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($rxOrderId, $visitId, $patientNo, $flowNo, 'prescription', (int)$drug['id'], (string)$drug['name'],
                        (string)$drug['spec'], (string)$drug['package_unit'], 'pack', max(1, (int)$drug['spec_pack_qty']),
                        (string)$drug['vendor_short'], (float)$drug['price'], (int)$ri['qty'],
                        (string)$drug['single_dose'], (string)$drug['frequency'], (string)$drug['route'],
                        (int)$drug['is_nurse'], 1, 'dispensed', $docId, $docName, '吴涛', $rxExec, $rxCreated));
            }
        }

        $this->out('旅程 #' . $seq . '：' . $j['name'] . '（' . $patientNo . ' / ' . $flowNo . '）已完成');
    }

    /** 生成确定性 DICOM UID（1.2.840.<org>.<hash>…） */
    private function dicomUid($seed) {
        $h1 = sprintf('%010d', abs(crc32($seed)));
        $h2 = sprintf('%04d', abs(crc32($seed . '#s')) % 10000);
        return '1.2.840.113619.2.55.3.' . date('Y') . $h1 . '.' . $h2;
    }

    /** 写入 FHIR/HL7/HIS/LIS 入向演示凭证（便于联调手册直接调用） */
    private function provisionCredentials() {
        try {
            set_setting('integration.inbound.fhir.enabled', '1');
            set_setting('integration.inbound.fhir.allowed_tokens',
                "demo-fhir,FHIR-DEMO-TOKEN,,1,system/*.read\n" .
                "pacs-viewer,PACS-DEMO-TOKEN,,1,system/ImagingStudy.read system/Patient.read");
            set_setting('integration.inbound.fhir.oauth_clients',
                "pacs,PacsSecret123,system/ImagingStudy.read system/Patient.read system/Encounter.read system/Observation.read system/Condition.read system/MedicationRequest.read");
            set_setting('integration.inbound.his.token', 'HIS-DEMO-TOKEN');
            set_setting('integration.inbound.his.token_scopes', 'patient:read patient:sync report:query catalog:sync');
            set_setting('integration.inbound.his.token_enabled', '1');
            set_setting('integration.inbound.lis.webhook_secret', 'LIS-DEMO-TOKEN');
            set_setting('integration.inbound.lis.webhook_secret_scopes', 'report:write');
            set_setting('integration.inbound.pacs.enabled', '1');
            set_setting('integration.inbound.pacs.token', 'PACS-DICOM-TOKEN');
            set_setting('integration.inbound.pacs.token_scopes', 'pacs:read');
            set_setting('integration.inbound.pacs.token_enabled', '1');
        } catch (Exception $ex) {
            fwrite(STDERR, '  ! 演示凭证写入失败：' . $ex->getMessage() . "\n");
        }
    }
}

$s = new FhirDemoSeeder();
$s->run();
