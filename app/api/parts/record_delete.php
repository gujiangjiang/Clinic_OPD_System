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
        $rec = DB::one('medical', 'SELECT * FROM patient_records WHERE id=?', array($recordId));
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
            $hasProgress = (int)DB::val('medical', "SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND record_type='progress' AND status<>'draft'", array($visitId));
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
        //     （转科前旧病历在当前科室下为只读展示，即使本人也不可删）
        if ((int)$rec['dept_id'] !== (int)$row['visit']['current_dept_id']) {
            json_fail('该病历书写于转科前科室，当前科室下为只读状态，不可删除');
        }
        // 3. 删除（物理删除 + 镜像清理）
        $pdo = DatabaseManager::pdo('medical');
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM patient_records WHERE id=?')->execute(array($recordId));
            // 清理该文书对应的旧镜像（精确匹配 patient_record_id；旧数据无关联时按 visit+doctor 最新兜底）
            $pdo->prepare('DELETE FROM records WHERE patient_record_id=?')->execute(array($recordId));
            $mirrorOld = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? AND doctor_id=? AND patient_record_id=0 ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
            if ($mirrorOld) {
                $pdo->prepare('DELETE FROM records WHERE id=?')->execute(array($mirrorOld['id']));
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('病历删除失败：' . $ex->getMessage());
        }
        // 4. 删除后：若当前科室已无文书 → 该科室就诊状态退回待就诊（paid）
        //    （就诊状态按科室划分：当前科室存在文书即就诊中，删除后无文书则待就诊；
        //     其他科室的文书不受影响——转回原科室仍显示就诊中）
        if ($row['visit']['status'] === 'visiting') {
            $remainCurDept = (int)DB::val('medical', 'SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND dept_id=?',
                array($visitId, (int)$row['visit']['current_dept_id']));
            if ($remainCurDept === 0) {
                DB::exec('patient', 'UPDATE registrations SET status=? WHERE id=?', array('paid', $visitId));
            }
        }
        json_ok(array('record_type' => $rec['record_type']), '病历记录已删除');
        return;
    }
}
