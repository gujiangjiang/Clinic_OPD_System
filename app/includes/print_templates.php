<?php
/**
 * ============================================================
 * print_templates.php v1.0.0 — 统一打印模板
 * ============================================================
 * 说明：所有单据打印（挂号凭条/缴费凭条/检验检查申请单/处方单/
 * 处置单/检验检查报告/诊断证明/电子病历）统一由本文件生成 HTML，
 * 前端 print.js 渲染到 #print-area 后调用 window.print()。
 * 打印样式见 print.css（@media print 只显示打印区域）。
 * ============================================================ */

/**
 * 条形码生成已独立到 app/core/barcode.php（barcode128_svg，纯 PHP Code 128 / SVG），
 * 由 bootstrap.php 全站加载，本文件直接调用即可。
 */

/** 竖向小票页头（医院名称/第二名称/单据标题，居中） */
function pt_ticket_header($title) {
    $hosp = setting('hospital_name', '');
    $hosp2 = setting('hospital_name2', '');
    $h = '<div class="ticket-hosp">' . e($hosp) . '</div>';
    if ($hosp2 !== '') {
        $h .= '<div class="ticket-hosp2">' . e($hosp2) . '</div>';
    }
    $h .= '<div class="ticket-title">' . e($title) . '</div>';
    return $h;
}

/** 竖向小票键值行（label 左、值右） */
function pt_ticket_row($label, $value) {
    return '<div class="ticket-row"><span>' . e($label) . '</span><span class="ticket-val">' . e($value) . '</span></div>';
}

/** 打印页头（医院名称/第二名称/单据标题） */
function pt_header($title) {
    $hosp = setting('hospital_name', '');
    $hosp2 = setting('hospital_name2', '');
    $h = '<div class="print-hosp">' . e($hosp) . '</div>';
    if ($hosp2 !== '') {
        $h .= '<div class="print-sub">' . e($hosp2) . '</div>';
    }
    $h .= '<div class="print-title-line">' . e($title) . '</div>';
    return $h;
}

/** 患者信息行（姓名/性别/年龄/患者ID/流水号/科室等） */
function pt_patient_info($visit, $patient) {
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = isset($visit['age']) ? $visit['age'] . '岁' : '';
    $items = array(
        '姓名' => $name,
        '性别' => $gender,
        '年龄' => $age,
        '患者ID' => isset($patient['patient_no']) ? $patient['patient_no'] : '',
        '证件号码' => isset($patient['id_card']) ? $patient['id_card'] : '',
        '门诊流水号' => isset($visit['flow_no']) ? $visit['flow_no'] : '',
        '科室' => isset($visit['first_dept_name']) ? $visit['first_dept_name'] : (isset($visit['current_dept_name']) ? $visit['current_dept_name'] : ''),
        '就诊序号' => isset($visit['visit_seq']) ? str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) : '',
        '挂号时间' => isset($visit['register_time']) ? $visit['register_time'] : '',
        '费用类别' => isset($visit['fee_type']) ? $visit['fee_type'] : '',
    );
    $html = '<div class="print-info">';
    foreach ($items as $k => $v) {
        if ($v !== '' && $v !== null) {
            $html .= '<span><strong>' . e($k) . '</strong>：' . e($v) . '</span>';
        }
    }
    $html .= '</div><div class="print-line"></div>';
    return $html;
}

/**
 * 挂号凭条（竖向小票格式）
 * @param array $visit   挂号记录
 * @param array $patient 患者档案
 */
