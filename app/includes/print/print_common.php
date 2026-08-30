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
    $h = '<div class="print-hosp">' . e($hosp) . '</div>';
    if ($hosp2 !== '') {
        $h .= '<div class="print-sub">' . e($hosp2) . '</div>';
    }
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
        '挂号时间' => isset($visit['registered_at']) ? $visit['registered_at'] : '',
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

function pt_sec($label, $body) {
    return '<div class="record-section"><div class="sec-label">' . e($label) . '</div>' .
        '<div class="sec-body">' . $body . '</div></div>';
}
