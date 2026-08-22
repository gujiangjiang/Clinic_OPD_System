<?php
/**
 * ============================================================
 * emr_formatter.php — 结构化电子病历文本格式化（服务端唯一实现）
 * ============================================================
 * 说明：
 * 1. 前端编辑器（emreditor.js）按同一规则做所见即所得展示；
 *    打印预览与入库快照（patient_records.emr_print_text）统一走本文件。
 * 2. 占位符规则：未填写的部分直接忽略不输出；
 *    体格检查/辅助检查/门诊处置整节为空时输出「-」。
 * 3. $emr 为 emr_data 解码后的结构化数组（见 record.php save 注释）。
 * ============================================================ */

/** 主诉：主要症状+时间+单位 [次要症状+时间+单位]，如 腰痛1天加重1小时 */
function emr_cc_text($cc) {
    $cc = is_array($cc) ? $cc : array();
    $seg = function ($s, $d, $u) {
        return $s . ($d !== '' && $d !== null ? $d : '') . ($u !== '' && $u !== null ? $u : '');
    };
    $main = $seg(isset($cc['symptom']) ? $cc['symptom'] : '', isset($cc['duration']) ? $cc['duration'] : '', isset($cc['unit']) ? $cc['unit'] : '');
    $second = $seg(isset($cc['second_symptom']) ? $cc['second_symptom'] : '', isset($cc['second_duration']) ? $cc['second_duration'] : '', isset($cc['second_unit']) ? $cc['second_unit'] : '');
    return $main . $second;
}

/** 现病史：供史者+时间+单位+具体内容[，来院途径] */
function emr_pi_text($pi) {
    $pi = is_array($pi) ? $pi : array();
    $g = function ($k) use ($pi) { return isset($pi[$k]) ? $pi[$k] : ''; };
    $head = $g('informant') . $g('duration') . $g('unit') . $g('content');
    $way = $g('arrival_way');
    if ($head === '' && $way === '') return '';
    if ($head !== '' && $way !== '') return $head . '，' . $way;
    return $head . $way;
}

/** 既往史：否认 | 承认，详细内容 */
function emr_ph_text($ph) {
    $ph = is_array($ph) ? $ph : array();
    $type = isset($ph['type']) && $ph['type'] !== '' ? $ph['type'] : '否认';
    if ($type === '否认') return '否认';
    $detail = isset($ph['detail']) ? $ph['detail'] : '';
    return $detail !== '' ? '承认，' . $detail : '承认';
}

/** 过敏史：否认 | 承认，细节内容 */
function emr_al_text($al) {
    $al = is_array($al) ? $al : array();
    $type = isset($al['type']) && $al['type'] !== '' ? $al['type'] : '否认';
    if ($type !== '承认') return '否认';
    $detail = isset($al['detail']) ? $al['detail'] : '';
    return $detail !== '' ? $detail : '承认';
}

/** 主要症状：仅输出已选类别（全身症状：发热，呼吸道症状：咳嗽）；全空返回 '' */
function emr_ms_text($ms) {
    $ms = is_array($ms) ? $ms : array();
    $out = array();
    foreach ($ms as $cat => $val) {
        if ($val !== '' && $val !== null) $out[] = $cat . '：' . $val;
    }
    return implode('，', $out);
}

/** 体格检查：已填项「名称：值」逗号连接；全空返回 '-' */
function emr_pe_text($pe) {
    $pe = is_array($pe) ? $pe : array();
    $out = array();
    foreach ($pe as $cat => $val) {
        if ($val !== '' && $val !== null) $out[] = $cat . '：' . $val;
    }
    return $out ? implode('，', $out) : '-';
}

/** 初步诊断：编码 部位 名称（备注）疑似?，多诊断逗号分隔 */
function emr_diag_text($diagnoses) {
    if (!is_array($diagnoses)) return '';
    $out = array();
    foreach ($diagnoses as $dg) {
        if (!is_array($dg)) continue;
        $name = isset($dg['name']) ? $dg['name'] : '';
        if ($name === '') continue;
        $part = isset($dg['part']) ? $dg['part'] : '';
        $note = isset($dg['note']) ? $dg['note'] : '';
        $sus = isset($dg['suspected']) && $dg['suspected'] === '是' ? '?' : '';
        $s = ($part !== '' ? $part : '') . $name . ($note !== '' ? '（' . $note . '）' : '') . $sus;
        if (isset($dg['code']) && $dg['code'] !== '') $s = $dg['code'] . ' ' . $s;
        $out[] = $s;
    }
    return implode('，', $out);
}

