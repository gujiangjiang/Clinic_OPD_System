<?php
/**
 * ============================================================
 * parts/record/record_save.php — 病历保存（首诊/续写/会诊/诊毕）
 * ============================================================
 * record_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function record_part_save($u) {
    $visitId = did(post('visit_id'));
    $finish = (int)post('finish', 0);
    $row = get_visit_row($visitId);
    if (!$row) json_fail('就诊记录不存在');
    $visit = $row['visit'];
    // 归档锁定：已诊毕(归档)病历不可再修改（法律/合规底线，前后端双重拦截）
    if ($visit['status'] === 'finished') {
        json_fail('该患者已诊毕，病历已归档，不可修改');
    }
    // 科室数据隔离：非挂号科室医生不能接诊/书写当前就诊
    if (!visit_dept_authorized($visit, $u)) {
        json_fail('您无权接诊该患者（就诊科室不在您的权限范围内）');
    }
    // 病历可访问天数校验：已诊毕历史病历须在医生 queue_days 可查看天数内
    if (!visit_access_allowed($visit, $u)) {
        json_fail('该病历超出您的可查看历史天数，无法修改');
    }

    // ===== 会诊锁校验（会诊期间病历只读） =====
    // 1) 医生处于「会诊处理中」（当前科室 = 某进行中/待处理会诊的目标科室）时，
    //    非会诊病历一律只读——仅会诊病历可编辑；
    //    医生在原科室（非目标科室）不受会诊影响，可正常编辑/续写首诊病历。
    //    （会诊不锁原科室：A 发 B 会诊未完毕，A 科室医生仍可继续编辑 A 病历。）
    // 2) 会诊病历在会诊「完毕」后永久只读。
    $consultCtx = get_consult_context($visit, $u);
    $consultationId = (int)post('consultation_id', 0);
    if ($consultCtx && $consultationId === 0) {
        json_fail('该就诊正在进行会诊，会诊前的病历已锁定为只读，仅可编辑会诊病历');
    }
    if ($consultationId > 0) {
        $consStatusRow = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array($consultationId));
        if ($consStatusRow && $consStatusRow['status'] === 'done') {
            json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可修改');
        }
    }

    // ===== 1. 解析与文书类型判定 =====
    $raw = post_raw('emr_data');
    $emr = json_decode($raw, true, 512);
    if (!is_array($emr)) json_fail('病历数据格式非法');
    $cc = isset($emr['chief_complaint']) && is_array($emr['chief_complaint']) ? $emr['chief_complaint'] : array();
    $pi = isset($emr['history_present']) && is_array($emr['history_present']) ? $emr['history_present'] : array();
    $diagnoses = isset($emr['diagnoses']) && is_array($emr['diagnoses']) ? $emr['diagnoses'] : array();

    // 文书类型服务端权威判定（不信任前端提交）：
    // · edit_record_id>0：切换回本人旧文书编辑 → 按指定记录定位与判定；
    // · progress_new=1：本人已点击「病历节点 +」新建续写 → 强制新建 progress
    //   （不更新旧文书，支持多段续写）；
    // · 本人已有文书 → 取【最新一条】维持其原有类型；
    // · 本人无文书 → 流水下已有他人病历时为续写（progress），否则为首诊。
    $editRecordId = (int)post('edit_record_id', 0);
    $progressNew = (int)post('progress_new', 0);
    $otherCount = (int)EmrRepository::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id<>?', array($visitId, $u['id']));
    if ($editRecordId > 0) {
        // 编辑本人指定文书（切换回旧首诊/旧续写）——校验归属
        $ownRow = EmrRepository::one('SELECT id, record_type, dept_id, consultation_id FROM patient_records WHERE id=? AND doctor_id=?', array($editRecordId, $u['id']));
        if (!$ownRow) json_fail('病历记录不存在或无权编辑');
        // 会诊完毕锁定：以记录自身的 consultation_id 为准（不信任前端传参——
        // 前端切换旧文书时可能丢失 consultation_id 导致 done 拦截被绕过）
        if ((int)$ownRow['consultation_id'] > 0) {
            $ownConsStatus = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array((int)$ownRow['consultation_id']));
            if ($ownConsStatus && $ownConsStatus['status'] === 'done') {
                json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可修改');
            }
        }
        // 转科校验：旧文书书写科室与就诊当前科室不一致 → 只读，不可编辑（即使本人也不行）；
        // 会诊记录（consultation_id>0）书写科室=会诊目标科室，不受转科限制
        if ((int)$ownRow['dept_id'] !== (int)$visit['current_dept_id'] && (int)$ownRow['consultation_id'] === 0) {
            json_fail('该病历书写于转科前科室，当前科室下为只读状态，不可编辑');
        }
        $recordType = $ownRow['record_type'];
    } else {
        $ownRow = EmrRepository::one('SELECT id, record_type, consultation_id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        // 会诊完毕锁定：仅当保存目标是「本人最新现有文书」时校验（无 edit_record_id
        // 且非新建续写 progress_new）；若本人最新文书是已完毕会诊病历则只读。
        // 新建续写（progress_new=1）保存目标是全新文书，与旧会诊记录无关，不可误拦。
        if (!$progressNew && $ownRow && (int)$ownRow['consultation_id'] > 0) {
            $ownConsStatus2 = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array((int)$ownRow['consultation_id']));
            if ($ownConsStatus2 && $ownConsStatus2['status'] === 'done') {
                json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可修改');
            }
        }
        if ($progressNew) {
            $recordType = 'progress';
        } else {
            $recordType = $ownRow
                ? ($ownRow['record_type'] === 'progress' ? 'progress' : 'initial')
                : ($otherCount > 0 ? 'progress' : 'initial');
        }
    }
    // 续写文书的父记录：
    // · progress_new（本人续写）→ 父 = 本人最近一条文书；
    // · 本人自续写（本人已有更早文书）→ 父 = 本人更早文书；
    // · 跨医生接诊续写 → 父 = 最近一条他人文书。
    $parentRow = null;
    if ($progressNew && $ownRow) {
        $parentRow = $ownRow;
    } elseif ($recordType === 'progress') {
        $myEarlier = $ownRow ? EmrRepository::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? AND id<? ORDER BY id DESC LIMIT 1', array($visitId, $u['id'], $ownRow['id'])) : null;
        $parentRow = $myEarlier
            ? $myEarlier
            : EmrRepository::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id<>? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
    }
    $parentRecordId = $parentRow ? (int)$parentRow['id'] : 0;

    // ===== 统一上下文断言（SSOT 守卫）=====
    // 第一层根判定：当前是否存在可写容器。会诊处理中 → 仅会诊病历可写；
    // 新建续写（progress_new，record 尚未创建）→ 豁免容器存在性，由后续
    // 必填与转科校验把关；会诊处理中且目标为会诊病历（consultation_id>0，
    // 会诊病历尚未创建，record 为 null）→ 豁免容器存在性（允许创建会诊病历）；
    // 其余情况 → 硬拦截不可写场景。
    // 注意：不传 targetContainerId——医生可切换到本人旧文书编辑（switchToRecord），
    // 其可编辑性已由 resolve 的 dept_match/consult 判定覆盖。
    $isConsultCreate = $consultationId > 0 && !$ownRow;
    if (!$progressNew && !$isConsultCreate) {
        $ctxRecord = $ownRow ? EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($ownRow['id'])) : null;
        EmrContextResolver::assertCanWrite($visit, $u, $ctxRecord);
    }

    // ===== 会诊病历关联：consultation_id>0 时校验会诊归属 =====
    // 校验：会诊单属于本就诊 + 目标科室为当前登录医生所在科室（会诊由目标科室医生书写）
    $consultationId = (int)post('consultation_id', 0);
    $recDeptId = (int)$visit['current_dept_id'];
    if ($consultationId > 0) {
        $cons = EmrRepository::one('SELECT * FROM consultations WHERE id=?', array($consultationId));
        if (!$cons) json_fail('会诊记录不存在');
        if ((int)$cons['visit_id'] !== (int)$visitId) json_fail('会诊记录不属于本次就诊');
        $curDeptRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
        $curDeptId = $curDeptRow ? (int)$curDeptRow['current_dept_id'] : 0;
        if ((int)$cons['target_dept_id'] !== $curDeptId) json_fail('该会诊不属于当前科室，无法书写会诊病历');
        // 会诊记录书写科室 = 会诊目标科室（非患者当前就诊科室）
        $recDeptId = (int)$cons['target_dept_id'];
    }

    // ===== 2. 必填校验（按文书类型分支） =====
    // 首诊：主诉 / 现病史 / 初步诊断；续写：病历续写内容 / 初步诊断
    // （主诊断取该医生诊断列表第 1 项，各医生文书互相独立、物理隔离）
    $hasDiagnosis = count(array_filter($diagnoses, function ($d) { return is_array($d) && !empty($d['name']); })) > 0;
    if ($recordType === 'progress') {
        $progContent = isset($emr['progress']['content']) ? trim((string)$emr['progress']['content']) : '';
        if ($progContent === '') json_fail('病历续写为必填项，请输入续写内容（可快捷填入「病史同上」）');
        if (!$hasDiagnosis) json_fail('初步诊断为必填项，请至少添加一个诊断');
    } else {
        if (!isset($cc['symptom']) || trim((string)$cc['symptom']) === '') json_fail('主诉为必填项，请填写主要症状');
        if (!isset($pi['content']) || trim((string)$pi['content']) === '') json_fail('现病史为必填项，请填写具体内容');
        if (!$hasDiagnosis) json_fail('初步诊断为必填项，请至少添加一个诊断');
    }

    // ===== 3. 合并默认结构 + 业务防御清洗 =====
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
    // 字符串字段统一裁剪（含续写内容）
    foreach (array('aux_result', 'aux_external', 'disposition_custom', 'advice') as $k) {
        $emr[$k] = trim((string)$emr[$k]);
    }
    $emr['allergies']['detail'] = trim((string)$emr['allergies']['detail']);
    $emr['progress']['content'] = trim((string)$emr['progress']['content']);

    // ===== 4. 投影字段提取（单一事实转换） =====
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

    // ===== 5. 打印文本（含当前医生本人已开项目快照） =====
    list($orderNames, $rxLines, $dispItems) = emr_order_snapshot($visitId, $u['id']);
    // 生命体征归属：打印文本快照仅含当前医生本人录入的体征（operator=本人姓名），
    // 谁的体征归属谁的文书；未录入则不含生命体征节
    $vitalsRow = EmrRepository::one('SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1', array($visitId, $u['name']));
    $vp = array();
    if ($vitalsRow) {
        if (!empty($vitalsRow['vital_sbp'])) $vp[] = '血压 ' . $vitalsRow['vital_sbp'] . '/' . $vitalsRow['vital_dbp'] . 'mmHg';
        if (!empty($vitalsRow['vital_heart_rate'])) $vp[] = '心率 ' . $vitalsRow['vital_heart_rate'] . '次/分';
        if (!empty($vitalsRow['vital_pulse'])) $vp[] = '脉搏 ' . $vitalsRow['vital_pulse'] . '次/分';
        if (!empty($vitalsRow['vital_spo2'])) $vp[] = '血氧 ' . $vitalsRow['vital_spo2'] . '%';
        if (!empty($vitalsRow['vital_respiration'])) $vp[] = '呼吸 ' . $vitalsRow['vital_respiration'] . '次/分';
    }
    $vitalsText = implode('；', $vp);
    $consciousness = post('consciousness');
    $printText = emr_print_text($emr, $vitalsText, $consciousness, $orderNames, $rxLines, $dispItems);
    $cleanJson = json_encode($emr, JSON_UNESCAPED_UNICODE);

    // 初复诊白名单校验（默认初诊）
    $visitType = post('visit_type', '初诊');
    if (!in_array($visitType, array('初诊', '复诊'), true)) $visitType = '初诊';

    // ===== 6. 事务写入（medical 库：patient_records + records 镜像同库） =====
    $now = now_str();
    $pdo = DatabaseManager::getMain();
    try {
        $pdo->beginTransaction();

        // A. patient_records 写入/更新
        // 更新：仅刷新内容投影，record_type/parent_record_id 维持原值
        // （不重写历史）；新增：写入服务端判定的文书类型与父记录 id。
        $pr = null;
        if ($editRecordId > 0) {
            $pr = array('id' => $editRecordId);   // 切换回旧文书：精确更新指定记录
        } else {
            $pr = EmrRepository::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        }
        if ($pr && !$progressNew) {
            EmrRepository::prepareExec('UPDATE patient_records SET chief_complaint=?, symptom_duration=?, symptom_unit=?, informant=?, arrival_way=?, has_past_history=?, allergy_history=?, is_leave_hospital=?, icd10_code=?, diagnosis_name=?, emr_data=?, emr_print_text=?, status=?, updated_at=? WHERE id=?', array(
                $mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $pr['id']));
            $recordId = (int)$pr['id'];
        } else {
            $recordId = EmrRepository::prepareInsert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at, consultation_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], $recDeptId, $u['id'], $u['name'], $recordType, $parentRecordId, $mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $now, $consultationId));
            // 体征记录回填：新病历保存前若以 record_id=0 录入过体征（未保存时的
            // 录入），关联到本次新建病历，保证该病历内后续修改体征为更新而非新增。
            EmrRepository::exec('UPDATE vitals SET record_id=? WHERE visit_id=? AND operator=? AND record_id=0', array($recordId, $visitId, $u['name']));
        }

        // B. 旧 records 表扁平镜像（兼容就诊历史列表/转科引用/诊断证明等既有消费方）
        // 续写文书：现病史投影为空时回填「病历续写」内容，保证旧消费方可读；
        // 主诉投影为空则如实存空（首诊信息归首诊医生文书，互不串写）。
        $piMirror = emr_pi_text($emr['history_present']);
        if ($piMirror === '' && $recordType === 'progress') $piMirror = $emr['progress']['content'];
        $mirror = array(
            'patient_record_id' => $recordId,
            'chief_complaint' => emr_cc_text($emr['chief_complaint']),
            'present_illness' => $piMirror,
            'past_history' => emr_ph_text($emr['past_history']),
            'allergy_history' => emr_al_text($emr['allergies']),
            'physical_exam' => emr_pe_text($emr['physical_exam']),
            'consciousness' => $consciousness,
            'preliminary_diagnosis' => emr_diag_text($diagnoses),
            'icd10_code' => $primaryIcd10,
            'is_observation' => $isLeaveHospital === '是' ? 1 : 0,
            'visit_type' => $visitType,
            'doctor_advice' => $emr['advice'],
            'status' => $finish ? 'done' : 'draft',
            'updated_at' => $now,
        );
        // 旧 records 表扁平镜像：patient_record_id 精确归属对应文书
        // （同医生多文书：首诊+多段续写各有一条镜像，编辑旧文书精确回写）
        $old = null;
        if ($recordId > 0) {
            $old = EmrRepository::one('SELECT id FROM records WHERE patient_record_id=?', array($recordId));
        }
        if (!$old) {
            $old = EmrRepository::one('SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        }
        if ($old && !$progressNew) {
            $set = array();
            $params = array();
            foreach ($mirror as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
            $params[] = $old['id'];
            EmrRepository::prepareExec('UPDATE records SET ' . implode(',', $set) . ' WHERE id=?', $params);
            $oldRecordId = (int)$old['id'];
        } else {
            $cols = 'visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, patient_record_id, ' . implode(',', array_keys($mirror)) . ', created_at';
            $marks = '?,?,?,?,?,?,?, ' . implode(',', array_fill(0, count($mirror), '?')) . ',?';
            $params = array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], $recordId);
            foreach ($mirror as $v) $params[] = $v;
            $params[] = $now;
            $oldRecordId = EmrRepository::prepareInsert("INSERT INTO records($cols) VALUES($marks)", $params);
        }

        // C. 同步患者主表全局既往史/过敏史（随病历保存同一事务，保证原子性）
        $curGlobal = EmrRepository::one('SELECT has_past_history, past_history, allergy_history FROM patients WHERE patient_no=?', array($visit['patient_no']));
        $phType = (string)$emr['past_history']['type'];
        $phDetail = (string)$emr['past_history']['detail'];
        $alType = isset($emr['allergies']['type']) ? (string)$emr['allergies']['type'] : '';
        $alDetail = isset($emr['allergies']['detail']) ? (string)$emr['allergies']['detail'] : '';
        // 过敏史：仅当通过模态框修改过（allergy_modified=1）才同步患者主表——
        // 患者主表是唯一数据源，模态框读写；未打开模态框保存病历不改变主表。
        $allergyModified = (int)post('allergy_modified', 0);
        $newAllergy = $curGlobal ? (string)$curGlobal['allergy_history'] : '';
        if ($allergyModified === 1) {
            $newAllergy = ($alType === '承认') ? $alDetail : '';
        }
        // 既往史：本次「承认」则同步；本次否认但全局为「承认」 → 保留全局
        $newPhType = $phType;
        $newPhDetail = $phDetail;
        if ($phType !== '承认' && $curGlobal && $curGlobal['has_past_history'] === '承认') {
            $newPhType = $curGlobal['has_past_history'];
            $newPhDetail = $curGlobal['past_history'];
        }
        EmrRepository::exec('UPDATE patients SET has_past_history=?, past_history=?, allergy_history=? WHERE patient_no=?', array(
            $newPhType, $newPhDetail, $newAllergy, $visit['patient_no'],
        ));

        // C2. 保存病历即视为接诊：若就诊状态仍为待就诊(paid)，标记为就诊中(visiting)
        // （以「是否存在病历」判定是否就诊，而非打开页面即算）
        if (!$finish && isset($visit['status']) && $visit['status'] === 'paid') {
            EmrRepository::exec('UPDATE registrations SET status=? WHERE id=?', array('visiting', $visitId));
        }

        // ===== 会诊病历保存成功 → 确保会诊状态为 doing =====
        // （接受会诊时已置 doing，此处兜底；若尚未接受则同步置 doing + 记录接收医生）
        if ($consultationId > 0) {
            $cons2 = EmrRepository::one('SELECT status, accepted_by FROM consultations WHERE id=?', array($consultationId));
            if ($cons2) {
                $updates = array();
                if ($cons2['status'] === 'pending') $updates['status'] = 'doing';
                if (empty($cons2['accepted_by'])) $updates['accepted_by'] = $u['name'];
                $updates['accepted_at'] = now_str();
                if (isset($updates['status'])) {
                    EmrRepository::exec('UPDATE consultations SET status=?, accepted_by=?, accepted_at=? WHERE id=?',
                        array($updates['status'], $updates['accepted_by'], $updates['accepted_at'], $consultationId));
                }
            }
        }

        // D. 诊毕：更新就诊状态
        if ($finish) {
            // 诊毕转归：离院方式必选；非「自主离院」必须填写对应补充信息
            $disposition = trim((string)post('disposition', ''));
            $dispDetail = trim((string)post('disposition_detail', ''));
            $dispAllow = array('自主离院', '住院', '转院', '死亡', '其他');
            if (!in_array($disposition, $dispAllow, true)) {
                $pdo->rollBack();
                json_fail('请选择离院方式（自主离院/住院/转院/死亡/其他）');
            }
            $dispNeed = array('住院' => '住院病区', '转院' => '接收医院名称', '死亡' => '死亡原因', '其他' => '其他转归情况');
            if ($disposition === '自主离院') {
                $dispDetail = '';
            } elseif ($dispDetail === '') {
                $pdo->rollBack();
                json_fail('请填写' . $dispNeed[$disposition]);
            }
            EmrRepository::exec('UPDATE registrations SET status=?, disposition=?, disposition_detail=?, finished_at=?, paid_at=COALESCE(paid_at,?) WHERE id=?',
                array('finished', $disposition, $dispDetail, now_str(), now_str(), $visitId));
            $pdo->commit();
            json_ok(array('finished' => 1, 'record_id' => $recordId), '病历已保存并诊毕');
        }
        $pdo->commit();
        json_ok(array('finished' => 0, 'record_id' => $recordId), '病历已保存');
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_fail('病历保存失败：' . $ex->getMessage());
    }
    return;
}