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
        $li('患者ID', $report['patient_no']) . $li('申请科室', $applyDept) . $li('临床诊断', $diag) .
        '<span class="lr-pcell lr-pcell-no"><b>报告单号：</b><span class="lr-reportno">' . e($report['report_no']) . '</span></span>' .
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
        $fc('报告时间：', $repTime) .
        $fc('申请医生：', $applyDoctor) .
        $fc('检验者：', $report['doctor']) .
        '<span class="lr-fcell"><b>审核者：</b><span class="lr-audit"></span></span>' .
        '<span class="lr-fcell lr-fspan">检验结果仅供临床诊疗参考，仅对送检标本负责！</span>' .
        '<span class="lr-fcell lr-fright">第 <span class="lr-page">1</span> / <span class="lr-total">1</span> 页</span>' .
        '</div>' .
        '</div>';

    $html .= '</div>';
    return $html;
}

/** 检查报告单：A4 纵向固定画布（页眉 4×3 患者信息 / 正文所见 2/3 + 诊断 1/3 / 页脚 3×3） */
function pt_imaging_report($report, $result, $item) {
    $html = '<div class="print-record-doc imr-doc">';

    // ===== 抬头（急诊病历版式：医院名称/第二名称两端对齐，标题在其下方） =====
    // ===== 患者信息（4×3 隐形表格） =====
    $row = get_visit_row((int)$report['visit_id']);
    $pname = $row ? $row['patient']['name'] : '';
    $pgender = $row ? $row['patient']['gender'] : '';
    $pbirth = $row ? $row['patient']['birth_date'] : '';
    $page = $row ? age_format($pbirth, $row['visit']['registered_at']) : '';
    // 快照优先；旧报告回退实时查询
    $applyDept = trim((string)(isset($report['apply_dept']) ? $report['apply_dept'] : ''));
    $applyDoctor = trim((string)(isset($report['apply_doctor']) ? $report['apply_doctor'] : ''));
    $diag = trim((string)(isset($report['clinical_diag']) ? $report['clinical_diag'] : ''));
    $applyTime = trim((string)(isset($report['apply_time']) ? $report['apply_time'] : ''));
    $regTime = trim((string)(isset($report['reg_time']) ? $report['reg_time'] : ''));
    $orderItem = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array((int)$result['order_item_id']));
    $order = $orderItem ? OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$orderItem['order_id'])) : null;
    // 报告单名称动态化：按检查分类（CT/DR/超声…）显示「XX检查报告单」
    // 快照 category_name 优先，其次申请单/检查项目分类回退
    $catName = trim((string)(isset($report['category_name']) ? $report['category_name'] : ''));
    if ($catName === '' && $order && !empty($order['category_name'])) {
        $catName = trim((string)$order['category_name']);
    }
    if ($catName === '' && $item && !empty($item['category']) && trim((string)$item['category']) !== '检查') {
        $catName = trim((string)$item['category']);
    }
    $title = $catName !== '' ? $catName . '检查报告单' : '检查报告单';
    $html .= pt_header($title);
    if (($applyDept === '' || $applyDoctor === '' || $applyTime === '') && $order) {
        if ($applyDept === '') $applyDept = (string)$order['dept_name'];
        if ($applyDoctor === '') $applyDoctor = (string)$order['doctor_name'];
        if ($applyTime === '') $applyTime = (string)$order['created_at'];
    }
    if ($regTime === '' && $orderItem) $regTime = (string)$orderItem['registered_at'];
    if ($diag === '') {
        $pr = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode((string)$pr['emr_data'], true) ?: array()), emr_default_data(null));
            $diags = isset($emr['diagnoses']) && is_array($emr['diagnoses']) ? $emr['diagnoses'] : array();
            if ($diags) $diag = emr_diag_text(array($diags[0]), false);
        }
        if ($diag === '') {
            $mirror = OrderRepository::one("SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
            if ($mirror) $diag = (string)$mirror['preliminary_diagnosis'];
        }
    }
    // 项目：该申请单全部检查项目逗号连接
    $itemNames = array();
    if ($order) {
        foreach (OrderRepository::q("SELECT item_name FROM order_items WHERE order_id=? AND item_type='imaging' ORDER BY id", array((int)$order['id'])) as $oi2) {
            $itemNames[] = (string)$oi2['item_name'];
        }
    }
    if (!$itemNames) $itemNames[] = isset($item['name']) ? $item['name'] : '';
    $itemsStr = implode('，', $itemNames);

    $pc = function ($label, $val) { return '<span class="imr-cell"><b>' . $label . '：</b>' . e($val) . '</span>'; };
    $html .= '<div class="imr-patgrid">' .
        $pc('姓名', $pname) . $pc('性别', $pgender) . $pc('年龄', $page) . $pc('出生日期', $pbirth) .
        $pc('患者ID', $report['patient_no']) . $pc('申请科室', $applyDept) . $pc('临床诊断', $diag) .
        '<span class="imr-cell imr-cell-no"><b>报告单号：</b><span class="imr-reportno">' . e($report['report_no']) . '</span></span>' .
        '<span class="imr-cell imr-cell-proj"><b>检查项目：</b>' . e($itemsStr) . '</span>' .
        '</div>';

    // ===== 正文：影像所见 2/3 + 影像诊断 1/3 =====
    $html .= '<div class="imr-body">' .
        '<div class="imr-sec imr-sec-findings"><div class="imr-sec-label">影像所见</div>' .
        '<div class="imr-sec-content">' . nl2br(e((string)$result['findings'])) . '</div></div>' .
        '<div class="imr-sec imr-sec-conclusion"><div class="imr-sec-label">影像诊断</div>' .
        '<div class="imr-sec-content">' . nl2br(e((string)$result['conclusion'])) . '</div></div>' .
        '</div>';

    // ===== 页脚（3×3 隐形表格） =====
    $applyTimeD = $applyTime !== '' ? substr($applyTime, 0, 16) : '—';
    $regTimeD = $regTime !== '' ? substr($regTime, 0, 16) : '—';
    $repTime = substr((string)$report['created_at'], 0, 16);
    $fc = function ($label, $val) { return '<span class="imr-cell"><b>' . $label . '</b>' . e($val) . '</span>'; };
    $html .= '<div class="imr-footgrid">' .
        $fc('申请医生：', $applyDoctor) .
        $fc('报告医生：', $report['doctor']) .
        '<span class="imr-cell"><b>审核医生：</b><span class="imr-audit"></span></span>' .
        $fc('申请时间：', $applyTimeD) . $fc('检查时间：', $regTimeD) . $fc('报告时间：', $repTime) .
        '<span class="imr-cell imr-foot-tip">仅供医师诊断参考，不做其他用途</span>' .
        '<span class="imr-cell imr-foot-page">第 <span class="imr-page">1</span> / <span class="imr-total">1</span> 页</span>' .
        '</div>';

    $html .= '</div>';
    return $html;
}

function pt_report($report, $result, $item) {
    $title = ($result['type'] === 'lab') ? '检验报告单' : '检查报告单';
    // 检验报告走独立横向 A5 版式；检查报告走独立 A4 纵向版式
    if ($result['type'] === 'lab') {
        return pt_lab_report($report, $result, $item);
    }
    if ($result['type'] === 'imaging') {
        return pt_imaging_report($report, $result, $item);
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