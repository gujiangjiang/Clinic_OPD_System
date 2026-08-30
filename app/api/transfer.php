<?php
/**
 * ============================================================
 * transfer.php — 转科接口
 * ============================================================
 * 说明：医生工作站转科功能：
 * 1. 转科后患者就诊序号、首次挂号科室、患者ID、流水号均不变
 *    （首次挂号信息不随转科改变）
 * 2. 记录转科去向，新科室医生接诊后可【一键引用】原病历
 * 3. 同一患者当日同首次科室不可重复挂号的限制同样不随转科改变
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 可选目标科室 ==================== */
    case 'targets':
        $deptId = (int)get('dept_id', 0);
        $list = DB::q("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id<>? ORDER BY type DESC, sort, id", array($deptId));
        json_ok(array('list' => array_map(function ($d) {
            // type：急诊/门诊 Tab 分类（与通用科室选择弹窗约定一致）
            return array('id' => (int)$d['id'], 'name' => $d['name'], 'type' => $d['type']);
        }, $list)));
        break;

    /* ==================== 执行转科 ==================== */
    case 'do':
        $visitId = did(post('visit_id'));
        $targetDept = (int)post('target_dept');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] !== 'visiting') {
            json_fail('仅就诊中的患者可转科');
        }
        $dept = DB::one('SELECT * FROM departments WHERE id=? AND status=1', array($targetDept));
        if (!$dept) json_fail('目标科室不存在或已停用');
        // 防自转：目标科室不能与患者当前科室相同（前端已隐藏当前科室，此处双保险）
        if ((int)$visit['current_dept_id'] === $targetDept) {
            json_fail('目标科室与患者当前科室相同，无需转科');
        }
        // 会诊拦截：书写了会诊病历的医生不可转科（会诊病历与当前科室绑定）
        $hasConsult = (int)DB::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=? AND consultation_id>0', array($visitId, $u['id']));
        if ($hasConsult > 0) {
            json_fail('您已书写会诊病历，会诊期间不可转科');
        }

        // 记录转科（附带原病历ID，供一键引用）
        $lastRecord = DB::one('SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        DB::insert('INSERT INTO referrals(visit_id, patient_no, flow_no, from_dept_id, from_dept_name, to_dept_id, to_dept_name, reason, ref_record_id, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'],
            $visit['current_dept_id'], $visit['current_dept_name'],
            $targetDept, $dept['name'], post('reason', ''),
            $lastRecord ? (int)$lastRecord['id'] : 0,
            $u['id'], $u['name'], now_str(),
        ));

        // 更新当前科室；状态回到待就诊（新科室候诊）
        DB::exec('UPDATE registrations SET current_dept_id=?, current_dept_name=?, status=? WHERE id=?', array(
            $targetDept, $dept['name'], 'paid', $visitId,
        ));
        json_ok(array(), '已转往【' . $dept['name'] . '】，就诊序号与首次挂号科室保持不变');
        break;

    default:
        json_fail('未知操作');
}
