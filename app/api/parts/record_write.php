<?php
/**
 * ============================================================
 * parts/record_write.php — 电子病历：写入（保存/续写/体征/诊断）
 * ============================================================
 * record.php 按功能拆分的一部分，动作：
 *   create_progress 新建续写骨架 / save 保存病历 / save_vitals 体征 /
 *   save_diag_order 诊断排序 / save_diags 诊断列表
 * ============================================================ */

function record_part_write($action) {
    $u = Auth::user();

    if ($action === 'create_progress') {
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        // 归档锁定：已诊毕(归档)不可创建续写
        if ($visit['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可创建续写');
        }
        // 病历可访问天数校验
        if (!visit_access_allowed($visit, $u)) {
            json_fail('该病历超出您的可查看历史天数，无法修改');
        }
        // 本人最近一条文书（首诊或上一次续写）——作为续写的父记录
        $ownLatest = EmrRepository::one('SELECT id, record_type, emr_data FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        if (!$ownLatest) json_fail('本人尚无病历，请先书写首诊病历');
        // 必填校验：当前文书必须已完善并保存，才能继续续写
        $ownEmr = json_decode((string)$ownLatest['emr_data'], true);
        if (!is_array($ownEmr)) $ownEmr = array();
        $hasDiag = false;
        if (!empty($ownEmr['diagnoses']) && is_array($ownEmr['diagnoses'])) {
            foreach ($ownEmr['diagnoses'] as $dg) {
                if (is_array($dg) && !empty($dg['name'])) { $hasDiag = true; break; }
            }
        }
        if ($ownLatest['record_type'] === 'progress') {
            $progC = isset($ownEmr['progress']['content']) ? trim((string)$ownEmr['progress']['content']) : '';
            if ($progC === '' || !$hasDiag) json_fail('请先完善并保存当前续写病历的必填项（病历续写内容与初步诊断），再新建续写');
        } else {
            $ccS = isset($ownEmr['chief_complaint']['symptom']) ? trim((string)$ownEmr['chief_complaint']['symptom']) : '';
            $piC = isset($ownEmr['history_present']['content']) ? trim((string)$ownEmr['history_present']['content']) : '';
            if ($ccS === '' || $piC === '' || !$hasDiag) json_fail('请先完善并保存当前首诊病历的必填项（主诉、现病史与初步诊断），再新建续写');
        }
        $now = now_str();
        $emr = emr_default_data(null);
        $cleanJson = json_encode($emr, JSON_UNESCAPED_UNICODE);
        $pdo = DatabaseManager::getMain();
        try {
            $pdo->beginTransaction();
            // patient_records：空骨架（status=draft，正文为空，保存时填充）
            EmrRepository::prepareInsert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'progress', (int)$ownLatest['id'], $cleanJson, '', 'draft', $now, $now));
            // records 旧镜像表同步占位（兼容既有消费方）
            EmrRepository::prepareInsert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'draft', $now, $now));
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('续写病历创建失败：' . $ex->getMessage());
        }
        json_ok(array(), '续写病历已创建');
        return;
    }

    if ($action === 'save') {
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
        // 1) 就诊存在「进行中」会诊时，非会诊病历一律只读（无论是否本人书写）；
        // 2) 会诊病历在会诊「完毕」后永久只读。
        $doingCons = EmrRepository::one("SELECT * FROM consultations WHERE visit_id=? AND status='doing' ORDER BY id DESC LIMIT 1", array($visitId));
        $consultationId = (int)post('consultation_id', 0);
        if ($doingCons && $consultationId === 0) {
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
        $emr = json_decode($raw, true);
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

            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('病历保存失败：' . $ex->getMessage());
        }

        // C. 同步患者主表全局既往史/过敏史
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

        // ===== 会诊病历保存成功 → 会诊状态从 pending 置 doing =====
        // （进入会诊页面不代表会诊中，有会诊病历成功保存后才改为会诊中）
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
                json_fail('请选择离院方式（自主离院/住院/转院/死亡/其他）');
            }
            $dispNeed = array('住院' => '住院病区', '转院' => '接收医院名称', '死亡' => '死亡原因', '其他' => '其他转归情况');
            if ($disposition === '自主离院') {
                $dispDetail = '';
            } elseif ($dispDetail === '') {
                json_fail('请填写' . $dispNeed[$disposition]);
            }
            EmrRepository::exec('UPDATE registrations SET status=?, disposition=?, disposition_detail=?, finished_at=?, paid_at=COALESCE(paid_at,?) WHERE id=?',
                array('finished', $disposition, $dispDetail, now_str(), now_str(), $visitId));
            json_ok(array('finished' => 1, 'record_id' => $recordId), '病历已保存并诊毕');
        }
        json_ok(array('finished' => 0, 'record_id' => $recordId), '病历已保存');
        return;
    }

    if ($action === 'save_vitals') {
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 归档锁定：已诊毕(归档)不可再录入生命体征
        if ($row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可再录入生命体征');
        }
        // 病历可访问天数校验
        if (!visit_access_allowed($row['visit'], $u)) {
            json_fail('该病历超出您的可查看历史天数，无法修改');
        }
        // 数值校验（与服务端同规则）：非负整数、生理合理区间；留空视为未测
        $spec = array(
            'vital_sbp'  => array(post('vital_sbp', 0), 1, 300, '收缩压'),
            'vital_dbp' => array(post('vital_dbp', 0), 1, 250, '舒张压'),
            'vital_heart_rate'   => array(post('vital_heart_rate', ''), 1, 300, '心率'),
            'vital_pulse'        => array(post('vital_pulse', ''), 1, 300, '脉搏'),
            'vital_spo2'         => array(post('vital_spo2', ''), 1, 100, '血氧饱和度'),
            'vital_respiration'  => array(post('vital_respiration', ''), 1, 100, '呼吸'),
        );
        $clean = array();
        foreach ($spec as $k => $c) {
            $raw = trim((string)$c[0]);
            if ($raw === '') { $clean[$k] = ($k === 'vital_sbp' || $k === 'vital_dbp') ? 0 : ''; continue; }
            if (!preg_match('/^\d+$/', $raw)) json_fail($c[3] . '须为非负整数（不留小数 / 负数 / 单位）');
            $n = (int)$raw;
            if ($n !== 0 && ($n < $c[1] || $n > $c[2])) json_fail($c[3] . '超出合理范围（' . $c[1] . '-' . $c[2] . '）');
            $clean[$k] = ($k === 'vital_sbp' || $k === 'vital_dbp') ? $n : (string)$n;
        }
        $recordId = (int)post('record_id', 0);
        // 记录关联：同一病历已有体征条目 → 修改（纠错不产生新记录）；
        // 无 → 新增一条（新病历首次录入 / 护士站录入）
        $existV = ($recordId > 0)
            ? EmrRepository::one('SELECT id FROM vitals WHERE visit_id=? AND record_id=? LIMIT 1', array($visitId, $recordId))
            : null;
        $now = now_str();
        if ($existV) {
            EmrRepository::exec('UPDATE vitals SET vital_sbp=?, vital_dbp=?, vital_heart_rate=?, vital_pulse=?, vital_spo2=?, vital_respiration=?, operator=?, created_at=? WHERE id=?', array(
                $clean['vital_sbp'], $clean['vital_dbp'],
                $clean['vital_heart_rate'], $clean['vital_pulse'], $clean['vital_spo2'], $clean['vital_respiration'],
                $u['name'], $now, $existV['id'],
            ));
        } else {
            EmrRepository::insert('INSERT INTO vitals(visit_id, patient_no, flow_no, vital_sbp, vital_dbp, vital_heart_rate, vital_pulse, vital_spo2, vital_respiration, operator, created_at, record_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'],
                $clean['vital_sbp'], $clean['vital_dbp'],
                $clean['vital_heart_rate'], $clean['vital_pulse'], $clean['vital_spo2'], $clean['vital_respiration'],
                $u['name'], $now, $recordId,
        ));
        }
        json_ok(array(), '生命体征已保存');
        return;
    }

    if ($action === 'save_diag_order') {
        $visitId = did(post('visit_id'));
        $rowOrder = get_visit_row($visitId);
        if (!$rowOrder) json_fail('就诊记录不存在');
        // 病历可访问天数校验
        if (!visit_access_allowed($rowOrder['visit'], $u)) {
            json_fail('该病历超出您的可查看历史天数，无法修改');
        }
        // 会诊拦截：会诊病历（本人在会诊接收科室的文书）不可调整诊断顺序
        $hasConsult = (int)EmrRepository::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=? AND consultation_id>0', array($visitId, $u['id']));
        if ($hasConsult > 0) {
            json_fail('会诊病历不可调整诊断顺序');
        }
        $keys = json_decode((string)post('ord_keys', '[]'), true);
        if (!is_array($keys)) json_fail('排序数据无效');
        $clean = array();
        foreach ($keys as $k) {
            $k = trim((string)$k);
            if ($k !== '' && count($clean) < 100 && !in_array($k, $clean, true)) $clean[] = $k;
        }
        $exist = (int)EmrRepository::val('SELECT id FROM diag_orders WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
        if ($exist > 0) {
            EmrRepository::prepareExec('UPDATE diag_orders SET ord_keys=?, updated_at=? WHERE id=?', array(implode("\n", $clean), now_str(), $exist));
        } else {
            EmrRepository::insert('INSERT INTO diag_orders(visit_id, doctor_id, ord_keys, updated_at) VALUES(?,?,?,?)', array(
                $visitId, $u['id'], implode("\n", $clean), now_str(),
            ));
        }
        json_ok(array('diag_order' => $clean), '诊断顺序已保存');
        return;
    }

    if ($action === 'save_diags') {
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 归档锁定：已诊毕(归档)不可调整诊断
        if ($row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可调整诊断');
        }
        // 病历可访问天数校验
        if (!visit_access_allowed($row['visit'], $u)) {
            json_fail('该病历超出您的可查看历史天数，无法修改');
        }
        // 仅本人文书可调整，且未诊毕；切换回旧文书编辑时按 edit_record_id 精确定位
        $editDiagRecordId = (int)post('edit_record_id', 0);
        if ($editDiagRecordId > 0) {
            $pr = EmrRepository::one('SELECT * FROM patient_records WHERE id=? AND doctor_id=?', array($editDiagRecordId, $u['id']));
        } else {
            $pr = EmrRepository::one('SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        }
        if (!$pr) json_fail('您在该就诊下暂无病历文书');
        // 会诊锁校验：会诊期间非会诊病历不可调整诊断；会诊完毕的会诊病历不可再调整诊断
        $diagDoingCons = EmrRepository::one("SELECT * FROM consultations WHERE visit_id=? AND status='doing' ORDER BY id DESC LIMIT 1", array($visitId));
        if ($diagDoingCons && (int)$pr['consultation_id'] === 0) {
            json_fail('该就诊正在进行会诊，会诊前的病历已锁定为只读，仅可编辑会诊病历');
        }
        if ((int)$pr['consultation_id'] > 0) {
            $diagConsRow = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array((int)$pr['consultation_id']));
            if ($diagConsRow && $diagConsRow['status'] === 'done') {
                json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可修改');
            }
        }
        // 转科校验：文书书写科室与就诊当前科室不一致 → 只读，不可调整诊断；
        // 会诊记录（consultation_id>0）书写科室=会诊目标科室，不受转科限制
        if ((int)$pr['dept_id'] !== (int)$row['visit']['current_dept_id'] && (int)$pr['consultation_id'] === 0) {
            json_fail('该病历书写于转科前科室，当前科室下为只读状态，不可调整诊断');
        }
        if ($pr['status'] === 'done') json_fail('病历已诊毕，无法调整诊断');
        // 添加/调整诊断前置条件（与前端一致，防接口直调绕过）：
        // 首诊=主诉/现病史已填写；续写=续写内容已填写
        $gateEmr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
        if ((string)$pr['record_type'] === 'progress') {
            $progGate = isset($gateEmr['progress']['content']) ? trim((string)$gateEmr['progress']['content']) : '';
            if ($progGate === '') json_fail('请先填写病历续写内容后再添加诊断');
        } else {
            $ccGate = isset($gateEmr['chief_complaint']['symptom']) ? trim((string)$gateEmr['chief_complaint']['symptom']) : '';
            $piGate = isset($gateEmr['history_present']['content']) ? trim((string)$gateEmr['history_present']['content']) : '';
            if ($ccGate === '' || $piGate === '') json_fail('请先完善主诉与现病史后再添加诊断');
        }
        $diags = json_decode((string)post('diagnoses', '[]'), true);
        if (!is_array($diags)) json_fail('诊断数据无效');
        $clean = array();
        foreach ($diags as $d) {
            if (!is_array($d) || empty($d['name'])) continue;
            $clean[] = array(
                'code' => (string)(isset($d['code']) ? $d['code'] : ''),
                'name' => (string)$d['name'],
                'part' => (string)(isset($d['part']) ? $d['part'] : ''),
                'note' => (string)(isset($d['note']) ? $d['note'] : ''),
                'suspected' => (string)(isset($d['suspected']) ? $d['suspected'] : ''),
            );
        }
        // 允许清空诊断：删除主诊断后第二位自动递补，无则主诊断置空
        // （原「主诊断不可删除」保护移除，消除首诊病历无法删除的悖论；
        //   删除病历前置是清空诊断，若主诊断不可删则首诊病历永远删不掉）
        $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
        $emr['diagnoses'] = $clean;
        $diagText = $clean ? emr_diag_text($clean) : '';
        $firstCode = $clean ? (string)$clean[0]['code'] : '';
        // 结构化文书更新（诊断 + 主诊断投影）
        EmrRepository::prepareExec('UPDATE patient_records SET emr_data=?, icd10_code=?, diagnosis_name=? WHERE id=?', array(
            json_encode($emr, JSON_UNESCAPED_UNICODE), $firstCode, $diagText, $pr['id']));
        // 旧镜像表同步（最新一行）：注意镜像表 ICD 列名为 icd10_code；
        // 先查 id 再按 id 更新（避免 UPDATE 内子查询的兼容性问题）
        $mirrorId = (int)EmrRepository::val('SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        if ($mirrorId > 0) {
            EmrRepository::prepareExec('UPDATE records SET preliminary_diagnosis=?, icd10_code=? WHERE id=?', array($diagText, $firstCode, $mirrorId));
        }
        json_ok(array('diagnoses' => $clean), '诊断已更新');
        return;
    }
}
