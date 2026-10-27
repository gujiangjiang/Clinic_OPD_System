<?php
/**
 * ============================================================
 * parts/doctor/doctor_queue_list.php — 候诊列表（含会诊请求）
 * ============================================================ */

function doctor_read_queue_list($u) {
    // 核心优化：候诊列表轮询只读接口，鉴权后立即释放 Session 锁
    Session::closeReadOnly();
    $deptId = (int)get('dept_id', 0);
    $myDepts = user_dept_ids($u);
    // 科室归属校验：dept_id 参数必须在医生关联科室范围内，防止跨科室越权查看候诊列表
    if ($deptId > 0 && !in_array($deptId, $myDepts, true)) {
        json_fail('无权查看该科室候诊列表');
    }
    if ($deptId <= 0) {
        $deptId = current_dept_id($u);
    }
    if ($deptId <= 0) {
        $ids = $myDepts;
        if ($ids) {
            $ph = in_placeholders($ids);
            $first = EmrRepository::one("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
            if ($first) $deptId = (int)$first['id'];
        }
    }
    if ($deptId <= 0) json_fail('当前医生未关联可用科室');
    $queueDays = user_queue_days($u);
    $since = date('Y-m-d', strtotime('-' . ($queueDays - 1) . ' days'));
    $rows = EmrRepository::q("SELECT r.id, r.patient_no, r.visit_seq, r.first_dept_name, r.session,
            r.status, r.registered_at, r.finished_at,
            p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
        FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
        WHERE r.current_dept_id=? AND date(r.registered_at)>=?
        AND r.status IN ('paid','visiting','finished')
        ORDER BY r.registered_at DESC", array($deptId, $since));
    $list = array_map(function ($r) {
        return array(
            'code' => oid($r['id']),
            'name' => $r['pname'], 'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'date' => ($__rdt = fmt_time((string)$r['registered_at'])) !== '' ? substr($__rdt, 0, 10) : '',
            'time' => $__rdt !== '' ? substr($__rdt, 11, 5) : '',
            'dept_name' => $r['first_dept_name'],
            'visit_seq' => (int)$r['visit_seq'],
            'session_text' => session_display_text($r['session']),
            'status' => $r['status'],
            // 完成时间展示：先按展示口径换算（utc 模式下 UTC→本地），再截取日期/时分
            'finish_date' => ($__ft = fmt_time((string)$r['finished_at'])) !== '' ? substr($__ft, 0, 10) : '',   // tz-ok：$__ft 已 fmt_time 换算（上行）
            'finished_at' => $__ft !== '' ? substr($__ft, 11, 5) : '',   // tz-ok：同上

        );
    }, $rows);
    $pref = (isset($_SESSION['queue_pref']) && is_array($_SESSION['queue_pref'])) ? $_SESSION['queue_pref'] : array();
    $consVisits = array();
    $consStatus = array();
    foreach (EmrRepository::q("SELECT id, visit_id, status, created_at, accepted_by, record_id FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC", array($deptId, $since)) as $c) {
        $vid = (int)$c['visit_id'];
        if (!isset($consStatus[$vid])) {
            $consVisits[] = $vid;
            $consStatus[$vid] = array('id' => (int)$c['id'], 'code' => oid($c['id']), 'status' => (string)$c['status'], 'created_at' => fmt_time((string)$c['created_at']), 'accepted_by' => (string)$c['accepted_by'], 'record_id' => (int)(isset($c['record_id']) ? $c['record_id'] : 0));
        }
    }
    $consultations = array();
    if ($consVisits) {
        $phC = in_placeholders($consVisits);
        $cRows = EmrRepository::q("SELECT r.id, r.patient_no, r.visit_seq, r.first_dept_id, r.first_dept_name, r.session,
                r.status, r.registered_at, r.finished_at,
                p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.id IN ($phC)
            ORDER BY r.registered_at DESC", $consVisits);
        foreach ($cRows as $r) {
            $cs = $consStatus[(int)$r['id']];
            $consultations[] = array(
                'code' => oid($r['id']),
                'consult_code' => $cs['code'],
                'consult_status' => $cs['status'],
                'accepted_by' => $cs['accepted_by'],
                'record_id' => $cs['record_id'],
                'name' => $r['pname'], 'gender' => $r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                'date' => ($__cdt = fmt_time((string)$cs['created_at'])) !== '' ? substr($__cdt, 0, 10) : '',
                'time' => $__cdt !== '' ? substr($__cdt, 11, 5) : '',
                'dept_name' => $r['first_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'session_text' => session_display_text($r['session']),
                'status' => $r['status'],
                'created_at' => fmt_time($cs['created_at']),
            );
        }
    }
    json_ok(array(
        'dept_id' => $deptId,
        'waiting' => (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE current_dept_id=? AND status='paid'", array($deptId)),
        'list' => $list,
        'consultations' => $consultations,
        'pref' => array('seen' => empty($pref['seen']) ? 0 : 1, 'today' => empty($pref['today']) ? 0 : 1, 'consult' => empty($pref['consult']) ? 0 : 1),
    ));
    return;
}