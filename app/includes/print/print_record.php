<?php
/** print/print_record.php — 统一打印模板：电子病历 */

function pt_record($visit, $patient, $record, $vitals, $mode = 'full', $isLast = true, $footRecordTime = null) {
    $title = (isset($visit['dept_type']) && $visit['dept_type'] === 'emergency') ? '急诊电子病历' : '门诊电子病历';
    $html = '';

    if ($mode === 'continue') {
        // 续写段承接头：一条虚线分割上下文书，下一行整行加粗、三段定位——
        // 左端「日期 时间」（该次续写首次保存时间）、居中「续写病历 / 会诊记录」、
        // 右端「科室」，随后直接接「病历续写：……」等续写正文
        $isConsultRec = (int)(isset($record['consultation_id']) ? $record['consultation_id'] : 0) > 0;
        // 科室：续写/会诊记录使用本记录自身的书写科室，而非就诊当前科室（转科/会诊后不同）
        $contDept = '';
        if (!empty($record['dept_id'])) {
            $dn = DB::one('SELECT name FROM departments WHERE id=?', array((int)$record['dept_id']));
            if ($dn) $contDept = (string)$dn['name'];
        }
        if ($contDept === '') $contDept = $visit['current_dept_name'] ?? '';
        $contTime = isset($record['created_at']) ? substr((string)$record['created_at'], 0, 16) : '';
        $html .= '<div class="print-record-cont">' .
            '<span class="prc-time">' . e($contTime) . '</span>' .
            '<span class="prc-title">' . ($isConsultRec ? '会诊记录' : '续写病历') . '</span>' .
            '<span class="prc-dept">' . e($contDept) . '</span>' .
            '</div>';
    } else {
        $html .= pt_header($title);

        // 右上角条形码（与挂号凭条一致：门诊号 flow_no，方便患者扫码缴费/打印报告）
        $code = isset($visit['flow_no']) && $visit['flow_no'] !== '' ? $visit['flow_no'] : (isset($patient['patient_no']) ? $patient['patient_no'] : '');
        if ($code !== '') {
            $html .= pt_barcode($code);
        }
    }

    // 患者信息：门诊为两栏网格；急诊为两行流式排版（第一行 姓名/性别/出生日期/年龄，
    // 第二行 患者ID/就诊科室/就诊时间），与病历编辑器完全一致，空值显示 —（所见即所得）
    // 仅首段（full）渲染——页眉与患者信息归首诊文书
    if ($mode === 'full') {
    $emergency = isset($visit['dept_type']) && $visit['dept_type'] === 'emergency';
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = pt_age_text($patient, $visit);
    if ($emergency) {
$info = '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        pt_info_cell('姓名', $name) . pt_info_cell('性别', $gender) .
        pt_info_cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
        pt_info_cell('年龄', $age) . '</div>' .
        '<div class="print-info-line">' .
        pt_info_cell('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '') .
        pt_info_cell('就诊科室', isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '') .
        pt_info_cell('就诊时间', isset($visit['registered_at']) ? $visit['registered_at'] : '') .
        '</div></div>';
    } else {
        $items = array(
            '姓名' => $name,
            '性别' => $gender,
            '年龄' => $age,
            '患者ID' => isset($patient['patient_no']) ? $patient['patient_no'] : '',
            '证件号码' => isset($patient['id_card']) ? $patient['id_card'] : '',
            '出生日期' => isset($patient['birth_date']) ? $patient['birth_date'] : '',
            '民族' => isset($patient['ethnicity']) ? $patient['ethnicity'] : '',
            '职业' => isset($patient['occupation']) ? $patient['occupation'] : '',
            '婚姻' => isset($patient['marital']) ? $patient['marital'] : '',
            '初复诊' => (isset($record['visit_type']) && $record['visit_type'] !== '') ? $record['visit_type'] : '初诊',
            '科室' => isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '',
            '联系方式' => isset($patient['phone']) ? $patient['phone'] : '',
        );
        $info = '<div class="print-info-grid">';
        foreach ($items as $k => $val) {
            $info .= pt_info_cell($k, $val);
        }
        $info .= '</div>';
    }
    // 患者信息上方横线：患者信息区上下各一条分隔线，版式清晰
    $html .= '<div class="print-line"></div>';
    $html .= $info;
    // 患者信息下方横线（与病历正文隔开）
    $html .= '<div class="print-line"></div>';
    }

    // ===== 结构化病历（patient_records.emr_data 存在时按结构化规则渲染）=====
    $emrStructured = false;
    $emr = array();
    if (!empty($record['emr_data'])) {
        $emr = json_decode($record['emr_data'], true);
        if (is_array($emr)) $emrStructured = true;
    }
    // 续写文书标识：续写内容置顶输出；主诉/现病史/主要症状/意识状态归首诊文书
    $isProgress = $emrStructured && isset($record['record_type']) && $record['record_type'] === 'progress';

    // 病历内容行内流式排版：主诉：xxx　现病史：xxx（紧凑省空间）
    $secs = array();
    if ($emrStructured) {
        // 会诊病历标识：consultation_id>0 的续写文书为会诊记录（打印显示「会诊记录」）
        $isConsultRec = (int)(isset($record['consultation_id']) ? $record['consultation_id'] : 0) > 0;
        if ($isProgress) {
            $progContent = isset($emr['progress']['content']) ? trim((string)$emr['progress']['content']) : '';
            if ($progContent !== '') $secs[] = array($isConsultRec ? '会诊记录' : '病历续写', e($progContent));
            // 续写：未填写（否认/空）的既往史/过敏史不显示，首诊维持原样
            $phT = emr_ph_text(isset($emr['past_history']) ? $emr['past_history'] : array());
            if ($phT !== '否认') $secs[] = array('既往史', $phT);
            $alT = emr_al_text(isset($emr['allergies']) ? $emr['allergies'] : array());
            if ($alT !== '否认') $secs[] = array('过敏史', $alT);
        } else {
        // 结构化：占位符剔除/空节隐藏/'-'回退等规则统一走 emr_formatter
        $secs[] = array('主诉', emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array()));
        $secs[] = array('现病史', emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array()));
        $secs[] = array('既往史', emr_ph_text(isset($emr['past_history']) ? $emr['past_history'] : array()));
        $secs[] = array('过敏史', emr_al_text(isset($emr['allergies']) ? $emr['allergies'] : array()));
        $msText = emr_ms_text(isset($emr['main_symptoms']) ? $emr['main_symptoms'] : array());
        if ($msText !== '') $secs[] = array('主要症状', e($msText));
        }
    } else {
        $secs[] = array('主诉', isset($record['chief_complaint']) ? $record['chief_complaint'] : '');
        $secs[] = array('现病史', isset($record['present_illness']) ? $record['present_illness'] : '');
        $secs[] = array('既往史', isset($record['past_history']) ? $record['past_history'] : '');
        $secs[] = array('过敏史', isset($record['allergy_history']) ? $record['allergy_history'] : '');
    }
    // 生命体征恒显示（未录入显示 -，首诊/续写一致）
    $vp = array();
    if (!empty($vitals['vital_sbp'])) $vp[] = '血压 ' . $vitals['vital_sbp'] . '/' . $vitals['vital_dbp'] . 'mmHg';
    if (!empty($vitals['vital_heart_rate'])) $vp[] = '心率 ' . $vitals['vital_heart_rate'] . '次/分';
    if (!empty($vitals['vital_pulse'])) $vp[] = '脉搏 ' . $vitals['vital_pulse'] . '次/分';
    if (!empty($vitals['vital_spo2'])) $vp[] = '血氧 ' . $vitals['vital_spo2'] . '%';
    if (!empty($vitals['vital_respiration'])) $vp[] = '呼吸 ' . $vitals['vital_respiration'] . '次/分';
        // 生命体征：首诊恒显示（未录入 -）；续写空节不显示
    if (!$isProgress || $vp) {
        $secs[] = array('生命体征', $vp ? implode('；', $vp) : '-');
    }
    // 意识状态：续写文书仅在本人镜像有值时输出（该节归首诊文书）
    if (!$isProgress || (isset($record['consciousness']) && $record['consciousness'] !== '')) {
        $secs[] = array('意识状态', isset($record['consciousness']) ? $record['consciousness'] : '');
    }
    if ($emrStructured) {
        // 体格检查：续写空节不显示（emr_pe_text 空时返回 '-'，需按原始数据判断）
        $peArr = isset($emr['physical_exam']) ? $emr['physical_exam'] : array();
        $peHas = false;
        foreach ((array)$peArr as $pv) { if ($pv !== '' && $pv !== null) { $peHas = true; break; } }
        if (!$isProgress || $peHas) $secs[] = array('体格检查', emr_pe_text($peArr));
        $secs[] = array('初步诊断', emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array()));
    } else {
        $peT2 = isset($record['physical_exam']) ? $record['physical_exam'] : '';
        if (!$isProgress || trim((string)$peT2) !== '') $secs[] = array('体格检查', $peT2);
        $diag = isset($record['preliminary_diagnosis']) ? $record['preliminary_diagnosis'] : '';
        if (isset($record['icd10_code']) && $record['icd10_code']) {
            $diag .= '（' . $record['icd10_code'] . '）';
        }
        $secs[] = array('初步诊断', $diag);
    }

    // 已开项目所见即所得：辅助检查（检验/检查）+ 门诊处置（处置/处方），与病历编辑页一致
    // 辅助检查：仅显示项目名称；处置：不换行显示名称×数量；处方：每行一个药品（名称/剂量/用法/途径/数量）
    // 多医生接诊：已开项目按该文书医生本人过滤（谁开单归属谁的病历）
    // 开单与病历强关联：仅打印本记录（record_id）名下开单；旧数据（record_id=0）回退按医生归属
    // 开单是病历文书的法律快照：退费只影响费用结算，不影响已开具的文书——
    // 退费项目仍打印（不标注，病历客观记录开单事实），仅已取消（cancelled）不打印
    $aux = array();
    $procs = array();
    $rxs = array();
    $recPrintId = (int)(isset($record['id']) ? $record['id'] : 0);
    $orderSql = "SELECT * FROM orders WHERE visit_id=? AND status NOT IN ('cancelled')";
    $orderParams = array($visit['id']);
    if (!empty($record['doctor_id'])) {
        $orderSql .= ' AND doctor_id=?';
        $orderParams[] = (int)$record['doctor_id'];
    }
    $orderSql .= " AND (record_id=? OR record_id=0)";
    $orderParams[] = $recPrintId;
    $orderSql .= ' ORDER BY id ASC';
    $orders = DB::q($orderSql, $orderParams);
    foreach ($orders as $o) {
        $its = DB::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
        foreach ($its as $it) {
            if ($it['item_name'] === '' || $it['item_name'] === null) continue; // 防空名明细
            if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
                $aux[] = e($it['item_name']);
            } elseif ($o['order_type'] === 'procedure') {
                $procs[] = e($it['item_name']) . '×' . (int)$it['quantity'];
            }
        }
        // 处方行统一走公共方法：成组医嘱树形格式（主药全要素、子药 ├─/└─ 缩进含剂量，
        // 组内频次/途径/数量仅主药行一次），与病历编辑页、打印快照全系统一致
        if ($o['order_type'] !== 'prescription') continue;
        foreach (emr_rx_display_lines($its) as $l) { $rxs[] = e($l); }
    }
    // 会诊：本人发起的会诊在门诊处置中显示「请X科会诊」（与病历编辑页一致）
    // 会诊与病历强关联：仅打印本记录（record_id）发起的会诊；旧数据（record_id=0）回退按医生归属
    $consRows = DB::q("SELECT target_dept_name, record_id FROM consultations WHERE visit_id=? AND from_doctor_id=? ORDER BY id ASC", array($visit['id'], (int)$record['doctor_id']));
    foreach ($consRows as $cr) {
        $crRec = (int)(isset($cr['record_id']) ? $cr['record_id'] : 0);
        if ($recPrintId > 0 && $crRec > 0 && $crRec !== $recPrintId) continue;
        $procs[] = '请' . e($cr['target_dept_name']) . '会诊';
    }
    if ($emrStructured) {
        // 结构化：辅助检查 = 已开项目 + 手工结果 + 外院结果；门诊处置 = 处方行 + 处置(含数量) + 自定义
        // 开单与病历强关联（record_id 过滤）：首诊/续写/会诊均打印各自名下开单
        // （退费项目作为法律快照同样保留，不标注——病历客观记录开单事实）
        $manualAux = array();
        foreach (array('aux_result', 'aux_external') as $k) {
            if (isset($emr[$k]) && $emr[$k] !== '') $manualAux[] = e($emr[$k]);
        }
        $auxAll = array_merge($aux, $manualAux);
        // 辅助检查：续写空节不显示
        if (!$isProgress || $auxAll) {
            $secs[] = array('辅助检查', $auxAll ? implode('，', $auxAll) : '-');
        }
        $treat = '';
        if ($rxs) foreach ($rxs as $rx) $treat .= '<div class="pf-rx-line">' . $rx . '</div>';
        $dispParts = array_merge($procs, isset($emr['disposition_custom']) && $emr['disposition_custom'] !== '' ? array(e($emr['disposition_custom'])) : array());
        if ($dispParts) $treat .= '<span class="pf-treat-proc">' . implode('，', $dispParts) . '</span>';
        // 门诊处置：续写空节不显示
        if (!$isProgress || $treat !== '') {
            $secs[] = array('门诊处置', $treat !== '' ? $treat : '-');
        }
    } else {
        if ($aux) $secs[] = array('辅助检查', implode('、', $aux));
        $treat = '';
        if ($procs) $treat .= '<span class="pf-treat-proc">' . implode('　', $procs) . '</span>';
        foreach ($rxs as $rx) $treat .= '<div class="pf-rx-line">' . $rx . '</div>';
        if ($treat !== '') $secs[] = array('门诊处置', $treat);
    }

    $secs[] = array('是否留观', $emrStructured ? emr_obs_text($emr) : (!empty($record['is_observation']) ? '是' : '否'));
    // 嘱托：续写空节不显示
    $adviceT = $emrStructured ? (isset($emr['advice']) ? $emr['advice'] : '') : (isset($record['doctor_advice']) ? $record['doctor_advice'] : '');
    if (!$isProgress || trim((string)$adviceT) !== '') $secs[] = array('嘱托', $adviceT);

    // 每个小节独立一个 .print-flow 块级节点：A5 分页器按「整节点」分配页面，
    // 若所有小节包在同一个节点里，内容再长也永远不会跨页拆分，
    // 只会在单页内溢出被裁掉。拆成逐节节点后可在小节边界自然翻页。
    foreach ($secs as $s) {
        $html .= '<div class="print-flow"><span class="pf-sec"><strong>' . e($s[0]) . '：</strong><span class="pf-body">' . $s[1] . '</span></span></div>';
    }

    // 医生签名：紧跟本段病历正文末尾、右下角。类名用 .print-rec-sign
    // （不进 A5 分页器页脚集合）——签名随段不沉整页底；多文书时各段
    // 签名紧贴各自正文，页脚仅最后一段输出。
    $html .= '<div class="print-rec-sign">医生：' . e(isset($record['doctor_name']) ? $record['doctor_name'] : '') . '</div>';

    // 页脚（末尾横线 + 左下角记录时间/右下角打印时间）：整份连续文档仅输出一次。
    // 记录时间统一为【首诊医师首次保存】时间（由调用方传入 $footRecordTime，
    // 多文书时取首段文书 created_at，不随续写改变）；
    // 单文书/旧数据回退本文书 created_at，再回退 updated_at。
    if ($isLast) {
        $recTime = $footRecordTime !== null && $footRecordTime !== ''
            ? $footRecordTime
            : (isset($record['created_at']) && $record['created_at'] !== '' ? $record['created_at']
                : (isset($record['updated_at']) ? $record['updated_at'] : ''));
        $html .= '<div class="print-line"></div>';
        $html .= '<div class="print-record-foot">' .
            '<span>记录时间：' . e($recTime) . '</span>' .
            '<span>打印时间：' . now_str() . '</span></div>';
    }
    return $html;
}
