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

/** 打印信息格：键值对（空值回退 —） */
function pt_info_cell($k, $val) {
    $val = ($val !== '' && $val !== null) ? $val : '—';
    return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
}

/** 条形码块（文档类打印） */
function pt_barcode($code) {
    return '<div class="print-record-barcode">' . barcode128_svg($code) . '<div>' . e($code) . '</div></div>';
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
