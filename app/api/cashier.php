<?php
/**
 * ============================================================
 * cashier.php — 挂号收费处接口
 * ============================================================
 * 说明：
 * 1. 挂号：身份证18位校验 + 自动计算出生日期/年龄/性别并锁定；
 *    有身份证可选费用类别并显示门诊号源，无身份证仅急诊且自费
 * 2. 编号规则：患者唯一ID=年月日8位+当日序号2位（与身份证绑定）；
 *    门诊流水号=年月日6位+当日序号4位（每次就诊新生成）；
 *    门诊就诊序号=每科室每日3位独立递增（001起，永久唯一不回收）
 * 3. 同一患者（ID/身份证）当日同【首次挂号科室】仅可挂一次，
 *    退费后可重挂但就诊序号递增；转科不影响首次科室判定
 * 4. 号源满时校验医生加号（仅限该患者本人使用）
 * 5. 缴费（模拟）/批量缴费/退费（仅限未使用的项目）
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/* ==================== 编号规则函数 ==================== */

/** 患者唯一ID：年月日 + 当日序号2位（25031101） */
function next_patient_no() {
    $ymd = date('ymd');
    $n = (int)DB::val('patient', "SELECT COUNT(*) FROM patients WHERE substr(patient_no,1,6)=?", array($ymd));
    return $ymd . str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT);
}

