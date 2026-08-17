<?php
/**
 * ============================================================
 * record.php — 电子病历接口
 * ============================================================
 * 说明：
 * 1. 加载病历：患者信息（不可编辑区）+ 病历内容 + 生命体征
 *    （与护士站共用接口双向同步）+ 该患者既往病历（转科一键引用）
 * 2. 保存病历：主诉/现病史/初步诊断为必填（缺失禁止保存与诊毕）
 * 3. 诊断与 ICD10 编码联动
 * 4. 诊断证明：单次就诊仅可开具一次
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';

$u = Auth::user();

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
        // 病历（当前医生在本就诊下的记录，无则新建草稿）
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visitId, $u['id']));
        if (!$record) {
            $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        }
        $recordData = array(
            'doctor_name' => $u['name'],
            'doctor_emp' => $doc ? $doc['emp_no'] : '',
            'doctor_title' => $doc ? $doc['title'] : '',
            'created_at' => $record ? $record['created_at'] : '',
            'updated_at' => $record ? $record['updated_at'] : '',
            'chief_complaint' => $record ? $record['chief_complaint'] : '',
            'present_illness' => $record ? $record['present_illness'] : '',
            'past_history' => $record ? $record['past_history'] : '',
            'allergy_history' => $record ? $record['allergy_history'] : '',
            'physical_exam' => $record ? $record['physical_exam'] : '',
            'consciousness' => $record ? $record['consciousness'] : '',
            'initial_diagnosis' => $record ? $record['initial_diagnosis'] : '',
            'diagnosis_code' => $record ? $record['diagnosis_code'] : '',
            'is_observation' => $record ? (int)$record['is_observation'] : 0,
            'advice' => $record ? $record['advice'] : '',
        );

        // 生命体征（最新一条，护士站与医生站共用）
        $vitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $vitalsData = $vitals ? $vitals : array(
            'bp_systolic' => '', 'bp_diastolic' => '', 'heart_rate' => '',
            'pulse' => '', 'spo2' => '', 'respiration' => '',
        );

        // 该患者全部既往病历（跨就诊，供转科一键引用；附带 content 供前端模板方式填充）
        $prevRows = DB::q('medical', 'SELECT * FROM records WHERE patient_no=? ORDER BY id DESC LIMIT 20', array($patient['patient_no']));
        $prevRecords = array();
        foreach ($prevRows as $pr) {
            $prevRecords[] = array(
                'id' => (int)$pr['id'],
                'doctor_name' => $pr['doctor_name'],
                'created_at' => $pr['created_at'],
                'content' => json_encode(array(
                    'chief_complaint' => $pr['chief_complaint'],
                    'present_illness' => $pr['present_illness'],
                    'past_history' => $pr['past_history'],
                    'allergy_history' => $pr['allergy_history'],
                ), JSON_UNESCAPED_UNICODE),
            );
        }

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
            'has_certificate' => (int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($visitId)) > 0,
        ));
        break;

    /* ==================== 保存病历 ==================== */
    case 'save':
        $visitId = (int)post('visit_id');
        $finish = (int)post('finish', 0);
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];

        $chief = post('chief_complaint');
        $present = post('present_illness');
        $diag = post('initial_diagnosis');
        // 必填校验：主诉/现病史/诊断为必填，缺失禁止保存与诊毕
        if (trim(strip_tags($chief)) === '') json_fail('主诉为必填项，请填写后再保存');
        if (trim(strip_tags($present)) === '') json_fail('现病史为必填项，请填写后再保存');
        if (trim($diag) === '') json_fail('初步诊断为必填项，请填写后再保存');

        $data = array(
            'chief_complaint' => $chief,
            'present_illness' => $present,
            'past_history' => post('past_history'),
            'allergy_history' => post('allergy_history'),
            'physical_exam' => post('physical_exam'),
            'consciousness' => post('consciousness'),
            'initial_diagnosis' => $diag,
            'diagnosis_code' => post('diagnosis_code'),
            'is_observation' => (int)post('is_observation', 0),
            'advice' => post('advice'),
            'status' => $finish ? 'done' : 'draft',
            'updated_at' => now_str(),
        );

        $exists = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
        if ($exists) {
            $set = array();
            $params = array();
            foreach ($data as $k => $v) {
                $set[] = $k . '=?';
                $params[] = $v;
            }
            $params[] = $exists['id'];
            DB::exec('medical', 'UPDATE records SET ' . implode(',', $set) . ' WHERE id=?', $params);
        } else {
            $params = array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name']);
            foreach ($data as $v) $params[] = $v;
            $params[] = now_str(); // created_at
            DB::insert('medical', 'INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, chief_complaint, present_illness, past_history, allergy_history, physical_exam, consciousness, initial_diagnosis, diagnosis_code, is_observation, advice, status, updated_at, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
        }

        // 诊毕：更新就诊状态
        if ($finish) {
            DB::exec('patient', 'UPDATE registrations SET status=?, payment_time=COALESCE(payment_time,?) WHERE id=?', array('finished', now_str(), $visitId));
            json_ok(array('finished' => 1), '病历已保存并诊毕');
        }
        json_ok(array('finished' => 0), '病历已保存');
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
        DB::insert('medical', 'INSERT INTO certificates(visit_id, patient_no, flow_no, doctor_id, doctor_name, content, created_at) VALUES(?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'], $u['id'], $u['name'], $content, now_str(),
        ));
        json_ok(array(), '诊断证明已开具');
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
