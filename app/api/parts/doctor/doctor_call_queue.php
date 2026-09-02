<?php
/**
 * ============================================================
 * parts/doctor/doctor_call_queue.php — 叫号队列
 * ============================================================ */

function doctor_read_call_queue($u) {
    $deptId = (int)get('dept_id', 0);
    if ($deptId <= 0) {
        $curRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
        $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
    }
    $dept = EmrRepository::one('SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
    if (!$dept) {
        $ids = doctor_dept_ids($u);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $first = EmrRepository::one("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
            if ($first) $dept = $first;
        }
    }
    if (!$dept) json_fail('当前医生未关联可用科室');
    $deptId = (int)$dept['id'];
    $current = EmrRepository::one("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no WHERE r.current_dept_id=? AND r.status='visiting' ORDER BY r.id DESC LIMIT 1", array($deptId));
    $next = EmrRepository::one("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.registered_at LIMIT 1", array($deptId));
    $waiting = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE current_dept_id=? AND status='paid'", array($deptId));
    $doctors = array();
    $docs = EmrRepository::q("SELECT name, emp_no, title, photo, intro, dept_ids FROM users WHERE role='doctor' AND status=1 ORDER BY id");
    foreach ($docs as $doc) {
        $ids = array();
        foreach (explode(',', isset($doc['dept_ids']) ? $doc['dept_ids'] : '') as $x) {
            if ((int)$x > 0) $ids[] = (int)$x;
        }
        if (in_array($deptId, $ids, true)) {
            $doctors[] = array(
                'name' => $doc['name'], 'emp_no' => $doc['emp_no'],
                'title' => $doc['title'],
                'photo' => $doc['photo'] ? img_data($doc['photo']) : '',
                'intro' => $doc['intro'],
            );
        }
    }
    $fmt = function ($r) use ($deptId) {
        if (!$r) return null;
        $follow = 0;
        if (!empty($r['patient_no'])) {
            $follow = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE patient_no=? AND current_dept_id=? AND date(registered_at)=? AND status IN ('paid','visiting','finished') AND id<>?", array($r['patient_no'], $deptId, today_str(), $r['id']));
        }
        return array(
            'name' => $r['pname'], 'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'], 'registered_at' => $r['registered_at'],
            'is_followup' => $follow > 0 ? 1 : 0,
        );
    };
    json_ok(array(
        'dept' => array('id' => (int)$dept['id'], 'name' => $dept['name'], 'type' => $dept['type']),
        'current' => $fmt($current),
        'next' => $fmt($next),
        'waiting' => $waiting,
        'doctors' => $doctors,
    ));
    return;
}