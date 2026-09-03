<?php
/**
 * ============================================================
 * parts/cashier_read.php — 收费处：读取
 * ============================================================
 * cashier.php 按功能拆分的一部分，读取类动作。
 * 数据访问统一委托 CashierRepository / PatientRepository，
 * 本文件不含原生 SQL。
 * ============================================================ */

function cashier_part_read($action) {
    $u = Auth::user();

    if ($action === 'home_stats') {
        $today = date('Y-m-d');
        $regToday = (int)CashierRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=?", array($today));
        $regFeeToday = (float)CashierRepository::val("SELECT COALESCE(SUM(total),0) FROM payments WHERE kind='visit' AND date(created_at)=?", array($today));
        $paidToday = (float)CashierRepository::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today));
        // 今日退费：refunds 表为挂号退费与订单退费统一流水（orders.refunded_at 只覆盖订单退费，
        // 挂号退费 cancel_visit 仅写 refunds + registrations.status=refunded，不落 orders）
        $refundToday = (float)CashierRepository::val("SELECT COALESCE(SUM(total),0) FROM refunds WHERE date(created_at)=?", array($today));
        $waiting = (int)CashierRepository::val("SELECT COUNT(*) FROM registrations WHERE status='paid' AND date(registered_at)=?", array($today));
        $trend = trend_7_days(function ($day) {
            return (float)CashierRepository::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($day));
        });
        json_ok(array(
            'kpi' => array('reg_today' => $regToday, 'reg_fee' => round($regFeeToday, 2), 'paid_today' => round($paidToday, 2),
                'refund_today' => round($refundToday, 2), 'waiting' => $waiting),
            'trend' => $trend,
        ));
        return;
    }

    if ($action === 'depts') {
        $idCard = get('id_card', '');
        $showAll = get('all') === '1';
        $ws = work_session_now();
        $bookable = in_array($ws, array('am', 'pm'), true);
        $session = $ws === 'pm' ? 'pm' : 'am'; // 非可挂时段展示上午号量供参考
        $depts = CashierRepository::q("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY type DESC, sort, id");
        $list = array();
        foreach ($depts as $d) {
            // 无身份证仅显示急诊科室（all=1 时不过滤，供号源总览展示）
            if (!$showAll && $idCard === '' && $d['type'] !== 'emergency') continue;
            $used = ($d['type'] === 'clinic') ? CashierRepository::deptUsed($d['id'], $session) : 0;
            $quota = ($d['type'] === 'clinic') ? ($session === 'am' ? (int)$d['am_quota'] : (int)$d['pm_quota']) : 0;
            $extra = 0;
            if ($idCard !== '' && $used >= $quota && $quota > 0) {
                $extra = (int)CashierRepository::val('SELECT COUNT(*) FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0', array($d['id'], today_str(), $idCard));
            }
            $isClinic = $d['type'] === 'clinic';
            $list[] = array(
                'id' => (int)$d['id'],
                'name' => $d['name'],
                'type' => $d['type'],
                'fee' => (float)$d['fee'],
                'quota' => $quota,
                'used' => $used,
                'remaining' => $isClinic ? max(0, $quota - $used) + $extra : -1,
                'full' => $isClinic ? ($used >= $quota && $extra === 0) : false,
                'bookable' => $isClinic ? $bookable : true, // 急诊 24 小时
            );
        }
        json_ok(array(
            'list' => $list,
            'session' => $session,
            'schedule' => array(
                'state' => $ws,
                'bookable' => $bookable,
                'msg' => work_status_msg($ws),
                'am_start' => work_schedule()['am_start'],
                'pm_start' => work_schedule()['pm_start'],
                'pm_end' => work_schedule()['pm_end'],
                'is_dst' => work_schedule()['is_dst'],
            ),
        ));
        return;
    }

    if ($action === 'reg_list') {
        $date = get('date', today_str());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today_str();
        $filters = array(
            'dept_type' => get('dept_type', ''),
            'status'    => get('status', ''),
            'kw'        => trim((string)get('kw', '')),
        );
        $rows = CashierRepository::visitListByDate($date, $filters);
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 条挂号记录（含退费/取消）</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">🗓️</div>当日暂无符合筛选条件的挂号记录</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>就诊序号</th><th>患者</th><th>患者ID</th><th>流水号</th><th>首次挂号科室</th><th>当前科室</th>' .
                '<th>费用</th><th>状态</th><th>挂号时间</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $statusBadge = '<span class="badge ' . ($r['status'] === 'paid' ? 'badge-primary' : ($r['status'] === 'finished' ? 'badge-success' : ($r['status'] === 'refunded' ? 'badge-gray' : ($r['status'] === 'cancelled' ? 'badge-gray' : 'badge-warning')))) . '">' . e(visit_status_name($r['status'])) . '</span>';
                // 操作列：退费患者显示退费理由（未填写则隐藏）
                $refundNote = ($r['status'] === 'refunded' && !empty($r['cancel_reason']))
                    ? '<div class="fs-12 text-danger" title="退费理由">退费：' . e($r['cancel_reason']) . '</div>' : '';
                $html .= '<tr>' .
                    '<td class="fw-700">' . e($r['first_dept_name']) . ' ' . str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '号</td>' .
                    '<td><a href="javascript:void(0)" onclick="patientEdit(\'' . e($r['patient_no']) . '\')">' . e($r['pname']) . '</a></td>' .
                    '<td>' . e($r['patient_no']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($r['first_dept_name']) . '</td>' .
                    '<td>' . e($r['current_dept_name']) . '</td>' .
                    '<td>¥' . money($r['fee']) . '</td>' .
                    '<td>' . $statusBadge . $refundNote . '</td>' .
                    '<td class="fs-12">' . e(substr($r['registered_at'], 5, 11)) . '</td>' .
                    '<td><div class="flex gap-4">' .
                    // 凭条是缴费凭证：仅已实际缴费的状态提供补打（待缴费/取消/退费不显示）
                    (in_array($r['status'], array('paid', 'visiting', 'finished'), true) ?
                        '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=receipt&visit_id=' . e(oid($r['id'])) . '\',null,\'ticket\')">补打凭条</button>' : '') .
                    // 待缴费：支持继续缴费（完成后自动打印凭条）
                    ($r['status'] === 'pending' ?
                        '<button class="btn btn-primary btn-sm" onclick="payVisit(\'' . e(oid($r['id'])) . '\')">继续缴费</button>' : '') .
                    (in_array($r['status'], array('pending', 'paid'), true) ?
                        '<button class="btn btn-outline btn-sm" onclick="cancelVisit(\'' . e(oid($r['id'])) . '\',\'' . e($r['status']) . '\')">' . ($r['status'] === 'paid' ? '退费' : '取消') . '</button>' : '') .
                    '</div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        return;
    }

    if ($action === 'visit_search') {
        $kw = trim(get('kw', ''));
        if ($kw === '') json_ok(array('list' => array()));
        json_ok(array('list' => search_visit_records($kw)));
        return;
    }

    if ($action === 'visit_detail') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $patient = $row['patient'];
        $html = '';

        // ===== 患者信息卡（姓名/年龄/性别/挂号科室/挂号时间/流水号） =====
        $html .= '<div class="card" style="padding:14px;margin-bottom:12px" data-vid="' . e(oid($visitId)) . '">' .
            '<div class="flex-between"><div><span class="fw-700 fs-16">' . e($patient['name']) . '</span> ' .
            '<span class="text-muted fs-13">' . e($patient['gender']) . ' / ' . age_format($patient['birth_date'], $visit['registered_at']) . '</span></div>' .
            '<span class="badge badge-primary">' . e($visit['flow_no']) . '</span></div>' .
            '<div class="fs-13 text-muted mt-4">患者ID ' . e($visit['patient_no']) . ' ｜ 首次科室 ' . e($visit['first_dept_name']) .
            ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ 挂号 ' . e(substr($visit['registered_at'], 0, 16)) .
            ' ｜ <span class="badge badge-gray">' . e(visit_status_name($visit['status'])) . '</span></div></div>';

        // 批量查询开单明细（避免逐单 N+1）
        $orders = CashierRepository::payableOrdersOfVisit($visitId);
        $orderIds = array();
        foreach ($orders as $o) $orderIds[] = (int)$o['id'];
        $itemsByOrder = array();
        if ($orderIds) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            foreach (CashierRepository::q("SELECT * FROM order_items WHERE order_id IN ($ph) ORDER BY id", $orderIds) as $it) {
                $itemsByOrder[(int)$it['order_id']][] = $it;
            }
        }
        $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');

        // ===== 未缴费项目（挂号费 + open 开单）提示与缴费 =====
        $unpaid = array();
        if ($visit['status'] === 'pending') {
            $unpaid[] = array('kind' => 'visit', 'name' => '挂号费（' . $visit['first_dept_name'] . '）', 'amount' => (float)$visit['fee']);
        }
        $openOrders = array();
        foreach ($orders as $o) {
            $items = isset($itemsByOrder[(int)$o['id']]) ? $itemsByOrder[(int)$o['id']] : array();
            if (order_agg_status($o['order_type'], $items) === 'open') {
                $openOrders[] = $o;
                $unpaid[] = array('kind' => 'order', 'oid' => oid($o['id']), 'order_no' => $o['order_no'],
                    'name' => (isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : '') . ' ' . $o['order_no'],
                    'doctor' => $o['doctor_name'], 'amount' => (float)$o['total_amount'], 'items' => $items);
            }
        }
        if ($unpaid) {
            $unpaidTotal = 0;
            foreach ($unpaid as $u) $unpaidTotal += $u['amount'];
            $html .= '<div class="fs-14 fw-700 mb-8">未缴费 <span class="fs-12 text-muted fw-400">（' . count($unpaid) . ' 项，合计 ¥' . money($unpaidTotal) . '）</span></div>';
            $html .= '<div style="border:1px solid var(--warning,#f59e0b);background:var(--warning-soft,rgba(245,158,11,.06));border-radius:8px;padding:10px 12px;margin-bottom:8px" class="flex-between">' .
                '<span class="fs-13">💡 该次就诊存在未缴费项目，点击「批量缴费」可一次性全部缴清（合并为一张缴费凭条）。</span>' .
                '<button class="btn btn-warning btn-sm" onclick="batchPayAll()">💳 一键全部缴费（¥' . money($unpaidTotal) . '）</button></div>';
            $html .= '<div class="flex gap-8 mb-8" id="batchBar" style="align-items:center">' .
                '<label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="batchAll" onchange="toggleAll()"> 全选</label>' .
                '<button class="btn btn-success btn-sm" onclick="batchPay()">批量缴费（已选 <span id="batchCount">0</span>）</button>' .
                ($visit['status'] === 'pending' ? '<button class="btn btn-primary btn-sm" onclick="payVisitFee(\'' . e(oid($visitId)) . '\')">缴挂号费</button>' : '') .
                '</div>';
            foreach ($unpaid as $u) {
                if ($u['kind'] === 'visit') {
                    $html .= '<div class="flex-between" style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">' .
                        '<span class="fs-13">🎫 ' . e($u['name']) . '</span>' .
                        '<span class="fs-13 fw-600">¥' . money($u['amount']) . '</span></div>';
                    continue;
                }
                $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' .
                    '<div class="flex-between">' .
                    '<span class="fs-13 fw-600">' . e($u['name']) . ' ｜ 开单医生 ' . e($u['doctor']) . '</span>' .
                    '<span class="fs-13 fw-600">¥' . money($u['amount']) . '</span></div>';
                foreach ($u['items'] as $it) {
                    $html .= '<div class="fs-12 text-muted">· ' . e($it['item_name']) . ' ×' . (int)$it['quantity'] . ' ￥' . money($it['price'] * $it['quantity']) . '</div>';
                }
                $html .= '<div class="mt-8 flex gap-8">' .
                    '<label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" class="batchPay" value="' . e($u['oid']) . '" onchange="updateBatchCount()"> 选择</label>' .
                    '<button class="btn btn-success btn-sm" onclick="payOrder(\'' . e($u['oid']) . '\')">缴费</button></div></div>';
            }
        }

        // ===== 已缴费：按缴费凭条批次（payment_no）分组展示 =====
        $pays = CashierRepository::paymentsOfVisit($visitId);
        // 分组：挂号费独立一组；订单按 payment_no 聚合（同批次共享凭条）
        $groups = array();
        $visitPay = null;
        foreach ($pays as $p) {
            if ($p['kind'] === 'visit') {
                $visitPay = $p;
                continue;
            }
            $no = (!empty($p['payment_no'])) ? $p['payment_no'] : ('P' . $p['id']);
            if (!isset($groups[$no])) $groups[$no] = array('payment_no' => $no, 'pay_id' => $p['id'], 'created_at' => $p['created_at'],
                'cashier_name' => $p['cashier_name'], 'method' => $p['method'], 'total' => 0, 'orders' => array());
            $groups[$no]['total'] += (float)$p['total'];
            $groups[$no]['orders'][] = $p['order_id'];
        }
        $html .= '<div class="fs-14 fw-700 mb-8 mt-16">缴费凭条 <span class="fs-12 text-muted fw-400">（同批次共享一张凭条与流水号，不可单独退费）</span></div>';
        if (!$pays) {
            $html .= '<div class="fs-13 text-muted">暂无缴费记录</div>';
        }
        // 挂号费凭条（优化2：补打直接用挂号凭条 receipt，非缴费凭条）
        if ($visitPay) {
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' .
                '<div class="flex-between">' .
                '<span class="fs-13 fw-600">🎫 挂号费凭条</span>' .
                '<span class="fs-13 fw-600">¥' . money($visitPay['total']) . '</span></div>' .
                // 优化8：挂号费凭条不显示流水号，仅 日期 时间 收费员
                '<div class="fs-12 text-muted mt-4">' . e(substr($visitPay['created_at'], 0, 16)) . ' ｜ 收费员 ' . e($visitPay['cashier_name']) . ' ｜ ' . e($visitPay['method']) . '</div>' .
                '<div class="mt-8 flex gap-8">' .
                '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=receipt&visit_id=' . e(oid($visitId)) . '\',null,\'ticket\')">🖨️ 补打挂号凭条</button>' .
                ($visit['status'] === 'paid' ? '<button class="btn btn-outline btn-sm" onclick="cancelVisit(\'' . e(oid($visitId)) . '\',\'paid\')">退费</button>' : '') .
                '</div></div>';
        }
        foreach ($groups as $g) {
            // 该批次订单明细（取首个 payment_id 打印合并凭条）
            $orderNames = array();
            $multi = count($g['orders']) > 1;
            $gItemCnt = 0;
            foreach ($g['orders'] as $gOid) {
                $gOrder = CashierRepository::order($gOid);
                $gItems = $gOrder ? CashierRepository::orderItems($gOid) : array();
                foreach ($gItems as $gi) {
                    $orderNames[] = $gi['item_name'];
                    $gItemCnt++;
                }
            }
            // 项目摘要：最多显示 3 项，其余省略
            $showNames = array_slice($orderNames, 0, 3);
            $sumText = implode('、', array_map('e', $showNames)) . (count($orderNames) > 3 ? ' 等 ' . count($orderNames) . ' 项' : '');
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' .
                '<div class="flex-between">' .
                '<span class="fs-13 fw-600">🧾 缴费凭条 <span class="fs-12 text-muted fw-400">' . ($multi ? '（含' . count($g['orders']) . '张开单）' : '') . '</span></span>' .
                '<span class="fs-13 fw-600">¥' . money($g['total']) . '</span></div>' .
                '<div class="fs-12 text-muted mt-4">' . e(substr($g['created_at'], 0, 16)) . ' ｜ 流水号 ' . e($g['payment_no']) . ' ｜ 收费员 ' . e($g['cashier_name']) . '</div>' .
                '<div class="fs-12 text-muted mt-4" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $sumText . '</div>' .
                '<div class="mt-8 flex gap-8">' .
                '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=payment&payment_id=' . e(oid($g['pay_id'])) . '\',null,\'ticket\')">🖨️ 补打凭条</button>' .
                // 同批次多订单 → 整单退费（不可单独退）；单订单 → 普通退费
                ($multi
                    ? '<button class="btn btn-outline btn-sm" onclick="refundBatch(\'' . e($g['payment_no']) . '\')">退费（整单）</button>'
                    : '<button class="btn btn-outline btn-sm" onclick="refundOrder(\'' . e(oid($g['orders'][0])) . '\')">退费</button>') .
                '</div></div>';
        }
        json_ok(array('html' => $html));
        return;
    }

    if ($action === 'pay_orders') {
        $ids = json_decode(post('order_ids', '[]'), true);
        if (!is_array($ids) || !$ids) json_fail('请选择要缴费的项目');
        $method = post('method', '现金');
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
        // 缴费流水号：每次缴费（含批量合并）生成唯一编号，同批次共享——
        // 打印合并凭条 / 补打 / 退费批次判定统一以 payment_no 关联。
        // 与挂号流水号关联（JF + 流水号 + 时间戳 + 随机），长位数防重复。
        $firstOid = did($ids[0]);
        $firstOrder = $firstOid > 0 ? CashierRepository::order($firstOid) : null;
        $paymentNo = next_payment_no($firstOrder ? $firstOrder['flow_no'] : '');
        $payId = 0;
        $total = 0;
        foreach ($ids as $oidStr) {
            $oidNum = did($oidStr);
            if ($oidNum <= 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_fail('存在无效的开单标识，请刷新后重试');
            }
            $order = CashierRepository::order($oidNum);
            if (!$order) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_fail('开单不存在');
            }
            $items = CashierRepository::orderItems($order['id']);
            // 原子条件更新防并发重复缴费：仅 open 明细可转 paid（事务内按影响行数判定）
            $paidRows = CashierRepository::updateWhere(
                'order_items', array('status' => 'paid'),
                'order_id=? AND status=\'open\'', array($order['id'])
            );
            if ($paidRows === 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_fail('存在已缴费项目，请刷新后重试');
            }
            $orderAffected = CashierRepository::exec(
                "UPDATE orders SET status='paid', paid_at=? WHERE id=? AND status='open'",
                array(now_str(), $order['id'])
            );
            if ($orderAffected === 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_fail('该开单已缴费，请刷新后重试');
            }
            // 处置（医生直接执行类）：缴费即视为已执行
            if ($order['order_type'] === 'procedure') {
                foreach ($items as $it) {
                    if (!(int)$it['is_nurse']) {
                        CashierRepository::updateOrderItemStatus($it['id'], 'done', array('executed_by' => $order['doctor_name'], 'executed_at' => now_str()));
                    }
                }
            }
            $total += (float)$order['total_amount'];
            $payId = CashierRepository::createPayment(array(
                'visit_id' => $order['visit_id'], 'order_id' => $order['id'], 'patient_no' => $order['patient_no'], 'flow_no' => $order['flow_no'],
                'kind' => 'order', 'total' => (float)$order['total_amount'], 'item_count' => count($items),
                'cashier_id' => $u['id'], 'cashier_name' => $u['name'], 'payment_no' => $paymentNo, 'method' => $method,
            ));
        }
        $pdo->commit();
        json_ok(array('payment_id' => oid($payId), 'payment_no' => $paymentNo, 'total' => $total), '缴费成功');
        return;
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('缴费失败：' . $ex->getMessage());
        }
    }
}