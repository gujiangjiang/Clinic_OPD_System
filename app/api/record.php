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
        // 病历续写（progress 文书专用）：续写内容为该文书顶部必填项；
        // 首诊（initial）文书中恒为空、不参与校验与打印
        'progress' => array('content' => ''),
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

/**
 * 诊断证明病历摘要快照（固化锚点）：以该挂号流水的【首诊文书】为
 * 唯一事实来源投影主诉/现病史/初步诊断——证书一经出具即冻结该内容，
 * 无论谁开具、谁补打、后续有多少次续写，展示与打印完全一致。
 * 无结构化病历时回退旧 records 镜像（兼容历史就诊）。
 */
function cert_snapshot_summary($visitId) {
    $pr = DB::one('medical', "SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC LIMIT 1", array($visitId));
    if ($pr) {
        $emr = json_decode($pr['emr_data'], true);
        if (is_array($emr)) {
            return array(
                'chief_complaint' => emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array()),
                'present_illness' => emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array()),
                'initial_diagnosis' => emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array()),
            );
        }
    }
    $rec = DB::one('medical', 'SELECT chief_complaint, present_illness, initial_diagnosis FROM records WHERE visit_id=? ORDER BY id ASC LIMIT 1', array($visitId));
    return array(
        'chief_complaint' => $rec ? (string)$rec['chief_complaint'] : '',
        'present_illness' => $rec ? (string)$rec['present_illness'] : '',
        'initial_diagnosis' => $rec ? (string)$rec['initial_diagnosis'] : '',
    );
}

/** 已开项目快照（与 /api/order visit_orders 同口径，排除已退费/已取消；
 * 多医生接诊：$doctorId>0 时仅取该医生本人开具的项目——谁开单归属谁的病历）
    返回 [检验检查名列表, 处方行列表, 处置项列表] */
function emr_order_snapshot($visitId, $doctorId = 0) {
    $sql = 'SELECT * FROM orders WHERE visit_id=?';
    $params = array($visitId);
    if ((int)$doctorId > 0) { $sql .= ' AND doctor_id=?'; $params[] = (int)$doctorId; }
    $sql .= ' ORDER BY id ASC';   // 与 visit_orders 同口径：新开项目追加在列表末尾
    $orders = DB::q('order', $sql, $params);
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
                // 处方行统一走公共方法：成组医嘱树形格式（子药含剂量，
                // 组内频次/途径/数量仅主药行一次），全系统同一套规则
                foreach (emr_rx_display_lines($items) as $l) { $rxLines[] = $l; }
                break; // 该处方单已整单处理，无需逐条重复
            }
        }
    }
    return array($orderNames, $rxLines, $dispItems);
}

