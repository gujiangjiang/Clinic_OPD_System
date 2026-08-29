<?php
/**
 * ============================================================
 * parts/cashier_write.php — 收费处：写入
 * ============================================================
 * cashier.php 按功能拆分的一部分，写入/操作类动作。
 * ============================================================ */

function cashier_part_write($action) {
    $u = Auth::user();

    if ($action === 'quick_name') {
        json_ok(array('name' => '无名氏' . next_patient_no()));
        return;
    }

    if ($action === 'register') {
        $idCard = strtoupper(post('id_card'));
        $name = post('name');
        $deptId = (int)post('dept_id');
        $feeType = post('fee_type', '自费');
        $hasId = ($idCard !== '');
        $isQuick = post('quick') === '1'; // 快速挂号（无名氏，危重症无身份信息场景）

        // ===== 基础校验 =====
        if (!$isQuick) {
            if ($name === '') json_fail('请填写患者姓名');
            // 实名挂号不允许使用「无名氏」前缀，避免与快速挂号患者混淆
            if (strpos($name, '无名氏') === 0) json_fail('请填写患者真实姓名');
        }
        if ($deptId <= 0) json_fail('请选择挂号科室');
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) json_fail('科室不存在或已停用');

        $gender = $birth = $age = 0;
        if ($hasId) {
            // 只有符合规则的身份证才允许挂号
            if (!idcard_valid($idCard)) {
                json_fail('身份证号码不正确（18位含校验码），请核对后重新输入');
            }
            if ($dept['type'] !== 'emergency' && $dept['type'] !== 'clinic') {
                json_fail('科室类型异常');
            }
            $info = idcard_info($idCard);
            $gender = $info['gender'];
            $birth = $info['birth'];
            $age = $info['age'];
        } elseif ($isQuick) {
            // 快速挂号（无名氏）：年龄必填（目测估算）；出生日期选填，
            // 缺省按「今天 − 年龄」推算；仅限挂号费为 0 元的科室
            if ((float)$dept['fee'] != 0) {
                json_fail('快速挂号（无名氏）仅可挂挂号费为 0 元的科室');
            }
            $age = (int)post('age', 0);
            if ($age < 1 || $age > 130) json_fail('请填写估算年龄（1-130 岁）');
            $gender = post('gender', '男') === '女' ? '女' : '男';
            $birth = post('birth_date', '');
            if ($birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) $birth = '';
            if ($birth === '') {
                $dt = new DateTime();
                $dt->modify('-' . $age . ' years');
                $birth = $dt->format('Y-m-d');
            }
            $feeType = '自费';
            $name = ''; // 姓名忽略前端传值，以系统生成的「无名氏+患者编号」为准
        } else {
            // 无身份证：仅急诊 + 默认自费（不可修改）
            if ($dept['type'] !== 'emergency') {
                json_fail('未填写身份证号码时仅可挂急诊科室');
            }
            $feeType = '自费';
            $gender = post('gender', '男');
            $birth = post('birth_date', '');
            $age = (int)post('age', 0);
        }

        // ===== 患者档案（既往登记自动获取，可修改；身份证信息绑定唯一ID） =====
        $patient = $hasId ? DB::one('patient', 'SELECT * FROM patients WHERE id_card=?', array($idCard)) : null;
        if ($patient) {
            // 已就诊过：更新可修改信息，姓名/性别/出生日期保持锁定
            DB::exec('patient', 'UPDATE patients SET name=?, ethnicity=?, marital=?, occupation=?, work_unit=?, address=?, phone=? WHERE id_card=?', array(
                $name, post('ethnicity'), post('marital'), post('occupation'), post('work_unit'), post('address'), post('phone'), $idCard,
            ));
            $patientNo = $patient['patient_no'];
        } else {
            $patientNo = next_patient_no();
            // 快速挂号：姓名 = 无名氏 + 患者编号（全局唯一，与实名患者天然区分）
            if ($isQuick) {
                $name = '无名氏' . $patientNo;
            }
            // 注意：无身份证时 id_card 存 NULL（SQLite 唯一约束允许多个 NULL，
            // 若存空字符串则第二位无身份证患者会触发唯一约束冲突）
            $dbCard = ($idCard !== '') ? $idCard : null;
            DB::insert('patient', 'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $patientNo, $dbCard, $name, $gender, $birth, $age, post('ethnicity'), post('marital'), post('occupation'), post('work_unit'), post('address'), post('phone'), now_str(),
            ));
        }

        // ===== 同一患者当日同【首次挂号科室】仅可挂一次 =====
        $dup = DB::one('patient', "SELECT id FROM registrations
            WHERE patient_no=? AND first_dept_id=? AND date(register_time)=? AND status IN ('pending','paid','visiting','finished')",
            array($patientNo, $deptId, today_str()));
        if ($dup) {
            json_fail('该患者今日已在【' . $dept['name'] . '】挂号，不能重复挂号（退费后可以重新挂号）');
        }

        // ===== 号源控制（门诊科室：按作息时间开放，急诊 24 小时不受限） =====
        $isExtra = 0;
        $wsState = work_session_now();
        if ($dept['type'] === 'clinic' && !in_array($wsState, array('am', 'pm'), true)) {
            json_fail(work_status_msg($wsState));
        }
        $session = $dept['type'] === 'emergency' ? 'all' : ($wsState === 'pm' ? 'pm' : 'am');
        if ($dept['type'] === 'clinic') {
            $quota = $session === 'am' ? (int)$dept['am_quota'] : (int)$dept['pm_quota'];
            $used = dept_used_count($deptId, $session);
            if ($quota > 0 && $used >= $quota) {
                // 号源满：校验医生加号（仅限该患者本人）
                $slot = $hasId ? DB::one('dept', 'SELECT id FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0', array($deptId, today_str(), $idCard)) : null;
                if (!$slot) {
                    json_fail('【' . $dept['name'] . '】今日号源已满，无法挂号，可联系医生工作站加号');
                }
                $isExtra = 1;
                DB::exec('dept', 'UPDATE extra_slots SET used=1 WHERE id=?', array($slot['id']));
            }
        }

        // ===== 生成挂号记录 =====
        $flowNo = next_flow_no();
        $visitSeq = next_visit_seq($deptId);
        $visitId = DB::insert('patient', 'INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, cashier_id, cashier_name, register_time, is_extra) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $patientNo, $flowNo, $visitSeq,
            $deptId, $dept['name'], $deptId, $dept['name'],
            $session, $feeType, (float)$dept['fee'], 'pending',
            $u['id'], $u['name'], now_str(), $isExtra,
        ));

        json_ok(array(
            'visit_id' => oid($visitId),
            'patient_no' => $patientNo,
            'name' => $name, // 快速挂号时为系统最终生成的「无名氏+编号」（可能与预览号不同，以本值为准）
            'flow_no' => $flowNo,
            'visit_seq' => $visitSeq,
            'dept_name' => $dept['name'],
            'fee' => (float)$dept['fee'],
            'id_card' => $idCard,
            'is_extra' => $isExtra,
        ), '挂号成功，请完成缴费');
        return;
    }

    if ($action === 'pay_visit') {
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] !== 'pending') json_fail('当前状态不可缴费');
        DB::exec('patient', 'UPDATE registrations SET status=?, payment_time=? WHERE id=?', array('paid', now_str(), $visitId));
        $payId = DB::insert('order', 'INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,0,?,?,?,?,1,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], 'visit', (float)$visit['fee'], $u['id'], $u['name'], now_str(),
        ));
        json_ok(array('payment_id' => oid($payId)), '缴费成功');
        return;
    }

    if ($action === 'cancel_visit') {
        $visitId = did(post('visit_id'));
        $reason = post('reason', '');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] === 'pending') {
            DB::exec('patient', "UPDATE registrations SET status='cancelled', cancel_reason=? WHERE id=?", array($reason, $visitId));
            json_ok(array(), '挂号已取消');
        } elseif ($visit['status'] === 'paid') {
            // 已缴费：退费并登记退费记录；同首次科室当日可重新挂号（序号递增）
            DB::exec('patient', "UPDATE registrations SET status='refunded', cancel_reason=? WHERE id=?", array($reason, $visitId));
            DB::insert('order', 'INSERT INTO refunds(visit_id, order_id, patient_no, flow_no, total, reason, cashier_id, cashier_name, created_at) VALUES(?,0,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], (float)$visit['fee'], $reason, $u['id'], $u['name'], now_str(),
            ));
            json_ok(array(), '退费成功：挂号费已退回，可重新挂号');
        } else {
            json_fail('当前状态不可退费（已就诊/已退费）');
        }
        return;
    }

    if ($action === 'refund_order') {
        $orderId = did(post('order_id'));
        if ($orderId <= 0) json_fail('参数无效');
        $reason = post('reason', '');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order) json_fail('开单不存在');
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=?', array($orderId));
        // 退费资格：检验/检查未登记、药房未发药、处置未执行
        foreach ($items as $it) {
            $started = ($it['status'] !== 'paid');
            if ($started) {
                json_fail('存在已开始执行的项目（' . e($it['item_name']) . '），不可退费');
            }
        }
        DB::exec('order', "UPDATE order_items SET status='refunded' WHERE order_id=?", array($orderId));
        DB::exec('order', "UPDATE orders SET status='refunded', refunded_at=? WHERE id=?", array(now_str(), $orderId));
        DB::insert('order', 'INSERT INTO refunds(visit_id, order_id, patient_no, flow_no, total, reason, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            $order['visit_id'], $orderId, $order['patient_no'], $order['flow_no'],
            (float)$order['total_amount'], $reason, $u['id'], $u['name'], now_str(),
        ));
        // 药品退费：恢复库存
        if ($order['order_type'] === 'prescription') {
            foreach ($items as $it) {
                if ($it['item_id'] > 0 && (int)$it['sub_of'] === 0) {
                    DB::exec('drug', 'UPDATE drugs SET qty = qty + ? WHERE id=?', array((int)$it['quantity'], $it['item_id']));
                    DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                        $it['item_id'], (int)$it['quantity'], 'refund', $order['order_no'], $u['name'], now_str(),
                    ));
                }
            }
        }
        json_ok(array(), '退费成功，药品库存已恢复');
        return;
    }
}
