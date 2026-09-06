<?php
/** print/print_report.php — 统一打印模板：检验/检查报告
 *  检验报告单：独立抬头（医院名称+检验报告单同行居中，第二名称两端对齐）
 *  患者信息两行（姓名 性别 年龄 出生日期 / 患者ID 申请科室 申请医生 临床诊断）
 *  横向 A5 固定画布（JS 分列分页：默认单列含序号，空间不足自动双列去序号）
 *  页脚：检验备注 + 实线 + 申请/检验/报告时间 + 检验者/审核者/页码 + 提示语 */

/** 检验报告单：横向 A5 画布内容（head + 结果行 + 页脚，由前端分列分页） */
function pt_lab_report($report, $result, $item) {
    $html = '<div class="print-record-doc lr-doc">';

    // ===== 抬头：医院名称 + 检验报告单 同一行居中；第二名称下方两端对齐 =====
    $hosp = setting('hospital_name', '');
    $hosp2 = setting('hospital_name2', '');
    $html .= '<div class="lr-titleline">' .
        '<span class="lr-hosp">' . e($hosp) . '</span>' .
        '<span class="lr-name">检验报告单</span>' .
        '</div>';
    if ($hosp2 !== '') {
        $html .= '<div class="lr-sub">' . e($hosp2) . '</div>';
    }

    // ===== 患者信息两行 =====
    $row = get_visit_row((int)$report['visit_id']);
    $pname = $row ? $row['patient']['name'] : '';
    $pgender = $row ? $row['patient']['gender'] : '';
    $pbirth = $row ? $row['patient']['birth_date'] : '';
    $page = $row ? age_format($pbirth, $row['visit']['registered_at']) : '';
    // 申请科室/申请医生：按结果关联的 order_item → order
    $orderItem = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array((int)$result['order_item_id']));
    $order = $orderItem ? OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$orderItem['order_id'])) : null;
    $applyDept = $order ? (string)$order['dept_name'] : '';
    $applyDoc = $order ? (string)$order['doctor_name'] : '';
    // 临床诊断：该就诊首份病历的初步诊断
    $diag = '';
    $pr = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
    if ($pr) {
        $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
        $diag = emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array());
    }
    if ($diag === '') {
        $mirror = OrderRepository::one('SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!=\'\' ORDER BY id ASC LIMIT 1', array((int)$report['visit_id']));
        if ($mirror) $diag = (string)$mirror['preliminary_diagnosis'];
    }
    $li = function ($label, $val) { return '<span class="lr-cell"><b>' . $label . '：</b>' . e($val) . '</span>'; };
    $html .= '<div class="lr-patlines">' .
        '<div class="lr-line">' . $li('姓名', $pname) . $li('性别', $pgender) . $li('年龄', $page) . $li('出生日期', $pbirth) . '</div>' .
        '<div class="lr-line">' . $li('患者ID', $report['patient_no']) . $li('申请科室', $applyDept) . $li('申请医生', $applyDoc) . $li('临床诊断', $diag) . '</div>' .
        '</div>';

    // ===== 结果区：列头 + 行（前端分列/分页，单列含序号） =====
    $html .= '<div class="lr-result">' .
        '<div class="lr-colhead"><span class="lr-seq">序号</span><span class="lr-item">项目</span>' .
        '<span class="lr-val">结果</span><span class="lr-unit">单位</span><span class="lr-ref">参考范围</span></div>' .
        '<div class="lr-rows">';
    $values = json_decode((string)$result['values_json'], true);
    if (is_array($values) && !empty($values['group'])) {
        $members = OrderRepository::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$item['id']));
        if (!$members) $members = array();
        foreach ($members as $m) {
            $v = isset($values['values'][(string)$m['id']]) ? $values['values'][(string)$m['id']] : '';
            $html .= '<div class="lr-row"><span class="lr-seq"></span><span class="lr-item">' . e($m['name']) . '</span>' .
                '<span class="lr-val">' . e($v) . '</span><span class="lr-unit">' . e($m['unit']) . '</span>' .
                '<span class="lr-ref">' . e($m['normal_range']) . '</span></div>';
        }
    } else {
        $value = is_array($values) && isset($values['value']) ? $values['value'] : '';
        $html .= '<div class="lr-row"><span class="lr-seq"></span><span class="lr-item">' . e(isset($item['name']) ? $item['name'] : '') . '</span>' .
            '<span class="lr-val">' . e($value) . '</span><span class="lr-unit">' . e(isset($item['unit']) ? $item['unit'] : '') . '</span>' .
            '<span class="lr-ref">' . e(isset($item['normal_range']) ? $item['normal_range'] : '') . '</span></div>';
    }
    $html .= '</div></div>';

    // ===== 页脚：检验备注 + 实线 + 时间 + 检验者/审核者/页码 + 提示语 =====
    $applyTime = $order ? substr((string)$order['created_at'], 0, 16) : '';
    $regTime = $orderItem && !empty($orderItem['registered_at']) ? substr((string)$orderItem['registered_at'], 0, 16) : '';
    $repTime = substr((string)$report['created_at'], 0, 16);
    $note = trim((string)(isset($report['content']) ? $report['content'] : ''));
    $html .= '<div class="lr-foot">' .
        '<div class="lr-note">检验备注：' . ($note !== '' ? e($note) : '（无）') . '</div>' .
        '<div class="lr-solid"></div>' .
        '<div class="lr-meta1">' . $li('申请时间', $applyTime) . $li('检验时间', $regTime) . $li('报告时间', $repTime) . '</div>' .
        '<div class="lr-meta2">' .
        '<span class="lr-cell"><b>检验者：</b>' . e($report['doctor']) . '</span>' .
        '<span class="lr-cell"><b>审核者：</b><span class="lr-audit">（留空）</span></span>' .
        '<span class="lr-cell lr-pageno">第 <span class="lr-page">1</span> / <span class="lr-total">1</span> 页</span>' .
        '</div>' .
        '<div class="lr-tip">检验结果仅供临床诊疗参考，仅对送检标本负责！</div>' .
        '</div>';

    $html .= '</div>';
    return $html;
}

function pt_report($report, $result, $item) {
    $title = ($result['type'] === 'lab') ? '检验报告单' : '检查报告单';
    // 检验报告走独立横向 A5 版式
    if ($result['type'] === 'lab') {
        return pt_lab_report($report, $result, $item);
    }
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
            $members = DB::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array(isset($item['id']) ? (int)$item['id'] : 0));
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