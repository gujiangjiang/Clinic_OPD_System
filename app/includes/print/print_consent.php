<?php
/** print/print_consent.php — 统一打印模板：知情同意/告知文书（A5）
 *  页眉（每页重复）：医院抬头 + 第二名称 + 自定义标题 + 患者信息
 *  正文（分页）：病情介绍（按勾选节逐节独立节点，空节自动不显示，仅首页）
 *              → 请仔细阅读以下内容
 *              → 告知正文：按段落原样输出（保留换行），作为可拆分文本流
 *               （print-split），分页器在放不下的位置自动把剩余文字自然换行续到
 *               下一页，不按固定字数/标点硬切——逐节独立节点 + 文本流共同保证
 *               勾选节增减时分页时机动态精确
 *              → 底部签名区（虚线告知内容 + 双列签名，print-foot-sec）：正文最底部，
 *               由分页器预留高度，随正文流保留在最后一页
 *  页脚（每页重复，精简）：一式两份提示语 */

/** 签名横线（与文字底对齐）：标签 + flex 弹性下划线 */
function consent_underline_line($label, $width = '') {
    return '<div style="display:flex;align-items:flex-end">' .
        '<span style="flex-shrink:0">' . e($label) . '</span>' .
        '<span style="flex:1;border-bottom:1px solid #000;height:1.1em' .
        ($width !== '' ? ';max-width:' . $width : '') . '"></span></div>';
}

/**
 * 知情同意/告知文书打印
 * @param array $visit    就诊信息
 * @param array $patient  患者信息
 * @param array $consent  consents 行（title/content/notice/emr_snapshot/legacy_record）
 * @param string $doctorName 开具医生
 */
function pt_consent($visit, $patient, $consent, $doctorName) {
    $html = '<div class="print-record-doc">';
    // ===== 页眉（每页重复） =====
    $html .= pt_header($consent['title']);   // 医院名称 + 第二名称 + 自定义文书标题
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = pt_age_text($patient, $visit);
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        pt_info_cell('姓名', $name) . pt_info_cell('性别', $gender) .
        pt_info_cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
        pt_info_cell('年龄', $age) . '</div>' .
        '<div class="print-info-line">' .
        // 就诊科室：优先取文书开具时固化科室（dept_name），旧数据回退当前就诊科室
        pt_info_cell('就诊科室', !empty($consent['dept_name']) ? $consent['dept_name'] : (isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '')) .
        pt_info_cell('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '') .
        pt_info_cell('就诊时间', isset($visit['registered_at']) ? $visit['registered_at'] : '') .
        '</div></div>';
    $html .= '<div class="print-line"></div>';

    // ===== 正文（分页） =====
    // 病情介绍：按勾选节【逐节独立节点】渲染——分页器逐节点测高分配，
    // 节数增减（复选框勾选变化/空节过滤）时分页时机动态精确；空节自动不显示。
    // 快照来源：consent.emr_snapshot（开具/编辑时固化）；旧数据（无快照）回退
    // legacy_record（主诉/现病史/初步诊断实时投影，旧行为兼容）。
    $labels = consent_section_keys();
    $sections = array();
    $dataMap = array();
    $snap = isset($consent['emr_snapshot']) && is_array($consent['emr_snapshot']) ? $consent['emr_snapshot'] : null;
    if ($snap && !empty($snap['data'])) {
        foreach (consent_section_filter(isset($snap['sections']) ? $snap['sections'] : array()) as $k) {
            $v = isset($snap['data'][$k]) ? trim((string)$snap['data'][$k]) : '';
            if ($v !== '' && $v !== '-') $sections[$k] = $v;   // 空节/无内容占位不显示
        }
    } else {
        $legacy = isset($consent['legacy_record']) && is_array($consent['legacy_record']) ? $consent['legacy_record'] : array();
        foreach (array('chief_complaint', 'present_illness', 'preliminary_diagnosis') as $k) {
            $v = trim((string)(isset($legacy[$k]) ? $legacy[$k] : ''));
            if ($v !== '') $sections[$k] = $v;
        }
    }
    foreach ($sections as $k => $v) {
        $html .= pt_sec($labels[$k], nl2br(e(strip_tags($v))));
    }
    $html .= '<div style="border-top:1px dashed #000;margin:8px 0"></div>';
    $html .= '<div style="font-weight:700;padding:2px 0">请仔细阅读以下内容：</div>';
    // 告知正文：按段落原样输出（保留换行），print-split 文本流由分页器自动续页
    $paras = preg_split('/\r\n\r\n|\n\n/', trim((string)$consent['content']));
    foreach ($paras as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $html .= '<div class="print-split" style="white-space:pre-wrap;line-height:1.9;font-size:14px;word-break:break-all">' . e($p) . '</div>';
    }
    // 底部签名区：告知内容（开具时固化，可自定义话术；旧数据回退默认话术）+ 双列签名
    $notice = trim((string)(isset($consent['notice']) ? $consent['notice'] : ''));
    if ($notice === '') $notice = consent_default_notice();
    $html .= '<div class="print-foot-sec" style="page-break-inside:avoid;padding-top:14px">' .
        '<div style="border-top:1px dashed #000;padding:8px 0 2px;line-height:1.9;font-size:13px;font-weight:700">' .
        e($notice) .
        '</div>' .
        '<div style="display:flex;gap:32px;padding:6px 0">' .
        '<div style="flex:1;text-align:left">' .
        consent_underline_line('患者/委托人签名：', '120px') .
        '<div style="margin-top:8px">' . consent_underline_line('签名时间：', '140px') . '</div>' .
        '</div>' .
        '<div style="flex:1;text-align:left">' .
        '<div>医生签名：' . e($doctorName) . '</div>' .
        '<div style="margin-top:8px">签名时间：' . now_str() . '</div>' .
        '</div>' .
        '</div>' .
        '</div>';

    // ===== 页脚（每页重复，精简） =====
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot" style="font-size:12px">' .
        '<span>本' . e($consent['title']) . '一式两份，一份交患者/委托人保管，一份由科室存档。</span>' .
        '</div>';
    $html .= '</div>';
    return $html;
}
