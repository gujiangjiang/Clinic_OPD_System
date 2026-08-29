<?php
/**
 * ============================================================
 * consultation.php — 科室间会诊接口
 * ============================================================
 * 说明：
 * 1. create   发起会诊（A 科室 → 目标科室）
 * 2. list     会诊列表（按科室 + 医生可见天数过滤；type=received 收到 / sent 发出）
 * 3. detail   会诊详情（含病历快照：主诉/现病史/体格检查/诊断）
 * 4. accept   开始会诊（pending → doing，记录接收医生）
 * 5. finish   会诊完毕（doing → done）
 * 6. delete   删除会诊（仅发起医生本人）
 * 状态流转：pending 发起会诊 → doing 正在会诊 → done 会诊完毕。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 医生候诊可见天数（2-7，缺省 3）——会诊列表同样受此限制 */
function consultation_queue_days($u) {
    if (isset($u['queue_days']) && (int)$u['queue_days'] >= 2 && (int)$u['queue_days'] <= 7) {
        return (int)$u['queue_days'];
    }
    $ud = DB::one('user', 'SELECT queue_days FROM users WHERE id=?', array($u['id']));
    if ($ud && (int)$ud['queue_days'] >= 2 && (int)$ud['queue_days'] <= 7) return (int)$ud['queue_days'];
    return 3;
}

/** 会诊行 → 前端结构 */
function consultation_row($c) {
    return array(
        'id' => (int)$c['id'],
        'visit_id' => (int)$c['visit_id'],
        'patient_no' => (string)$c['patient_no'],
        'flow_no' => (string)$c['flow_no'],
        'from_dept_id' => (int)$c['from_dept_id'],
        'from_dept_name' => (string)$c['from_dept_name'],
        'from_doctor_id' => (int)$c['from_doctor_id'],
        'from_doctor_name' => (string)$c['from_doctor_name'],
        'target_dept_id' => (int)$c['target_dept_id'],
        'target_dept_name' => (string)$c['target_dept_name'],
        'description' => (string)$c['description'],
        'purpose' => (string)$c['purpose'],
        'status' => (string)$c['status'],
        'accepted_by' => (string)$c['accepted_by'],
        'accepted_at' => (string)$c['accepted_at'],
        'finished_at' => (string)$c['finished_at'],
        'created_at' => (string)$c['created_at'],
    );
}

