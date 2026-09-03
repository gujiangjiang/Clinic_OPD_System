<?php
/**
 * ============================================================
 * parts/cashier_write.php — 收费处：写入
 * ============================================================
 * cashier.php 按功能拆分的一部分，写入/操作类动作。
 * 数据访问统一委托 CashierRepository，本文件不含原生 SQL。
 * 复合写操作在本层开启原生事务协同多个 Repository。
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
        $dept = CashierRepository::activeDept($deptId);
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
        // 并发撞号重试：patient_no/flow_no 为 COUNT+1 生成（无锁），两个窗口同时挂号
        // 可能生成相同编号，触发 UNIQUE 约束冲突（SQLite=19/MySQL=1062）→ 回滚并重试
        // 重新生成编号，最多 3 次。加号标记已改为条件更新（used=0→1）防双发。
        // fail()：事务内业务校验失败先显式回滚再输出（兼容 MySQL 非持久连接）
        $fail = function ($msg) use (&$pdo) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            json_fail($msg);
        };
        $pdo = DatabaseManager::getMain();
        $maxAttempts = 3;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $pdo->beginTransaction();
        try {
        $patient = $hasId ? PatientRepository::byIdCard($idCard) : null;
        if ($patient) {
            // 已就诊过：更新可修改信息，姓名/性别/出生日期保持锁定
            CashierRepository::updatePatientByIdCard($idCard, array(
                'name' => $name, 'ethnicity' => post('ethnicity'), 'marital' => post('marital'),
                'occupation' => post('occupation'), 'work_unit' => post('work_unit'),
                'address' => post('address'), 'phone' => post('phone'),
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
            CashierRepository::createPatient(array(
                'patient_no' => $patientNo, 'id_card' => $dbCard, 'name' => $name, 'gender' => $gender,
                'birth_date' => $birth, 'age' => $age, 'ethnicity' => post('ethnicity'),
                'marital' => post('marital'), 'occupation' => post('occupation'),
                'work_unit' => post('work_unit'), 'address' => post('address'), 'phone' => post('phone'),
            ));
        }

        // ===== 同一患者当日同【首次挂号科室】仅可挂一次 =====
        $dup = CashierRepository::todayDupVisit($patientNo, $deptId);
        if ($dup) {
            $fail('该患者今日已在【' . $dept['name'] . '】挂号，不能重复挂号（退费后可以重新挂号）');
        }

        // ===== 号源控制（门诊科室：按作息时间开放，急诊 24 小时不受限） =====
        $isExtra = 0;
        $wsState = work_session_now();
        if ($dept['type'] === 'clinic' && !in_array($wsState, array('am', 'pm'), true)) {
            $fail(work_status_msg($wsState));
        }
        $session = $dept['type'] === 'emergency' ? 'all' : ($wsState === 'pm' ? 'pm' : 'am');
        if ($dept['type'] === 'clinic') {
            $quota = $session === 'am' ? (int)$dept['am_quota'] : (int)$dept['pm_quota'];
            $used = CashierRepository::deptUsed($deptId, $session);
            if ($quota > 0 && $used >= $quota) {
                // 号源满：校验医生加号（仅限该患者本人）
                $slot = $hasId ? CashierRepository::unusedSlot($deptId, $idCard) : null;
                if (!$slot) {
                    $fail('【' . $dept['name'] . '】今日号源已满，无法挂号，可联系医生工作站加号');
                }
                $isExtra = 1;
                $affectedSlot = CashierRepository::markSlotUsed($slot['id']);
                if ($affectedSlot === 0) {
                    $fail('该加号名额已被使用，请刷新后重试');
                }
            }
        }

        // ===== 生成挂号记录 =====
        $flowNo = next_flow_no();
        $visitSeq = next_visit_seq($deptId);
        $visitId = CashierRepository::createRegistration(array(
            'patient_no' => $patientNo, 'flow_no' => $flowNo, 'visit_seq' => $visitSeq,
            'first_dept_id' => $deptId, 'first_dept_name' => $dept['name'],
            'current_dept_id' => $deptId, 'current_dept_name' => $dept['name'],
            'session' => $session, 'fee_type' => $feeType, 'fee' => (float)$dept['fee'],
            'status' => 'pending', 'cashier_id' => $u['id'], 'cashier_name' => $u['name'],
            'is_extra' => $isExtra,
        ));

        $pdo->commit();
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
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 唯一约束冲突（并发撞号）→ 重新生成编号重试；其余异常直接失败
            if ($attempt < $maxAttempts - 1 && is_unique_conflict($ex)) {
                continue;
            }
            json_fail('挂号失败：' . $ex->getMessage());
        }
        }
    }

    if ($action === 'pay_visit') {
        $visitId = did(post('visit_id'));
        $method = post('method', '现金');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        // 原子条件更新防并发重复缴费（仅 pending 可转 paid）
        $affectedPay = CashierRepository::exec(
            "UPDATE registrations SET status='paid', paid_at=? WHERE id=? AND status='pending'",
            array(now_str(), $visitId)
        );
        if ($affectedPay === 0) json_fail('当前状态不可缴费');
        // 缴费流水号：挂号费同样生成唯一编号（凭条显示/退费批次判定）
        $paymentNo = 'JF' . date('YmdHis') . str_pad((string)rand(0, 999), 3, '0', STR_PAD_LEFT);
        $payId = CashierRepository::createPayment(array(
            'visit_id' => $visitId, 'order_id' => 0, 'patient_no' => $visit['patient_no'], 'flow_no' => $visit['flow_no'],
            'kind' => 'visit', 'total' => (float)$visit['fee'], 'item_count' => 1,
            'cashier_id' => $u['id'], 'cashier_name' => $u['name'], 'payment_no' => $paymentNo, 'method' => $method,
        ));
        json_ok(array('payment_id' => oid($payId), 'payment_no' => $paymentNo), '缴费成功');
        return;
    }

    if ($action === 'cancel_visit') {
        $visitId = did(post('visit_id'));
        $reason = post('reason', '');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] === 'pending') {
            CashierRepository::updateVisitStatus($visitId, 'cancelled', array('cancel_reason' => $reason));
            json_ok(array(), '挂号已取消');
        } elseif ($visit['status'] === 'paid') {
            // 已缴费：退费并登记退费记录；同首次科室当日可重新挂号（序号递增）
            // 条件状态迁移（WHERE status='paid'）防并发重复退费：两个窗口同时取消，
            // 仅第一个 update 影响行数为 1，第二个为 0 即拒绝
            $pdo = DatabaseManager::getMain();
            $pdo->beginTransaction();
            try {
                $affected = CashierRepository::exec(
                    "UPDATE registrations SET status='refunded', cancel_reason=? WHERE id=? AND status='paid'",
                    array($reason, $visitId)
                );
                if ($affected === 0) {
                    $pdo->rollBack();
                    json_fail('当前状态不可退费（已退费/已就诊）');
                }
                CashierRepository::createRefund(array(
                    'visit_id' => $visitId, 'order_id' => 0, 'patient_no' => $visit['patient_no'], 'flow_no' => $visit['flow_no'],
                    'total' => (float)$visit['fee'], 'reason' => $reason, 'cashier_id' => $u['id'], 'cashier_name' => $u['name'],
                ));
                $pdo->commit();
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_fail('退费失败：' . $ex->getMessage());
            }
            json_ok(array(), '退费成功：挂号费已退回，可重新挂号');
        } else {
            json_fail('当前状态不可退费（已就诊/已退费）');
        }
        return;
    }

    // ===== 退费核心（单订单原子退费：状态迁移 + 退费流水 + 药品库存恢复） =====
    // 条件状态迁移防并发重复退费；支持 payment_no（批次凭条）与支付方式记录
    $refundOne = function ($u, $order, $reason, $paymentNo, $method) {
        $orderId = (int)$order['id'];
        $items = CashierRepository::orderItems($orderId);
        // 退费资格：检验/检查未登记、药房未发药、处置未执行
        foreach ($items as $it) {
            if ($it['status'] !== 'paid') {
                json_fail('存在已开始执行的项目（' . e($it['item_name']) . '），不可退费');
            }
        }
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            $affectedOrder = CashierRepository::exec(
                "UPDATE orders SET status='refunded', refunded_at=? WHERE id=? AND status='paid'",
                array(now_str(), $orderId)
            );
            if ($affectedOrder === 0) {
                $pdo->rollBack();
                json_fail('当前订单状态不可退费（已退费/已取消/未缴费）');
            }
            $affectedItems = CashierRepository::exec(
                "UPDATE order_items SET status='refunded' WHERE order_id=? AND status='paid'",
                array($orderId)
            );
            if ($affectedItems === 0) {
                $pdo->rollBack();
                json_fail('订单明细状态已变更，不可退费');
            }
            CashierRepository::createRefund(array(
                'visit_id' => $order['visit_id'], 'order_id' => $orderId, 'patient_no' => $order['patient_no'], 'flow_no' => $order['flow_no'],
                'total' => (float)$order['total_amount'], 'reason' => $reason, 'cashier_id' => $u['id'], 'cashier_name' => $u['name'],
                'payment_no' => $paymentNo, 'method' => $method,
            ));
            // 药品退费：恢复库存（幂等——仅对本事务实际置为 refunded 的 paid 明细）
            if ($order['order_type'] === 'prescription') {
                foreach ($items as $it) {
                    if ($it['item_id'] > 0 && (int)$it['sub_of'] === 0 && $it['status'] === 'paid') {
                        CashierRepository::restoreDrugStock($it['item_id'], (int)$it['quantity']);
                        CashierRepository::createInventoryTrans((int)$it['item_id'], (int)$it['quantity'], 'refund', $order['order_no'], $u['name']);
                    }
                }
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('退费失败：' . $ex->getMessage());
        }
    };

    if ($action === 'refund_order') {
        $orderId = did(post('order_id'));
        if ($orderId <= 0) json_fail('参数无效');
        $reason = post('reason', '');
        $order = CashierRepository::order($orderId);
        if (!$order) json_fail('开单不存在');
        // 批量缴费批次判定：同 payment_no 关联多张订单 = 同一张缴费凭条，
        // 凭条一致性要求整单退费，禁止单独退其中某一项目（前后端一致拦截）
        $batchPay = CashierRepository::one(
            "SELECT payment_no FROM payments WHERE order_id=? AND kind='order' ORDER BY id DESC LIMIT 1",
            array($orderId)
        );
        $paymentNo = ($batchPay && !empty($batchPay['payment_no'])) ? $batchPay['payment_no'] : '';
        if ($paymentNo !== '') {
            $batchOrders = CashierRepository::q(
                'SELECT o.* FROM orders o JOIN payments p ON p.order_id=o.id AND p.kind=\'order\'
                 WHERE p.payment_no=? AND o.id<>? AND o.status=\'paid\'',
                array($paymentNo, $orderId)
            );
            if ($batchOrders) {
                json_fail('该单与 ' . count($batchOrders) . ' 张开单为同一缴费批次（同一缴费凭条），不可单独退费，请整单退费');
            }
        }
        $refundOne($u, $order, $reason, $paymentNo, post('method', '现金'));
        json_ok(array(), '退费成功，药品库存已恢复');
        return;
    }

    if ($action === 'refund_batch') {
        // 整单退费：同 payment_no（同一缴费凭条）的全部订单一起退，
        // 凭条一致性要求不允许单独退其中某一项目，只能整单退
        $paymentNo = trim((string)post('payment_no', ''));
        $reason = post('reason', '');
        if ($paymentNo === '') json_fail('缺少缴费批次号');
        $orders = CashierRepository::q(
            'SELECT o.* FROM orders o JOIN payments p ON p.order_id=o.id AND p.kind=\'order\'
             WHERE p.payment_no=? AND o.status=\'paid\' ORDER BY o.id ASC',
            array($paymentNo)
        );
        if (!$orders) json_fail('该缴费批次无待退费订单（已退费/已取消）');
        $method = post('method', '现金');
        foreach ($orders as $o) {
            $refundOne($u, $o, $reason, $paymentNo, $method);
        }
        json_ok(array(), '已整单退费 ' . count($orders) . ' 张开单');
        return;
    }
}