<?php
/** print/print_report.php — 统一打印模板：检验/检查报告
 *  检验报告单：独立抬头（医院名称+检验报告单同行居中，第二名称两端对齐）
 *  患者信息两行（姓名 性别 年龄 出生日期 / 患者ID 申请科室 申请医生 临床诊断）
 *  横向 A5 固定画布（JS 分列分页：默认单列含序号，空间不足自动双列去序号）
 *  页脚：检验备注 + 实线 + 申请/检验/报告时间 + 检验者/审核者/页码 + 提示语 */

/** 检验报告单：横向 A5 画布内容（head + 结果行 + 页脚，由前端分列分页） */
function pt_lab_report($report, $result, $item) {
    $hosp = setting('hospital_name', '');
    $hosp2 = setting('hospital_name2', '');

    // ===== 抬头：无第二名称 → 医院名称 + 检验报告单；有第二名称 → 两组名称两端对齐 + 检验报告单 =====
    $html = '<div class="print-record-doc lr-doc">';
    $html .= '<div class="lr-titleline">' .
        '<div class="lr-hospwrap">' .
        '<span class="lr-hosp">' . e($hosp) . '</span>' .
        ($hosp2 !== '' ? '<div class="lr-sub">' . e($hosp2) . '</div>' : '') .
        '</div>' .
        '<span class="lr-name">检验报告单</span>' .
        '</div>';

    // ===== 患者信息两行 =====
    $row = get_visit_row((int)$report['visit_id']);
    $pname = $row ? $row['patient']['name'] : '';
    $pgender = $row ? $row['patient']['gender'] : '';
    $pbirth = $row ? $row['patient']['birth_date'] : '';
    $page = $row ? age_format($pbirth, $row['visit']['registered_at']) : '';
    // 快照优先（生成时定格）：申请科室/医生/临床诊断/申请时间/检验时间；
    // 旧报告（未快照）回退实时查询
    $applyDept = trim((string)(isset($report['apply_dept']) ? $report['apply_dept'] : ''));
    $applyDoctor = trim((string)(isset($report['apply_doctor']) ? $report['apply_doctor'] : ''));
    $diag = trim((string)(isset($report['clinical_diag']) ? $report['clinical_diag'] : ''));
    $applyTime = trim((string)(isset($report['apply_time']) ? $report['apply_time'] : ''));
    $regTime = trim((string)(isset($report['reg_time']) ? $report['reg_time'] : ''));
    if ($applyDept === '' || $applyDoctor === '' || $applyTime === '') {
        $orderItem = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array((int)$result['order_item_id']));
        $order = $orderItem ? OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$orderItem['order_id'])) : null;
        if ($applyDept === '' && $order) $applyDept = (string)$order['dept_name'];
        if ($applyDoctor === '' && $order) $applyDoctor = (string)$order['doctor_name'];
        if ($applyTime === '' && $order) $applyTime = (string)$order['created_at'];
        if ($regTime === '' && $orderItem) $regTime = (string)$orderItem['registered_at'];
    }
    if ($diag === '') {
        $pr = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode((string)$pr['emr_data'], true) ?: array()), emr_default_data(null));
            $diags = isset($emr['diagnoses']) && is_array($emr['diagnoses']) ? $emr['diagnoses'] : array();
            if ($diags) $diag = emr_diag_text(array($diags[0]), false);   // 首诊断，不含 ICD10
        }
        if ($diag === '') {
            $mirror = OrderRepository::one("SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
            if ($mirror) $diag = (string)$mirror['preliminary_diagnosis'];
        }
    }
    $li = function ($label, $val) { return '<span class="lr-pcell"><b>' . $label . '：</b>' . e($val) . '</span>'; };
    // 患者信息：隐形 2×4 表格（第一行 姓名 性别 年龄 出生日期；
    // 第二行 患者ID 申请科室 临床诊断 报告单号）
    $html .= '<div class="lr-patgrid">' .
        $li('姓名', $pname) . $li('性别', $pgender) . $li('年龄', $page) . $li('出生日期', $pbirth) .
        $li('患者ID', $report['patient_no']) . $li('申请科室', $applyDept) . $li('临床诊断', $diag) . $li('报告单号', $report['report_no']) .
        '</div>';

    // ===== 结果区：表格头两条实线 + 无边框行（前端分列分页） =====
    $html .= '<div class="lr-result">' .
        '<div class="lr-colhead"><span class="lr-seq">序号</span><span class="lr-item">项目</span>' .
        '<span class="lr-val">结果</span><span class="lr-unit">单位</span><span class="lr-ref">参考范围</span></div>';
    $values = json_decode((string)$result['values_json'], true);
    if (is_array($values) && !empty($values['group'])) {
        // 检验组：按组内成员逐行显示结果（组合项目按组价收费，成员结果分别出具）
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
    $html .= '</div>';

    // ===== 页脚 =====
    $applyTimeD = $applyTime !== '' ? substr($applyTime, 0, 16) : '—';
    $regTimeD = $regTime !== '' ? substr($regTime, 0, 16) : '—';
    $repTime = substr((string)$report['created_at'], 0, 16);
    $note = trim((string)(isset($report['content']) ? $report['content'] : ''));
    $fc = function ($label, $val) { return '<span class="lr-fcell"><b>' . $label . '</b>' . e($val) . '</span>'; };
    // 页脚：隐形 3×3 表格（第一行 申请/检验/报告时间，第二行 申请医生/检验者/审核者，
    // 第三行 前两列合并提示语 + 末列页码）；报告时间靠右，其余靠左
    $html .= '<div class="lr-foot">' .
        '<div class="lr-note">检验备注：' . ($note !== '' ? e($note) : '（无）') . '</div>' .
        '<div class="lr-solid"></div>' .
        '<div class="lr-footgrid">' .
        $fc('申请时间：', $applyTimeD) . $fc('检验时间：', $regTimeD) .
        '<span class="lr-fcell lr-fright"><b>报告时间：</b>' . e($repTime) . '</span>' .
        $fc('申请医生：', $applyDoctor) .
        $fc('检验者：', $report['doctor']) .
        '<span class="lr-fcell"><b>审核者：</b><span class="lr-audit"></span></span>' .
        '<span class="lr-fcell lr-fspan">检验结果仅供临床诊疗参考，仅对送检标本负责！</span>' .
        '<span class="lr-fcell">第 <span class="lr-page">1</span> / <span class="lr-total">1</span> 页</span>' .
        '</div>' .
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