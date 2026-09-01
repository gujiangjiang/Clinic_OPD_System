<?php
/**
 * ============================================================
 * parts/record_delete.php — 电子病历：删除病历记录
 * ============================================================
 * record.php 按功能拆分的一部分，动作：delete_record
 * ============================================================ */

function record_part_delete($action) {
    $u = Auth::user();

    if ($action === 'delete_record') {
        $visitId = did(post('visit_id'));
        $recordId = (int)post('record_id', 0);
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        if ($recordId <= 0) json_fail('参数错误');
        $rec = EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($recordId));
        if (!$rec) json_fail('病历记录不存在');
        if ((int)$rec['visit_id'] !== (int)$visitId) json_fail('病历记录不属于本次就诊');
        // 0. 诊毕锁定：已诊毕的病历已归档，任何节点（含续写）一律不可删除（法律/合规底线）
        if ((string)$row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可删除');
        }
        // 1. 身份越权拦截
        if ((int)$rec['doctor_id'] !== (int)$u['id']) {
            json_fail('无权删除非本人创建的病历记录');
        }
        // 2. 首诊锁定校验：存在已保存的续写病程 → 禁止删除首诊
        if ($rec['record_type'] === 'initial') {
            $hasProgress = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND record_type='progress' AND status<>'draft'", array($visitId));
            if ($hasProgress > 0) {
                json_fail('该病历已存在后续病程记录，不可删除首诊病历');
            }
        }
        // 2.5 诊断锁定：当前病历节点存在诊断则不允许删除（避免产生无主诊断），
        // 与未保存病历的拦截规则一致——需先删除该病历内的全部诊断
        $recEmr = emr_merge_defaults(emr_normalize(json_decode($rec['emr_data'], true)), emr_default_data(null));
        if (isset($recEmr['diagnoses']) && is_array($recEmr['diagnoses']) && count($recEmr['diagnoses'])) {
            json_fail('该病历已添加诊断，不可删除；请先删除该病历内的全部诊断后再删除病历');
        }
        // 2.6 转科校验：文书书写科室与就诊当前科室不一致 → 只读，不可删除
        //     （转科前旧病历在当前科室下为只读展示，即使本人也不可删；
        //       会诊记录 dept_id=会诊目标科室，不受转科限制）
        if ((int)$rec['dept_id'] !== (int)$row['visit']['current_dept_id'] && (int)$rec['consultation_id'] === 0) {
            json_fail('该病历书写于转科前科室，当前科室下为只读状态，不可删除');
        }
        // 2.65 会诊完毕锁定：会诊已完毕（done）的会诊病历永久只读，任何人不可删除
        if ((int)$rec['consultation_id'] > 0) {
            $delConsStatus = EmrRepository::one('SELECT status FROM consultations WHERE id=?', array((int)$rec['consultation_id']));
            if ($delConsStatus && $delConsStatus['status'] === 'done') {
                json_fail('该会诊已完毕，会诊病历已永久锁定为只读，不可删除');
            }
        }
        // 3. 删除（物理删除 + 镜像清理 + 关联状态回退）——复合写操作在事务内控制
        $pdo = DatabaseManager::getMain();
        try {
            $pdo->beginTransaction();
            // 3.1 会诊病历回退：删除会诊病历 = 放弃本次会诊处理 → 会诊状态回退待会诊
            $recConsultId = (int)$rec['consultation_id'];
            if ($recConsultId > 0) {
                $cons = EmrRepository::one('SELECT id, status FROM consultations WHERE id=?', array($recConsultId));
                if ($cons && $cons['status'] !== 'done') {
                    EmrRepository::revertConsultation($recConsultId);
                }
            }
            // 3.2 删除病历记录 + 镜像清理
            EmrRepository::deleteRecord($recordId);
            EmrRepository::deleteMirrorByPatientRecord($recordId);
            $mirrorOld = EmrRepository::one('SELECT id FROM records WHERE visit_id=? AND doctor_id=? AND patient_record_id=0 ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
            if ($mirrorOld) {
                EmrRepository::deleteMirrorById($mirrorOld['id']);
            }
            // 3.3 删除后：若当前科室已无文书 → 该科室就诊状态退回待就诊（paid）
            if ($row['visit']['status'] === 'visiting') {
                $remainCurDept = (int)EmrRepository::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND dept_id=?',
                    array($visitId, (int)$row['visit']['current_dept_id']));
                if ($remainCurDept === 0) {
                    EmrRepository::revertVisitStatus($visitId, 'paid');
                }
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('病历删除失败：' . $ex->getMessage());
        }
        $newStatus = (string)EmrRepository::val('SELECT status FROM registrations WHERE id=?', array($visitId));
        json_ok(array('record_type' => $rec['record_type'], 'visit_status' => $newStatus), '病历记录已删除');
        return;
    }
}
