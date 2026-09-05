<?php
/**
 * ============================================================
 * parts/doctor/doctor_call_queue.php — 医生端叫号大屏（/doctor/call）数据
 * ============================================================
 * 说明：医生「诊室门口叫号屏幕」的轮询数据源。与叫号大屏一致，遵循
 * 「医生工作站推送信号 + 回库校验」模型：仅当医生已绑定大屏时返回该
 * 诊室的患者数据（当前就诊/下一位/候诊数/出诊医生），未绑定一律为空，
 * 不再从数据库自动抓取全科室患者。
 * ============================================================ */

function doctor_read_call_queue($u) {
    $room = QueueRepository::one(
        "SELECT * FROM clinic_rooms WHERE current_doctor_id=? AND room_type='doctor' ORDER BY id DESC LIMIT 1",
        array((int)$u['id'])
    );
    if (!$room) {
        json_ok(array(
            'dept' => null, 'bound' => false,
            'current' => null, 'next' => null, 'waiting' => 0, 'doctors' => array(),
        ));
        return;
    }
    $deptId = (int)$room['dept_id'];
    $dept = EmrRepository::one('SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
    if (!$dept) {
        json_fail('大屏科室不存在');
    }
    $bound = QueueRepository::roomBound($room);
    if (!$bound) {
        json_ok(array(
            'dept' => array('id' => (int)$dept['id'], 'name' => $dept['name'], 'type' => $dept['type']),
            'bound' => false,
            'current' => null, 'next' => null, 'waiting' => 0, 'doctors' => array(),
        ));
        return;
    }
    $current = QueueRepository::roomCurrentVisit($room);
    $pool = QueueRepository::deptPool($deptId, 8);
    $next = $pool ? $pool[0] : null;
    $waiting = (int)QueueRepository::deptPoolCount($deptId);
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
        'bound' => true,
        'current' => $fmt($current),
        'next' => $fmt($next),
        'waiting' => $waiting,
        'doctors' => $doctors,
    ));
    return;
}