/** 门诊流水号：年月日 + 当日序号4位（2503110001） */
function next_flow_no() {
    $ymd = date('ymd');
    $n = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE substr(flow_no,1,6)=?", array($ymd));
    return $ymd . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** 门诊就诊序号：每科室每日3位独立递增（含退费/取消记录，序号不回收） */
function next_visit_seq($deptId) {
    $n = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE first_dept_id=? AND date(register_time)=?", array($deptId, today_str()));
    return $n + 1;
}

/** 当日某科室某时段已用号源数 */
function dept_used_count($deptId, $session) {
    return (int)DB::val('patient', "SELECT COUNT(*) FROM registrations
        WHERE first_dept_id=? AND date(register_time)=? AND session=? AND status IN ('pending','paid','visiting','finished')",
        array($deptId, today_str(), $session));
}

switch ($action) {

    /* ==================== 可挂科室及号源 ==================== */
    case 'depts':
        $idCard = get('id_card', '');
        $session = (int)date('H') < 12 ? 'am' : 'pm';
        $depts = DB::q('dept', "SELECT * FROM departments WHERE status=1 ORDER BY type DESC, sort, id");
        $list = array();
        foreach ($depts as $d) {
            // 无身份证仅显示急诊科室
            if ($idCard === '' && $d['type'] !== 'emergency') continue;
            $used = ($d['type'] === 'clinic') ? dept_used_count($d['id'], $session) : 0;
            $quota = ($d['type'] === 'clinic') ? ($session === 'am' ? (int)$d['am_quota'] : (int)$d['pm_quota']) : 0;
            $extra = 0;
            if ($idCard !== '' && $used >= $quota && $quota > 0) {
                $extra = (int)DB::val('dept', 'SELECT COUNT(*) FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0', array($d['id'], today_str(), $idCard));
            }
            $list[] = array(
                'id' => (int)$d['id'],
                'name' => $d['name'],
                'type' => $d['type'],
                'fee' => (float)$d['fee'],
                'quota' => $quota,
                'used' => $used,
                'remaining' => ($d['type'] === 'clinic') ? max(0, $quota - $used) + $extra : -1,
                'full' => ($d['type'] === 'clinic') ? ($used >= $quota && $extra === 0) : false,
            );
        }
        json_ok(array('list' => $list, 'session' => $session));
        break;

    /* ==================== 挂号 ==================== */
    case 'register':
        $idCard = strtoupper(post('id_card'));
        $name = post('name');
        $deptId = (int)post('dept_id');
        $feeType = post('fee_type', '自费');
        $hasId = ($idCard !== '');

        // ===== 基础校验 =====
        if ($name === '') json_fail('请填写患者姓名');
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
            DB::insert('patient', 'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $patientNo, $idCard, $name, $gender, $birth, $age, post('ethnicity'), post('marital'), post('occupation'), post('work_unit'), post('address'), post('phone'), now_str(),
            ));
        }

        // ===== 同一患者当日同【首次挂号科室】仅可挂一次 =====
        $dup = DB::one('patient', "SELECT id FROM registrations
            WHERE patient_no=? AND first_dept_id=? AND date(register_time)=? AND status IN ('pending','paid','visiting','finished')",
            array($patientNo, $deptId, today_str()));
        if ($dup) {
            json_fail('该患者今日已在【' . $dept['name'] . '】挂号，不能重复挂号（退费后可以重新挂号）');
        }

        // ===== 号源控制（门诊科室：上午/下午号源） =====
        $isExtra = 0;
        $session = (int)date('H') < 12 ? 'am' : 'pm';
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
            'visit_id' => $visitId,
            'patient_no' => $patientNo,
            'flow_no' => $flowNo,
            'visit_seq' => $visitSeq,
            'dept_name' => $dept['name'],
            'fee' => (float)$dept['fee'],
            'id_card' => $idCard,
            'is_extra' => $isExtra,
        ), '挂号成功，请完成缴费');
        break;

    /* ==================== 挂号费缴费（模拟） ==================== */
    case 'pay_visit':
        $visitId = (int)post('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] !== 'pending') json_fail('当前状态不可缴费');
        DB::exec('patient', 'UPDATE registrations SET status=?, payment_time=? WHERE id=?', array('paid', now_str(), $visitId));
        $payId = DB::insert('order', 'INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at) VALUES(?,0,?,?,?,?,1,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], 'visit', (float)$visit['fee'], $u['id'], $u['name'], now_str(),
        ));
        json_ok(array('payment_id' => $payId), '缴费成功');
        break;

    /* ==================== 挂号管理（按天查询，含退费/取消） ==================== */
    case 'reg_list':
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
                    '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=receipt&visit_id=' . (int)$r['id'] . '\',null)">补打凭条</button>' .
                    (in_array($r['status'], array('pending', 'paid'), true) ?
                        '<button class="btn btn-outline btn-sm" onclick="cancelVisit(' . (int)$r['id'] . ',\'' . e($r['status']) . '\')">' . ($r['status'] === 'paid' ? '退费' : '取消') . '</button>' : '') .
                    '</div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 退费/取消挂号 ==================== */
    case 'cancel_visit':
        $visitId = (int)post('visit_id');
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
        break;

    /* ==================== 缴费与退费：就诊查询 ==================== */
    case 'visit_search':
        $kw = trim(get('kw', ''));
        if ($kw === '') json_ok(array('list' => array()));
        $list = array();
        // 按身份证查患者 → 该患者全部就诊
        $patient = DB::one('patient', 'SELECT * FROM patients WHERE id_card=?', array($kw));
        if ($patient) {
            $visits = DB::q('patient', 'SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patient['patient_no']));
            foreach ($visits as $v) {
                $list[] = array('visit' => $v, 'patient' => $patient);
            }
        } else {
            // 按患者ID / 流水号直接查
            $v = DB::one('patient', 'SELECT * FROM registrations WHERE patient_no=? OR flow_no=? ORDER BY id DESC LIMIT 1', array($kw, $kw));
            if ($v) {
                $p = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($v['patient_no']));
                $list[] = array('visit' => $v, 'patient' => $p);
            }
        }
        json_ok(array('list' => $list));
        break;

    /* ==================== 就诊缴费详情（HTML，分组显示已缴/待缴） ==================== */
    case 'visit_detail':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $html = '<div class="card" style="padding:14px;margin-bottom:12px" data-vid="' . (int)$visitId . '">' .
            '<div class="flex-between"><div><span class="fw-700 fs-16">' . e($row['patient']['name']) . '</span> ' .
            '<span class="text-muted fs-13">' . e($row['patient']['gender']) . ' / ' . (int)$row['patient']['age'] . '岁</span></div>' .
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
                    '<label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" class="batchPay" value="' . (int)$o['id'] . '" onchange="updateBatchCount()"> 选择</label>' .
                    '<button class="btn btn-success btn-sm" onclick="payOrder(' . (int)$o['id'] . ')">缴费</button></div>';
            } elseif ($agg === 'paid') {
                $html .= '<div class="mt-8"><button class="btn btn-outline btn-sm" onclick="refundOrder(' . (int)$o['id'] . ')">申请退费</button></div>';
            } elseif ($agg === 'refunded') {
                $html .= '<div class="mt-8"><span class="badge badge-gray">已退费</span></div>';
            } else {
                $html .= '<div class="mt-8"><span class="fs-12 text-muted">已进入执行流程，不可退费</span></div>';
            }
            $html .= '</div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 订单缴费（单项目/批量） ==================== */
    case 'pay_orders':
        $ids = json_decode(post('order_ids', '[]'), true);
        if (!is_array($ids) || !$ids) json_fail('请选择要缴费的项目');
        $payId = 0;
        $total = 0;
        foreach ($ids as $oid) {
            $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array((int)$oid));
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
        json_ok(array('payment_id' => $payId, 'total' => $total), '缴费成功');
        break;

    /* ==================== 订单退费（仅限未使用的项目） ==================== */
    case 'refund_order':
        $orderId = (int)post('order_id');
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
        break;

    default:
        json_fail('未知操作');
}
