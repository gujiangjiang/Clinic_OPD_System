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
