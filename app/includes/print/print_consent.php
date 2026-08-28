<?php
/** print/print_consent.php — 统一打印模板：知情同意书（A5） */

function pt_consent($visit, $patient, $consent, $doctorName) {
    $html = '<div class="print-record-doc">';
    // 医院抬头 + 标题（XX知情同意书）
    $hosp = defined('HOSP_NAME') ? HOSP_NAME : '';
    $html .= '<div class="print-record-barcode" style="text-align:center;margin-bottom:6px">' .
        barcode128_svg($consent['flow_no']) .
        '<div>' . e($consent['flow_no']) . '</div></div>';
    $html .= '<div style="text-align:center;font-size:16px;font-weight:700;margin:8px 0 16px">' . e($hosp) . '</div>';
    $html .= '<div style="text-align:center;font-size:18px;font-weight:700;margin:0 0 16px;border-bottom:2px solid #000;padding-bottom:8px">' . e($consent['title']) . '</div>';

    // 患者信息
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', $visit['name'] ?? ($patient['name'] ?? '')) .
        $cell('性别', $visit['gender'] ?? ($patient['gender'] ?? '')) .
        $cell('年龄', function_exists('pt_age_text') ? pt_age_text($patient, $visit) : '') .
        $cell('患者ID', $patient['patient_no'] ?? '') . '</div></div>';
    $html .= '<div class="print-line"></div>';

    // 知情同意内容（正文区域占比最大）
    $html .= '<div style="min-height:200px;padding:12px 0;line-height:1.8;font-size:14px;white-space:pre-wrap">' . e($consent['content']) . '</div>';

    // 签名区（左右两栏）
    $html .= '<div class="print-line" style="margin-top:24px"></div>';
    $html .= '<div style="display:flex;justify-content:space-between;padding:16px 0 8px;font-size:14px">' .
        '<div style="flex:1">医生签名：' . e($doctorName) . '</div>' .
        '<div style="flex:1;text-align:right">患者/代理人签名：<span style="display:inline-block;width:120px;border-bottom:1px solid #000">&nbsp;</span></div>' .
        '</div>';

    // 页脚（左下记录时间、右下打印时间）
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>记录时间：' . e($consent['created_at']) . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
    return $html;
}