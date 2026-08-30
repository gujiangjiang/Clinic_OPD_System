<?php
/** print/print_receipt.php — 统一打印模板：挂号凭条/缴费凭条 */

function pt_receipt($visit, $patient) {
    $code = isset($visit['flow_no']) && $visit['flow_no'] !== '' ? $visit['flow_no'] : (isset($patient['patient_no']) ? $patient['patient_no'] : '');
    $paid = in_array(isset($visit['status']) ? $visit['status'] : '', array('paid', 'visiting', 'finished'), true);
    $html = '<div class="print-ticket">';
    $html .= pt_ticket_header('挂号凭条');
    $html .= '<div class="ticket-divider"></div>';
    $html .= pt_ticket_row('患者姓名', isset($visit['name']) ? $visit['name'] : '');
    $html .= pt_ticket_row('患者ID', isset($patient['patient_no']) ? $patient['patient_no'] : '');
    $html .= pt_ticket_row('门诊号', $code);
    $html .= pt_ticket_row('性别', isset($visit['gender']) ? $visit['gender'] : '');
    $html .= pt_ticket_row('出生日期', isset($patient['birth_date']) ? $patient['birth_date'] : '');
    $html .= pt_ticket_row('年龄', pt_age_text($patient, $visit));
    $html .= pt_ticket_row('挂号科室', isset($visit['first_dept_name']) ? $visit['first_dept_name'] .
        (isset($visit['dept_type']) && $visit['dept_type'] === 'emergency' ? ' (急诊)' : '') : '');
    $html .= pt_ticket_row('就诊序号', isset($visit['visit_seq']) ? str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) : '');
    $html .= pt_ticket_row('就诊日期', isset($visit['registered_at']) ? substr($visit['registered_at'], 0, 10) : '');
    $html .= pt_ticket_row('挂号时间', isset($visit['registered_at']) ? substr($visit['registered_at'], 0, 16) : '');
    $html .= pt_ticket_row('费用类别', isset($visit['fee_type']) ? $visit['fee_type'] : '');
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-row"><span>挂号费</span><span class="ticket-val">' . money(isset($visit['fee']) ? $visit['fee'] : 0) . ' 元</span></div>';
    $html .= '<div class="ticket-row"><span>支付状态</span><span class="ticket-val">' . ($paid ? '已支付' : e(isset($visit['status_name']) ? $visit['status_name'] : '')) . '</span></div>';
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-barcode">' . barcode128_svg($code, 44, 2) .
        '<div class="ticket-barcode-text">门诊号: ' . e($code) . '</div></div>';
    $html .= '<div class="ticket-note">请妥善保管，按时就诊。</div>';
    $html .= '<div class="ticket-print-time">打印时间: ' . now_str() . '</div>';
    $html .= '</div>';
    return $html;
}

function pt_payment($pay, $items) {
    $pName = isset($pay['patient_no']) ? DB::val('SELECT name FROM patients WHERE patient_no=?', array($pay['patient_no'])) : '';
    $code = isset($pay['flow_no']) && $pay['flow_no'] !== '' ? $pay['flow_no'] : (isset($pay['patient_no']) ? $pay['patient_no'] : '');
    $html = '<div class="print-ticket">';
    $html .= pt_ticket_header('缴费凭条');
    $html .= '<div class="ticket-divider"></div>';
    $html .= pt_ticket_row('患者姓名', $pName);
    $html .= pt_ticket_row('患者ID', isset($pay['patient_no']) ? $pay['patient_no'] : '');
    $html .= pt_ticket_row('门诊号', $code);
    $html .= pt_ticket_row('缴费时间', isset($pay['created_at']) ? substr($pay['created_at'], 0, 16) : '');
    $html .= pt_ticket_row('收费员', isset($pay['cashier_name']) ? $pay['cashier_name'] : '');
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-section-title">收费项目</div>';
    $total = 0;
    foreach ($items as $it) {
        $sub = (float)$it['price'] * (int)$it['quantity'];
        $total += $sub;
        $html .= '<div class="ticket-row ticket-item"><span>' . e(isset($it['name']) ? $it['name'] : '') .
            ((int)$it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : '') . '</span>' .
            '<span class="ticket-val">¥' . money($sub) . '</span></div>';
    }
    $html .= '<div class="ticket-row ticket-total"><span>合计</span><span class="ticket-val">¥' . money($total) . '</span></div>';
    $html .= '<div class="ticket-divider"></div>';
    $html .= '<div class="ticket-barcode">' . barcode128_svg($code, 44, 2) .
        '<div class="ticket-barcode-text">门诊号: ' . e($code) . '</div></div>';
    $html .= '<div class="ticket-note">缴费成功，请妥善保管本凭条，凭此前往相应科室执行检查/检验/取药。</div>';
    $html .= '<div class="ticket-print-time">打印时间: ' . now_str() . '</div>';
    $html .= '</div>';
    return $html;
}
