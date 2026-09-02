<?php
/**
 * ============================================================
 * parts/doctor/doctor_queue_list.php — 候诊列表（含会诊请求）
 * ============================================================ */

function doctor_read_queue_list($u) {
    $deptId = (int)get('dept_id', 0);
    if ($deptId <= 0) {
        $deptId = current_dept_id($u);
    }
    if ($deptId <= 0) {
        $ids = user_dept_ids($u);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $first = EmrRepository::one("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
            if ($first) $deptId = (int)$first['id'];
        }
    }
    if ($deptId <= 0) json_fail('当前医生未关联可用科室');
    $queueDays = 3;
    if (isset($u['queue_days'])) {
        $queueDays = (int)$u['queue_days'];
    } else {
        $ud = EmrRepository::one('SELECT queue_days FROM users WHERE id=?', array($u['id']));
        if ($ud && (int)$ud['queue_days'] >= 2 && (int)$ud['queue_days'] <= 7) $queueDays = (int)$ud['queue_days'];
    }
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
            'date' => substr($r['registered_at'], 0, 10),
            'time' => substr($r['registered_at'], 11, 5),
            'dept_name' => $r['first_dept_name'],
            'visit_seq' => (int)$r['visit_seq'],
            'session_text' => session_display_text($r['session']),
            'status' => $r['status'],
            'finish_date' => !empty($r['finished_at']) ? substr($r['finished_at'], 0, 10) : '',
            'finished_at' => !empty($r['finished_at']) ? substr($r['finished_at'], 11, 5) : '',
        );
    }, $rows);
    $pref = (isset($_SESSION['queue_pref']) && is_array($_SESSION['queue_pref'])) ? $_SESSION['queue_pref'] : array();
    $consVisits = array();
    $consStatus = array();
    foreach (EmrRepository::q("SELECT id, visit_id, status, created_at, accepted_by, record_id FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC", array($deptId, $since)) as $c) {
        $vid = (int)$c['visit_id'];
        if (!isset($consStatus[$vid])) {
            $consVisits[] = $vid;
            $consStatus[$vid] = array('id' => (int)$c['id'], 'code' => oid($c['id']), 'status' => (string)$c['status'], 'created_at' => (string)$c['created_at'], 'accepted_by' => (string)$c['accepted_by'], 'record_id' => (int)(isset($c['record_id']) ? $c['record_id'] : 0));
        }
    }
    $consultations = array();
    if ($consVisits) {
        $phC = implode(',', array_fill(0, count($consVisits), '?'));
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
                'date' => substr($cs['created_at'], 0, 10),
                'time' => substr($cs['created_at'], 11, 5),
                'dept_name' => $r['first_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'session_text' => session_display_text($r['session']),
                'status' => $r['status'],
                'created_at' => $cs['created_at'],
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