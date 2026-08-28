<?php
/** print/print_consent.php — 统一打印模板：知情同意书（A5）
 *  页眉（每页重复）：医院抬头 + 第二名称 + XX知情同意书 + 患者信息
 *  正文（分页）：病情介绍（主诉/初步诊断，仅首页）→ 请仔细阅读以下内容
 *              → 知情同意正文：按段落原样输出（保留换行），作为可拆分文本流
 *               （print-split），分页器在放不下的位置自动把剩余文字自然换行续到
 *               下一页，不按固定字数/标点硬切
 *              → 底部签名区（虚线告知 + 双列签名，print-foot-sec）：正文最底部，
 *               由分页器预留高度，随正文流保留在最后一页
 *  页脚（每页重复，精简）：一式两份提示语 */

/** 签名横线（与文字底对齐）：标签 + flex 弹性下划线 */
function consent_underline_line($label, $width = '') {
    return '<div style="display:flex;align-items:flex-end">' .
        '<span style="flex-shrink:0">' . e($label) . '</span>' .
        '<span style="flex:1;border-bottom:1px solid #000;height:1.1em' .
        ($width !== '' ? ';max-width:' . $width : '') . '"></span></div>';
}

function pt_consent($visit, $patient, $consent, $doctorName, $record) {
    $html = '<div class="print-record-doc">';
    // ===== 页眉（每页重复） =====
    $html .= pt_header($consent['title']);   // 医院名称 + 第二名称 + XX知情同意书
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = pt_age_text($patient, $visit);
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', $name) . $cell('性别', $gender) .
        $cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
        $cell('年龄', $age) . '</div>' .
        '<div class="print-info-line">' .
        $cell('就诊科室', isset($visit['current_dept_name']) ? $visit['current_dept_name'] : '') .
        $cell('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '') .
        $cell('就诊时间', isset($visit['register_time']) ? $visit['register_time'] : '') .
        '</div></div>';
    $html .= '<div class="print-line"></div>';

    // ===== 正文（分页） =====
    $record = is_array($record) ? $record : array();
    // 病情介绍（仅首页）：主诉 + 初步诊断
    $html .= '<div class="consent-sec">';
    $html .= pt_sec('主诉', nl2br(e(isset($record['chief_complaint']) ? strip_tags($record['chief_complaint']) : '')));
    $html .= pt_sec('初步诊断', e(isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : ''));
    $html .= '<div style="border-top:1px dashed #000;margin:8px 0"></div>';
    $html .= '<div style="font-weight:700;padding:2px 0">请仔细阅读以下内容：</div>';
    $html .= '</div>';
    // 知情同意正文：按段落原样输出（保留换行），print-split 文本流由分页器自动续页
    $paras = preg_split('/\r\n\r\n|\n\n/', trim((string)$consent['content']));
    foreach ($paras as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $html .= '<div class="print-split" style="white-space:pre-wrap;line-height:1.9;font-size:14px;word-break:break-all">' . e($p) . '</div>';
    }
    // 底部签名区：正文最底部，由分页器预留高度，保留在正文流的最后一页
    $html .= '<div class="print-foot-sec" style="page-break-inside:avoid;padding-top:14px">' .
        '<div style="border-top:1px dashed #000;padding:8px 0 2px;line-height:1.9;font-size:13px;font-weight:700">' .
        '患者/委托人已知晓上述病情介绍与知情同意内容，医生已向我详细解释，' .
        '我已完全理解，愿意承担可能出现的手术/操作风险及并发症，并遵从医嘱，配合治疗。' .
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
        '<span>本知情同意书一式两份，一份交患者/委托人保管，一份由科室存档。</span>' .
        '</div>';
    $html .= '</div>';
    return $html;
}