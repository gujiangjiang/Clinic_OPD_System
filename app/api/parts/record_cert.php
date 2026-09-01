<?php
/**
 * ============================================================
 * parts/record_cert.php — 电子病历：诊断证明
 * ============================================================
 * record.php 按功能拆分的一部分，动作：
 *   certificate 开具 / certificate_print 打印 /
 *   check_previous_diagnoses 前序诊断查重
 * 数据访问统一委托 EmrRepository / PatientRepository，本文件不含原生 SQL。
 * ============================================================ */

function record_part_cert($action) {
    $u = Auth::user();

    if ($action === 'certificate') {
        $visitId = did(post('visit_id'));
        $content = post('content');
        if ($content === '') json_fail('请填写医生建议');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 会诊期间拦截：存在进行中会诊时不允许开具诊断证明（会诊仅看病不诊断）
        $doingCertCons = ConsultationRepository::one("SELECT id FROM consultations WHERE visit_id=? AND status='doing' LIMIT 1", array($visitId));
        if ($doingCertCons) {
            json_fail('该就诊正在进行会诊，会诊期间不可开具诊断证明');
        }
        // 权限校验：
        // 已诊毕（归档）病历 → 补开证明走原逻辑：接诊过该患者的医生（或管理员）可开具；
        // 未诊毕 → 必须持有当前科室可编辑病历（转科后旧文书只读，须先续写保存）。
        if ($u['role'] !== 'admin') {
            $archived = (string)$row['visit']['status'] === 'finished';
            if ($archived) {
                $involved = EmrRepository::countByVisitDoctor($visitId, $u['id']);
                if ($involved === 0) {
                    json_fail('您未接诊过该患者，无权开具诊断证明');
                }
            } else {
                // 未诊毕 → 必须持有当前科室可写容器（统一上下文断言）
                $editableRec = get_editable_record($row['visit'], $u);
                EmrContextResolver::assertCanWrite($row['visit'], $u, $editableRec ? EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($editableRec['id'])) : null);
            }
        }
        if (EmrRepository::countCertificatesByVisit($visitId) > 0) {
            json_fail('本次就诊已开具过诊断证明，不可重复开具');
        }
        // 证明号：ZM 前缀 + 时间戳 + 2 位随机——与申请单号（JY/JC/CZ/CF/DD）同源
        // 规则但前缀互不冲突；循环校验保证唯一。
        do {
            $certNo = 'ZM' . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
        } while (EmrRepository::countCertificatesByNo($certNo) > 0);
        // 病历摘要快照：开具瞬间以首诊文书为锚点固化主诉/现病史/初步诊断，
        // 证书内容从此不再随续写或后续修改变化（法律文书不可变性）
        $snap = cert_snapshot_summary($visitId);
        EmrRepository::insertCertificate(array(
            'visit_id' => $visitId, 'patient_no' => $row['visit']['patient_no'], 'flow_no' => $row['visit']['flow_no'],
            'doctor_id' => $u['id'], 'doctor_name' => $u['name'], 'content' => $content, 'created_at' => now_str(), 'cert_no' => $certNo,
            'chief_complaint' => $snap['chief_complaint'], 'present_illness' => $snap['present_illness'], 'preliminary_diagnosis' => $snap['preliminary_diagnosis'],
        ));
        json_ok(array('cert_no' => $certNo), '诊断证明已开具');
        return;
    }

    if ($action === 'certificate_print') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $cert = EmrRepository::certificateByVisit($visitId);
        if (!$cert) json_fail('未开具诊断证明');
        $record = EmrRepository::one('SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        // 固化快照：证书存有开具时的病历摘要则原样使用——无论谁开具、
        // 谁补打、后续有多少次续写，打印内容与开具时完全一致；
        // 历史证明（无快照列）回退原实时取数行为。
        $record = cert_fallback_snapshot($record, $cert);
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        json_ok(array('html' => pt_certificate($visit, $row['patient'], $record, $cert, $cert['doctor_name'])));
        return;
    }

    if ($action === 'check_previous_diagnoses') {
        $visitId = did(get('visit_id') !== '' ? get('visit_id') : get('reg_id'));
        $kw = trim((string)get('keyword'));
        if (!$visitId) json_fail('缺少挂号流水参数');
        // 大小写归一化（服务器可能未启用 mbstring，见 helpers.php polyfill 说明；
        // 中文无大小写差异，ASCII 编码字母统一小写比较）
        $lc = function ($s) { return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); };
        $lkw = $lc($kw);
        $rows = EmrRepository::recordsByVisitOtherDoctors($visitId, $u['id']);
        $list = array();
        foreach ($rows as $pr2) {
            $emr2 = json_decode($pr2['emr_data'], true);
            $dgs = (is_array($emr2) && isset($emr2['diagnoses']) && is_array($emr2['diagnoses'])) ? $emr2['diagnoses'] : array();
            foreach ($dgs as $dg) {
                if (!is_array($dg) || empty($dg['name'])) continue;
                $name = (string)$dg['name'];
                $code = isset($dg['code']) ? (string)$dg['code'] : '';
                if ($lkw !== '' && strpos($lc($name), $lkw) === false && strpos($lc($code), $lkw) === false) continue;
                $list[] = array(
                    'doctor_id' => (int)$pr2['doctor_id'],
                    'doctor_name' => (string)$pr2['doctor_name'],
                    'record_id' => (int)$pr2['id'],
                    'record_type' => ($pr2['record_type'] === 'progress') ? 'progress' : 'initial',
                    'created_at' => (string)$pr2['created_at'],
                    'name' => $name,
                    'code' => $code,
                    'part' => isset($dg['part']) ? (string)$dg['part'] : '',
                    'note' => isset($dg['note']) ? (string)$dg['note'] : '',
                    'suspected' => isset($dg['suspected']) ? (string)$dg['suspected'] : '',
                );
            }
        }
        json_ok(array('list' => $list, 'count' => count($list)));
        return;
    }
}