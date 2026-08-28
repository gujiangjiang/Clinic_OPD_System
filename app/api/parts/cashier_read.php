<?php
/**
 * ============================================================
 * parts/cashier_read.php — 收费处：读取
 * ============================================================
 * cashier.php 按功能拆分的一部分，读取类动作。
 * ============================================================ */

function cashier_part_read($action) {
    $u = Auth::user();

    if ($action === 'home_stats') {
        $today = date('Y-m-d');
        $regToday = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=?", array($today));
        $regFeeToday = (float)DB::val('order', "SELECT COALESCE(SUM(total),0) FROM payments WHERE kind='visit' AND date(created_at)=?", array($today));
        $paidToday = (float)DB::val('order', "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today));
        $refundToday = (float)DB::val('order', "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='refunded' AND date(refunded_at)=?", array($today));
        $waiting = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE status='paid' AND date(register_time)=?", array($today));
        $labels = array(); $series = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $series[] = (float)DB::val('order', "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($day));
        }
        json_ok(array(
            'kpi' => array('reg_today' => $regToday, 'reg_fee' => round($regFeeToday, 2), 'paid_today' => round($paidToday, 2),
                'refund_today' => round($refundToday, 2), 'waiting' => $waiting),
            'trend' => array('labels' => $labels, 'data' => $series),
        ));
        return;
    }

    if ($action === 'depts') {
        $idCard = get('id_card', '');
        $showAll = get('all') === '1';
        $ws = work_session_now();
        $bookable = in_array($ws, array('am', 'pm'), true);
        $session = $ws === 'pm' ? 'pm' : 'am'; // 非可挂时段展示上午号量供参考
        $depts = DB::q('dept', "SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY type DESC, sort, id");
        $list = array();
        foreach ($depts as $d) {
            // 无身份证仅显示急诊科室（all=1 时不过滤，供号源总览展示）
            if (!$showAll && $idCard === '' && $d['type'] !== 'emergency') continue;
            $used = ($d['type'] === 'clinic') ? dept_used_count($d['id'], $session) : 0;
            $quota = ($d['type'] === 'clinic') ? ($session === 'am' ? (int)$d['am_quota'] : (int)$d['pm_quota']) : 0;
            $extra = 0;
            if ($idCard !== '' && $used >= $quota && $quota > 0) {
                $extra = (int)DB::val('dept', 'SELECT COUNT(*) FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0', array($d['id'], today_str(), $idCard));
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
        $rows = DB::q('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE date(r.register_time)=? ORDER BY r.visit_seq", array($date));
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 条挂号记录（含退费/取消）</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">🗓️</div>当日暂无挂号记录</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>就诊序号</th><th>患者</th><th>患者ID</th><th>流水号</th><th>首次挂号科室</th><th>当前科室</th>' .
                '<th>费用</th><th>状态</th><th>挂号时间</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $statusBadge = '<span class="badge ' . ($r['status'] === 'paid' ? 'badge-primary' : ($r['status'] === 'finished' ? 'badge-success' : ($r['status'] === 'refunded' ? 'badge-gray' : ($r['status'] === 'cancelled' ? 'badge-gray' : 'badge-warning')))) . '">' . e(visit_status_name($r['status'])) . '</span>';
                $html .= '<tr>' .
                    '<td class="fw-700">' . e($r['first_dept_name']) . ' ' . str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '号</td>' .
                    '<td><a href="javascript:void(0)" onclick="patientEdit(\'' . e($r['patient_no']) . '\')">' . e($r['pname']) . '</a></td>' .
                    '<td>' . e($r['patient_no']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($r['first_dept_name']) . '</td>' .
                    '<td>' . e($r['current_dept_name']) . '</td>' .
                    '<td>¥' . money($r['fee']) . '</td>' .
                    '<td>' . $statusBadge . '</td>' .
                    '<td class="fs-12">' . e(substr($r['register_time'], 5, 11)) . '</td>' .
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
        $list = array();
        // 按身份证查患者 → 该患者全部就诊
        $patient = DB::one('patient', 'SELECT * FROM patients WHERE id_card=?', array($kw));
        if ($patient) {
            $visits = DB::q('patient', 'SELECT * FROM registrations WHERE patient_no=? ORDER BY register_time DESC, id DESC', array($patient['patient_no']));
            foreach ($visits as $v) {
                $v['id'] = oid($v['id']);   // 混淆串：前端透传，后端解码
                $list[] = array('visit' => $v, 'patient' => $patient);
            }
        } else {
            // 按患者ID / 流水号直接查
            $v = DB::one('patient', 'SELECT * FROM registrations WHERE patient_no=? OR flow_no=? ORDER BY register_time DESC, id DESC LIMIT 1', array($kw, $kw));
            if ($v) {
                $v['id'] = oid($v['id']);   // 混淆串
                $p = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($v['patient_no']));
                $list[] = array('visit' => $v, 'patient' => $p);
            }
        }
        json_ok(array('list' => $list));
        return;
    }

    if ($action === 'visit_detail') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $html = '<div class="card" style="padding:14px;margin-bottom:12px" data-vid="' . e(oid($visitId)) . '">' .
            '<div class="flex-between"><div><span class="fw-700 fs-16">' . e($row['patient']['name']) . '</span> ' .
            '<span class="text-muted fs-13">' . e($row['patient']['gender']) . ' / ' . age_format($row['patient']['birth_date'], $visit['register_time']) . '</span></div>' .
            '<span class="badge badge-primary">' . e($visit['flow_no']) . '</span></div>' .
            '<div class="fs-13 text-muted mt-4">患者ID ' . e($visit['patient_no']) . ' ｜ 首次科室 ' . e($visit['first_dept_name']) .
            ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ 挂号 ' . e(substr($visit['register_time'], 0, 16)) . '</div></div>';

        // 已缴费（含挂号费与项目缴费）
        $pays = DB::q('order', 'SELECT * FROM payments WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $html .= '<div class="fs-14 fw-700 mb-8">已缴费</div>';
        if (!$pays) {
            $html .= '<div class="fs-13 text-muted mb-12">暂无缴费记录</div>';
        }
        foreach ($pays as $p) {
            $html .= '<div class="flex-between" style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">' .
                '<span class="fs-13">' . e($p['kind'] === 'visit' ? '挂号费' : '项目缴费') . ' ｜ ' . e(substr($p['created_at'], 0, 16)) . ' ｜ ' . e($p['cashier_name']) . '</span>' .
                '<span class="fs-13 fw-600">¥' . money($p['total']) . '</span></div>';
        }

        // 待缴费开单（分组显示开单医生、开单时间）
        $orders = DB::q('order', "SELECT * FROM orders WHERE visit_id=? AND status<>'refunded' AND status<>'cancelled' ORDER BY id", array($visitId));
        $html .= '<div class="fs-14 fw-700 mb-8 mt-16">待缴费 / 可退费项目</div>';
        $html .= '<div class="flex gap-8 mb-8" id="batchBar" style="align-items:center">' .
            '<label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="batchAll" onchange="toggleAll()"> 全选</label>' .
            '<button class="btn btn-success btn-sm" onclick="batchPay()">批量缴费（已选 <span id="batchCount">0</span>）</button>' .
            '</div>';
        if (!$orders) {
            $html .= '<div class="fs-13 text-muted">暂无待缴费项目</div>';
        }
        $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
        foreach ($orders as $o) {
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $agg = order_agg_status($o['order_type'], $items);
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">';
            $html .= '<div class="flex-between">' .
                '<span class="fs-13 fw-600">' . (isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : '') . ' ' . e($o['order_no']) .
                ' ｜ 开单医生 ' . e($o['doctor_name']) . ' ｜ ' . e(substr($o['created_at'], 0, 16)) . '</span>' .
                '<span class="fs-13 fw-600">¥' . money($o['total_amount']) . '</span></div>';
            $itemLines = '';
            foreach ($items as $it) {
                $itemLines .= '<div class="fs-12 text-muted">· ' . e($it['item_name']) . ' ×' . (int)$it['quantity'] .
                    ' ￥' . money($it['price'] * $it['quantity']) . '（' . e(item_status_name($it['status'])) . '）</div>';
            }
            $html .= $itemLines;
            // 操作：待缴费 → 缴费按钮；已缴费 → 退费按钮（仅未使用项目）
            if ($agg === 'open') {
                $html .= '<div class="mt-8 flex gap-8">' .
                    '<label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" class="batchPay" value="' . e(oid($o['id'])) . '" onchange="updateBatchCount()"> 选择</label>' .
                    '<button class="btn btn-success btn-sm" onclick="payOrder(\'' . e(oid($o['id'])) . '\')">缴费</button></div>';
            } elseif ($agg === 'paid') {
                $html .= '<div class="mt-8"><button class="btn btn-outline btn-sm" onclick="refundOrder(\'' . e(oid($o['id'])) . '\')">申请退费</button></div>';
            } elseif ($agg === 'refunded') {
                $html .= '<div class="mt-8"><span class="badge badge-gray">已退费</span></div>';
            } else {
                $html .= '<div class="mt-8"><span class="fs-12 text-muted">已进入执行流程，不可退费</span></div>';
            }
            $html .= '</div>';
        }
        json_ok(array('html' => $html));
        return;
    }

    if ($action === 'pay_orders') {
        $ids = json_decode(post('order_ids', '[]'), true);
        if (!is_array($ids) || !$ids) json_fail('请选择要缴费的项目');
        $payId = 0;
        $total = 0;
        foreach ($ids as $oidStr) {
            $oidNum = did($oidStr);
            if ($oidNum <= 0) json_fail('存在无效的开单标识，请刷新后重试');
            $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($oidNum));
            if (!$order) json_fail('开单不存在');
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=?', array($order['id']));
            foreach ($items as $it) {
                if ($it['status'] !== 'open') json_fail('存在已缴费项目，请刷新后重试');
            }
            // 缴费
            DB::exec('order', "UPDATE order_items SET status='paid' WHERE order_id=?", array($order['id']));
            DB::exec('order', "UPDATE orders SET status='paid', paid_at=? WHERE id=?", array(now_str(), $order['id']));
            // 处置（医生直接执行类）：缴费即视为已执行
            if ($order['order_type'] === 'procedure') {
                foreach ($items as $it) {
                    if (!(int)$it['need_nurse']) {
                        DB::exec('order', "UPDATE order_items SET status='done', executed_by=?, executed_at=? WHERE id=?", array($order['doctor_name'], now_str(), $it['id']));
                    }
                }
            }
            $total += (float)$order['total_amount'];
            $payId = DB::insert('order', 'INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                $order['visit_id'], $order['id'], $order['patient_no'], $order['flow_no'], 'order',
                (float)$order['total_amount'], count($items), $u['id'], $u['name'], now_str(),
            ));
        }
        json_ok(array('payment_id' => oid($payId), 'total' => $total), '缴费成功');
        return;
    }
}
