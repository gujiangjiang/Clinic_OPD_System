<?php
/** print/print_order.php — 统一打印模板：申请单/处方单/处置单 */

function pt_order($order, $items, $title) {
    // 复用病历文档容器：A5 版式、医院名称/第二名称与电子病历完全一致
    $html = '<div class="print-record-doc">';
    $html .= pt_header($title);

    // 右上角条形码：处方单号/申请单号（与电子病历右上角门诊号条码同款式样）
    $html .= '<div class="print-record-barcode">' . barcode128_svg(isset($order['order_no']) ? $order['order_no'] : '') .
        '<div>' . e(isset($order['order_no']) ? $order['order_no'] : '') . '</div></div>';

    // 患者信息：参考急诊病历两行流式排版、两端对齐（无论门诊/急诊开单统一此样式）
    $patient = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($order['patient_no']));
    // 临床诊断：取就诊结构化病历诊断（优先），旧镜像表 initial_diagnosis 兜底
    $diagText = '';
    $pr = DB::one('medical', 'SELECT emr_data FROM patient_records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array($order['visit_id']));
    if ($pr && !empty($pr['emr_data'])) {
        $emr = json_decode($pr['emr_data'], true);
        if (is_array($emr) && !empty($emr['diagnoses'])) {
            $diagText = emr_diag_names($emr['diagnoses']);   // 临床诊断仅名称（+疑似?）
        }
    }
    if ($diagText === '') {
        $oldRec = DB::one('medical', 'SELECT initial_diagnosis FROM records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array($order['visit_id']));
        if ($oldRec && trim((string)$oldRec['initial_diagnosis']) !== '') {
            $diagText = trim((string)$oldRec['initial_diagnosis']);
        }
    }
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    // 开单科室：取开单医生当前科室（单号已由右上角条形码展示，这里显示科室）
    $deptName = '';
    $docU = DB::one('user', 'SELECT current_dept_id FROM users WHERE id=?', array((int)$order['doctor_id']));
    if ($docU && (int)$docU['current_dept_id'] > 0) {
        $dp = DB::one('dept', 'SELECT name FROM departments WHERE id=?', array((int)$docU['current_dept_id']));
        if ($dp) $deptName = $dp['name'];
    }
    if ($deptName === '') {
        $ordVisit = DB::one('patient', 'SELECT current_dept_name FROM registrations WHERE id=?', array((int)$order['visit_id']));
        if ($ordVisit) $deptName = $ordVisit['current_dept_name'];
    }
    // 患者信息：第一行 姓名/性别/出生日期/年龄，第二行 患者ID/流水号/开单科室，第三行 临床诊断
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', $patient ? $patient['name'] : '') .
        $cell('性别', $patient ? $patient['gender'] : '') .
        $cell('出生日期', $patient ? $patient['birth_date'] : '') .
        $cell('年龄', $patient ? pt_age_text($patient, null) : '') . '</div>' .
        '<div class="print-info-line">' .
        $cell('患者ID', $order['patient_no']) .
        $cell('流水号', $order['flow_no']) .
        $cell('开单科室', $deptName) . '</div>' .
        '<div class="print-diag"><strong>临床诊断</strong>：' . e($diagText !== '' ? $diagText : '—') . '</div>' .
        '</div><div class="print-line"></div>';

    $isDrug = ($order['order_type'] === 'prescription');
    $isProc = ($order['order_type'] === 'procedure');
    $isLabImg = ($order['order_type'] === 'lab' || $order['order_type'] === 'imaging');
    if ($isDrug) {
        // 处方起始 ℞ 标志（处方内容左上角）
        $html .= '<div class="print-rx-mark">℞</div>';
    }
    // ===== 处方：标准医院处方样式——无表头/序号/边框 =====
    // 列：名称(名称 规格 厂商) | 剂量 | 途径 | 频次 | 数量(×N)
    // 组医嘱：主药 途径/频次/数量 纵向合并(rowspan)跨其子医嘱，垂直居中靠左
    if ($isDrug) {
        $nameTxt = function ($x) {
            $s = e($x['item_name']);
            if (!empty($x['spec'])) $s .= ' ' . e($x['spec']);
            if (!empty($x['company_short'])) $s .= ' ' . e($x['company_short']);
            return $s;
        };
        $html .= '<table class="rx-print">';
        $mainSeq = 0;
        $rxTotal = 0;
        foreach ($items as $it) {
            if ((int)$it['sub_of'] > 0) continue;
            $mainSeq++;
            $subs = array();
            foreach ($items as $subIt) {
                if ((int)$subIt['sub_of'] === $mainSeq) $subs[] = $subIt;
            }
            $rowspan = 1 + count($subs);
            $rxTotal += (float)$it['price'] * (int)$it['quantity'];   // 主药计费
            // 组合医嘱：剂量与途径之间用朝左大括号（┐│┘）把组包起来
            $bracket = $rowspan > 1 ? '┐' : '';
            $html .= '<tr>' .
                '<td class="rx-name">' . $nameTxt($it) . '</td>' .
                '<td class="rx-dose">' . e($it['single_dose']) . '</td>' .
                '<td class="rx-bracket">' . $bracket . '</td>' .
                '<td class="rx-route" rowspan="' . $rowspan . '">' . e($it['route_name']) . '</td>' .
                '<td class="rx-freq" rowspan="' . $rowspan . '">' . e($it['frequency_name']) . '</td>' .
                '<td class="rx-qty">×' . (int)$it['quantity'] . '</td>' .
                '</tr>';
            $sc = count($subs);
            foreach ($subs as $si => $sub) {
                $rxTotal += (float)$sub['price'] * (int)$sub['quantity'];   // 子医嘱计费
                $branch = $si === $sc - 1 ? '┘' : '┤';   // 中间子医嘱用朝左连接符 ┤
                $html .= '<tr>' .
                    '<td class="rx-name">' . $nameTxt($sub) . '</td>' .
                    '<td class="rx-dose">' . e($sub['single_dose']) . '</td>' .
                    '<td class="rx-bracket">' . $branch . '</td>' .
                    '<td class="rx-qty">×' . (int)$sub['quantity'] . '</td>' .
                    '</tr>';
            }
        }
        $html .= '</table>';
    } else {
        // ===== 处置/检验/检查：带表头表格（检验/检查不显示数量） =====
        $html .= '<table>
        <tr><th style="width:6%">序号</th><th style="width:' . ($isProc ? '34%' : '64%') . '">项目名称</th>';
        if ($isProc) {
            $html .= '<th style="width:20%">数量</th><th style="width:20%">单价</th><th style="width:20%">金额</th>';
        } else {
            $html .= '<th style="width:30%">单价</th>';
        }
        $html .= '</tr>';

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
                // 检验组合：申请单上显示组内成员明细（spec 为成员名列表）
                ($order['order_type'] === 'lab' && !empty($it['spec'])
                    ? '<div class="fs-12 text-muted">组合包含：' . e($it['spec']) . '</div>' : '') .
                '</td>';
            if ($isProc) {
                $html .= '<td>' . $qty . '</td><td>¥' . money($it['price']) . '</td><td>¥' . money($sub) . '</td></tr>';
            } else {
                // 检验/检查：不涉及数量，仅显示单价
                $html .= '<td>¥' . money($it['price']) . '</td></tr>';
            }
        }
        $html .= '</table>';
    }
    if ($isDrug) {
        // 处方完毕居中分隔（紧跟药品表格，属于处方内容结尾）
        $html .= '<div class="print-rx-end">—————— 处方完毕 ——————</div>';
        // 以下从「取药提示」到「本处方当日内有效」连续进入 A5 页脚（foot 区块），
        // 聚集在页面底部，依次为：
        // 取药提示（print-note）→ 医师签名（print-record-sign）→ 实线（print-line）
        // → 调配/复核发药（print-note）→ 实线（print-line）→ 开单/打印时间
        // （print-record-foot）→ 本处方当日内有效（print-note），之后由分页器
        // 追加页码。
        $html .= '<div class="print-note">请凭本处方单至药房取药</div>';
        // 金额（左）+ 医师签名（右）同一行：取药提示下方靠左显示金额
        $html .= '<div class="print-note" style="display:flex;align-items:center;font-size:13px;font-weight:600;margin-top:10px">' .
            '<span style="flex:1;text-align:left">金额：¥' . money($rxTotal) . '</span>' .
            '<span>医师签名：' . e(isset($order['doctor_name']) ? $order['doctor_name'] : '') . '</span>' .
            '</div>';
        $html .= '<div class="print-line"></div>';
        $html .= '<div class="print-note" style="display:flex;font-size:13px;line-height:1.8;margin-top:4px;margin-bottom:4px"><span style="flex:1;text-align:left">调配：</span><span style="flex:1;text-align:left">复核、发药：</span></div>';
        $html .= '<div class="print-line"></div>';
        $html .= '<div class="print-record-foot">' .
            '<span>开单时间：' . e(isset($order['created_at']) ? $order['created_at'] : '') . '</span>' .
            '<span>打印时间：' . now_str() . '</span></div>';
        $html .= '<div class="print-note" style="text-align:center">（本处方当日内有效）</div>';
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
        // 医生签名：开单项目正文右下方（类似病历签名位置）
        $html .= '<div class="print-record-sign">' .
            '开单医生：' . e(isset($order['doctor_name']) ? $order['doctor_name'] : '') . '</div>';
        // 末尾横线 + 页脚：左下角开单时间、右下角打印时间
        $html .= '<div class="print-line"></div>';
        $html .= '<div class="print-record-foot">' .
            '<span>开单时间：' . e(isset($order['created_at']) ? $order['created_at'] : '') . '</span>' .
            '<span>打印时间：' . now_str() . '</span></div>';
    }
    $html .= '</div>';
    return $html;
}
