<?php
/**
 * ============================================================
 * record.php — 电子病历接口（结构化 EMR）
 * ============================================================
 * 说明：
 * 1. 数据模型：patient_records 表为唯一真理来源——
 *    emr_data 存完整结构化 JSON；投影字段（主症状/时间单位/供史者/
 *    来院途径/既往史标记/过敏史/留观/主诊断）由后端从 JSON 提取，
 *    供统计检索；emr_print_text 为剔除占位符的打印纯净文书快照。
 * 2. 保存流程：校验清洗 → 投影提取 → 生成打印文本 → 事务写入
 *    patient_records + 旧 records 扁平镜像（兼容就诊历史/转科引用）→
 *    同步患者主表全局既往史/过敏史（跨就诊自动调用，以最新为准）。
 * 3. 业务防御：既往史选「否认」时后端强制清空详细内容。
 * 4. 生命体征/诊断证明逻辑保持不变。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';

$u = Auth::user();

/** 结构化病历默认骨架（新病历/字段缺失回退） */
function emr_default_data($patient = null) {
    $phType = '否认';
    $phDetail = '';
    $alType = '否认';
    $alDetail = '';
    if ($patient) {
        // 跨就诊自动调用：患者主表存有历史既往史/过敏史时预填（以最新一次保存为准）
        if (!empty($patient['past_history_type'])) $phType = $patient['past_history_type'];
        if (!empty($patient['past_history_detail'])) $phDetail = $patient['past_history_detail'];
        // 患者主表 allergies 存纯文本摘要：非空即视为「承认」并回填细节
        if (!empty($patient['allergies'])) {
            $alType = '承认';
            $alDetail = $patient['allergies'];
        }
    }
    return array(
        'chief_complaint' => array('symptom' => '', 'duration' => '', 'unit' => '', 'second_symptom' => '', 'second_duration' => '', 'second_unit' => ''),
        'history_present' => array('informant' => '', 'duration' => '', 'unit' => '', 'content' => '', 'arrival_way' => ''),
        'past_history' => array('type' => $phType, 'detail' => $phDetail),
        'allergies' => array('type' => $alType, 'detail' => $alDetail),
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

/** 递归合并：保证 emr_data 具备全部结构键（旧草稿/缺键回退默认值） */
function emr_merge_defaults($data, $defaults) {
    foreach ($defaults as $k => $v) {
        if (!isset($data[$k]) || $data[$k] === null) {
            $data[$k] = $v;
        } elseif (is_array($v) && !isset($v[0])) {
            // 关联数组（子结构）且数据侧同为数组时才递归合并；
            // 数据侧为字符串等标量（如旧版 allergies 纯文本）时保留原值，
            // 由 emr_normalize 统一归一化，避免对字符串取数组偏移导致致命错误
            if (is_array($data[$k])) {
                $data[$k] = emr_merge_defaults($data[$k], $v);
            }
        }
    }
    return $data;
}

/** 旧格式归一化：allergies 曾为纯文本字符串 → 结构化（非空视为承认） */
function emr_normalize($emr) {
    if (isset($emr['allergies']) && !is_array($emr['allergies'])) {
        $old = trim((string)$emr['allergies']);
        $emr['allergies'] = array('type' => $old !== '' ? '承认' : '否认', 'detail' => $old);
    }
    return $emr;
}

/** 已开项目快照（与 /api/order visit_orders 同口径，排除已退费/已取消）：
    返回 [检验检查名列表, 处方行列表, 处置项列表] */
function emr_order_snapshot($visitId) {
    $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
    $orderNames = array();
    $rxLines = array();
    $dispItems = array();
        foreach ($orders as $o) {
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $agg = order_agg_status($o['order_type'], $items);
            if ($agg === 'refunded' || $agg === 'cancelled') continue;
            foreach ($items as $it) {
                if (empty($it['item_name'])) continue; // 防空名明细混入病历文本
                if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
                $orderNames[] = $it['item_name'];
            } elseif ($o['order_type'] === 'procedure') {
                $dispItems[] = array('name' => $it['item_name'], 'qty' => (int)$it['quantity']);
            } elseif ($o['order_type'] === 'prescription') {
                $parts = array();
                if (!empty($it['single_dose'])) $parts[] = $it['single_dose'];
                if (!empty($it['frequency_name'])) $parts[] = $it['frequency_name'];
                if (!empty($it['route_name'])) $parts[] = $it['route_name'];
                $rxLines[] = $it['item_name'] . ($parts ? '　' . implode('　', $parts) : '') . '　×' . (int)$it['quantity'];
            }
        }
    }
    return array($orderNames, $rxLines, $dispItems);
}

switch ($action) {

    /* ==================== 加载病历 ==================== */
    case 'get':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $patient = $row['patient'];

        // 当前科室（可能已转科，显示当前就诊科室）
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $deptName = $dept ? $dept['name'] : $visit['first_dept_name'];
        $deptType = $dept ? $dept['type'] : 'clinic';

        // 医生信息（工号/职称，需求18.2：工作站显示医生姓名工号职称）
        $doc = DB::one('user', 'SELECT emp_no, title FROM users WHERE id=?', array($u['id']));
        // 结构化病历（当前医生在本就诊下的记录，无则取本就诊最新一条，再无则新建骨架）
        $pr = DB::one('medical', 'SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visitId, $u['id']));
        if (!$pr) {
            $pr = DB::one('medical', 'SELECT * FROM patient_records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        }
        $emr = emr_merge_defaults(
            emr_normalize($pr ? json_decode($pr['emr_data'], true) : array()),
            emr_default_data($pr ? null : $patient)
        );
        // 归一化后补齐缺失键（旧数据 allergies 为空串时上面已转结构，其余键照常回退）
        $emr = emr_merge_defaults($emr, emr_default_data(null));
        $recordData = array(
            'doctor_name' => $u['name'],
            'doctor_emp' => $doc ? $doc['emp_no'] : '',
            'doctor_title' => $doc ? $doc['title'] : '',
            'created_at' => $pr ? $pr['created_at'] : '',
            'updated_at' => $pr ? $pr['updated_at'] : '',
            'emr' => $emr,
            'status' => $pr ? $pr['status'] : 'draft',
        );
        // 意识状态/初复诊保存在旧 records 镜像表（结构化表不含这两项），
        // 必须回读，否则保存后刷新页面意识状态会丢失回「未选择」、初复诊回「初诊」
        $mirror = DB::one('medical', 'SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visitId, $u['id']));
        if (!$mirror) {
            $mirror = DB::one('medical', 'SELECT consciousness, visit_type FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        }
        $recordData['consciousness'] = $mirror ? (string)$mirror['consciousness'] : '';
        $recordData['visit_type'] = ($mirror && $mirror['visit_type'] !== '') ? $mirror['visit_type'] : '初诊';
        // 扁平投影字段（主诉/现病史/初步诊断）：供诊断证明补开等旧字段消费方使用。
        // 结构化病历升级后 get 曾不再返回这些字段，导致「就诊历史→补开诊断证明」
        // 误判病历不完整（结构化升级残留缺陷）。优先由结构化 emr 投影生成，
        // 为空时回退 records 镜像表（兼容未结构化的历史病历）。
        $mirrorFlat = DB::one('medical', 'SELECT chief_complaint, present_illness, initial_diagnosis FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $ccText = emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array());
        $piText = emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array());
        $diagText = emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array());
        if ($ccText === '' && $mirrorFlat) $ccText = (string)$mirrorFlat['chief_complaint'];
        if ($piText === '' && $mirrorFlat) $piText = (string)$mirrorFlat['present_illness'];
        // 初步诊断直接使用投影文本——诊断名称本身已含 ICD-10 编码前缀
        // （如「M51.9 腰椎间盘突出」），无需再以括号追加编码（避免重复）。
        if ($diagText === '' && $mirrorFlat) {
            $diagText = (string)$mirrorFlat['initial_diagnosis'];
        }
        $recordData['chief_complaint'] = $ccText;
        $recordData['present_illness'] = $piText;
        $recordData['initial_diagnosis'] = $diagText;

        // 生命体征（最新一条，护士站与医生站共用）
        $vitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $vitalsData = $vitals ? $vitals : array(
            'bp_systolic' => '', 'bp_diastolic' => '', 'heart_rate' => '',
            'pulse' => '', 'spo2' => '', 'respiration' => '',
        );

        // 该患者全部既往病历（跨就诊，供转科一键引用；附带 content 供前端模板方式填充）
        $prevRows = DB::q('medical', 'SELECT * FROM patient_records WHERE patient_no=? ORDER BY id DESC LIMIT 20', array($patient['patient_no']));
        $prevRecords = array();
        foreach ($prevRows as $pr2) {
            $prevEmr = json_decode($pr2['emr_data'], true);
            $prevRecords[] = array(
                'id' => (int)$pr2['id'],
                'doctor_name' => $pr2['doctor_name'],
                'created_at' => $pr2['created_at'],
                'content' => json_encode(array(
                    'chief_complaint' => emr_cc_text(isset($prevEmr['chief_complaint']) ? $prevEmr['chief_complaint'] : array()),
                    'present_illness' => emr_pi_text(isset($prevEmr['history_present']) ? $prevEmr['history_present'] : array()),
                    'past_history' => emr_ph_text(isset($prevEmr['past_history']) ? $prevEmr['past_history'] : array()),
                    'allergy_history' => emr_al_text(isset($prevEmr['allergies']) ? $prevEmr['allergies'] : array()),
                ), JSON_UNESCAPED_UNICODE),
            );
        }

        // 诊断证明信息：供前端「已开具」只读预览展示。
        // 注意——前端只读区域仅是预览，真正打印走 certificate_print
        // 从服务器重新渲染，内容以服务器保存数据为准，不可被前端篡改。
        $certRow = DB::one('medical', 'SELECT cert_no, content, doctor_name, created_at FROM certificates WHERE visit_id=? ORDER BY id DESC', array($visitId));

        json_ok(array(
            'patient' => array(
                'patient_id' => $patient['patient_no'],
                'birth_date' => $patient['birth_date'],
                'id_card' => $patient['id_card'],
                'nation' => $patient['ethnicity'],
                'occupation' => $patient['occupation'],
                'marital' => $patient['marital'],
                'phone' => $patient['phone'],
            ),
            'visit' => array(
                'id' => (int)$visit['id'],
                'name' => $patient['name'],
                'gender' => $patient['gender'],
                'age' => (int)$patient['age'],
                'age_fmt' => age_format($patient['birth_date'], $visit['register_time']),
                'dept_type' => $deptType,
                'dept_name' => $deptName,
                'current_dept_id' => (int)$visit['current_dept_id'],
                'visit_no' => $visit['flow_no'],
                'visit_seq' => (int)$visit['visit_seq'],
                'status' => $visit['status'],   // 就诊状态：finished 表示已诊毕（前端据此将病历置为只读）
                'created_at' => $visit['register_time'],
            ),
            'record' => $recordData,
            'vitals' => $vitalsData,
            'prev_records' => $prevRecords,
            'has_certificate' => $certRow ? 1 : 0,
            // 已开具时附带证书数据（证明号/医生建议/开具时间等），仅用于只读预览展示
            'certificate' => $certRow ? array(
                'cert_no' => (string)$certRow['cert_no'],
                'content' => (string)$certRow['content'],
                'doctor_name' => (string)$certRow['doctor_name'],
                'created_at' => (string)$certRow['created_at'],
            ) : null,
        ));
        break;

    /* ==================== 保存病历（结构化） ====================
     * 前端仅提交完整 emr_data JSON 对象；后端：
     * 1) 校验必填（主诉症状/现病史内容/初步诊断）
     * 2) 业务防御清洗（否认既往史强制清空细节）
     * 3) 投影字段提取 → 打印文本生成
     * 4) 事务写 patient_records + records 镜像；同步 patients 全局既往史/过敏史 */
    case 'save':
        $visitId = (int)post('visit_id');
        $finish = (int)post('finish', 0);
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];

        // ===== 1. 解析与校验 =====
        $raw = post_raw('emr_data');
        $emr = json_decode($raw, true);
        if (!is_array($emr)) json_fail('病历数据格式非法');
        $cc = isset($emr['chief_complaint']) && is_array($emr['chief_complaint']) ? $emr['chief_complaint'] : array();
        $pi = isset($emr['history_present']) && is_array($emr['history_present']) ? $emr['history_present'] : array();
        $diagnoses = isset($emr['diagnoses']) && is_array($emr['diagnoses']) ? $emr['diagnoses'] : array();
        if (!isset($cc['symptom']) || trim((string)$cc['symptom']) === '') json_fail('主诉为必填项，请填写主要症状');
        if (!isset($pi['content']) || trim((string)$pi['content']) === '') json_fail('现病史为必填项，请填写具体内容');
        if (!count(array_filter($diagnoses, function ($d) { return is_array($d) && !empty($d['name']); }))) {
            json_fail('初步诊断为必填项，请至少添加一个诊断');
        }

        // ===== 2. 合并默认结构 + 业务防御清洗 =====
        $emr = emr_merge_defaults($emr, emr_default_data(null));
        // 旧格式兼容：allergies 纯文本字符串归一化（见 emr_normalize）
        $emr = emr_normalize($emr);
        if ($emr['past_history']['type'] !== '承认') {
            $emr['past_history']['type'] = '否认';
            $emr['past_history']['detail'] = ''; // 否认既往史：即使前端强行提交细节也强制清空
        }
        if ($emr['allergies']['type'] !== '承认') {
            $emr['allergies']['type'] = '否认';
            $emr['allergies']['detail'] = ''; // 否认过敏史：同样强制清空
        }
        // 字符串字段统一裁剪
        foreach (array('aux_result', 'aux_external', 'disposition_custom', 'advice') as $k) {
            $emr[$k] = trim((string)$emr[$k]);
        }
        $emr['allergies']['detail'] = trim((string)$emr['allergies']['detail']);

        // ===== 3. 投影字段提取（单一事实转换） =====
        $mainSymptom = (string)$cc['symptom'];
        $symptomDuration = isset($cc['duration']) ? (string)$cc['duration'] : '';
        $symptomUnit = isset($cc['unit']) ? (string)$cc['unit'] : '';
        $informant = isset($pi['informant']) ? (string)$pi['informant'] : '';
        $arrivalWay = isset($pi['arrival_way']) ? (string)$pi['arrival_way'] : '';
        $hasPastHistory = $emr['past_history']['type'] === '承认' ? '是' : '否';
        // 过敏史投影：承认时存细节文本（患者主表同步/统计用），否认存空
        $allergies = $emr['allergies']['type'] === '承认' ? $emr['allergies']['detail'] : '';
        $isLeaveHospital = emr_obs_text($emr);
        $primaryIcd10 = '';
        $primaryDiagnosis = '';
        foreach ($diagnoses as $dg) {
            if (is_array($dg) && !empty($dg['name'])) {
                $primaryIcd10 = isset($dg['code']) ? $dg['code'] : '';
                $primaryDiagnosis = $dg['name'];
                break; // 主诊断永远取第 1 个
            }
        }

        // ===== 4. 打印文本（含当前已开项目快照） =====
        list($orderNames, $rxLines, $dispItems) = emr_order_snapshot($visitId);
        $vitalsRow = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $vp = array();
        if ($vitalsRow) {
            if (!empty($vitalsRow['bp_systolic'])) $vp[] = '血压 ' . $vitalsRow['bp_systolic'] . '/' . $vitalsRow['bp_diastolic'] . 'mmHg';
            if (!empty($vitalsRow['heart_rate'])) $vp[] = '心率 ' . $vitalsRow['heart_rate'] . '次/分';
            if (!empty($vitalsRow['pulse'])) $vp[] = '脉搏 ' . $vitalsRow['pulse'] . '次/分';
            if (!empty($vitalsRow['spo2'])) $vp[] = '血氧 ' . $vitalsRow['spo2'] . '%';
            if (!empty($vitalsRow['respiration'])) $vp[] = '呼吸 ' . $vitalsRow['respiration'] . '次/分';
        }
        $vitalsText = implode('；', $vp);
        $consciousness = post('consciousness');
        $printText = emr_print_text($emr, $vitalsText, $consciousness, $orderNames, $rxLines, $dispItems);
        $cleanJson = json_encode($emr, JSON_UNESCAPED_UNICODE);

        // 初复诊白名单校验（默认初诊）
        $visitType = post('visit_type', '初诊');
        if (!in_array($visitType, array('初诊', '复诊'), true)) $visitType = '初诊';

        // ===== 5. 事务写入（medical 库：patient_records + records 镜像同库） =====
        $now = now_str();
        $pdo = DatabaseManager::pdo('medical');
        try {
            $pdo->beginTransaction();

            // A. patient_records 写入/更新
            $pr = DB::one('medical', 'SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
            if ($pr) {
                $pdo->prepare('UPDATE patient_records SET main_symptom=?, symptom_duration=?, symptom_unit=?, informant=?, arrival_way=?, has_past_history=?, allergies=?, is_leave_hospital=?, primary_icd10=?, primary_diagnosis=?, emr_data=?, emr_print_text=?, status=?, updated_at=? WHERE id=?')
                    ->execute(array($mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $pr['id']));
                $recordId = (int)$pr['id'];
            } else {
                $pdo->prepare('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, main_symptom, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergies, is_leave_hospital, primary_icd10, primary_diagnosis, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], $mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $now));
                $recordId = (int)$pdo->lastInsertId();
            }

            // B. 旧 records 表扁平镜像（兼容就诊历史列表/转科引用/诊断证明等既有消费方）
            $mirror = array(
                'chief_complaint' => emr_cc_text($emr['chief_complaint']),
                'present_illness' => emr_pi_text($emr['history_present']),
                'past_history' => emr_ph_text($emr['past_history']),
                'allergy_history' => emr_al_text($emr['allergies']),
                'physical_exam' => emr_pe_text($emr['physical_exam']),
                'consciousness' => $consciousness,
                'initial_diagnosis' => emr_diag_text($diagnoses),
                'diagnosis_code' => $primaryIcd10,
                'is_observation' => $isLeaveHospital === '是' ? 1 : 0,
                'visit_type' => $visitType,
                'advice' => $emr['advice'],
                'status' => $finish ? 'done' : 'draft',
                'updated_at' => $now,
            );
            $old = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
            if ($old) {
                $set = array();
                $params = array();
                foreach ($mirror as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
                $params[] = $old['id'];
                $pdo->prepare('UPDATE records SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);
                $oldRecordId = (int)$old['id'];
            } else {
                $cols = 'visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, ' . implode(',', array_keys($mirror)) . ', created_at';
                $marks = '?,?,?,?,?,?, ' . implode(',', array_fill(0, count($mirror), '?')) . ',?';
                $params = array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name']);
                foreach ($mirror as $v) $params[] = $v;
                $params[] = $now;
                $pdo->prepare("INSERT INTO records($cols) VALUES($marks)")->execute($params);
                $oldRecordId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('病历保存失败：' . $ex->getMessage());
        }

        // C. 同步患者主表全局既往史/过敏史（跨就诊自动调用；以最新修改为准）
        DB::exec('patient', 'UPDATE patients SET past_history_type=?, past_history_detail=?, allergies=? WHERE patient_no=?', array(
            $emr['past_history']['type'], $emr['past_history']['detail'], $allergies, $visit['patient_no'],
        ));

        // D. 诊毕：更新就诊状态
        if ($finish) {
            DB::exec('patient', 'UPDATE registrations SET status=?, payment_time=COALESCE(payment_time,?) WHERE id=?', array('finished', now_str(), $visitId));
            json_ok(array('finished' => 1, 'record_id' => $recordId), '病历已保存并诊毕');
        }
        json_ok(array('finished' => 0, 'record_id' => $recordId), '病历已保存');
        break;

    /* ==================== 保存生命体征（医生站/护士站共用） ==================== */
    case 'save_vitals':
        $visitId = (int)post('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        DB::insert('nurse', 'INSERT INTO vitals(visit_id, patient_no, flow_no, bp_systolic, bp_diastolic, heart_rate, pulse, spo2, respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'],
            (int)post('bp_systolic', 0), (int)post('bp_diastolic', 0),
            post('heart_rate'), post('pulse'), post('spo2'), post('respiration'),
            $u['name'], now_str(),
        ));
        json_ok(array(), '生命体征已保存');
        break;

    /* ==================== 开具诊断证明（单次就诊一次） ==================== */
    case 'certificate':
        $visitId = (int)post('visit_id');
        $content = post('content');
        if ($content === '') json_fail('请填写医生建议');
        if ((int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($visitId)) > 0) {
            json_fail('本次就诊已开具过诊断证明，不可重复开具');
        }
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 证明号：ZM 前缀 + 时间戳 + 2 位随机——与申请单号（JY/JC/CZ/CF/DD）同源
        // 规则但前缀互不冲突；循环校验保证唯一。
        do {
            $certNo = 'ZM' . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
        } while ((int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE cert_no=?', array($certNo)) > 0);
        DB::insert('medical', 'INSERT INTO certificates(visit_id, patient_no, flow_no, doctor_id, doctor_name, content, created_at, cert_no) VALUES(?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'], $u['id'], $u['name'], $content, now_str(), $certNo,
        ));
        json_ok(array('cert_no' => $certNo), '诊断证明已开具');
        break;

    /* ==================== 诊断证明打印 ==================== */
    case 'certificate_print':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $cert = DB::one('medical', 'SELECT * FROM certificates WHERE visit_id=?', array($visitId));
        if (!$cert) json_fail('未开具诊断证明');
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        json_ok(array('html' => pt_certificate($visit, $row['patient'], $record, $cert, $cert['doctor_name'])));
        break;

    default:
        json_fail('未知操作');
}
