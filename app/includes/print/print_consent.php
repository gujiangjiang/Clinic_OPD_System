<?php
/** print/print_consent.php — 统一打印模板：知情同意书（A5）
 *  头部（每页重复）：医院抬头 + 第二名称 + XX知情同意书 + 患者信息 +
 *                   病情介绍（主诉/现病史/初步诊断）+ 「请仔细阅读以下内容：」
 *  中部：知情同意正文（唯一可变区，可跨页接续）
 *  底部（每页重复）：虚线告知提示 + 双列签名（患者/委托人 + 医生） + 页脚 */

function pt_consent($visit, $patient, $consent, $doctorName, $record) {
    $html = '<div class="print-record-doc">';
    // ===== 头部（每页重复） =====
    $html .= pt_header($consent['title']);   // 医院名称 + 第二名称 + XX知情同意书
    $name = isset($visit['name']) ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '');
    $gender = isset($visit['gender']) ? $visit['gender'] : '';
    $age = pt_age_text($patient, $visit);
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    // 患者信息（急诊两行流式）
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

    // 病情介绍（主诉/现病史/初步诊断）
    $html .= '<div class="print-head-sec">';
    $record = is_array($record) ? $record : array();
    $html .= pt_sec('主诉', nl2br(e(isset($record['chief_complaint']) ? strip_tags($record['chief_complaint']) : '')));
    $html .= pt_sec('现病史', nl2br(e(isset($record['present_illness']) ? strip_tags($record['present_illness']) : '')));
    $html .= pt_sec('初步诊断', e(isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : ''));
    $html .= '</div>';
    // 请仔细阅读以下内容
    $html .= '<div class="print-head-sec" style="font-weight:700;padding:6px 0">请仔细阅读以下内容：</div>';

    // ===== 中部：知情同意正文（唯一可变区，可跨页接续） =====
    $html .= '<div style="white-space:pre-wrap;line-height:1.8;font-size:14px;padding:8px 0">' . e($consent['content']) . '</div>';

    // ===== 底部（每页重复） =====
    // 虚线告知提示（笼统通用，不限定内容）
    $html .= '<div class="print-note" style="border-top:1px dashed #000;margin-top:20px;padding:10px 0 6px;line-height:1.9;font-size:13px">' .
        '患者/委托人已知晓上述病情介绍与知情同意内容，医生已向我详细解释，' .
        '我已完全理解，愿意承担可能出现的手术/操作风险及并发症，并遵从医嘱，配合治疗。' .
        '</div>';
    // 双列签名
    $html .= '<div class="print-record-sign" style="display:flex;justify-content:space-between;padding:10px 0">' .
        '<div style="flex:1">患者/委托人签名：<span style="display:inline-block;width:100px;border-bottom:1px solid #000">&nbsp;</span></div>' .
        '<div style="flex:1;text-align:right">签名时间：<span style="display:inline-block;width:90px;border-bottom:1px solid #000">&nbsp;</span></div>' .
        '</div>';
    $html .= '<div class="print-record-sign" style="display:flex;justify-content:space-between;padding:2px 0 10px">' .
        '<div style="flex:1">医生签名：' . e($doctorName) . '</div>' .
        '<div style="flex:1;text-align:right">签名时间：' . now_str() . '</div>' .
        '</div>';
    // 页脚（左下记录时间、右下打印时间）
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>记录时间：' . e($consent['created_at']) . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
    return $html;
}