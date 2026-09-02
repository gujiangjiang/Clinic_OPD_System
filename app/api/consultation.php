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
require_once APP_ROOT . '/app/includes/emr_formatter.php';

$u = Auth::user();

/** 医生候诊可见天数（2-7，缺省 3）——会诊列表同样受此限制 */
function consultation_queue_days($u) {
    if (isset($u['queue_days']) && (int)$u['queue_days'] >= 2 && (int)$u['queue_days'] <= 7) {
        return (int)$u['queue_days'];
    }
    $ud = ConsultationRepository::one('SELECT queue_days FROM users WHERE id=?', array($u['id']));
    if ($ud && (int)$ud['queue_days'] >= 2 && (int)$ud['queue_days'] <= 7) return (int)$ud['queue_days'];
    return 3;
}

/** 会诊行 → 前端结构（id 一律返回混淆串 code，前端传回后 did 解码） */
function consultation_row($c) {
    $c = consult_ensure_no($c);
    return array(
        'id' => (int)$c['id'],
        'code' => oid($c['id']),
        'consult_no' => (string)$c['consult_no'],
        'visit_id' => (int)$c['visit_id'],
        'visit_code' => oid($c['visit_id']),
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
        'finished_by' => (string)$c['finished_by'],
        'finished_at' => (string)$c['finished_at'],
        'record_id' => (int)(isset($c['record_id']) ? $c['record_id'] : 0),
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
        // 可编辑病历前置校验：本人必须有当前科室可编辑的病历（首诊/续写）才可发起会诊
        // 转科后旧科室文书只读，必须在本科室新建续写病历并保存后才能发起会诊。
        // 统一上下文断言（SSOT 守卫）：会诊发起必须持有活跃可写容器。
        $myRec = get_editable_record($visit, $u);
        EmrContextResolver::assertCanWrite($visit, $u, $myRec ? EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($myRec['id'])) : null);
        // 会诊与病历强关联：记录发起会诊时所在的病历记录 id（与开单一致，按 record_id 展示）
        $consRecId = (int)$myRec['id'];
        // 会诊拦截：本人已书写会诊病历且该会诊尚未完毕（pending/doing）→ 不可再发起会诊
        // 已完毕（done）的会诊病历不再阻挡发起新会诊——用户可在会诊完毕后再次向同科室或
        // 他科室发送会诊。注意：consultations 表在 consultation 库，medical 库查询
        // 不得内嵌跨库子查询，须先在 consultation 库取进行中会诊 id 列表再关联。
        $ownConsultIds = array();
        foreach (ConsultationRepository::q("SELECT id FROM consultations WHERE visit_id=? AND status IN ('pending','doing')", array($visitId)) as $ocr) {
            $ownConsultIds[] = (int)$ocr['id'];
        }
        $ownConsult = 0;
        if ($ownConsultIds) {
            $ph = implode(',', array_fill(0, count($ownConsultIds), '?'));
            $ownConsult = (int)ConsultationRepository::val(                "SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=? AND consultation_id IN ($ph)",
                array_merge(array($visitId, $u['id']), $ownConsultIds));
        }
        if ($ownConsult > 0) json_fail('您正在会诊处理中，不可再发起会诊');
        $targetDeptId = (int)post('target_dept_id', 0);
        if ($targetDeptId <= 0) json_fail('请选择会诊科室');
        // 目标科室必须合法且不能是当前科室
        $targetDept = ConsultationRepository::one("SELECT id, name FROM departments WHERE id=? AND status=1 AND type IN ('clinic','emergency')", array($targetDeptId));
        if (!$targetDept) json_fail('会诊科室不存在或不可用');
        if ((int)$targetDept['id'] === (int)$visit['current_dept_id']) json_fail('会诊科室不能是当前科室');
        // 会诊拦截：本就诊存在发往同一科室的待处理/进行中会诊 → 不可重复发起（同科室不同会诊矛盾）
        // 会诊已完毕（done）后可再次向该科室发起会诊。
        $activeToSame = (int)ConsultationRepository::val("SELECT COUNT(*) FROM consultations WHERE visit_id=? AND target_dept_id=? AND status IN ('pending','doing')",
            array($visitId, $targetDeptId));
        if ($activeToSame > 0) json_fail('该就诊已有发往 ' . $targetDept['name'] . ' 的进行中会诊，请等待会诊处理完毕后再发起');
        $description = trim((string)post('description', ''));
        $purpose = trim((string)post('purpose', ''));
        if ($description === '') json_fail('请填写会诊描述');
        if ($purpose === '') json_fail('请填写会诊目的');
        $now = now_str();
        // 生成唯一会诊单号（HZ + 时间戳 + 随机，循环查重防撞号）
        do {
            $consultNo = consult_gen_no();
        } while ((int)ConsultationRepository::val('SELECT COUNT(*) FROM consultations WHERE consult_no=?', array($consultNo)) > 0);
        $cid = ConsultationRepository::insert('INSERT INTO consultations(visit_id, patient_no, flow_no, consult_no, from_dept_id, from_dept_name, from_doctor_id, from_doctor_name, target_dept_id, target_dept_name, description, purpose, status, record_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], $consultNo,
            (int)$visit['current_dept_id'], (string)$visit['current_dept_name'],
            $u['id'], $u['name'],
            (int)$targetDept['id'], (string)$targetDept['name'],
            $description, $purpose, 'pending', $consRecId, $now,
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
            $deptId = current_dept_id($u);
        }
        if ($deptId <= 0) json_fail('当前医生未关联可用科室');
        $days = consultation_queue_days($u);
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $type = get('type', 'received');
        if ($type === 'sent') {
            // 发出的会诊（本人发起，近 N 天）
            $rows = ConsultationRepository::q("SELECT * FROM consultations WHERE from_doctor_id=? AND date(created_at)>=? ORDER BY id DESC", array($u['id'], $since));
        } else {
            // 收到的会诊（目标科室 = 当前科室，近 N 天）
            $rows = ConsultationRepository::q("SELECT * FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC", array($deptId, $since));
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
        $pr = ConsultationRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND record_type='initial' ORDER BY id ASC LIMIT 1",
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
        $rows = ConsultationRepository::q('SELECT * FROM consultations WHERE visit_id=? ORDER BY id ASC', array($visitId));
        json_ok(array('list' => array_map('consultation_row', $rows)));
        break;

    /* ==================== 会诊详情（含病历快照） ==================== */
    case 'detail':
        $cid = did(get('id'));
        $c = ConsultationRepository::one('SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        // 权限校验：发起医生、目标科室医生、或就诊科室授权医生可查看
        $isFromDoctor = (int)$c['from_doctor_id'] === (int)$u['id'];
        $isTargetDept = false;
        $isTargetDept = current_dept_id($u) === (int)$c['target_dept_id'];
        if (!$isFromDoctor && !$isTargetDept) {
            $vRow = get_visit_row((int)$c['visit_id']);
            if (!$vRow || !visit_dept_authorized($vRow['visit'], $u)) {
                json_fail('无权限查看该会诊');
            }
        }
        $data = consultation_row($c);
        // 病历快照：取发起科室医生的首诊文书（主诉/现病史/体格检查/诊断）
        $pr = ConsultationRepository::one("SELECT * FROM patient_records WHERE visit_id=? AND dept_id=? AND record_type='initial' ORDER BY id ASC LIMIT 1",
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
        $consRec = ConsultationRepository::one('SELECT id, doctor_name, status, created_at FROM patient_records WHERE consultation_id=?', array($cid));
        $data['record'] = $consRec ? array(
            'record_id' => (int)$consRec['id'], 'doctor_name' => (string)$consRec['doctor_name'],
            'status' => (string)$consRec['status'], 'created_at' => (string)$consRec['created_at'],
        ) : null;
        json_ok(array('consultation' => $data));
        break;

    /* ==================== 接受会诊（pending → doing） ==================== */
    case 'accept':
        $cid = did(post('id'));
        $c = ConsultationRepository::one('SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        $curDeptId = current_dept_id($u);
        if ((int)$c['target_dept_id'] !== $curDeptId) json_fail('该会诊不属于当前科室');
        // 接受会诊：原子条件更新防并发重复接受（仅 pending 可转 doing）
        $acceptAffected = ConsultationRepository::exec(
            "UPDATE consultations SET status=?, accepted_by=?, accepted_at=? WHERE id=? AND status='pending'",
            array('doing', $u['name'], now_str(), $cid)
        );
        if ($acceptAffected === 0) json_fail('该会诊已被其他医生处理');
        json_ok(array(), '已接受会诊，请书写会诊病历');
        break;

    /* ==================== 会诊完毕（doing → done） ==================== */
    case 'finish':
        $cid = did(post('id'));
        $c = ConsultationRepository::one('SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        $curDeptId2 = current_dept_id($u);
        if ((int)$c['target_dept_id'] !== $curDeptId2) json_fail('该会诊不属于当前科室');
        if ($c['status'] === 'pending') json_fail('会诊尚未开始');
        if ($c['status'] === 'done') json_fail('该会诊已完毕');
        // 原子条件更新防并发重复完成（仅 doing 可转 done）
        $finishAffected = ConsultationRepository::exec(
            "UPDATE consultations SET status=?, finished_by=?, finished_at=? WHERE id=? AND status='doing'",
            array('done', $u['name'], now_str(), $cid)
        );
        if ($finishAffected === 0) json_fail('该会诊状态已变更，请刷新后重试');
        json_ok(array(), '会诊已完毕');
        break;

    /* ==================== 删除会诊（仅发起医生本人，且未被接收处理） ==================== */
    case 'delete':
        $cid = did(post('id'));
        $c = ConsultationRepository::one('SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$c) json_fail('会诊记录不存在');
        if ((int)$c['from_doctor_id'] !== (int)$u['id']) json_fail('仅发起会诊本人可删除');
        // 已被接收（doing/done）的会诊不可删除——B 科室已投入处理
        if ($c['status'] !== 'pending') json_fail('该会诊已被接收科室处理，不可删除');
        ConsultationRepository::exec('DELETE FROM consultations WHERE id=?', array($cid));
        json_ok(array(), '会诊已删除');
        break;

    default:
        json_fail('未知操作');
}