function pt_receipt($visit, $patient) {
    $code = isset($visit['flow_no']) && $visit['flow_no'] !== '' ? $visit['flow_no'] : (isset($patient['patient_no']) ? $patient['patient_no'] : '');
    $paid = in_array(isset($visit['status']) ? $visit['status'] : '', array('paid', 'visiting', 'finished'), true);
    $html = '<div class="print-ticket">';
    $html .= pt_ticket_header('挂号凭条');
    $html .= '<div class="ticket-divider"></div>';
    $html .= pt_ticket_row('患者姓名', isset($visit['name']) ? $visit['name'] : '');
    $html .= pt_ticket_row('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '');
    $html .= pt_ticket_row('门诊号', $code);
    $html .= pt_ticket_row('性别', isset($visit['gender']) ? $visit['gender'] : '');
    $html .= pt_ticket_row('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '');
    $html .= pt_ticket_row('年龄', isset($patient['age']) ? (int)$patient['age'] . ' 岁' : '');
    $html .= pt_ticket_row('挂号科室', isset($visit['first_dept_name']) ? $visit['first_dept_name'] .
        (isset($visit['dept_type']) && $visit['dept_type'] === 'emergency' ? ' (急诊)' : '') : '');
    $html .= pt_ticket_row('就诊序号', isset($visit['visit_seq']) ? str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) : '');
    $html .= pt_ticket_row('就诊日期', isset($visit['register_time']) ? substr($visit['register_time'], 0, 10) : '');
    $html .= pt_ticket_row('挂号时间', isset($visit['register_time']) ? substr($visit['register_time'], 0, 16) : '');
    $html .= pt_ticket_row('费用类别', isset($visit['fee_type']) ? $visit['fee_type'] : '');
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-row"><span>挂号费</span><span class="ticket-val">' . money(isset($visit['fee']) ? $visit['fee'] : 0) . ' 元</span></div>';
    $html .= '<div class="ticket-row"><span>支付状态</span><span class="ticket-val">' . ($paid ? '已支付' : e(isset($visit['status_name']) ? $visit['status_name'] : '')) . '</span></div>';
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-barcode">' . barcode128_svg($code, 44, 2) .
        '<div class="ticket-barcode-text">门诊号: ' . e($code) . '</div></div>';
    $html .= '<div class="ticket-note">请妥善保管，按时就诊。</div>';
    $html .= '<div class="ticket-print-time">打印时间: ' . now_str() . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * 缴费凭条（竖向小票格式）
 * @param array $pay    缴费记录
 * @param array $items  项目明细 [{name, quantity, price}]
 */
function pt_payment($pay, $items) {
    $pName = isset($pay['patient_no']) ? DB::val('patient', 'SELECT name FROM patients WHERE patient_no=?', array($pay['patient_no'])) : '';
    $code = isset($pay['flow_no']) && $pay['flow_no'] !== '' ? $pay['flow_no'] : (isset($pay['patient_no']) ? $pay['patient_no'] : '');
    $html = '<div class="print-ticket">';
    $html .= pt_ticket_header('缴费凭条');
    $html .= '<div class="ticket-divider"></div>';
    $html .= pt_ticket_row('患者姓名', $pName);
    $html .= pt_ticket_row('患者ID', isset($pay['patient_no']) ? $pay['patient_no'] : '');
    $html .= pt_ticket_row('门诊号', $code);
    $html .= pt_ticket_row('缴费时间', isset($pay['created_at']) ? substr($pay['created_at'], 0, 16) : '');
    $html .= pt_ticket_row('收费员', isset($pay['cashier_name']) ? $pay['cashier_name'] : '');
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-section-title">收费项目</div>';
    $total = 0;
    foreach ($items as $it) {
        $sub = (float)$it['price'] * (int)$it['quantity'];
        $total += $sub;
        $html .= '<div class="ticket-row ticket-item"><span>' . e(isset($it['name']) ? $it['name'] : '') .
            ((int)$it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : '') . '</span>' .
            '<span class="ticket-val">¥' . money($sub) . '</span></div>';
    }
    $html .= '<div class="ticket-row ticket-total"><span>合计</span><span class="ticket-val">¥' . money($total) . '</span></div>';
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-barcode">' . barcode128_svg($code, 44, 2) .
        '<div class="ticket-barcode-text">门诊号: ' . e($code) . '</div></div>';
    $html .= '<div class="ticket-note">缴费成功，请妥善保管本凭条，凭此前往相应科室执行检查/检验/取药。</div>';
    $html .= '<div class="ticket-print-time">打印时间: ' . now_str() . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * 申请单 / 处方单 / 处置单
 * @param array  $order 开单记录
 * @param array  $items 明细（含 sub_of 子处方分组）
 * @param string $title 单据标题（检验申请单/检查申请单/处置单/处方单）
 */
function pt_order($order, $items, $title) {
    $html = pt_header($title);
    $html .= '<div class="print-info">
        <span><strong>患者ID</strong>：' . e($order['patient_no']) . '</span>
        <span><strong>流水号</strong>：' . e($order['flow_no']) . '</span>
        <span><strong>单号</strong>：' . e($order['order_no']) . '</span>
        <span><strong>开单医生</strong>：' . e($order['doctor_name']) . '</span>
        <span><strong>开单时间</strong>：' . e($order['created_at']) . '</span>
    </div><div class="print-line"></div>';

    $isDrug = ($order['order_type'] === 'prescription');
    $html .= '<table>
        <tr><th style="width:6%">序号</th><th style="width:34%">项目名称</th>' .
        ($isDrug ? '<th style="width:16%">规格/含量</th><th style="width:14%">剂量</th><th style="width:12%">频次</th><th style="width:14%">途径</th>' : '<th style="width:20%">数量</th><th style="width:20%">单价</th><th style="width:20%">金额</th>') .
        '</tr>';

    // 主项目
    $idx = 0;
    $mainTotal = 0;
    foreach ($items as $it) {
        if ((int)$it['sub_of'] > 0) continue;
        $idx++;
        $qty = (int)$it['quantity'];
        $sub = (float)$it['price'] * $qty;
        $mainTotal += $sub;
        $html .= '<tr><td>' . $idx . '</td><td>' . e($it['item_name']) .
            ($isDrug && !empty($it['company_short']) ? '（' . e($it['company_short']) . '）' : '') .
            // 检验组合：申请单上显示组内成员明细（spec 为成员名列表）
            (!$isDrug && $order['order_type'] === 'lab' && !empty($it['spec'])
                ? '<div class="fs-12 text-muted">组合包含：' . e($it['spec']) . '</div>' : '') .
            '</td>';
        if ($isDrug) {
            $html .= '<td>' . e($it['spec']) . '</td>
                <td>' . e($it['single_dose']) . '</td>
                <td>' . e($it['frequency_name']) . '</td>
                <td>' . e($it['route_name']) . (!empty($it['need_nurse']) ? '（护士站执行）' : '') . '</td></tr>';
        } else {
            $html .= '<td>' . $qty . '</td><td>¥' . money($it['price']) . '</td><td>¥' . money($sub) . '</td></tr>';
        }
        // 静脉输液子处方（大括号关联：剂量单独显示，频次途径合并）
        $subs = array();
        foreach ($items as $subIt) {
            if ((int)$subIt['sub_of'] === (int)$it['sub_of'] || ((int)$it['sub_of'] === 0 && (int)$subIt['sub_of'] === $idx)) {
                // 子处方挂在当前主项目后
            }
        }
        foreach ($items as $subIt) {
            if ((int)$subIt['sub_of'] === $idx) {
                $subs[] = $subIt;
            }
        }
        if ($subs) {
            $html .= '<tr><td colspan="6" class="sub-order">';
            foreach ($subs as $si => $subIt) {
                $html .= '└ ' . e($subIt['item_name']) . '　剂量：' . e($subIt['single_dose']) .
                    ($si < count($subs) - 1 ? '<br>' : '');
            }
            $html .= '</td></tr>';
        }
    }
    if (!$isDrug) {
        $html .= '<tr><td colspan="5" style="text-align:right;font-weight:700">合计</td><td style="font-weight:700">¥' . money($mainTotal) . '</td></tr>';
    }
    $html .= '</table>';
    if ($isDrug) {
        $html .= '<div class="print-note">请凭本处方单至药房取药' .
            ($order['need_nurse_any'] ? '；标注“护士站执行”的项目请前往护士站执行。' : '。') . '</div>';
    } else {
        $html .= '<div class="print-note">请凭本申请单至相应科室登记执行。</div>';
    }
    $html .= '<div class="print-footer"><span>医生签名：</span><span>打印时间：' . now_str() . '</span></div>';
    return $html;
}

/**
 * 检验/检查报告单
 * @param array $report  报告记录
 * @param array $result  结果记录（values_json/findings/conclusion）
 * @param array $item    项目定义（name/unit/normal_range/critical_*）
 * @param array $visit   挂号记录
 */
function pt_report($report, $result, $item, $visit) {
    $title = ($result['type'] === 'lab') ? '检验报告单' : '检查报告单';
    $html = pt_header($title);
    $html .= '<div class="print-info">
        <span><strong>患者ID</strong>：' . e($report['patient_no']) . '</span>
        <span><strong>流水号</strong>：' . e($report['flow_no']) . '</span>
        <span><strong>报告编号</strong>：' . e($report['report_no']) . '</span>
        <span><strong>项目</strong>：' . e(isset($item['name']) ? $item['name'] : '') . '</span>
        <span><strong>执行人</strong>：' . e($report['doctor']) . '</span>
        <span><strong>报告时间</strong>：' . e($report['created_at']) . '</span>
    </div><div class="print-line"></div>';

    if ($result['type'] === 'lab') {
        $values = json_decode($result['values_json'], true);
        $html .= '<table>
            <tr><th style="width:25%">项目名称</th><th style="width:20%">结果</th><th style="width:15%">单位</th><th style="width:20%">正常范围</th><th style="width:20%">危急值</th></tr>';
        if (is_array($values) && !empty($values['group'])) {
            // 检验组：按组内成员逐行显示结果（组合项目按组价收费，成员结果分别出具）
            $members = DB::q('lab', 'SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array(isset($item['id']) ? (int)$item['id'] : 0));
            if (!$members) {
                $members = array();
            }
            foreach ($members as $m) {
                $v = isset($values['values'][(string)$m['id']]) ? $values['values'][(string)$m['id']] : '';
                $html .= '<tr><td>' . e($m['name']) . '</td>' .
                    '<td style="font-weight:700">' . e($v) . '</td>' .
                    '<td>' . e($m['unit']) . '</td>' .
                    '<td>' . e($m['normal_range']) . '</td>' .
                    '<td>' . e(($m['critical_low'] !== '' ? '低' . $m['critical_low'] : '') . ($m['critical_high'] !== '' ? ' 高' . $m['critical_high'] : '')) . '</td></tr>';
            }
        } else {
            $value = is_array($values) && isset($values['value']) ? $values['value'] : '';
            $html .= '<tr><td>' . e(isset($item['name']) ? $item['name'] : '') . '</td>
                <td style="font-weight:700">' . e($value) . '</td>
                <td>' . e(isset($item['unit']) ? $item['unit'] : '') . '</td>
                <td>' . e(isset($item['normal_range']) ? $item['normal_range'] : '') . '</td>
                <td>' . e((isset($item['critical_low']) && $item['critical_low'] !== '' ? '低' . $item['critical_low'] : '') . (isset($item['critical_high']) && $item['critical_high'] !== '' ? ' 高' . $item['critical_high'] : '')) . '</td></tr>';
        }
        $html .= '</table>';
    } else {
        $html .= '<div class="record-section"><div class="sec-label">影像所见</div><div class="sec-body">' .
            nl2br(e(isset($result['findings']) ? $result['findings'] : '')) . '</div></div>';
        $html .= '<div class="record-section"><div class="sec-label">检查结论</div><div class="sec-body">' .
            nl2br(e(isset($result['conclusion']) ? $result['conclusion'] : '')) . '</div></div>';
    }
    $html .= '<div class="print-footer"><span>报告人：' . e($report['doctor']) . '</span><span>打印时间：' . now_str() . '</span></div>';
    return $html;
}

/**
 * 电子病历打印（服务端版，用于打印中心/补打）
 */
function pt_record($visit, $patient, $record, $vitals) {
    $title = (isset($visit['dept_type']) && $visit['dept_type'] === 'emergency') ? '急诊电子病历' : '门诊电子病历';
    // 文档容器（relative，供右上角条形码定位）
    $html = '<div class="print-record-doc">';
    $html .= pt_header($title);

    // 右上角条形码（与挂号凭条一致：门诊号 flow_no，方便患者扫码缴费/打印报告）
    $code = isset($visit['flow_no']) && $visit['flow_no'] !== '' ? $visit['flow_no'] : (isset($patient['patient_no']) ? $patient['patient_no'] : '');
    if ($code !== '') {
        $html .= '<div class="print-record-barcode">' . barcode128_svg($code) .
            '<div>' . e($code) . '</div></div>';
    }

    // 患者信息两栏：门诊/急诊字段集与病历编辑器完全一致，空值显示 —（所见即所得）
    $emergency = isset($visit['dept_type']) && $visit['dept_type'] === 'emergency';
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = isset($visit['age']) ? $visit['age'] . '岁' : '';
    $items = $emergency ? array(
        '姓名' => $name,
        '性别' => $gender,
        '出生日期' => isset($patient['birth_date']) ? $patient['birth_date'] : '',
        '年龄' => $age,
        '患者ID' => isset($patient['patient_no']) ? $patient['patient_no'] : '',
        '就诊科室' => isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '',
        '就诊时间' => isset($visit['register_time']) ? $visit['register_time'] : '',
    ) : array(
        '姓名' => $name,
        '性别' => $gender,
        '年龄' => $age,
        '患者ID' => isset($patient['patient_no']) ? $patient['patient_no'] : '',
        '证件号码' => isset($patient['id_card']) ? $patient['id_card'] : '',
        '出生日期' => isset($patient['birth_date']) ? $patient['birth_date'] : '',
        '民族' => isset($patient['ethnicity']) ? $patient['ethnicity'] : '',
        '职业' => isset($patient['occupation']) ? $patient['occupation'] : '',
        '婚姻' => isset($patient['marital']) ? $patient['marital'] : '',
        '初复诊' => '—',
        '科室' => isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '',
        '联系方式' => isset($patient['phone']) ? $patient['phone'] : '',
    );
    $info = '<div class="print-info-grid">';
    foreach ($items as $k => $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        $info .= '<div class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</div>';
    }
    $info .= '</div>';
    // 患者信息上方横线：患者信息区上下各一条分隔线，版式清晰
    $html .= '<div class="print-line"></div>';
    $html .= $info;
    // 患者信息下方横线（与病历正文隔开）
    $html .= '<div class="print-line"></div>';

    // 病历内容行内流式排版：主诉：xxx　现病史：xxx（紧凑省空间）
    $secs = array();
    $secs[] = array('主诉', isset($record['chief_complaint']) ? $record['chief_complaint'] : '');
    $secs[] = array('现病史', isset($record['present_illness']) ? $record['present_illness'] : '');
    $secs[] = array('既往史', isset($record['past_history']) ? $record['past_history'] : '');
    $secs[] = array('过敏史', isset($record['allergy_history']) ? $record['allergy_history'] : '');
    if ($vitals) {
        $vp = array();
        if (!empty($vitals['bp_systolic'])) $vp[] = '血压 ' . $vitals['bp_systolic'] . '/' . $vitals['bp_diastolic'] . 'mmHg';
        if (!empty($vitals['heart_rate'])) $vp[] = '心率 ' . $vitals['heart_rate'] . '次/分';
        if (!empty($vitals['pulse'])) $vp[] = '脉搏 ' . $vitals['pulse'] . '次/分';
        if (!empty($vitals['spo2'])) $vp[] = '血氧 ' . $vitals['spo2'] . '%';
        if (!empty($vitals['respiration'])) $vp[] = '呼吸 ' . $vitals['respiration'] . '次/分';
        if ($vp) $secs[] = array('生命体征', implode('；', $vp));
    }
    $secs[] = array('意识状态', isset($record['consciousness']) ? $record['consciousness'] : '');
    $secs[] = array('体格检查', isset($record['physical_exam']) ? $record['physical_exam'] : '');
    $diag = isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : '';
    if (isset($record['diagnosis_code']) && $record['diagnosis_code']) {
        $diag .= '（' . $record['diagnosis_code'] . '）';
    }
    $secs[] = array('初步诊断', $diag);
    $secs[] = array('留观', !empty($record['is_observation']) ? '是' : '否');
    $secs[] = array('嘱托', isset($record['advice']) ? $record['advice'] : '');

    $flow = '';
    foreach ($secs as $s) {
        $flow .= '<span class="pf-sec"><strong>' . e($s[0]) . '：</strong><span class="pf-body">' . $s[1] . '</span></span>';
    }
    $html .= '<div class="print-flow">' . $flow . '</div>';

    // 医生签名：位于病历末尾横线上方、病历内容部分右下角
    $html .= '<div class="print-record-sign">医生：' . e(isset($record['doctor_name']) ? $record['doctor_name'] : '') . '</div>';

    // 病历末尾横线：下方为页脚（左下角记录时间、右下角打印时间）
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>记录时间：' . e(isset($record['updated_at']) ? $record['updated_at'] : '') . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
    return $html;
}

/** 病历段落 */
function pt_sec($label, $body) {
    return '<div class="record-section"><div class="sec-label">' . e($label) . '</div>' .
        '<div class="sec-body">' . $body . '</div></div>';
}

/**
 * 诊断证明
 */
function pt_certificate($visit, $patient, $record, $cert, $doctorName) {
    $html = pt_header('诊断证明书');
    $html .= pt_patient_info($visit, $patient);
    $html .= '<div class="record-section"><div class="sec-label">主诉</div><div class="sec-body">' .
        nl2br(e(isset($record['chief_complaint']) ? strip_tags($record['chief_complaint']) : '')) . '</div></div>';
    $html .= '<div class="record-section"><div class="sec-label">现病史</div><div class="sec-body">' .
        nl2br(e(isset($record['present_illness']) ? strip_tags($record['present_illness']) : '')) . '</div></div>';
    $html .= '<div class="record-section"><div class="sec-label">初步诊断</div><div class="sec-body">' .
        e(isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : '') .
        (isset($record['diagnosis_code']) && $record['diagnosis_code'] ? '（' . e($record['diagnosis_code']) . '）' : '') . '</div></div>';
    $html .= '<div class="record-section"><div class="sec-label">医生建议</div><div class="sec-body">' .
        nl2br(e(isset($cert['content']) ? $cert['content'] : '')) . '</div></div>';
    $html .= '<div class="print-footer"><span>医生：' . e($doctorName) . '</span><span>日期：' . now_str('Y-m-d') . '</span></div>';
    return $html;
}
