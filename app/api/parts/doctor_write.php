<?php
/**
 * ============================================================
 * parts/doctor_write.php — 医生端：写入
 * ============================================================
 * doctor.php 按功能拆分的一部分，写入/操作类动作。
 * ============================================================ */

function doctor_part_write($action) {
    $u = Auth::user();

    if ($action === 'set_dept') {
        $deptId = (int)post('dept_id');
        $ids = doctor_dept_ids($u);
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
}
