<?php
/** print/print_cert.php — 统一打印模板：诊断证明 */

function pt_certificate($visit, $patient, $record, $cert, $doctorName) {
    $certNo = isset($cert['cert_no']) ? $cert['cert_no'] : '';
    // 文档容器：与病历/申请单共用 .print-record-doc 版式（A5 + 分页器）
    $html = '<div class="print-record-doc">';
    $html .= pt_header('诊断证明书');

    // 右上角条形码：证明号
    $html .= '<div class="print-record-barcode">' . barcode128_svg($certNo) .
        '<div>' . e($certNo) . '</div></div>';

    // 患者信息两行（第一行 姓名/性别/出生日期/年龄，第二行 患者ID/流水号/证明号）
    $cell = function ($k, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="print-info-cell"><strong>' . e($k) . '</strong>：' . e($val) . '</span>';
    };
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        $cell('姓名', isset($visit['name']) && $visit['name'] !== '' ? $visit['name'] : (isset($patient['name']) ? $patient['name'] : '')) .
        $cell('性别', isset($visit['gender']) && $visit['gender'] !== '' ? $visit['gender'] : (isset($patient['gender']) ? $patient['gender'] : '')) .
        $cell('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '') .
        $cell('年龄', function_exists('pt_age_text') ? pt_age_text($patient, $visit) : '') . '</div>' .
        '<div class="print-info-line">' .
        $cell('患者ID', isset($visit['patient_no']) ? $visit['patient_no'] : '') .
        $cell('流水号', isset($visit['flow_no']) ? $visit['flow_no'] : '') .
        $cell('证明号', $certNo) . '</div>' .
        '</div><div class="print-line"></div>';

    // 病历摘要与医生建议（每节独立 record-section，可随分页器跨页）
    $html .= pt_sec('主诉', nl2br(e(isset($record['chief_complaint']) ? strip_tags($record['chief_complaint']) : '')));
    $html .= pt_sec('现病史', nl2br(e(isset($record['present_illness']) ? strip_tags($record['present_illness']) : '')));
    // 初步诊断：名称本身已含 ICD-10 编码前缀，直接显示、不再追加括号编码
    $html .= pt_sec('初步诊断', e(isset($record['initial_diagnosis']) ? $record['initial_diagnosis'] : ''));
    $html .= pt_sec('医生建议', nl2br(e(isset($cert['content']) ? $cert['content'] : '')));

    // 医生签名右下角 + 末尾横线 + 页脚（左下角开具时间、右下角打印时间）
    $html .= '<div class="print-record-sign">医生：' . e($doctorName) . '</div>';
    $html .= '<div class="print-line"></div>';
    $html .= '<div class="print-record-foot">' .
        '<span>开具时间：' . e(isset($cert['created_at']) ? $cert['created_at'] : '') . '</span>' .
        '<span>打印时间：' . now_str() . '</span></div>';
    $html .= '</div>';
    return $html;
}
