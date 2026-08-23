<?php
require_once APP_ROOT . '/app/includes/emr_formatter.php';
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

/**
 * 打印文档年龄文本（EMR 全年龄段格式）：
 * 优先 出生日期 + 就诊时间 精确格式化；缺失/异常时回退快照整数年龄
 */
function pt_age_text($patient, $visit) {
    $birth = isset($patient['birth_date']) && $patient['birth_date'] !== '' ? $patient['birth_date']
        : (isset($visit['birth_date']) && $visit['birth_date'] !== '' ? $visit['birth_date'] : '');
    if ($birth !== '') {
        $target = isset($visit['register_time']) && $visit['register_time'] !== '' ? $visit['register_time'] : null;
        $s = age_format($birth, $target);
        if ($s !== '') return $s;
    }
    if (isset($visit['age']) && $visit['age'] !== '' && $visit['age'] !== null) return (int)$visit['age'] . '岁';
    if (isset($patient['age']) && $patient['age'] !== '' && $patient['age'] !== null) return (int)$patient['age'] . '岁';
    return '';
}

/** 患者信息行（姓名/性别/年龄/患者ID/流水号/科室等） */
function pt_patient_info($visit, $patient) {
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = pt_age_text($patient, $visit);
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
    $html .= pt_ticket_row('年龄', pt_age_text($patient, $visit));
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
    // 复用病历文档容器：A5 版式、医院名称/第二名称与电子病历完全一致
    $html = '<div class="print-record-doc">';
    $html .= pt_header($title);

    // 右上角条形码：处方单号/申请单号（与电子病历右上角门诊号条码同款式样）
    $html .= '<div class="print-record-barcode">' . barcode128_svg(isset($order['order_no']) ? $order['order_no'] : '') .
        '<div>' . e(isset($order['order_no']) ? $order['order_no'] : '') . '</div></div>';

    // 患者信息：参考急诊病历两行流式排版、两端对齐（无论门诊/急诊开单统一此样式）
    $patient = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($order['patient_no']));
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    // 患者信息仅两行：第一行 姓名/性别/出生日期/年龄，第二行 患者ID/流水号/单号
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', $patient ? $patient['name'] : '') .
        $cell('性别', $patient ? $patient['gender'] : '') .
        $cell('出生日期', $patient ? $patient['birth_date'] : '') .
        $cell('年龄', $patient ? pt_age_text($patient, null) : '') . '</div>' .
        '<div class="print-info-line">' .
        $cell('患者ID', $order['patient_no']) .
        $cell('流水号', $order['flow_no']) .
        $cell('单号', $order['order_no']) . '</div>' .
        '</div><div class="print-line"></div>';

    $isDrug = ($order['order_type'] === 'prescription');
    if ($isDrug) {
        // 处方起始 ℞ 标志（处方内容左上角）
        $html .= '<div class="print-rx-mark">℞</div>';
    }
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
            $scount = count($subs);
            foreach ($subs as $si => $subIt) {
                // 成组医嘱树状连线符：┌ 首个 / ├ 中间 / └ 末尾
                $branch = $si === 0 ? '┌' : ($si === $scount - 1 ? '└' : '├');
                $html .= $branch . ' ' . e($subIt['item_name']) . '　剂量：' . e($subIt['single_dose']) .
                    ($si < $scount - 1 ? '<br>' : '');
            }
            $html .= '</td></tr>';
        }
    }
    $html .= '</table>';
    if ($isDrug) {
        // 处方完毕居中分隔（紧跟药品表格，属于处方内容结尾）
        $html .= '<div class="print-rx-end">—————— 处方完毕 ——————</div>';
        // 取药提示（处方内容之外）
        $html .= '<div class="print-note">请凭本处方单至药房取药' .
            ($order['need_nurse_any'] ? '；标注“护士站执行”的项目请前往护士站执行。' : '。') . '</div>';
    } else {
        // 合计：不用表格行，直接显示在表格右下方（避免超出表格空间）
        $html .= '<div class="print-order-total">合计：¥' . money($mainTotal) . '</div>';
        $html .= '<div class="print-note">请凭本申请单至相应科室登记执行。</div>';
        // 检验/检查申请单底部专项提醒（空腹、防护等注意事项）
        if ($order['order_type'] === 'lab') {
            $html .= '<div class="print-note print-note-tip">温馨提示：肝功能等抽血检验项目需空腹采血，请按检验科指引提前做好准备。</div>';
        } elseif ($order['order_type'] === 'imaging') {
            $html .= '<div class="print-note print-note-tip">温馨提示：X 线、CT 等检查请注意辐射防护；腹部超声等部分检查需空腹进行，请遵医嘱提前准备。</div>';
        }
    }
    // 医生签名：开单项目正文右下方（类似病历签名位置）
    $html .= '<div class="print-record-sign">' .
        ($isDrug ? '医师签名：' : '开单医生：') . e(isset($order['doctor_name']) ? $order['doctor_name'] : '') . '</div>';
    // 末尾横线 + 页脚：左下角开单时间、右下角打印时间
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>开单时间：' . e(isset($order['created_at']) ? $order['created_at'] : '') . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
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
 * 多医生接诊：同一挂号流水的全部文书输出为【一份连续文档】——
 * 首段（$mode='full'）带完整页眉（医院抬头/标题/条形码/患者信息）；
 * 续写段（$mode='continue'）以分割线 + 「病历续写 / 续写时间」承接头开始，
 * 承接首诊接着书写，不再重复无意义页眉，避免空间浪费。
 * 医生签名使用 .print-rec-sign（不进分页器页脚集合），始终紧跟各段
 * 正文末尾右下角；页脚（记录时间/打印时间）仅最后一段输出（$isLast）。
 * 注意：本函数只输出【片段】，外层 .print-record-doc 容器由调用方
 * （print.php）统一包裹——A5 分页器按该容器识别整份文档的头/尾。
 */