switch ($action) {

    /* ==================== 发起会诊 ==================== */
    case 'create':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] === 'finished') json_fail('该患者已诊毕，病历已归档');
        if (!visit_access_allowed($visit, $u)) json_fail('该病历超出您的可查看历史天数，无法发起会诊');
        $targetDeptId = (int)post('target_dept_id', 0);
        if ($targetDeptId <= 0) json_fail('请选择会诊科室');
        // 目标科室必须合法且不能是当前科室
        $targetDept = DB::one('dept', "SELECT id, name FROM departments WHERE id=? AND status=1 AND type IN ('clinic','emergency')", array($targetDeptId));
        if (!$targetDept) json_fail('会诊科室不存在或不可用');
        if ((int)$targetDept['id'] === (int)$visit['current_dept_id']) json_fail('会诊科室不能是当前科室');
        $description = trim((string)post('description', ''));
        $purpose = trim((string)post('purpose', ''));
        if ($description === '') json_fail('请填写会诊描述');
        if ($purpose === '') json_fail('请填写会诊目的');
        $now = now_str();
        $cid = DB::insert('consultation', 'INSERT INTO consultations(visit_id, patient_no, flow_no, from_dept_id, from_dept_name, from_doctor_id, from_doctor_name, target_dept_id, target_dept_name, description, purpose, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'],
            (int)$visit['current_dept_id'], (string)$visit['current_dept_name'],
            $u['id'], $u['name'],
            (int)$targetDept['id'], (string)$targetDept['name'],
            $description, $purpose, 'pending', $now,
        ));
        // 站内信通知目标科室（携带会诊详情链接）
        send_msg('doctor', 0, '新的会诊请求',
            '患者：' . $row['patient']['name'] . '（' . $visit['patient_no'] . '），' .
            (string)$visit['current_dept_name'] . ' ' . $u['name'] . ' 请' . $targetDept['name'] . '会诊，请及时处理',
            'consultation', '/api/consultation?action=detail&id=' . oid($cid),
            array('msg_type' => 'patient', 'patient_name' => $row['patient']['name'], 'visit_id' => $visitId, 'target_dept_id' => (int)$targetDept['id']));
        json_ok(array('id' => $cid), '会诊发送成功');
        break;

    /* ==================== 会诊列表（type=received 收到 / sent 发出） ==================== */
    case 'list':
        $deptId = (int)get('dept_id', 0);
        if ($deptId <= 0) {
            $curRow = DB::one('user', 'SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
            $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        }
        if ($deptId <= 0) json_fail('当前医生未关联可用科室');
        $days = consultation_queue_days($u);
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $type = get('type', 'received');
        if ($type === 'sent') {
            // 发出的会诊（本人发起，近 N 天）
            $rows = DB::q('consultation', "SELECT * FROM consultations WHERE from_doctor_id=? AND date(created_at)>=? ORDER BY id DESC", array($u['id'], $since));
        } else {
            // 收到的会诊（目标科室 = 当前科室，近 N 天）
            $rows = DB::q('consultation', "SELECT * FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC", array($deptId, $since));
        }
        $list = array_map('consultation_row', $rows);
        json_ok(array('list' => $list, 'days' => $days));
        break;

    /* ==================== 病历快照（发起会诊表单只读展示） ==================== */
    case 'snapshot':
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        if (!visit_access_allowed($row['visit'], $u)) json_fail('该病历超出您的可查看历史天数');
        // 取本人首诊文书的快照（无则空）
        $pr = DB::one('medical', "SELECT emr_data FROM patient_records WHERE visit_id=? AND record_type='initial' ORDER BY id ASC LIMIT 1",
            array($visitId));
        $snap = array('chief_complaint' => '', 'present_illness' => '', 'physical_exam' => '', 'diagnoses' => '');
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
            $snap['chief_complaint'] = emr_cc_text($emr['chief_complaint']);
            $snap['present_illness'] = emr_pi_text($emr['history_present']);
            $snap['physical_exam'] = emr_pe_text($emr['physical_exam']);
            $snap['diagnoses'] = emr_diag_text($emr['diagnoses']);
        }
        json_ok(array('snapshot' => $snap));
        break;

    /* ==================== 本就诊会诊列表（右侧会诊分区） ==================== */
    case 'visit_consults':
        $visitId = did(get('visit_id'));
        if ($visitId <= 0) json_fail('参数错误');
        $rows = DB::q('consultation', 'SELECT * FROM consultations WHERE visit_id=? ORDER BY id ASC', array($visitId));
        json_ok(array('list' => array_map('consultation_row', $rows)));
        break;

    /* ==================== 会诊详情（含病历快照） ==================== */
    case 'detail':
        $cid = did(get('id'));
        $c = DB::one('consultation', 'SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        $data = consultation_row($c);
        // 病历快照：取发起科室医生的首诊文书（主诉/现病史/体格检查/诊断）
        $pr = DB::one('medical', "SELECT * FROM patient_records WHERE visit_id=? AND dept_id=? AND record_type='initial' ORDER BY id ASC LIMIT 1",
            array((int)$c['visit_id'], (int)$c['from_dept_id']));
        $snap = array('chief_complaint' => '', 'present_illness' => '', 'physical_exam' => '', 'diagnoses' => array());
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
            $snap['chief_complaint'] = emr_cc_text($emr['chief_complaint']);
            $snap['present_illness'] = emr_pi_text($emr['history_present']);
            $snap['physical_exam'] = emr_pe_text($emr['physical_exam']);
            $snap['diagnoses'] = emr_diag_text($emr['diagnoses']);
        }
        $data['snapshot'] = $snap;
        // 会诊病历（B 科室医生开的关联病历，如已创建）
        $consRec = DB::one('medical', 'SELECT id, doctor_name, status, created_at FROM patient_records WHERE consultation_id=?', array($cid));
        $data['record'] = $consRec ? array(
            'record_id' => (int)$consRec['id'], 'doctor_name' => (string)$consRec['doctor_name'],
            'status' => (string)$consRec['status'], 'created_at' => (string)$consRec['created_at'],
        ) : null;
        json_ok(array('consultation' => $data));
        break;

    /* ==================== 开始会诊（pending → doing） ==================== */
    case 'accept':
        $cid = did(post('id'));
        $c = DB::one('consultation', 'SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        if ((int)$c['target_dept_id'] !== (int)$u['current_dept_id']) json_fail('该会诊不属于当前科室');
        if ($c['status'] !== 'pending') json_fail('该会诊已开始处理');
        DB::exec('consultation', 'UPDATE consultations SET status=?, accepted_by=?, accepted_at=? WHERE id=?',
            array('doing', $u['name'], now_str(), $cid));
        json_ok(array(), '会诊已开始');
        break;

    /* ==================== 会诊完毕（doing → done） ==================== */
    case 'finish':
        $cid = did(post('id'));
        $c = DB::one('consultation', 'SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        if ((int)$c['target_dept_id'] !== (int)$u['current_dept_id']) json_fail('该会诊不属于当前科室');
        if ($c['status'] === 'pending') json_fail('会诊尚未开始');
        if ($c['status'] === 'done') json_fail('该会诊已完毕');
        DB::exec('consultation', 'UPDATE consultations SET status=?, finished_at=? WHERE id=?',
            array('done', now_str(), $cid));
        json_ok(array(), '会诊已完毕');
        break;

    /* ==================== 删除会诊（仅发起医生本人） ==================== */
    case 'delete':
        $cid = did(post('id'));
        $c = DB::one('consultation', 'SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        if ((int)$c['from_doctor_id'] !== (int)$u['id']) json_fail('仅发起会诊本人可删除');
        DB::exec('consultation', 'DELETE FROM consultations WHERE id=?', array($cid));
        json_ok(array(), '会诊已删除');
        break;

    default:
        json_fail('未知操作');
}