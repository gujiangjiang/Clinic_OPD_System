<?php
/**
 * ============================================================
 * parts/record/record_save_diag_order.php — 诊断排序
 * ============================================================
 * record_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function record_part_save_diag_order($u) {
    $visitId = did(post('visit_id'));
    $rowOrder = get_visit_row($visitId);
    if (!$rowOrder) json_fail('就诊记录不存在');
    // 病历可访问天数校验
    if (!visit_access_allowed($rowOrder['visit'], $u)) {
        json_fail('该病历超出您的可查看历史天数，无法修改');
    }
    // 会诊拦截：会诊病历（本人在会诊接收科室的文书）不可调整诊断顺序
    $hasConsult = (int)EmrRepository::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=? AND consultation_id>0', array($visitId, $u['id']));
    if ($hasConsult > 0) {
        json_fail('会诊病历不可调整诊断顺序');
    }
    $keys = json_decode((string)post('ord_keys', '[]'), true);
    if (!is_array($keys)) json_fail('排序数据无效');
    $clean = array();
    foreach ($keys as $k) {
        $k = trim((string)$k);
        if ($k !== '' && count($clean) < 100 && !in_array($k, $clean, true)) $clean[] = $k;
    }
    $exist = (int)EmrRepository::val('SELECT id FROM diag_orders WHERE visit_id=? AND doctor_id=?', array($visitId, $u['id']));
    if ($exist > 0) {
        EmrRepository::prepareExec('UPDATE diag_orders SET ord_keys=?, updated_at=? WHERE id=?', array(implode("\n", $clean), now_str(), $exist));
    } else {
        EmrRepository::insert('INSERT INTO diag_orders(visit_id, doctor_id, ord_keys, updated_at) VALUES(?,?,?,?)', array(
            $visitId, $u['id'], implode("\n", $clean), now_str(),
        ));
    }
    json_ok(array('diag_order' => $clean), '诊断顺序已保存');
    return;
}