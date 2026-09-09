<?php
/** print/print_common.php — 统一打印模板：公共小票/页头/患者信息 helper */

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

function pt_ticket_row($label, $value) {
    return '<div class="ticket-row"><span>' . e($label) . '</span><span class="ticket-val">' . e($value) . '</span></div>';
}

function pt_header($title) {
    $hosp = setting('hospital_name', '');
    $hosp2 = setting('hospital_name2', '');
    // 抬头块：以第一名称长度为标准宽度，第二名称（若存在）左右两端与第一名称对齐
    $h = '<div class="print-hosp-block">';
    if ($hosp !== '') {
        $h .= '<div class="print-hosp">' . e($hosp) . '</div>';
    }
    if ($hosp2 !== '') {
        $h .= '<div class="print-sub">' . e($hosp2) . '</div>';
    }
    $h .= '</div>';
    $h .= '<div class="print-title-line">' . e($title) . '</div>';
    return $h;
}

function pt_age_text($patient, $visit) {
    $birth = isset($patient['birth_date']) && $patient['birth_date'] !== '' ? $patient['birth_date']
        : (isset($visit['birth_date']) && $visit['birth_date'] !== '' ? $visit['birth_date'] : '');
    if ($birth !== '') {
        $target = isset($visit['registered_at']) && $visit['registered_at'] !== '' ? $visit['registered_at'] : null;
        $s = age_format($birth, $target);
        if ($s !== '') return $s;
    }
    if (isset($visit['age']) && $visit['age'] !== '' && $visit['age'] !== null) return (int)$visit['age'] . '岁';
    if (isset($patient['age']) && $patient['age'] !== '' && $patient['age'] !== null) return (int)$patient['age'] . '岁';
    return '';
}

/** 打印信息格：键值对（空值回退 —） */
function pt_info_cell($k, $val) {
    $val = ($val !== '' && $val !== null) ? $val : '—';
    return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
}

/**
 * 报告格：键值对（行内 <b> 标签 + 自定义容器类）。
 * 检验报告（lr-pcell）与检查报告（imr-cell）共用，仅容器类不同。
 */
function pt_cell($label, $val, $cls) {
    return '<span class="' . e($cls) . '"><b>' . e($label) . '：</b>' . e($val) . '</span>';
}

/** 条形码块（文档类打印）：单号文字内嵌 SVG（textLength 与条码黑条区等宽对齐） */
function pt_barcode($code) {
    return '<div class="print-record-barcode">' . barcode128_svg($code, 44, 1, true) . '</div>';
}

/** 文档页脚（末尾横线 + 左下角时间/右下角打印时间） */
function pt_doc_foot($timeLabel, $time) {
    return '<div class="print-line"></div>' .
        '<div class="print-record-foot">' .
        '<span>' . e($timeLabel) . '：' . e($time) . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
}

function pt_sec($label, $body) {
    return '<div class="record-section"><div class="sec-label">' . e($label) . '</div>' .
        '<div class="sec-body">' . $body . '</div></div>';
}

/**
 * 报告打印上下文（检验/检查报告共用）：患者信息 + 快照固化字段回退。
 * 快照优先（生成时定格）：申请科室/申请医生/临床诊断/申请时间/登记时间，
 * 旧报告（未快照）回退实时查询 orders/order_items，诊断再回退结构化病历
 * 首诊断（不含 ICD10）与旧镜像表 preliminary_diagnosis。
 * @param array $report reports 行（含快照字段 apply_dept 等，可缺失）
 * @param array $result results 行（含 order_item_id / type）
 * @return array  ctx：row/pname/pgender/pbirth/page/applyDept/applyDoctor/diag/
 *                applyTime/regTime/order/orderItem
 */
function pt_report_context($report, $result) {
    $row = get_visit_row((int)$report['visit_id']);
    $ctx = array(
        'row' => $row,
        'pname' => $row ? $row['patient']['name'] : '',
        'pgender' => $row ? $row['patient']['gender'] : '',
        'pbirth' => $row ? $row['patient']['birth_date'] : '',
        'page' => $row ? age_format($row['patient']['birth_date'], $row['visit']['registered_at']) : '',
        'applyDept' => '', 'applyDoctor' => '', 'diag' => '', 'applyTime' => '', 'regTime' => '',
        'order' => null, 'orderItem' => null,
    );
    foreach (array('applyDept', 'applyDoctor', 'diag', 'applyTime', 'regTime') as $k) {
        if (isset($report[$k])) $ctx[$k] = trim((string)$report[$k]);
    }
    // 旧报告（未快照）回退实时查询
    $orderItem = null;
    if (!empty($result['order_item_id'])) {
        $orderItem = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array((int)$result['order_item_id']));
    }
    $order = $orderItem ? OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$orderItem['order_id'])) : null;
    $ctx['order'] = $order;
    $ctx['orderItem'] = $orderItem;
    if ($order) {
        if ($ctx['applyDept'] === '') $ctx['applyDept'] = (string)$order['dept_name'];
        if ($ctx['applyDoctor'] === '') $ctx['applyDoctor'] = (string)$order['doctor_name'];
        if ($ctx['applyTime'] === '') $ctx['applyTime'] = (string)$order['created_at'];
    }
    if ($ctx['regTime'] === '' && $orderItem) $ctx['regTime'] = (string)$orderItem['registered_at'];
    if ($ctx['diag'] === '') {
        $pr = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode((string)$pr['emr_data'], true) ?: array()), emr_default_data(null));
            $diags = isset($emr['diagnoses']) && is_array($emr['diagnoses']) ? $emr['diagnoses'] : array();
            if ($diags) $ctx['diag'] = emr_diag_text(array($diags[0]), false);   // 首诊断，不含 ICD10
        }
        if ($ctx['diag'] === '') {
            $mirror = OrderRepository::one("SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!='' ORDER BY id ASC LIMIT 1", array((int)$report['visit_id']));
            if ($mirror) $ctx['diag'] = (string)$mirror['preliminary_diagnosis'];
        }
    }
    return $ctx;
}