/** 辅助检查：已开项目名 + 手工结果 + 外院结果；全空返回 '-' */
function emr_aux_text($emr, $orderNames) {
    $g = function ($k) use ($emr) { return isset($emr[$k]) ? $emr[$k] : ''; };
    $parts = array();
    if (is_array($orderNames)) {
        foreach ($orderNames as $n) {
            if ($n !== '' && $n !== null) $parts[] = $n;
        }
    }
    foreach (array('aux_result', 'aux_external') as $k) {
        if ($g($k) !== '') $parts[] = $g($k);
    }
    return $parts ? implode('，', $parts) : '-';
}

/** 门诊处置：处方一行一条 + 处置项（含数量）+ 自定义内容；全空返回 '-' */
function emr_disp_text($emr, $rxLines, $dispItems) {
    $lines = array();
    if (is_array($rxLines)) foreach ($rxLines as $l) { if ($l !== '') $lines[] = $l; }
    $disps = array();
    if (is_array($dispItems)) {
        foreach ($dispItems as $it) {
            $name = is_array($it) ? (isset($it['name']) ? $it['name'] : '') : $it;
            $qty = is_array($it) && isset($it['qty']) ? (int)$it['qty'] : 0;
            if ($name === '') continue;
            $disps[] = $name . ($qty > 1 ? 'x' . $qty : '');
        }
    }
    $custom = isset($emr['disposition_custom']) ? $emr['disposition_custom'] : '';
    if ($custom !== '') $disps[] = $custom;
    if (count($disps)) $lines[] = implode('，', $disps);
    return $lines ? implode("\n", $lines) : '-';
}

/** 是否留观文本 */
function emr_obs_text($emr) {
    return (isset($emr['is_leave_hospital']) && $emr['is_leave_hospital'] === '是') ? '是' : '否';
}

/**
 * 完整打印文本（按病历文书节顺序；供入库快照与打印预览复用）
 * @param array  $emr         结构化病历数据
 * @param string $vitalsText  生命体征文本（无则空串）
 * @param string $consciousness 意识状态
 * @param array  $orderNames  已开检验/检查项目名列表
 * @param array  $rxLines     处方行列表（每行一个药品）
 * @param array  $dispItems   处置项列表 [['name'=>'小清创','qty'=>2], ...]
 * @return string
 */
function emr_print_text($emr, $vitalsText = '', $consciousness = '', $orderNames = array(), $rxLines = array(), $dispItems = array()) {
    $secs = array(
        array('主诉', emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array())),
        array('现病史', emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array())),
        array('既往史', emr_ph_text(isset($emr['past_history']) ? $emr['past_history'] : array())),
        array('过敏史', isset($emr['allergies']) ? $emr['allergies'] : ''),
    );
    $ms = emr_ms_text(isset($emr['main_symptoms']) ? $emr['main_symptoms'] : array());
    if ($ms !== '') $secs[] = array('主要症状', $ms);
    if ($vitalsText !== '') $secs[] = array('生命体征', $vitalsText);
    if ($consciousness !== '') $secs[] = array('意识状态', $consciousness);
    $secs[] = array('体格检查', emr_pe_text(isset($emr['physical_exam']) ? $emr['physical_exam'] : array()));
    $diag = emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array());
    $secs[] = array('初步诊断', $diag);
    $secs[] = array('辅助检查', emr_aux_text($emr, $orderNames));
    $secs[] = array('门诊处置', emr_disp_text($emr, $rxLines, $dispItems));
    $secs[] = array('是否留观', emr_obs_text($emr));
    $advice = isset($emr['advice']) ? $emr['advice'] : '';
    if ($advice !== '') $secs[] = array('嘱托', $advice);
    $out = array();
    foreach ($secs as $s) {
        if ($s[1] === '' || $s[1] === null) continue;
        $out[] = $s[0] . '：' . $s[1];
    }
    return implode("\n", $out);
}