switch ($action) {

    /* ==================== 加载病历 ==================== */
    case 'get':
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $patient = $row['patient'];
        // 科室数据隔离：非挂号科室医生不能查看当前就诊（已诊毕归档可查看）
        if (!visit_dept_authorized($visit, $u)) {
            json_fail('您无权查看该患者的当前就诊（就诊科室不在您的权限范围内）');
        }

        // 当前科室（可能已转科，显示当前就诊科室）
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $deptName = $dept ? $dept['name'] : $visit['first_dept_name'];
        $deptType = $dept ? $dept['type'] : 'clinic';

        // 医生信息（工号/职称，需求18.2：工作站显示医生姓名工号职称）
        $doc = DB::one('user', 'SELECT emp_no, title FROM users WHERE id=?', array($u['id']));

        // ===== 多医生接诊（1:N）：该挂号流水下全部病历（按创建时间升序） =====
        // 每位医生各自拥有独立文书：谁书写谁签名；前序病历对后序医生只读展示。
        $allRows = DB::q('medical', 'SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC', array($visitId));
        // 补齐各文书医生的工号/职称（users 与 medical 分库，不能 JOIN，按 id 批量查询）
        $docIds = array();
        foreach ($allRows as $pr2) { $docIds[(int)$pr2['doctor_id']] = true; }
        $docMeta = array();
        if ($docIds) {
            $ph = implode(',', array_fill(0, count($docIds), '?'));
            foreach (DB::q('user', "SELECT id, emp_no, title FROM users WHERE id IN ($ph)", array_keys($docIds)) as $dm) {
                $docMeta[(int)$dm['id']] = $dm;
            }
        }
        // 补齐各文书的书写科室名（转科后各文书科室可能不同，按文书自身 dept_id 归属）
        $deptIds = array();
        foreach ($allRows as $pr2) { if ((int)$pr2['dept_id'] > 0) $deptIds[(int)$pr2['dept_id']] = true; }
        $deptNames = array();
        if ($deptIds) {
            $ph2 = implode(',', array_fill(0, count($deptIds), '?'));
            foreach (DB::q('dept', "SELECT id, name FROM departments WHERE id IN ($ph2)", array_keys($deptIds)) as $dn) {
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
            // 生命体征归属：按录入护士/医生（operator）匹配本医生录入的体征，取最新一条。
            // 多医生接诊下谁的体征归属谁的文书——未录入则返回空（前端显示 -）。
            $ownVitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1',
                array((int)$pr2['visit_id'], (string)$pr2['doctor_name']));
            // 意识状态/初复诊按文书医生本人从旧 records 镜像表回读
            $mirror = DB::one('medical', 'SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC',
                array((int)$pr2['visit_id'], (int)$pr2['doctor_id']));
            return array(
                'id' => (int)$pr2['id'],
                'record_id' => (int)$pr2['id'],
                'doctor_id' => (int)$pr2['doctor_id'],
                'doctor_name' => (string)$pr2['doctor_name'],
                'doctor_emp' => $meta ? (string)$meta['emp_no'] : '',
                'doctor_title' => $meta ? (string)$meta['title'] : '',
                'dept_name' => isset($deptNames[(int)$pr2['dept_id']]) ? $deptNames[(int)$pr2['dept_id']] : '',
                'record_type' => ($pr2['record_type'] === 'progress') ? 'progress' : 'initial',
                'parent_record_id' => (int)$pr2['parent_record_id'],
                'primary_icd10' => (string)$pr2['primary_icd10'],
                'primary_diagnosis' => (string)$pr2['primary_diagnosis'],
                'status' => (string)$pr2['status'],
                'created_at' => (string)$pr2['created_at'],
                'updated_at' => (string)$pr2['updated_at'],
                'emr' => $emr2,
                'vitals' => $ownVitals ? $ownVitals : array(),
                'consciousness' => $mirror ? (string)$mirror['consciousness'] : '',
            );
        };
        $recordsHistory = array();
        $mine = null;
        foreach ($allRows as $pr2) {
            $item = $mapRecord($pr2);
            $recordsHistory[] = $item;
            if ((int)$pr2['doctor_id'] === (int)$u['id']) {
                $mine = $item; // 当前医生在该流水下自己的文书（草稿或已保存）
            }
        }

        // 结构化病历：严格取当前医生本人的记录（无则新建骨架，
        // 绝不回退他人病历——他人病历仅作上方只读展示，互不篡改）
        $pr = $mine ? DB::one('medical', 'SELECT * FROM patient_records WHERE id=?', array($mine['id'])) : null;
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
            'created_at' => $pr ? $pr['created_at'] : '',
            'updated_at' => $pr ? $pr['updated_at'] : '',
            'emr' => $emr,
            'status' => $pr ? $pr['status'] : 'draft',
        );
        // 意识状态/初复诊保存在旧 records 镜像表（结构化表不含这两项），
        // 必须回读，否则保存后刷新页面意识状态会丢失回「未选择」、初复诊回「初诊」。
        // 仅取当前医生本人的镜像行——多医生文书互不串写。
        $mirror = DB::one('medical', 'SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visitId, $u['id']));
        $recordData['consciousness'] = $mirror ? (string)$mirror['consciousness'] : '';
        $recordData['visit_type'] = ($mirror && $mirror['visit_type'] !== '') ? $mirror['visit_type'] : '初诊';
        // 生命体征归属：仅取当前登录医生本人录入的最新体征（operator=本人姓名），
        // 未录入则为空（前端显示 -）。多医生接诊下谁的体征归属谁的文书。
        $myVitals = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1', array($visitId, $u['name']));
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
        $mirrorFlat = DB::one('medical', 'SELECT chief_complaint, present_illness, initial_diagnosis FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
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

        // 生命体征归属：当前登录医生本人录入的最新体征（operator=本人姓名），
        // 未录入则为空（前端显示 -）。多医生接诊下谁的体征归属谁的文书。
        $vitalsData = $recordData['vitals'] ? $recordData['vitals'] : array(
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
            'diag_order' => diag_order_keys($visitId, $u['id']),   // 本人诊断聚合显示顺序（跨医生排序载体，独立存储）
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
                'age_fmt' => age_format($patient['birth_date'], $visit['register_time']),
                'dept_type' => $deptType,
                'dept_name' => $deptName,
                'current_dept_id' => (int)$visit['current_dept_id'],
                'visit_no' => $visit['flow_no'],
                'visit_seq' => (int)$visit['visit_seq'],
                'fee_type' => (string)(isset($visit['fee_type']) ? $visit['fee_type'] : ''),   // 费用类别（自费/居民医保/职工医保/其他），横条徽章展示
                'fee' => (float)(isset($visit['fee']) ? $visit['fee'] : 0),   // 挂号费（横条总费用徽章 = 挂号费 + 开单合计）
                'status' => $visit['status'],   // 就诊状态：finished 表示已诊毕（前端据此将病历置为只读）
                'created_at' => $visit['register_time'],
            ),
            'record' => $recordData,
            // ===== 多医生接诊（1:N）三件套 =====
            // records_history：该挂号流水下全部病历（按创建时间升序，含医生姓名/
            // 工号职称/文书类型/主诊断/完整结构化数据）——前端据此渲染前序病历
            // 只读查看区（谁书写谁签名，互不篡改）。
            'records_history' => $recordsHistory,
            // current_doctor_record：当前登录医生本人此前已保存的草稿/病历，
            // 无则 null——有则回显编辑，绝不回退他人病历。
            'current_doctor_record' => $mine,
            // global_patient_info：患者主表最新既往史/过敏史（任何医生保存后
            // 全局同步），供续写/首诊编辑器实时回显。
            'global_patient_info' => array(
                // 1=否认 0=承认（patients.past_history_type「否认/承认」的数值映射；
                // 空视为否认，与病历骨架默认一致）
                'past_history_denied' => (!isset($patient['past_history_type']) || $patient['past_history_type'] !== '承认') ? 1 : 0,
                'past_history_detail' => (string)(isset($patient['past_history_detail']) ? $patient['past_history_detail'] : ''),
                'allergies' => (string)(isset($patient['allergies']) ? $patient['allergies'] : ''),
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
                'initial_diagnosis' => (string)(isset($certRow['initial_diagnosis']) ? $certRow['initial_diagnosis'] : ''),
            ) : null,
            // 未开具时的「将固化内容」预览：与开具时写入证书的摘要同源
            // （首诊文书锚点），所见即所冻
            'cert_summary' => cert_snapshot_summary($visitId),
        ));
        break;

    /* ==================== 保存病历（结构化） ====================
     * 前端仅提交完整 emr_data JSON 对象；后端：
     * 1) 服务端权威判定文书类型（initial 首诊 / progress 续写，1:N 多医生接诊）
     * 2) 按文书类型分支校验必填（首诊=主诉/现病史/诊断；续写=病历续写内容/诊断）
     * 3) 业务防御清洗（否认既往史强制清空细节）
     * 4) 投影字段提取（主诊断=本医生诊断列表第 1 项）→ 打印文本生成
     * 5) 事务写 patient_records（含 record_type/parent_record_id）+ records 镜像；
     *    同步 patients 全局既往史/过敏史（跨就诊自动调用，以最新为准） */

    /* ==================== 新建续写文书（本人病情变化自续写 / 接诊续写） ====================
     * 本人已有文书后，点击「病历节点 +」→ 创建一条新的 progress 文书骨架
     * （record_type=progress，parent_record_id=本人最近一条文书）。
     * 续写次数不受限制（可多段续写），但创建前必须已完善并保存当前文书的必填项
     * （首诊=主诉/现病史/初步诊断；续写=病历续写内容/初步诊断），防止空续写堆积。
     * 鉴权：仅当前登录医生本人可创建。 */
    case 'create_progress':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        // 归档锁定：已诊毕(归档)不可创建续写
        if ($visit['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可创建续写');
        }
        // 本人最近一条文书（首诊或上一次续写）——作为续写的父记录
        $ownLatest = DB::one('medical', 'SELECT id, record_type, emr_data FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
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
        $pdo = DatabaseManager::pdo('medical');
        try {
            $pdo->beginTransaction();
            // patient_records：空骨架（status=draft，正文为空，保存时填充）
            $pdo->prepare('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'progress', (int)$ownLatest['id'], $cleanJson, '', 'draft', $now, $now));
            // records 旧镜像表同步占位（兼容既有消费方）
            $pdo->prepare('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute(array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'draft', $now, $now));
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('续写病历创建失败：' . $ex->getMessage());
        }
        json_ok(array(), '续写病历已创建');
        break;

    case 'save':
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
        $otherCount = (int)DB::val('medical', 'SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id<>?', array($visitId, $u['id']));
        if ($editRecordId > 0) {
            // 编辑本人指定文书（切换回旧首诊/旧续写）——校验归属
            $ownRow = DB::one('medical', 'SELECT id, record_type FROM patient_records WHERE id=? AND doctor_id=?', array($editRecordId, $u['id']));
            if (!$ownRow) json_fail('病历记录不存在或无权编辑');
            $recordType = $ownRow['record_type'];
        } else {
            $ownRow = DB::one('medical', 'SELECT id, record_type FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
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
            $myEarlier = $ownRow ? DB::one('medical', 'SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? AND id<? ORDER BY id DESC LIMIT 1', array($visitId, $u['id'], $ownRow['id'])) : null;
            $parentRow = $myEarlier
                ? $myEarlier
                : DB::one('medical', 'SELECT id FROM patient_records WHERE visit_id=? AND doctor_id<>? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        }
        $parentRecordId = $parentRow ? (int)$parentRow['id'] : 0;

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
        $vitalsRow = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1', array($visitId, $u['name']));
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

        // ===== 6. 事务写入（medical 库：patient_records + records 镜像同库） =====
        $now = now_str();
        $pdo = DatabaseManager::pdo('medical');
        try {
            $pdo->beginTransaction();

            // A. patient_records 写入/更新
            // 更新：仅刷新内容投影，record_type/parent_record_id 维持原值
            // （不重写历史）；新增：写入服务端判定的文书类型与父记录 id。
            $pr = null;
            if ($editRecordId > 0) {
                $pr = array('id' => $editRecordId);   // 切换回旧文书：精确更新指定记录
            } else {
                $pr = DB::one('medical', 'SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
            }
            if ($pr && !$progressNew) {
                $pdo->prepare('UPDATE patient_records SET main_symptom=?, symptom_duration=?, symptom_unit=?, informant=?, arrival_way=?, has_past_history=?, allergies=?, is_leave_hospital=?, primary_icd10=?, primary_diagnosis=?, emr_data=?, emr_print_text=?, status=?, updated_at=? WHERE id=?')
                    ->execute(array($mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $pr['id']));
                $recordId = (int)$pr['id'];
            } else {
                $pdo->prepare('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, main_symptom, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergies, is_leave_hospital, primary_icd10, primary_diagnosis, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], $recordType, $parentRecordId, $mainSymptom, $symptomDuration, $symptomUnit, $informant, $arrivalWay, $hasPastHistory, $allergies, $isLeaveHospital, $primaryIcd10, $primaryDiagnosis, $cleanJson, $printText, $finish ? 'done' : 'draft', $now, $now));
                $recordId = (int)$pdo->lastInsertId();
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
                'initial_diagnosis' => emr_diag_text($diagnoses),
                'diagnosis_code' => $primaryIcd10,
                'is_observation' => $isLeaveHospital === '是' ? 1 : 0,
                'visit_type' => $visitType,
                'advice' => $emr['advice'],
                'status' => $finish ? 'done' : 'draft',
                'updated_at' => $now,
            );
            // 旧 records 表扁平镜像：patient_record_id 精确归属对应文书
            // （同医生多文书：首诊+多段续写各有一条镜像，编辑旧文书精确回写）
            $old = null;
            if ($recordId > 0) {
                $old = DB::one('medical', 'SELECT id FROM records WHERE patient_record_id=?', array($recordId));
            }
            if (!$old) {
                $old = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
            }
            if ($old && !$progressNew) {
                $set = array();
                $params = array();
                foreach ($mirror as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
                $params[] = $old['id'];
                $pdo->prepare('UPDATE records SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);
                $oldRecordId = (int)$old['id'];
            } else {
                $cols = 'visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, patient_record_id, ' . implode(',', array_keys($mirror)) . ', created_at';
                $marks = '?,?,?,?,?,?,?, ' . implode(',', array_fill(0, count($mirror), '?')) . ',?';
                $params = array($visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], $recordId);
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

        // C2. 保存病历即视为接诊：若就诊状态仍为待就诊(paid)，标记为就诊中(visiting)
        // （以「是否存在病历」判定是否就诊，而非打开页面即算）
        if (!$finish && isset($visit['status']) && $visit['status'] === 'paid') {
            DB::exec('patient', 'UPDATE registrations SET status=? WHERE id=?', array('visiting', $visitId));
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
            DB::exec('patient', 'UPDATE registrations SET status=?, disposition=?, disposition_detail=?, finish_time=?, payment_time=COALESCE(payment_time,?) WHERE id=?',
                array('finished', $disposition, $dispDetail, now_str(), now_str(), $visitId));
            json_ok(array('finished' => 1, 'record_id' => $recordId), '病历已保存并诊毕');
        }
        json_ok(array('finished' => 0, 'record_id' => $recordId), '病历已保存');
        break;

    /* ==================== 保存生命体征（医生站/护士站共用） ==================== */
    case 'save_vitals':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 归档锁定：已诊毕(归档)不可再录入生命体征
        if ($row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可再录入生命体征');
        }
        // 数值校验（与服务端同规则）：非负整数、生理合理区间；留空视为未测
        $spec = array(
            'bp_systolic'  => array(post('bp_systolic', 0), 1, 300, '收缩压'),
            'bp_diastolic' => array(post('bp_diastolic', 0), 1, 250, '舒张压'),
            'heart_rate'   => array(post('heart_rate', ''), 1, 300, '心率'),
            'pulse'        => array(post('pulse', ''), 1, 300, '脉搏'),
            'spo2'         => array(post('spo2', ''), 1, 100, '血氧饱和度'),
            'respiration'  => array(post('respiration', ''), 1, 100, '呼吸'),
        );
        $clean = array();
        foreach ($spec as $k => $c) {
            $raw = trim((string)$c[0]);
            if ($raw === '') { $clean[$k] = ($k === 'bp_systolic' || $k === 'bp_diastolic') ? 0 : ''; continue; }
            if (!preg_match('/^\d+$/', $raw)) json_fail($c[3] . '须为非负整数（不留小数 / 负数 / 单位）');
            $n = (int)$raw;
            if ($n !== 0 && ($n < $c[1] || $n > $c[2])) json_fail($c[3] . '超出合理范围（' . $c[1] . '-' . $c[2] . '）');
            $clean[$k] = ($k === 'bp_systolic' || $k === 'bp_diastolic') ? $n : (string)$n;
        }
        DB::insert('nurse', 'INSERT INTO vitals(visit_id, patient_no, flow_no, bp_systolic, bp_diastolic, heart_rate, pulse, spo2, respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'],
            $clean['bp_systolic'], $clean['bp_diastolic'],
            $clean['heart_rate'], $clean['pulse'], $clean['spo2'], $clean['respiration'],
            $u['name'], now_str(),
        ));
        json_ok(array(), '生命体征已保存');
        break;

    /* ==================== 诊断排序保存（跨医生全局显示顺序，独立存储不引用） ==================== */
    case 'save_diag_order':
        $visitId = did(post('visit_id'));
        if (!get_visit_row($visitId)) json_fail('就诊记录不存在');
        $keys = json_decode((string)post('ord_keys', '[]'), true);
        if (!is_array($keys)) json_fail('排序数据无效');
        $clean = array();
        foreach ($keys as $k) {
            $k = trim((string)$k);
            if ($k !== '' && count($clean) < 100 && !in_array($k, $clean, true)) $clean[] = $k;
        }
        $exist = (int)DB::val('medical', 'SELECT id FROM diag_orders WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
        if ($exist > 0) {
            $pdo2 = DatabaseManager::pdo('medical');
            $pdo2->prepare('UPDATE diag_orders SET ord_keys=?, updated_at=? WHERE id=?')
                ->execute(array(implode("\n", $clean), now_str(), $exist));
        } else {
            DB::insert('medical', 'INSERT INTO diag_orders(visit_id, doctor_id, ord_keys, updated_at) VALUES(?,?,?,?)', array(
                $visitId, $u['id'], implode("\n", $clean), now_str(),
            ));
        }
        json_ok(array('diag_order' => $clean), '诊断顺序已保存');
        break;

    /* ==================== 诊断列表即时保存（侧边栏调整：增删/排序/主诊断） ==================== */
    case 'save_diags':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 归档锁定：已诊毕(归档)不可调整诊断
        if ($row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可调整诊断');
        }
        // 仅本人文书可调整，且未诊毕；切换回旧文书编辑时按 edit_record_id 精确定位
        $editDiagRecordId = (int)post('edit_record_id', 0);
        if ($editDiagRecordId > 0) {
            $pr = DB::one('medical', 'SELECT * FROM patient_records WHERE id=? AND doctor_id=?', array($editDiagRecordId, $u['id']));
        } else {
            $pr = DB::one('medical', 'SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        }
        if (!$pr) json_fail('您在该就诊下暂无病历文书');
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
        $pdo = DatabaseManager::pdo('medical');
        // 结构化文书更新（诊断 + 主诊断投影）
        $pdo->prepare('UPDATE patient_records SET emr_data=?, primary_icd10=?, primary_diagnosis=? WHERE id=?')
            ->execute(array(json_encode($emr, JSON_UNESCAPED_UNICODE), $firstCode, $diagText, $pr['id']));
        // 旧镜像表同步（最新一行）：注意镜像表 ICD 列名为 diagnosis_code；
        // 先查 id 再按 id 更新（避免 UPDATE 内子查询的兼容性问题）
        $mirrorId = (int)DB::val('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        if ($mirrorId > 0) {
            $pdo->prepare('UPDATE records SET initial_diagnosis=?, diagnosis_code=? WHERE id=?')
                ->execute(array($diagText, $firstCode, $mirrorId));
        }
        json_ok(array('diagnoses' => $clean), '诊断已更新');
        break;

    /* ==================== 删除病历记录（节点删除 + 生命周期约束） ====================
     * 业务规则：
     * 1. 身份越权：仅记录创建者本人可删除（403 语义）；
     * 2. 首诊锁定：若该就诊下已存在任何已保存的续写病程记录，则首诊不可删除
     *    （400 语义）——病历必须保留可追溯的首诊锚点；
     * 3. 续写节点：本人创建的续写可独立删除，不影响首诊及其余续写。
     * 删除同时清理旧 records 镜像（按 patient_record_id 精确匹配）。 */
    case 'delete_record':
        $visitId = did(post('visit_id'));
        $recordId = (int)post('record_id', 0);
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        if ($recordId <= 0) json_fail('参数错误');
        $rec = DB::one('medical', 'SELECT * FROM patient_records WHERE id=?', array($recordId));
        if (!$rec) json_fail('病历记录不存在');
        if ((int)$rec['visit_id'] !== (int)$visitId) json_fail('病历记录不属于本次就诊');
        // 0. 诊毕锁定：已诊毕的病历已归档，任何节点（含续写）一律不可删除（法律/合规底线）
        if ((string)$row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可删除');
        }
        // 1. 身份越权拦截
        if ((int)$rec['doctor_id'] !== (int)$u['id']) {
            json_fail('无权删除非本人创建的病历记录');
        }
        // 2. 首诊锁定校验：存在已保存的续写病程 → 禁止删除首诊
        if ($rec['record_type'] === 'initial') {
            $hasProgress = (int)DB::val('medical', "SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND record_type='progress' AND status<>'draft'", array($visitId));
            if ($hasProgress > 0) {
                json_fail('该病历已存在后续病程记录，不可删除首诊病历');
            }
        }
        // 2.5 诊断锁定：当前病历节点存在诊断则不允许删除（避免产生无主诊断），
        // 与未保存病历的拦截规则一致——需先删除该病历内的全部诊断
        $recEmr = emr_merge_defaults(emr_normalize(json_decode($rec['emr_data'], true)), emr_default_data(null));
        if (isset($recEmr['diagnoses']) && is_array($recEmr['diagnoses']) && count($recEmr['diagnoses'])) {
            json_fail('该病历已添加诊断，不可删除；请先删除该病历内的全部诊断后再删除病历');
        }
        // 3. 删除（物理删除 + 镜像清理）
        $pdo = DatabaseManager::pdo('medical');
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM patient_records WHERE id=?')->execute(array($recordId));
            // 清理该文书对应的旧镜像（精确匹配 patient_record_id；旧数据无关联时按 visit+doctor 最新兜底）
            $pdo->prepare('DELETE FROM records WHERE patient_record_id=?')->execute(array($recordId));
            $mirrorOld = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=? AND patient_record_id=0 ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
            if ($mirrorOld) {
                $pdo->prepare('DELETE FROM records WHERE id=?')->execute(array($mirrorOld['id']));
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('病历删除失败：' . $ex->getMessage());
        }
        // 4. 删除后：若该就诊已无任何病历 → 退回到未就诊状态（待就诊 paid）
        //    （以「是否存在病历」判定是否就诊：病历删除后即视为未就诊）
        if ($row['visit']['status'] === 'visiting') {
            $remain = (int)DB::val('medical', 'SELECT COUNT(*) FROM patient_records WHERE visit_id=?', array($visitId));
            if ($remain === 0) {
                DB::exec('patient', 'UPDATE registrations SET status=? WHERE id=?', array('paid', $visitId));
            }
        }
        json_ok(array('record_type' => $rec['record_type']), '病历记录已删除');
        break;

    /* ==================== 开具诊断证明（单次就诊一次） ==================== */
    case 'certificate':
        $visitId = did(post('visit_id'));
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
        // 病历摘要快照：开具瞬间以首诊文书为锚点固化主诉/现病史/初步诊断，
        // 证书内容从此不再随续写或后续修改变化（法律文书不可变性）
        $snap = cert_snapshot_summary($visitId);
        DB::insert('medical', 'INSERT INTO certificates(visit_id, patient_no, flow_no, doctor_id, doctor_name, content, created_at, cert_no, chief_complaint, present_illness, initial_diagnosis) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'], $u['id'], $u['name'], $content, now_str(), $certNo,
            $snap['chief_complaint'], $snap['present_illness'], $snap['initial_diagnosis'],
        ));
        json_ok(array('cert_no' => $certNo), '诊断证明已开具');
        break;

    /* ==================== 诊断证明打印 ==================== */
    case 'certificate_print':
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $cert = DB::one('medical', 'SELECT * FROM certificates WHERE visit_id=?', array($visitId));
        if (!$cert) json_fail('未开具诊断证明');
        $record = DB::one('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        // 固化快照：证书存有开具时的病历摘要则原样使用——无论谁开具、
        // 谁补打、后续有多少次续写，打印内容与开具时完全一致；
        // 历史证明（无快照列）回退原实时取数行为。
        if ((isset($cert['chief_complaint']) && $cert['chief_complaint'] !== '') ||
            (isset($cert['present_illness']) && $cert['present_illness'] !== '') ||
            (isset($cert['initial_diagnosis']) && $cert['initial_diagnosis'] !== '')) {
            $record = is_array($record) ? $record : array();
            $record['chief_complaint'] = $cert['chief_complaint'];
            $record['present_illness'] = $cert['present_illness'];
            $record['initial_diagnosis'] = $cert['initial_diagnosis'];
        }
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        json_ok(array('html' => pt_certificate($visit, $row['patient'], $record, $cert, $cert['doctor_name'])));
        break;

    /* ==================== 前序诊断查重（跨医生引用） ====================
     * 检索该挂号流水下前序【其他医生】已添加过的诊断，供前端在
     * 诊断模态框中弹出引用确认（引用后仍可修改部位/备注/疑似标记）。
     * 入参：visit_id（兼容 reg_id 别名）+ keyword（诊断名称或 ICD-10 编码，
     * 留空返回前序全部诊断）。匹配规则：名称或编码包含关键词（不区分大小写）。 */
    case 'check_previous_diagnoses':
        $visitId = did(get('visit_id') !== '' ? get('visit_id') : get('reg_id'));
        $kw = trim((string)get('keyword'));
        if (!$visitId) json_fail('缺少挂号流水参数');
        // 大小写归一化（服务器可能未启用 mbstring，见 helpers.php polyfill 说明；
        // 中文无大小写差异，ASCII 编码字母统一小写比较）
        $lc = function ($s) { return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); };
        $lkw = $lc($kw);
        $rows = DB::q('medical', 'SELECT * FROM patient_records WHERE visit_id=? AND doctor_id<>? ORDER BY id ASC', array($visitId, $u['id']));
        $list = array();
        foreach ($rows as $pr2) {
            $emr2 = json_decode($pr2['emr_data'], true);
            $dgs = (is_array($emr2) && isset($emr2['diagnoses']) && is_array($emr2['diagnoses'])) ? $emr2['diagnoses'] : array();
            foreach ($dgs as $dg) {
                if (!is_array($dg) || empty($dg['name'])) continue;
                $name = (string)$dg['name'];
                $code = isset($dg['code']) ? (string)$dg['code'] : '';
                if ($lkw !== '' && strpos($lc($name), $lkw) === false && strpos($lc($code), $lkw) === false) continue;
                $list[] = array(
                    'doctor_id' => (int)$pr2['doctor_id'],
                    'doctor_name' => (string)$pr2['doctor_name'],
                    'record_id' => (int)$pr2['id'],
                    'record_type' => ($pr2['record_type'] === 'progress') ? 'progress' : 'initial',
                    'created_at' => (string)$pr2['created_at'],
                    'name' => $name,
                    'code' => $code,
                    'part' => isset($dg['part']) ? (string)$dg['part'] : '',
                    'note' => isset($dg['note']) ? (string)$dg['note'] : '',
                    'suspected' => isset($dg['suspected']) ? (string)$dg['suspected'] : '',
                );
            }
        }
        json_ok(array('list' => $list, 'count' => count($list)));
        break;

    default:
        json_fail('未知操作');
}
