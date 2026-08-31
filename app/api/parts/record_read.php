<?php
/**
 * ============================================================
 * parts/record_read.php — 电子病历：读取（get）
 * ============================================================
 * record.php 按功能拆分的一部分，动作：get 加载病历。
 * ============================================================ */

function record_part_read($action) {
    $u = Auth::user();

    if ($action === 'get') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $patient = $row['patient'];
        // 科室数据隔离：非挂号科室医生不能查看当前就诊（已诊毕归档可查看）
        if (!visit_dept_authorized($visit, $u)) {
            json_fail('您无权查看该患者的当前就诊（就诊科室不在您的权限范围内）');
        }
        // 病历可访问天数校验：已诊毕历史病历须在医生 queue_days 可查看天数内
        if (!visit_access_allowed($visit, $u)) {
            json_fail('该病历超出您的可查看历史天数，请通过候诊列表或就诊历史查看');
        }

        // 当前科室（可能已转科，显示当前就诊科室）
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $deptName = $dept ? $dept['name'] : $visit['first_dept_name'];
        $deptType = $dept ? $dept['type'] : 'clinic';

        // 医生信息（工号/职称，需求18.2：工作站显示医生姓名工号职称）
        $doc = EmrRepository::one('SELECT emp_no, title FROM users WHERE id=?', array($u['id']));

        // ===== 多医生接诊（1:N）：该挂号流水下全部病历（按创建时间升序） =====
        // 每位医生各自拥有独立文书：谁书写谁签名；前序病历对后序医生只读展示。
        $allRows = EmrRepository::q('SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC', array($visitId));
        // 补齐各文书医生的工号/职称（users 与 medical 分库，不能 JOIN，按 id 批量查询）
        $docIds = array();
        foreach ($allRows as $pr2) { $docIds[(int)$pr2['doctor_id']] = true; }
        $docMeta = array();
        if ($docIds) {
            $ph = implode(',', array_fill(0, count($docIds), '?'));
            foreach (EmrRepository::q("SELECT id, emp_no, title FROM users WHERE id IN ($ph)", array_keys($docIds)) as $dm) {
                $docMeta[(int)$dm['id']] = $dm;
            }
        }
        // 补齐各文书的书写科室名（转科后各文书科室可能不同，按文书自身 dept_id 归属）
        $deptIds = array();
        foreach ($allRows as $pr2) { if ((int)$pr2['dept_id'] > 0) $deptIds[(int)$pr2['dept_id']] = true; }
        $deptNames = array();
        if ($deptIds) {
            $ph2 = implode(',', array_fill(0, count($deptIds), '?'));
            foreach (EmrRepository::q("SELECT id, name FROM departments WHERE id IN ($ph2)", array_keys($deptIds)) as $dn) {
                $deptNames[(int)$dn['id']] = (string)$dn['name'];
            }
        }
        /** 单条 patient_records → 前端历史/编辑两用结构 */
        $mapRecord = function ($pr2) use ($docMeta, $deptNames) {
            $emr2 = emr_merge_defaults(
                emr_normalize(json_decode($pr2['emr_data'], true)),
                emr_default_data(null)
            );
            $meta = isset($docMeta[(int)$pr2['doctor_id']]) ? $docMeta[(int)$pr2['doctor_id']] : null;
            // 生命体征归属：按文书记录精确关联（record_id 优先，兼容旧数据按录入医生取最新）。
            // 续写/会诊病历各自独立体征——只取本记录关联的体征；续写无自身体征则恒为空，
            // 首诊记录才按 operator 回退就诊体征。
            $ownVitals = null;
            if ((int)$pr2['id'] > 0) {
                $ownVitals = EmrRepository::one('SELECT * FROM vitals WHERE record_id=? ORDER BY id DESC LIMIT 1', array((int)$pr2['id']));
            }
            if (!$ownVitals && $pr2['record_type'] !== 'progress') {
                $ownVitals = EmrRepository::one('SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1',
                    array((int)$pr2['visit_id'], (string)$pr2['doctor_name']));
            }
            // 意识状态/初复诊按文书医生本人从旧 records 镜像表回读
            $mirror = EmrRepository::one('SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC',
                array((int)$pr2['visit_id'], (int)$pr2['doctor_id']));
            return array(
                'id' => (int)$pr2['id'],
                'record_id' => (int)$pr2['id'],
                'doctor_id' => (int)$pr2['doctor_id'],
                'doctor_name' => (string)$pr2['doctor_name'],
                'doctor_emp' => $meta ? (string)$meta['emp_no'] : '',
                'doctor_title' => $meta ? (string)$meta['title'] : '',
                'dept_id' => (int)$pr2['dept_id'],
                'dept_name' => isset($deptNames[(int)$pr2['dept_id']]) ? $deptNames[(int)$pr2['dept_id']] : '',
                'consultation_id' => (int)(isset($pr2['consultation_id']) ? $pr2['consultation_id'] : 0),
                'record_type' => ($pr2['record_type'] === 'progress') ? 'progress' : 'initial',
                'parent_record_id' => (int)$pr2['parent_record_id'],
                'icd10_code' => (string)$pr2['icd10_code'],
                'diagnosis_name' => (string)$pr2['diagnosis_name'],
                'status' => (string)$pr2['status'],
                'created_at' => (string)$pr2['created_at'],
                'updated_at' => (string)$pr2['updated_at'],
                'emr' => $emr2,
                'vitals' => $ownVitals ? $ownVitals : array(),
                'consciousness' => $mirror ? (string)$mirror['consciousness'] : '',
            );
        };
        // 会诊上下文：当前医生是否处于该就诊的会诊处理中（基于就诊+目标科室，与 URL 参数无关）
        // 用于：1) 前端刷新后仍能进入会诊模式；2) dept_match 精确判定；
        //       3) $mine 选择——优先「当前上下文可编辑」的本人文书
        $consultCtx = get_consult_context($visit, $u);
        $consultMode = $consultCtx ? 1 : 0;
        $consultCode = $consultCtx ? oid($consultCtx['id']) : '';

        // 医生当前所在科室（会话 auth_user 不含 current_dept_id，须从 user 库读取）
        $docDept = (int)(isset($u['current_dept_id']) ? $u['current_dept_id'] : 0);
        if ($docDept <= 0) {
            $docDeptRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array((int)$u['id']));
            $docDept = $docDeptRow ? (int)$docDeptRow['current_dept_id'] : 0;
        }
        // 跨科室只读查看（纯状态驱动，不用 URL 参数）：
        // 医生当前科室 != 就诊当前科室，且非会诊处理中 → 本就诊对当前医生绝对只读
        // （不同科室病历隔离：跨科室仅可查看，要书写须转科/续写/发会诊）。
        // 会诊处理中（consultMode）医生在目标科室，其会诊病历可编辑，不受此限制。
        $crossDeptView = (!$consultMode && $docDept > 0
            && $docDept !== (int)$visit['current_dept_id']) ? 1 : 0;

        $recordsHistory = array();
        $mine = null;       // 本人最新可编辑文书（首选项）
        $mineLatest = null; // 本人最新文书（无可编辑时兜底，走 deptMismatch 只读展示）
        foreach ($allRows as $pr2) {
            $item = $mapRecord($pr2);
            $recordsHistory[] = $item;
            if ((int)$pr2['doctor_id'] === (int)$u['id']) {
                $mineLatest = $item;
                // 跨科室只读查看：一律不设可编辑文书（dept_match=0，全只读展示）
                if ($crossDeptView) continue;
                // 当前上下文可编辑判定（与 dept_match 同规则）：
                // 会诊处理中 → 仅会诊病历可编辑；普通模式 → 书写科室==当前科室
                // 或 本人会诊文书且会诊未完毕（已完毕的会诊病历只读，不抢占编辑位）
                $editable = $consultMode
                    ? (int)$pr2['consultation_id'] === (int)$consultCtx['id']
                    : ((int)$pr2['dept_id'] === (int)$visit['current_dept_id']
                        || ((int)$pr2['consultation_id'] > 0 && EmrRepository::val(                            "SELECT COUNT(*) FROM consultations WHERE id=? AND visit_id=? AND status IN ('pending','doing')",
                            array((int)$pr2['consultation_id'], (int)$visit['id'])) > 0));
                if ($editable) $mine = $item;   // 最新可编辑者胜出
            }
        }
        if (!$mine) $mine = $mineLatest;   // 无当前科室可编辑文书 → 回退最新（只读展示+续写占位）

        // 结构化病历：严格取当前医生本人的记录（无则新建骨架，
        // 绝不回退他人病历——他人病历仅作上方只读展示，互不篡改）
        $pr = $mine ? EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($mine['id'])) : null;
        $emr = emr_merge_defaults(
            emr_normalize($pr ? json_decode($pr['emr_data'], true) : array()),
            emr_default_data($pr ? null : $patient)
        );
        // 归一化后补齐缺失键（旧数据 allergies 为空串时上面已转结构，其余键照常回退）
        $emr = emr_merge_defaults($emr, emr_default_data(null));
        $recordData = array(
            'record_id' => $mine ? $mine['id'] : 0,
            'doctor_id' => (int)$u['id'],
            'doctor_name' => $u['name'],
            'doctor_emp' => $doc ? $doc['emp_no'] : '',
            'doctor_title' => $doc ? $doc['title'] : '',
            // 文书类型：本次流水下已有他人病历时，当前医生的新文书为续写（progress）
            'record_type' => $pr ? (string)$pr['record_type'] : ($recordsHistory ? 'progress' : 'initial'),
            // 会诊记录关联 id（>0 即会诊病历，前端据此显示「会诊记录」徽章）
            'consultation_id' => $pr ? (int)(isset($pr['consultation_id']) ? $pr['consultation_id'] : 0) : 0,
            // 科室匹配：本人当前文书是否可编辑——
            // 跨科室只读查看（crossDeptView）→ 一律只读（dept_match=0）；
            // 会诊处理中：仅会诊病历（consultation_id=进行中会诊）可编辑；
            // 普通模式：书写科室==就诊当前科室（转科后旧文书不匹配 → 只读）
            'dept_match' => (!$crossDeptView && $pr && (
                ($consultMode
                    ? (int)$pr['consultation_id'] === (int)$consultCtx['id']
                    : ((int)$pr['dept_id'] === (int)$visit['current_dept_id']
                        || ((int)$pr['consultation_id'] > 0 && EmrRepository::val("SELECT COUNT(*) FROM consultations WHERE id=? AND visit_id=? AND status IN ('pending','doing')",
                            array((int)$pr['consultation_id'], (int)$visit['id'])) > 0))
                    )
            )) ? 1 : 0,
            'created_at' => $pr ? $pr['created_at'] : '',
            'updated_at' => $pr ? $pr['updated_at'] : '',
            'emr' => $emr,
            'status' => $pr ? $pr['status'] : 'draft',
        );
        // 意识状态/初复诊保存在旧 records 镜像表（结构化表不含这两项），
        // 必须回读，否则保存后刷新页面意识状态会丢失回「未选择」、初复诊回「初诊」。
        // 仅取当前医生本人的镜像行——多医生文书互不串写。
        $mirror = EmrRepository::one('SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visitId, $u['id']));
        $recordData['consciousness'] = $mirror ? (string)$mirror['consciousness'] : '';
        $recordData['visit_type'] = ($mirror && $mirror['visit_type'] !== '') ? $mirror['visit_type'] : '初诊';
        // 生命体征归属：按文书记录精确关联（record_id 优先，兼容旧数据）。
        // 续写/会诊病历各自独立体征——只取本记录关联的体征，绝不复用首诊体征。
        $myVitals = null;
        if ($pr && (int)$pr['id'] > 0) {
            $myVitals = EmrRepository::one('SELECT * FROM vitals WHERE record_id=? ORDER BY id DESC LIMIT 1', array((int)$pr['id']));
        }
        // 续写/会诊记录：无自身体征则恒为空（不回退就诊/首诊体征）；
        // 首诊记录才按 operator 回退就诊体征（护士站录入共用）
        $isPrgRec = $pr && $pr['record_type'] === 'progress';
        if (!$myVitals && !$isPrgRec) {
            $myVitals = EmrRepository::one('SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1', array($visitId, $u['name']));
        }
        $recordData['vitals'] = $myVitals ? $myVitals : array();
        // 扁平投影字段（主诉/现病史/初步诊断）：供诊断证明补开等旧字段消费方使用。
        // 结构化病历升级后 get 曾不再返回这些字段，导致「就诊历史→补开诊断证明」
        // 误判病历不完整（结构化升级残留缺陷）。优先由结构化 emr 投影生成；
        // 多医生场景下当前医生未填的节按时间倒序取流水内最新非空值（仅展示用），
        // 再无则回退 records 镜像表（兼容未结构化的历史病历）。
        $ccText = emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array());
        $piText = emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array());
        $diagText = emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array());
        if ($ccText === '' || $piText === '' || $diagText === '') {
            foreach (array_reverse($recordsHistory) as $rh) {
                if ($ccText === '') {
                    $t = emr_cc_text(isset($rh['emr']['chief_complaint']) ? $rh['emr']['chief_complaint'] : array());
                    if ($t !== '') $ccText = $t;
                }
                if ($piText === '') {
                    $t = emr_pi_text(isset($rh['emr']['history_present']) ? $rh['emr']['history_present'] : array());
                    if ($t !== '') $piText = $t;
                }
                if ($diagText === '') {
                    $t = emr_diag_text(isset($rh['emr']['diagnoses']) ? $rh['emr']['diagnoses'] : array());
                    if ($t !== '') $diagText = $t;
                }
                if ($ccText !== '' && $piText !== '' && $diagText !== '') break;
            }
        }
        $mirrorFlat = EmrRepository::one('SELECT chief_complaint, present_illness, preliminary_diagnosis FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        if ($ccText === '' && $mirrorFlat) $ccText = (string)$mirrorFlat['chief_complaint'];
        if ($piText === '' && $mirrorFlat) $piText = (string)$mirrorFlat['present_illness'];
        // 初步诊断直接使用投影文本——诊断名称本身已含 ICD-10 编码前缀
        // （如「M51.9 腰椎间盘突出」），无需再以括号追加编码（避免重复）。
        if ($diagText === '' && $mirrorFlat) {
            $diagText = (string)$mirrorFlat['preliminary_diagnosis'];
        }
        $recordData['chief_complaint'] = $ccText;
        $recordData['present_illness'] = $piText;
        $recordData['preliminary_diagnosis'] = $diagText;

        // 生命体征归属：当前登录医生本人录入的最新体征（operator=本人姓名），
        // 未录入则为空（前端显示 -）。多医生接诊下谁的体征归属谁的文书。
        $vitalsData = $recordData['vitals'] ? $recordData['vitals'] : array(
            'vital_sbp' => '', 'vital_dbp' => '', 'vital_heart_rate' => '',
            'vital_pulse' => '', 'vital_spo2' => '', 'vital_respiration' => '',
        );

        // 该患者全部既往病历（跨就诊，供转科一键引用；附带 content 供前端模板方式填充）
        $prevRows = EmrRepository::q('SELECT * FROM patient_records WHERE patient_no=? ORDER BY id DESC LIMIT 20', array($patient['patient_no']));
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
        $certRow = EmrRepository::one('SELECT cert_no, content, doctor_name, created_at FROM certificates WHERE visit_id=? ORDER BY id DESC', array($visitId));

        json_ok(array(
            'diag_order' => diag_order_keys($visitId, $u['id']),   // 本人诊断聚合显示顺序（跨医生排序载体，独立存储）
            // 只读查看标志（跨科室绝对只读，纯状态驱动）：前端据此全锁死，
            // 且与诊毕不同——本模式不允许补开诊断证明
            'readonly_view' => $crossDeptView ? 1 : 0,
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
                'id' => oid($visit['id']),   // 混淆串：前端 certificateModal 等回传时后端 did 解码
                'name' => $patient['name'],
                'gender' => $patient['gender'],
                'age' => (int)$patient['age'],
                'age_fmt' => age_format($patient['birth_date'], $visit['registered_at']),
                'dept_type' => $deptType,
                'dept_name' => $deptName,
                'first_dept_name' => (string)$visit['first_dept_name'],
                'current_dept_id' => (int)$visit['current_dept_id'],
                'visit_no' => $visit['flow_no'],
                'visit_seq' => (int)$visit['visit_seq'],
                'fee_type' => (string)(isset($visit['fee_type']) ? $visit['fee_type'] : ''),   // 费用类别（自费/居民医保/职工医保/其他），横条徽章展示
                'fee' => (float)(isset($visit['fee']) ? $visit['fee'] : 0),   // 挂号费（横条总费用徽章 = 挂号费 + 开单合计）
                'status' => $visit['status'],   // 就诊状态：finished 表示已诊毕（前端据此将病历置为只读）
                'created_at' => $visit['registered_at'],
            ),
            'record' => $recordData,
            // ===== 统一病历上下文（SSOT）=====
            // 前端据此派生全部写操作能力（编辑器只读/开单面板/删除按钮等），
            // 状态单向派生：UI 不维护独立布尔，完全由 active_context 驱动。
            'active_context' => EmrContextResolver::resolve($visit, $u, $pr),
            // 会诊模式锁定（后端权威）：当前医生对该就诊是否处于会诊处理中。
            // 前端据此进入会诊模式——刷新后依然有效（不依赖 URL 参数）。
            'consult_mode' => $consultMode,
            'consult_code' => $consultCode,
            // ===== 多医生接诊（1:N）三件套 =====
            // records_history：该挂号流水下全部病历（按创建时间升序，含医生姓名/
            // 工号职称/文书类型/主诊断/完整结构化数据）——前端据此渲染前序病历
            // 只读查看区（谁书写谁签名，互不篡改）。
            'records_history' => $recordsHistory,
            // 会诊：本次就诊关联的会诊（id/发起医生/目标科室/状态），前端在
            // 病历门诊处置中显示「请X科会诊」并驱动右侧会诊列表
            'consults' => array_map(function ($cc) {
                return array(
                    'id' => (int)$cc['id'],
                    'code' => oid($cc['id']),
                    'from_doctor_id' => (int)$cc['from_doctor_id'],
                    'from_dept_name' => (string)$cc['from_dept_name'],
                    'target_dept_id' => (int)$cc['target_dept_id'],
                    'target_dept_name' => (string)$cc['target_dept_name'],
                    'status' => (string)$cc['status'],
                    'record_id' => (int)(isset($cc['record_id']) ? $cc['record_id'] : 0),
                    'accepted_by' => (string)$cc['accepted_by'],
                );
            }, EmrRepository::q('SELECT id, from_doctor_id, from_dept_name, target_dept_id, target_dept_name, status, record_id, accepted_by FROM consultations WHERE visit_id=? ORDER BY id ASC', array($visitId))),
            // current_doctor_record：当前登录医生本人此前已保存的草稿/病历，
            // 无则 null——有则回显编辑，绝不回退他人病历。
            'current_doctor_record' => $mine,
            // global_patient_info：患者主表最新既往史/过敏史（任何医生保存后
            // 全局同步），供续写/首诊编辑器实时回显。
            'global_patient_info' => array(
                // 1=否认 0=承认（patients.has_past_history「否认/承认」的数值映射；
                // 空视为否认，与病历骨架默认一致）
                'past_history_denied' => (!isset($patient['has_past_history']) || $patient['has_past_history'] !== '承认') ? 1 : 0,
                'past_history' => (string)(isset($patient['past_history']) ? $patient['past_history'] : ''),
                'allergy_history' => (string)(isset($patient['allergy_history']) ? $patient['allergy_history'] : ''),
            ),
            'vitals' => $vitalsData,
            'prev_records' => $prevRecords,
            'has_certificate' => $certRow ? 1 : 0,
            // 已开具时附带证书数据（证明号/医生建议/开具时间 + 病历摘要快照），
            // 仅用于只读预览展示——有快照时预览与打印均以快照为准
            'certificate' => $certRow ? array(
                'cert_no' => (string)$certRow['cert_no'],
                'content' => (string)$certRow['content'],
                'doctor_name' => (string)$certRow['doctor_name'],
                'created_at' => (string)$certRow['created_at'],
                'chief_complaint' => (string)(isset($certRow['chief_complaint']) ? $certRow['chief_complaint'] : ''),
                'present_illness' => (string)(isset($certRow['present_illness']) ? $certRow['present_illness'] : ''),
                'preliminary_diagnosis' => (string)(isset($certRow['preliminary_diagnosis']) ? $certRow['preliminary_diagnosis'] : ''),
            ) : null,
            // 未开具时的「将固化内容」预览：与开具时写入证书的摘要同源
            // （首诊文书锚点），所见即所冻
            'cert_summary' => cert_snapshot_summary($visitId),
        ));
        return;
    }
}
