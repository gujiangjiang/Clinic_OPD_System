<?php
/**
 * ============================================================
 * parts/record/record_create_progress.php — 新建续写骨架
 * ============================================================
 * record_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function record_part_create_progress($u) {
    $visitId = did(post('visit_id'));
    $row = get_visit_row($visitId);
    if (!$row) json_fail('就诊记录不存在');
    $visit = $row['visit'];
    // 归档锁定：已诊毕(归档)不可创建续写
    if ($visit['status'] === 'finished') {
        json_fail('该患者已诊毕，病历已归档，不可创建续写');
    }
    // 病历可访问天数校验
    if (!visit_access_allowed($visit, $u)) {
        json_fail('该病历超出您的可查看历史天数，无法修改');
    }
    // 本人最近一条文书（首诊或上一次续写）——作为续写的父记录
    $ownLatest = EmrRepository::one('SELECT id, record_type, emr_data FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
    if (!$ownLatest) json_fail('本人尚无病历，请先书写首诊病历');
    // 必填校验：当前文书必须已完善并保存，才能继续续写
    $ownEmr = json_decode((string)$ownLatest['emr_data'], true);
    if (!is_array($ownEmr)) $ownEmr = array();
    $hasDiag = false;
    if (!empty($ownEmr['diagnoses']) && is_array($ownEmr['diagnoses'])) {
        foreach ($ownEmr['diagnoses'] as $dg) {
            if (is_array($dg) && !empty($dg['name'])) { $hasDiag = true; break; }
        }
    }
    if ($ownLatest['record_type'] === 'progress') {
        $progC = isset($ownEmr['progress']['content']) ? trim((string)$ownEmr['progress']['content']) : '';
        if ($progC === '' || !$hasDiag) json_fail('请先完善并保存当前续写病历的必填项（病历续写内容与初步诊断），再新建续写');
    } else {
        $ccS = isset($ownEmr['chief_complaint']['symptom']) ? trim((string)$ownEmr['chief_complaint']['symptom']) : '';
        $piC = isset($ownEmr['history_present']['content']) ? trim((string)$ownEmr['history_present']['content']) : '';
        if ($ccS === '' || $piC === '' || !$hasDiag) json_fail('请先完善并保存当前首诊病历的必填项（主诉、现病史与初步诊断），再新建续写');
    }
    $now = now_str();
    $emr = emr_default_data(null);
    $cleanJson = json_encode($emr, JSON_UNESCAPED_UNICODE);
    $pdo = DatabaseManager::getMain();
    try {
        $pdo->beginTransaction();
        // patient_records：空骨架（status=draft，正文为空，保存时填充）
        EmrRepository::prepareInsert('INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, emr_data, emr_print_text, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'progress', (int)$ownLatest['id'], $cleanJson, '', 'draft', $now, $now));
        // records 旧镜像表同步占位（兼容既有消费方）
        EmrRepository::prepareInsert('INSERT INTO records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], $visit['current_dept_id'], $u['id'], $u['name'], 'draft', $now, $now));
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_fail('续写病历创建失败：' . $ex->getMessage());
    }
    json_ok(array(), '续写病历已创建');
    return;
}