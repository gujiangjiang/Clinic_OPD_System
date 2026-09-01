<?php
/** print/print_consult.php — 统一打印模板：会诊申请单
 *  样式参考检验检查申请单，标题为「会诊申请单」；
 *  患者信息区开单科室改为申请科室、去掉临床诊断；
 *  正文为主诉/现病史/体格检查/会诊详情/会诊目的/会诊科室；
 *  右下角申请医生，左下角「请凭本会诊单至相应科室会诊。」 */

function pt_consult($visit, $patient, $cons, $snap) {
    $html = '<div class="print-record-doc">';
    $html .= pt_header('会诊申请单');

    // 右上角条形码 + 会诊单号
    $displayNo = isset($cons['consult_no']) && $cons['consult_no'] !== '' ? $cons['consult_no'] : (string)$cons['id'];
    $html .= pt_barcode($displayNo);

    // 患者信息：姓名/性别/出生日期/年龄 + 患者ID/流水号/申请科室（无临床诊断）
    $applyDept = (string)$cons['from_dept_name'];
    if ($applyDept === '') {
        $docU = DB::one('SELECT current_dept_id FROM users WHERE id=?', array((int)$cons['from_doctor_id']));
        if ($docU && (int)$docU['current_dept_id'] > 0) {
            $dp = DB::one('SELECT name FROM departments WHERE id=?', array((int)$docU['current_dept_id']));
            if ($dp) $applyDept = (string)$dp['name'];
        }
    }
    $html .= '<div class="print-info-lines">' .
        '<div class="print-info-line">' .
        pt_info_cell('姓名', $patient ? $patient['name'] : '') .
        pt_info_cell('性别', $patient ? $patient['gender'] : '') .
        pt_info_cell('出生日期', $patient ? $patient['birth_date'] : '') .
        pt_info_cell('年龄', $patient ? pt_age_text($patient, null) : '') . '</div>' .
        '<div class="print-info-line">' .
        pt_info_cell('患者ID', isset($cons['patient_no']) ? $cons['patient_no'] : '') .
        pt_info_cell('流水号', isset($cons['flow_no']) ? $cons['flow_no'] : '') .
        pt_info_cell('申请科室', $applyDept) . '</div>' .
        '</div><div class="print-line"></div>';

    // 正文：主诉/现病史/体格检查/会诊详情/会诊目的/会诊科室
    // （使用打印专用 .print-flow/.pf-sec/.pf-body 段落样式，与电子病历打印一致）
    $ro = function ($label, $val) {
        $val = ($val !== '' && $val !== null) ? $val : '—';
        return '<span class="pf-sec"><strong>' . e($label) . '：</strong><span class="pf-body">' . e($val) . '</span></span>';
    };
    $html .= '<div class="print-flow">' .
        $ro('主诉', isset($snap['chief_complaint']) ? $snap['chief_complaint'] : '') .
        $ro('现病史', isset($snap['present_illness']) ? $snap['present_illness'] : '') .
        $ro('体格检查', isset($snap['physical_exam']) ? $snap['physical_exam'] : '') .
        $ro('会诊详情', isset($cons['description']) ? $cons['description'] : '') .
        $ro('会诊目的', isset($cons['purpose']) ? $cons['purpose'] : '') .
        $ro('会诊科室', isset($cons['target_dept_name']) ? $cons['target_dept_name'] : '') .
        '</div><div class="print-line" style="margin-top:14px"></div>';

    // 左下角提示 + 右下角申请医生
    $html .= '<div class="print-note">请凭本会诊单至相应科室会诊。</div>';
    $html .= '<div class="print-record-sign">' .
        '申请医生：' . e(isset($cons['from_doctor_name']) ? $cons['from_doctor_name'] : '') . '</div>';
    // 末尾横线 + 页脚：左下角申请时间、右下角打印时间
    $html .= pt_doc_foot('申请时间', isset($cons['created_at']) ? $cons['created_at'] : '');

    $html .= '</div>';
    return $html;
}
