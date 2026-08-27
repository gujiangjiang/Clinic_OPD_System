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

/** 结构化病历默认骨架（新病历/字段缺失回退；$patient 传入时预填患者既往史/过敏史） */
function emr_default_data($patient = null) {
    $phType = '否认';
    $phDetail = '';
    $alType = '否认';
    $alDetail = '';
    if ($patient) {
        // 跨就诊自动调用：患者主表存有历史既往史/过敏史时预填（以最新一次保存为准）
        if (!empty($patient['past_history_type'])) $phType = $patient['past_history_type'];
        if (!empty($patient['past_history_detail'])) $phDetail = $patient['past_history_detail'];
        // 患者主表 allergies 存纯文本摘要：非空即视为「承认」并回填细节
        if (!empty($patient['allergies'])) {
            $alType = '承认';
            $alDetail = $patient['allergies'];
        }
    }
    return array(
        // 病历续写（progress 文书专用）：续写内容为该文书顶部必填项；
        // 首诊（initial）文书中恒为空、不参与校验与打印
        'progress' => array('content' => ''),
        'chief_complaint' => array('symptom' => '', 'duration' => '', 'unit' => '', 'second_symptom' => '', 'second_duration' => '', 'second_unit' => ''),
        'history_present' => array('informant' => '', 'duration' => '', 'unit' => '', 'content' => '', 'arrival_way' => ''),
        'past_history' => array('type' => $phType, 'detail' => $phDetail),
        'allergies' => array('type' => $alType, 'detail' => $alDetail),
        'main_symptoms' => array(
            '全身症状' => '', '呼吸道症状' => '', '消化道症状' => '',
            '皮疹症状' => '', '出血症状' => '', '神经系统症状' => '',
        ),
        'physical_exam' => array(
            '皮肤黏膜' => '', '头部' => '', '胸部' => '', '肺脏及胸膜' => '', '心脏' => '',
            '腹部' => '', '神经反射' => '', '肌力及肌张力' => '', '其它体格检查' => '',
        ),
        'diagnoses' => array(),
        'aux_result' => '',
        'aux_external' => '',
        'disposition_custom' => '',
        'is_leave_hospital' => '否',
        'advice' => '',
    );
}

/** 递归合并：保证 emr_data 具备全部结构键（旧草稿/缺键回退默认值） */
function emr_merge_defaults($data, $defaults) {
    foreach ($defaults as $k => $v) {
        if (!isset($data[$k]) || $data[$k] === null) {
            $data[$k] = $v;
        } elseif (is_array($v) && !isset($v[0])) {
            // 关联数组（子结构）且数据侧同为数组时才递归合并；
            // 数据侧为字符串等标量（如旧版 allergies 纯文本）时保留原值，
            // 由 emr_normalize 统一归一化，避免对字符串取数组偏移导致致命错误
            if (is_array($data[$k])) {
                $data[$k] = emr_merge_defaults($data[$k], $v);
            }
        }
    }
    return $data;
}

/** 旧格式归一化：allergies 曾为纯文本字符串 → 结构化（非空视为承认） */
function emr_normalize($emr) {
    if (isset($emr['allergies']) && !is_array($emr['allergies'])) {
        $old = trim((string)$emr['allergies']);
        $emr['allergies'] = array('type' => $old !== '' ? '承认' : '否认', 'detail' => $old);
    }
    return $emr;
}

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

/**
 * 处方条目 → 展示行（成组医嘱树形格式，病历打印快照/实时渲染统一调用）：
 * · 组内主药行（首条）：名称　剂量　频次　途径　×数量；
 * · 子药行：├─ / └─（树形连接符）+ 名称 + 剂量（临床必填）；
 *   频次/途径/数量组内一致仅主药行显示一次；
 * · 非成组药品（group_no=0）各自独立一行全要素显示。
 * @param array $items 处方 order_items（含 group_no）
 * @return array 展示行数组
 */
function emr_rx_display_lines($items) {
    $lines = array();
    $fullLine = function ($it) {
        $p = array();
        if (!empty($it['single_dose'])) $p[] = $it['single_dose'];
        if (!empty($it['frequency_name'])) $p[] = $it['frequency_name'];
        if (!empty($it['route_name'])) $p[] = $it['route_name'];
        return $it['item_name'] . ($p ? '　' . implode('　', $p) : '') . '　×' . (int)$it['quantity'];
    };
    $n = count($items);
    $i = 0;
    while ($i < $n) {
        $it0 = $items[$i];
        if (empty($it0['item_name'])) { $i++; continue; } // 防空名明细混入病历文本
        $g = isset($it0['group_no']) ? (int)$it0['group_no'] : 0;
        if (!$g) { $lines[] = $fullLine($it0); $i++; continue; }
        $arr = array($it0);
        $j = $i + 1;
        while ($j < $n && isset($items[$j]) && (int)$items[$j]['group_no'] === $g) { $arr[] = $items[$j]; $j++; }
        foreach ($arr as $idx => $x) {
            if (empty($x['item_name'])) continue; // 防空名明细混入病历文本
            if ($idx === 0) { $lines[] = $fullLine($x); continue; }
            $head = ($idx === count($arr) - 1 ? '└─ ' : '├─ ') . $x['item_name'];
            if (!empty($x['single_dose'])) $head .= '　' . $x['single_dose'];
            $lines[] = $head;
        }
        $i = $j;
    }
    return $lines;
}

/** 是否留观文本 */
function emr_obs_text($emr) {
    return (isset($emr['is_leave_hospital']) && $emr['is_leave_hospital'] === '是') ? '是' : '否';
}

/** 诊断聚合显示顺序键（visit+医生维度，跨医生排序载体；无记录返回空数组） */
function diag_order_keys($visitId, $doctorId) {
    $row = DB::one('medical', 'SELECT ord_keys FROM diag_orders WHERE visit_id=? AND doctor_id=?', array($visitId, $doctorId));
    if (!$row || trim((string)$row['ord_keys']) === '') return array();
    $keys = explode("\n", (string)$row['ord_keys']);
    $out = array();
    foreach ($keys as $k) {
        $k = trim($k);
        if ($k !== '') $out[] = $k;
    }
    return $out;
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
    $emr = is_array($emr) ? $emr : array();
    $secs = array();
    // 病历续写（progress 续写文书专用，置顶输出；首诊文书恒为空自动跳过）
    $progContent = isset($emr['progress']['content']) ? trim((string)$emr['progress']['content']) : '';
    if ($progContent !== '') $secs[] = array('病历续写', $progContent);
    $secs[] = array('主诉', emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array()));
    $secs[] = array('现病史', emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array()));
    $secs[] = array('既往史', emr_ph_text(isset($emr['past_history']) ? $emr['past_history'] : array()));
    // 兼容旧数据：allergies 曾为纯文本字符串
    $alRaw = isset($emr['allergies']) ? $emr['allergies'] : '';
    $secs[] = array('过敏史', is_array($alRaw) ? emr_al_text($alRaw) : (string)$alRaw);
    $ms = emr_ms_text(isset($emr['main_symptoms']) ? $emr['main_symptoms'] : array());
    if ($ms !== '') $secs[] = array('主要症状', $ms);
    // 生命体征恒显示（未录入显示 -，首诊/续写一致）
    $secs[] = array('生命体征', $vitalsText !== '' ? $vitalsText : '-');
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
