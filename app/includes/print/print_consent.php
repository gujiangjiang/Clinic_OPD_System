<?php
/** print/print_consent.php — 统一打印模板：知情同意书（A5）
 *  页眉（每页重复）：医院抬头 + 第二名称 + XX知情同意书 + 患者信息
 *  正文（分页，参考电子病历）：病情介绍（主诉/初步诊断，仅首页）
 *              → 请仔细阅读以下内容 → 知情同意正文（按行分节点跨页接续）
 *              → 虚线告知提示 → 双列签名
 *  页脚（每页重复）：一式两份提示语（精简，保证页脚低高度→版心大） */

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
    // 知情同意正文（按行分节点供跨页接续）
    $lines = preg_split('/\r\n|\r|\n/', (string)$consent['content']);
    foreach ($lines as $ln) {
        if (trim((string)$ln) === '') {
            $html .= '<div style="height:8px"></div>';
        } else {
            $html .= '<div style="line-height:1.9;font-size:14px">' . e($ln) . '</div>';
        }
    }
    // 虚线告知提示（正文尾部，笼统通用）
    $html .= '<div class="print-note" style="border-top:1px dashed #000;margin-top:16px;padding:8px 0 4px;line-height:1.9;font-size:13px">' .
        '患者/委托人已知晓上述病情介绍与知情同意内容，医生已向我详细解释，' .
        '我已完全理解，愿意承担可能出现的手术/操作风险及并发症，并遵从医嘱，配合治疗。' .
        '</div>';
    // 双列签名（无分隔线，两列靠左对齐，纵向排列）
    $html .= '<div class="print-rec-sign" style="display:flex;gap:30px;padding:8px 0">' .
        '<div style="flex:1">' .
        '<div>患者/委托人签名：<span style="display:inline-block;width:90px;border-bottom:1px solid #000">&nbsp;</span></div>' .
        '<div style="margin-top:8px">签名时间：<span style="display:inline-block;width:100px;border-bottom:1px solid #000">&nbsp;</span></div>' .
        '</div>' .
        '<div style="flex:1">' .
        '<div>医生签名：' . e($doctorName) . '</div>' .
        '<div style="margin-top:8px">签名时间：' . now_str() . '</div>' .
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