function pt_record($visit, $patient, $record, $vitals, $mode = 'full', $isLast = true, $footRecordTime = null) {
    $title = (isset($visit['dept_type']) && $visit['dept_type'] === 'emergency') ? '急诊电子病历' : '门诊电子病历';
    $html = '';

    if ($mode === 'continue') {
        // 续写段承接头：一条虚线分割上下文书，下一行整行加粗、三段定位——
        // 左端「日期 时间」（该次续写首次保存时间）、居中「续写病历」、
        // 右端「科室」，随后直接接「病历续写：……」等续写正文
        $contDept = isset($visit['current_dept_name']) ? (string)$visit['current_dept_name'] : '';
        $contTime = isset($record['created_at']) ? substr((string)$record['created_at'], 0, 16) : '';
        $html .= '<div class="print-record-cont">' .
            '<span class="prc-time">' . e($contTime) . '</span>' .
            '<span class="prc-title">续写病历</span>' .
            '<span class="prc-dept">' . e($contDept) . '</span>' .
            '</div>';
    } else {
        $html .= pt_header($title);

        // 右上角条形码（与挂号凭条一致：门诊号 flow_no，方便患者扫码缴费/打印报告）
        $code = isset($visit['flow_no']) && $visit['flow_no'] !== '' ? $visit['flow_no'] : (isset($patient['patient_no']) ? $patient['patient_no'] : '');
        if ($code !== '') {
            $html .= '<div class="print-record-barcode">' . barcode128_svg($code) .
                '<div>' . e($code) . '</div></div>';
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
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    if ($emergency) {
        $info = '<div class="print-info-lines">' .
            '<div class="print-info-line">' .
            $cell('姓名', $name) . $cell('性别', $gender) .
            $cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
            $cell('年龄', $age) . '</div>' .
            '<div class="print-info-line">' .
            $cell('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '') .
            $cell('就诊科室', isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '') .
            $cell('就诊时间', isset($visit['register_time']) ? $visit['register_time'] : '') .
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
            $info .= $cell($k, $val);
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
        if ($isProgress) {
            $progContent = isset($emr['progress']['content']) ? trim((string)$emr['progress']['content']) : '';
            if ($progContent !== '') $secs[] = array('病历续写', e($progContent));
            $secs[] = array('既往史', emr_ph_text(isset($emr['past_history']) ? $emr['past_history'] : array()));
            $secs[] = array('过敏史', emr_al_text(isset($emr['allergies']) ? $emr['allergies'] : array()));
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
    if ($vitals) {
        $vp = array();
        if (!empty($vitals['bp_systolic'])) $vp[] = '血压 ' . $vitals['bp_systolic'] . '/' . $vitals['bp_diastolic'] . 'mmHg';
        if (!empty($vitals['heart_rate'])) $vp[] = '心率 ' . $vitals['heart_rate'] . '次/分';
        if (!empty($vitals['pulse'])) $vp[] = '脉搏 ' . $vitals['pulse'] . '次/分';
        if (!empty($vitals['spo2'])) $vp[] = '血氧 ' . $vitals['spo2'] . '%';
        if (!empty($vitals['respiration'])) $vp[] = '呼吸 ' . $vitals['respiration'] . '次/分';
        if ($vp) $secs[] = array('生命体征', implode('；', $vp));
    }
    // 意识状态：续写文书仅在本人镜像有值时输出（该节归首诊文书）
    if (!$isProgress || (isset($record['consciousness']) && $record['consciousness'] !== '')) {
        $secs[] = array('意识状态', isset($record['consciousness']) ? $record['consciousness'] : '');
    }
    if ($emrStructured) {
        $secs[] = array('体格检查', emr_pe_text(isset($emr['physical_exam']) ? $emr['physical_exam'] : array()));
        $secs[] = array('初步诊断', emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array()));
    } else {
        $secs[] = array('体格检查', isset($record['physical_exam']) ? $record['physical_exam'] : '');
        $diag = isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : '';
        if (isset($record['diagnosis_code']) && $record['diagnosis_code']) {
            $diag .= '（' . $record['diagnosis_code'] . '）';
        }
        $secs[] = array('初步诊断', $diag);
    }

    // 已开项目所见即所得：辅助检查（检验/检查）+ 门诊处置（处置/处方），与病历编辑页一致
    // 辅助检查：仅显示项目名称；处置：不换行显示名称×数量；处方：每行一个药品（名称/剂量/用法/途径/数量）
    // 多医生接诊：已开项目按该文书医生本人过滤（谁开单归属谁的病历）
    $aux = array();
    $procs = array();
    $rxs = array();
    $orderSql = "SELECT * FROM orders WHERE visit_id=? AND status NOT IN ('refunded','cancelled')";
    $orderParams = array($visit['id']);
    if (!empty($record['doctor_id'])) {
        $orderSql .= ' AND doctor_id=?';
        $orderParams[] = (int)$record['doctor_id'];
    }
    $orderSql .= ' ORDER BY id DESC';
    $orders = DB::q('order', $orderSql, $orderParams);
    foreach ($orders as $o) {
        $its = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
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
    if ($emrStructured) {
        // 结构化：辅助检查 = 已开项目 + 手工结果 + 外院结果；门诊处置 = 处方行 + 处置(含数量) + 自定义
        $manualAux = array();
        foreach (array('aux_result', 'aux_external') as $k) {
            if (isset($emr[$k]) && $emr[$k] !== '') $manualAux[] = e($emr[$k]);
        }
        $auxAll = array_merge($aux, $manualAux);
        $secs[] = array('辅助检查', $auxAll ? implode('，', $auxAll) : '-');
        $treat = '';
        if ($rxs) foreach ($rxs as $rx) $treat .= '<div class="pf-rx-line">' . $rx . '</div>';
        $dispParts = array_merge($procs, isset($emr['disposition_custom']) && $emr['disposition_custom'] !== '' ? array(e($emr['disposition_custom'])) : array());
        if ($dispParts) $treat .= ($treat ? '' : '') . '<span class="pf-treat-proc">' . implode('，', $dispParts) . '</span>';
        $secs[] = array('门诊处置', $treat !== '' ? $treat : '-');
    } else {
        if ($aux) $secs[] = array('辅助检查', implode('、', $aux));
        $treat = '';
        if ($procs) $treat .= '<span class="pf-treat-proc">' . implode('　', $procs) . '</span>';
        foreach ($rxs as $rx) $treat .= '<div class="pf-rx-line">' . $rx . '</div>';
        if ($treat !== '') $secs[] = array('门诊处置', $treat);
    }

    $secs[] = array('是否留观', $emrStructured ? emr_obs_text($emr) : (!empty($record['is_observation']) ? '是' : '否'));
    $secs[] = array('嘱托', $emrStructured ? (isset($emr['advice']) ? $emr['advice'] : '') : (isset($record['advice']) ? $record['advice'] : ''));

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

/** 病历段落 */
function pt_sec($label, $body) {
    return '<div class="record-section"><div class="sec-label">' . e($label) . '</div>' .
        '<div class="sec-body">' . $body . '</div></div>';
}

/**
 * 诊断证明（标准 A5 纸，版式与申请单一致）
 * 抬头右上角条形码编码「证明号」（ZM 前缀），患者信息第二行含证明号；
 * 签名右下角、页脚左开具时间/右打印时间。
 */
function pt_certificate($visit, $patient, $record, $cert, $doctorName) {
    $certNo = isset($cert['cert_no']) ? $cert['cert_no'] : '';
    // 文档容器：与病历/申请单共用 .print-record-doc 版式（A5 + 分页器）
    $html = '<div class="print-record-doc">';
    $html .= pt_header('诊断证明书');

    // 右上角条形码：证明号
    $html .= '<div class="print-record-barcode">' . barcode128_svg($certNo) .
        '<div>' . e($certNo) . '</div></div>';

    // 患者信息两行（第一行 姓名/性别/出生日期/年龄，第二行 患者ID/流水号/证明号）
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', isset($visit['name']) && $visit['name'] !== '' ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '')) .
        $cell('性别', isset($visit['gender']) && $visit['gender'] !== '' ? $visit['gender'] : (isset($patient['gender']) ? $patient['gender'] : '')) .
        $cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
        $cell('年龄', function_exists('pt_age_text') ? pt_age_text($patient, $visit) : '') . '</div>' .
        '<div class="print-info-line">' .
        $cell('患者ID', isset($visit['patient_no']) ? $visit['patient_no'] : '') .
        $cell('流水号', isset($visit['flow_no']) ? $visit['flow_no'] : '') .
        $cell('证明号', $certNo) . '</div>' .
        '</div><div class="print-line"></div>';

    // 病历摘要与医生建议（每节独立 record-section，可随分页器跨页）
    $html .= pt_sec('主诉', nl2br(e(isset($record['chief_complaint']) ? strip_tags($record['chief_complaint']) : '')));
    $html .= pt_sec('现病史', nl2br(e(isset($record['present_illness']) ? strip_tags($record['present_illness']) : '')));
    // 初步诊断：名称本身已含 ICD-10 编码前缀，直接显示、不再追加括号编码
    $html .= pt_sec('初步诊断', e(isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : ''));
    $html .= pt_sec('医生建议', nl2br(e(isset($cert['content']) ? $cert['content'] : '')));

    // 医生签名右下角 + 末尾横线 + 页脚（左下角开具时间、右下角打印时间）
    $html .= '<div class="print-record-sign">医生：' . e($doctorName) . '</div>';
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>开具时间：' . e(isset($cert['created_at']) ? $cert['created_at'] : '') . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
    return $html;
}
