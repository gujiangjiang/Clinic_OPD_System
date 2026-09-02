<?php
/**
 * ============================================================
 * parts/record/record_save_diags.php — 诊断列表保存
 * ============================================================
 * record_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function record_part_save_diags($u) {
    $visitId = did(post('visit_id'));
    $row = get_visit_row($visitId);
    if (!$row) json_fail('就诊记录不存在');
    // 归档锁定：已诊毕(归档)不可调整诊断
    if ($row['visit']['status'] === 'finished') {
        json_fail('该患者已诊毕，病历已归档，不可调整诊断');
    }
    // 病历可访问天数校验
    if (!visit_access_allowed($row['visit'], $u)) {
        json_fail('该病历超出您的可查看历史天数，无法修改');
    }
    // 仅本人文书可调整，且未诊毕；切换回旧文书编辑时按 edit_record_id 精确定位
    $editDiagRecordId = (int)post('edit_record_id', 0);
    if ($editDiagRecordId > 0) {
        $pr = EmrRepository::one('SELECT * FROM patient_records WHERE id=? AND doctor_id=?', array($editDiagRecordId, $u['id']));
    } else {
        $pr = EmrRepository::one('SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
    }
    if (!$pr) json_fail('您在该就诊下暂无病历文书');
    // 会诊锁校验：仅当医生处于「会诊处理中」（当前科室=目标科室）时，
    // 非会诊病历不可调整诊断；原科室医生不受会诊影响。
    // 会诊完毕的会诊病历不可再调整诊断
    $diagConsultCtx = get_consult_context($row['visit'], $u);
    if ($diagConsultCtx && (int)$pr['consultation_id'] === 0) {
        json_fail('该就诊正在进行会诊，会诊前的病历已锁定为只读，仅可编辑会诊病历');
    }
    if ((int)$pr['consultation_id'] > 0) {
        $diagConsRow = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array((int)$pr['consultation_id']));
        if ($diagConsRow && $diagConsRow['status'] === 'done') {
            json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可修改');
        }
    }
    // 转科校验：文书书写科室与就诊当前科室不一致 → 只读，不可调整诊断；
    // 会诊记录（consultation_id>0）书写科室=会诊目标科室，不受转科限制
    if ((int)$pr['dept_id'] !== (int)$row['visit']['current_dept_id'] && (int)$pr['consultation_id'] === 0) {
        json_fail('该病历书写于转科前科室，当前科室下为只读状态，不可调整诊断');
    }
    if ($pr['status'] === 'done') json_fail('病历已诊毕，无法调整诊断');
    // 添加/调整诊断前置条件（与前端一致，防接口直调绕过）：
    // 首诊=主诉/现病史已填写；续写=续写内容已填写
    $gateEmr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
    if ((string)$pr['record_type'] === 'progress') {
        $progGate = isset($gateEmr['progress']['content']) ? trim((string)$gateEmr['progress']['content']) : '';
        if ($progGate === '') json_fail('请先填写病历续写内容后再添加诊断');
    } else {
        $ccGate = isset($gateEmr['chief_complaint']['symptom']) ? trim((string)$gateEmr['chief_complaint']['symptom']) : '';
        $piGate = isset($gateEmr['history_present']['content']) ? trim((string)$gateEmr['history_present']['content']) : '';
        if ($ccGate === '' || $piGate === '') json_fail('请先完善主诉与现病史后再添加诊断');
    }
    $diags = json_decode((string)post('diagnoses', '[]'), true);
    if (!is_array($diags)) json_fail('诊断数据无效');
    $clean = array();
    foreach ($diags as $d) {
        if (!is_array($d) || empty($d['name'])) continue;
        $clean[] = array(
            'code' => (string)(isset($d['code']) ? $d['code'] : ''),
            'name' => (string)$d['name'],
            'part' => (string)(isset($d['part']) ? $d['part'] : ''),
            'note' => (string)(isset($d['note']) ? $d['note'] : ''),
            'suspected' => (string)(isset($d['suspected']) ? $d['suspected'] : ''),
        );
    }
    // 允许清空诊断：删除主诊断后第二位自动递补，无则主诊断置空
    $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
    $emr['diagnoses'] = $clean;
    $diagText = $clean ? emr_diag_text($clean) : '';
    $firstCode = $clean ? (string)$clean[0]['code'] : '';
    // 结构化文书更新（诊断 + 主诊断投影）
    EmrRepository::prepareExec('UPDATE patient_records SET emr_data=?, icd10_code=?, diagnosis_name=? WHERE id=?', array(
        json_encode($emr, JSON_UNESCAPED_UNICODE), $firstCode, $diagText, $pr['id']));
    // 旧镜像表同步（最新一行）：注意镜像表 ICD 列名为 icd10_code；
    // 先查 id 再按 id 更新（避免 UPDATE 内子查询的兼容性问题）
    $mirrorId = (int)EmrRepository::val('SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
    if ($mirrorId > 0) {
        EmrRepository::prepareExec('UPDATE records SET preliminary_diagnosis=?, icd10_code=? WHERE id=?', array($diagText, $firstCode, $mirrorId));
    }
    json_ok(array('diagnoses' => $clean), '诊断已更新');
    return;
}