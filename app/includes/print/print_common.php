<?php
/** print/print_common.php — 统一打印模板：公共小票/页头/患者信息 helper */

/** 医院名称读取：优先归档行内快照（hospital_name），无快照回退当前系统设置 */
function pt_hosp($row = null) {
    if (is_array($row) && !empty($row['hospital_name'])) return (string)$row['hospital_name'];
    return setting('hospital_name', '');
}

/** 医院第二名称读取：优先归档行内快照（hospital_name2），无快照回退当前系统设置 */
function pt_hosp2($row = null) {
    if (is_array($row) && !empty($row['hospital_name2'])) return (string)$row['hospital_name2'];
    return setting('hospital_name2', '');
}

/** 归档科室名称读取：优先归档行内快照（dept_name/dept_id→名字由调用方处理），无快照回退设置 */
function pt_dept_name($row = null) {
    if (is_array($row) && !empty($row['dept_name'])) return (string)$row['dept_name'];
    return '';
}

function pt_ticket_header($title, $ctx = null) {
    $hosp = pt_hosp($ctx);
    $hosp2 = pt_hosp2($ctx);
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

function pt_header($title, $ctx = null) {
    $hosp = pt_hosp($ctx);
    $hosp2 = pt_hosp2($ctx);
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
        // tz-ok：age_format 内部对存储时间戳做展示换算（fmt_time）后再算年龄；此处传存储原值
        $target = isset($visit['registered_at']) && $visit['registered_at'] !== '' ? $visit['registered_at'] : null;   // tz-ok：age_format 内部换算
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

/** 打印时间展示（唯一入口）：按快照冻结时区（$ctx['__tz']）换算，缺省站点时区。
 *  打印内容中的一切时间一律经此渲染——历史单据重打与开具时刻的本地时间完全一致，
 *  不随全站时区修改或存储方案（local/utc）切换而漂移（法律合规）。
 * @param string     $ts  存储时间串
 * @param array|null $ctx 归档行（含 __tz 冻结时区，由 print.php 注入）
 */
function pt_time($ts, $ctx = null) {
    $tz = (is_array($ctx) && !empty($ctx['__tz'])) ? (string)$ctx['__tz'] : null;
    return fmt_time($ts, $tz);
}

/** 文档页脚（末尾横线 + 左下角时间/右下角打印时间）
 *  $time 由调用方经 pt_time() 预换算（冻结时区）；打印时间=当前时刻按站点时区展示 */
function pt_doc_foot($timeLabel, $time) {
    return '<div class="print-line"></div>' .
        '<div class="print-record-foot">' .
        '<span>' . e($timeLabel) . '：' . e($time) . '</span>' .
        '<span>打印时间：' . fmt_time(now_str()) . '</span></div>';
}

/**
 * 打印文件名（浏览器「另存为 PDF」默认文件名 = document.title）：
 *   医院名称_文书标题_患者姓名_单号_打印时间
 * 前端 print.js 在调起打印前临时把 document.title 切换为返回值，打印返回后恢复。
 * 空段自动略过；非法文件名字符（\ / : * ? " < > | 与控制符）统一替换为 -。
 * @param string     $docTitle    文书标题（如：门诊电子病历 / CT检查报告单 / 门诊处方笺）
 * @param string     $patientName 患者姓名（快照优先，由调用方传入）
 * @param string     $docNo       单号（流水号/检查号/处方号…；为空则略过该段）
 * @param array|null $ctx         归档行（含 hospital_name 快照，同 pt_hosp；可选）
 * @return string
 */
function pt_filename($docTitle, $patientName = '', $docNo = '', $ctx = null) {
    $parts = array(
        pt_hosp($ctx),
        (string)$docTitle,
        (string)$patientName,
        (string)$docNo,
        date('Ymd_His'),
    );
    $parts = array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; });
    return preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '-', implode('_', $parts));
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
 * @param array $report reports 行（含快照字段 apply_dept_name 等，可缺失）
 * @param array $result results 行（含 order_item_id / type）
 * @return array  ctx：row/pname/pgender/pbirth/page/applyDept/applyDoctor/diag/
 *                applyTime/regTime/order/orderItem
 */
function pt_report_context($report, $result) {
    $row = get_visit_row((int)$report['visit_id']);
    $ctx = array(
        'row' => $row,   // tz-ok：age 相关行见下方 age_format（内部换算）
        'pname' => $row ? $row['patient']['name'] : '',
        'pgender' => $row ? $row['patient']['gender'] : '',
        'pbirth' => $row ? $row['patient']['birth_date'] : '',
        'page' => $row ? age_format($row['patient']['birth_date'], $row['visit']['registered_at']) : '',   // tz-ok：age_format 内部换算
        'applyDept' => '', 'applyDoctor' => '', 'diag' => '', 'applyTime' => '', 'regTime' => '',
        'order' => null, 'orderItem' => null,
    );
    // 报告打印快照（法律合规）：出具时刻患者资料优先，无快照兼容旧数据回退现患者表
    $snap = isset($report['id']) ? snapshot_get('report', (int)$report['id']) : null;
    if ($snap) {
        if ($snap['patient_name'] !== '') $ctx['pname'] = $snap['patient_name'];
        if ($snap['gender'] !== '') $ctx['pgender'] = $snap['gender'];
        if ($snap['birth_date'] !== '') {
            $ctx['pbirth'] = $snap['birth_date'];
            $ctx['page'] = age_format($snap['birth_date'], $row && $row['visit'] ? $row['visit']['registered_at'] : '');   // tz-ok：age_format 内部换算
        }
    }
    // 快照字段读取（reports 行键为规范化列名：applied_at / registered_at /
    // apply_dept_name / apply_doctor_name / clinical_diagnosis）
    // tz-ok：以下 ctx 时间保持存储原值（模板渲染时经 pt_time 按冻结时区换算）
    $reportFieldMap = array('applyDept' => 'apply_dept_name', 'applyDoctor' => 'apply_doctor_name',
        'diag' => 'clinical_diagnosis', 'applyTime' => 'applied_at', 'regTime' => 'registered_at');   // tz-ok：模板 pt_time 换算
    foreach ($reportFieldMap as $ctxKey => $col) {
        if (isset($report[$col]) && $report[$col] !== '' && $report[$col] !== null) {
            $ctx[$ctxKey] = trim((string)$report[$col]);
        }
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
        if ($ctx['applyTime'] === '') $ctx['applyTime'] = (string)$order['created_at'];   // tz-ok：存储原值（模板 pt_time 换算）
    }
    if ($ctx['regTime'] === '' && $orderItem) $ctx['regTime'] = (string)$orderItem['registered_at'];   // tz-ok：存储原值（同上）
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
