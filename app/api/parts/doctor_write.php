<?php
/**
 * ============================================================
 * parts/doctor_write.php — 医生端：写入
 * ============================================================
 * doctor.php 按功能拆分的一部分，写入/操作类动作。
 * ============================================================ */

require __DIR__ . '/doctor/doctor_call_actions.php';

function doctor_part_write($action) {
    $u = Auth::user();

    if ($action === 'set_dept') {
        $deptId = (int)post('dept_id');
        $ids = user_dept_ids($u);
        if (!in_array($deptId, $ids, true)) json_fail('无权选择该科室');
        $dept = EmrRepository::one('SELECT id FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) json_fail('科室不存在或已停用');
        EmrRepository::exec('UPDATE users SET current_dept_id=? WHERE id=?', array($deptId, $u['id']));
        Auth::updateSession('current_dept_id', $deptId);
        json_ok(array('dept_id' => $deptId), '科室已切换');
        return;
    }

    if ($action === 'add_slot') {
        $deptId = (int)post('dept_id');
        $idCard = strtoupper(post('id_card'));
        $name = post('name');
        if ($deptId <= 0) json_fail('请选择科室');
        if ($idCard === '' || !idcard_valid($idCard)) json_fail('请输入正确的18位身份证号码');
        if ($name === '') json_fail('请填写患者姓名');
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) json_fail('科室不存在');
        // 不限号科室无需加号（仅限号科室提供医生加号功能）
        if (!dept_is_limited($dept)) json_fail('该科室为不限号科室，无需加号');
        // 同一患者当日同科室已有加号未使用时，不重复添加
        $exists = EmrRepository::one("SELECT id FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0", array($deptId, today_str(), $idCard));
        if ($exists) json_fail('该患者今日已存在未使用的加号');
        EmrRepository::insert('INSERT INTO extra_slots(dept_id, reg_date, id_card, name, doctor_id, doctor_name, used, created_at) VALUES(?,?,?,?,?,?,0,?)', array(
            $deptId, today_str(), $idCard, $name, $u['id'], $u['name'], now_str(),
        ));
        json_ok(array(), '加号成功：患者凭本人身份证至挂号处挂号即可');
        return;
    }

    if ($action === 'bind_room') {
        $roomId = (int)post('room_id');
        $room = EmrRepository::one('SELECT * FROM clinic_rooms WHERE id=?', array($roomId));
        if (!$room) json_fail('诊室不存在');
        // 科室归属校验：诊室必须属于本人关联科室，防止跨科室绑定大屏
        if (!in_array((int)$room['dept_id'], user_dept_ids($u), true)) {
            json_fail('无权绑定该科室诊室');
        }
        // 后端强拦截：大屏必须在线
        if (empty($room['screen_last_heartbeat']) || (time() - strtotime($room['screen_last_heartbeat'])) > 30) {
            json_fail('该大屏当前处于离线状态，无法绑定，请确保大屏已开启并在运行！');
        }
        // 已被其他医生占用 → 拒绝
        if ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
            json_fail('该大屏已被 ' . $room['current_doctor_name'] . ' 占用，无法绑定');
        }
        // 释放该医生此前绑定的其他诊室（一人一块屏）
        EmrRepository::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL WHERE current_doctor_id=?', array($u['id']));
        // 绑定当前诊室
        EmrRepository::exec('UPDATE clinic_rooms SET current_doctor_id=?, current_doctor_name=?, doctor_heartbeat=?, updated_at=? WHERE id=?',
            array($u['id'], $u['name'], now_str(), now_str(), $roomId));
        json_ok(array('room_id' => $roomId, 'room_name' => $room['room_name']), '已绑定大屏「' . $room['room_name'] . '」');
        return;
    }

    if ($action === 'unbind_room') {
        $roomId = (int)post('room_id');
        EmrRepository::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE id=? AND current_doctor_id=?',
            array(now_str(), $roomId, $u['id']));
        json_ok(array(), '已释放诊室');
        return;
    }

    if ($action === 'room_heartbeat') {
        $roomId = (int)post('room_id');
        EmrRepository::exec('UPDATE clinic_rooms SET doctor_heartbeat=?, updated_at=? WHERE id=? AND current_doctor_id=?',
            array(now_str(), now_str(), $roomId, $u['id']));
        json_ok(array());
        return;
    }

    /* ==================== 叫号动作（下一位 / 过号 / 再次叫号） ==================== */

    if ($action === 'call_next') {
        $room = doctor_bound_room($u, (int)post('room_id'));
        if (!$room) json_fail('请先绑定大屏诊室后再叫号');
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            $res = doctor_call_claim_next_tx($u, $room);
            if (isset($res['error'])) { $pdo->rollBack(); json_fail($res['error']); }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('叫号失败：' . $ex->getMessage());
        }
        json_ok(array(
            'visit' => $res['visit'],
            'pool_count' => QueueRepository::deptPoolCount((int)$room['dept_id']),
        ), '已呼叫 ' . $res['name']);
        return;
    }

    if ($action === 'call_repeat') {
        $room = doctor_bound_room($u, (int)post('room_id'));
        if (!$room) json_fail('请先绑定大屏诊室');
        if ((int)$room['current_visit_id'] <= 0) json_fail('当前无就诊患者，无法再次叫号');
        $cur = QueueRepository::roomCurrentVisit($room);
        if (!$cur) json_fail('当前患者状态已变化，请刷新后再操作');
        $now = now_str();
        QueueRepository::exec(
            "UPDATE clinic_rooms SET current_called_at=?, last_call_action='repeat_call', last_call_at=?, updated_at=? WHERE id=?",
            array($now, $now, $now, (int)$room['id'])
        );
        QueueRepository::insert(
            "INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at)
             VALUES(?,?,?,?,?,?,?,'repeat_call',?)",
            array((int)$cur['id'], $cur['flow_no'], $cur['patient_no'], (int)$room['dept_id'], (int)$room['id'], $u['id'], $u['name'], $now)
        );
        json_ok(array(), '已再次呼叫 ' . $cur['pname']);
        return;
    }

    if ($action === 'call_miss') {
        $room = doctor_bound_room($u, (int)post('room_id'));
        if (!$room) json_fail('请先绑定大屏诊室');
        $curId = (int)$room['current_visit_id'];
        if ($curId <= 0) json_fail('当前无就诊患者，无需过号');
        $cur = QueueRepository::roomCurrentVisit($room);
        if (!$cur) json_fail('当前患者状态已变化，请刷新后再操作');
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            $now = now_str();
            // 1) 标记当前患者过号
            QueueRepository::insert(
                "INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at)
                 VALUES(?,?,?,?,?,?,?,'miss',?)",
                array($curId, $cur['flow_no'], $cur['patient_no'], (int)$room['dept_id'], (int)$room['id'], $u['id'], $u['name'], $now)
            );
            // 2) 自动认领下一位
            $res = doctor_call_claim_next_tx($u, $room);
            if (isset($res['error'])) {
                // 无下一位：清空当前就诊，大屏显示暂无就诊患者
                QueueRepository::exec(
                    "UPDATE clinic_rooms SET current_visit_id=0, current_flow_no='', current_called_at=?,
                     last_call_action='miss', last_call_at=?, updated_at=? WHERE id=?",
                    array($now, $now, $now, (int)$room['id'])
                );
                $pdo->commit();
                json_ok(array('missed' => $cur['pname'], 'visit' => null),
                    '已过号 ' . $cur['pname'] . '，当前无候诊患者');
                return;
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('过号失败：' . $ex->getMessage());
        }
        json_ok(array(
            'missed' => $cur['pname'],
            'visit' => $res['visit'],
            'pool_count' => QueueRepository::deptPoolCount((int)$room['dept_id']),
        ), '已过号 ' . $cur['pname'] . '，自动呼叫 ' . $res['name']);
        return;
    }
}
