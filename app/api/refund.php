<?php
/**
 * ============================================================
 * refund.php — 退费申请审批接口
 * ============================================================
 * 说明：已开始执行的项目（检验已登记、检查已登记、已发药、处置已执行、
 * 患者已就诊等）不可直接退费，须先发起退费申请：
 * 1. apply   收费员发起退费申请（填理由），系统自动确定审批人
 *            （开单医生 + 涉及的检验/影像/药房/护士站角色），
 *            并通过站内消息通知各审批人。
 * 2. detail  查询申请详情（患者信息 + 项目执行状态 + 各审批人意见）
 * 3. approve 审批人同意/拒绝（需为申请关联的审批人，且申请未完结）
 * 全部审批人同意后，收费员在缴费管理中执行退费。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/* ==================== 退费前置检测（收费员） ==================== */
if ($action === 'check') {
    // 检测缴费批次是否可直接退费：
    // 全部项目处于 paid（未开始执行）→ 可直接退费；
    // 存在 registered/done/dispensed 等已执行状态 → 需走退费申请审批流
    $paymentNo = trim((string)get('payment_no', ''));
    if ($paymentNo === '') json_fail('缺少缴费批次号');
    $pays = CoreRepository::q("SELECT * FROM payments WHERE payment_no=? AND kind='order' ORDER BY id ASC", array($paymentNo));
    if (!$pays) json_fail('未找到该缴费批次');
    $orderIds = array();
    foreach ($pays as $p) $orderIds[] = (int)$p['order_id'];
    $blocked = array();   // 存在已执行状态的项目
    $allPaid = true;
    foreach ($orderIds as $oid) {
        $o = CoreRepository::one('SELECT * FROM orders WHERE id=?', array($oid));
        if (!$o) continue;
        $its = CoreRepository::q('SELECT * FROM order_items WHERE order_id=?', array($oid));
        foreach ($its as $it) {
            if ($it['status'] === 'paid') continue;
            if ($it['status'] !== 'open' && $it['status'] !== 'refunded' && $it['status'] !== 'cancelled') {
                $allPaid = false;
                $blocked[] = array('name' => $it['item_name'], 'status' => $it['status'], 'executed_by' => $it['executed_by']);
            }
        }
    }
    // 是否已有进行中的退费申请
    $pendingReq = CoreRepository::one("SELECT id, status FROM refund_requests WHERE payment_no=? AND status='pending' ORDER BY id DESC LIMIT 1", array($paymentNo));
    $approvedReq = CoreRepository::one("SELECT id FROM refund_requests WHERE payment_no=? AND status='approved' ORDER BY id DESC LIMIT 1", array($paymentNo));
    json_ok(array(
        'all_paid' => $allPaid ? 1 : 0,
        'blocked' => $blocked,
        'pending_request_id' => $pendingReq ? oid($pendingReq['id']) : '',
        'approved' => $approvedReq ? 1 : 0,
    ));
    return;
}

/* ==================== 发起退费申请（收费员） ==================== */
if ($action === 'apply') {
    $paymentNo = trim((string)post('payment_no', ''));
    $reason = trim((string)post('reason', ''));
    if ($paymentNo === '') json_fail('缺少缴费批次号');
    // 校验该批次属于本人收费范围（收费员不限科室）
    $pays = CoreRepository::q("SELECT * FROM payments WHERE payment_no=? AND kind='order' ORDER BY id ASC", array($paymentNo));
    if (!$pays) json_fail('未找到该缴费批次');
    $visitId = (int)$pays[0]['visit_id'];
    $orderIds = array();
    foreach ($pays as $p) $orderIds[] = (int)$p['order_id'];
    $visitRow = get_visit_row($visitId);
    if (!$visitRow) json_fail('就诊记录不存在');
    $patient = $visitRow['patient'];
    // 判定审批人：开单医生 + 涉及执行的角色（检验/影像/药房/护士站）
    // 各取一个在职用户（角色用户若多个取首个）
    $approvers = array();   // [['role','user_id','user_name']...]
    $doctors = array();
    $needLab = $needImaging = $needPharmacy = $needNurse = false;
    $allPaid = true;
    foreach ($orderIds as $oid) {
        $o = CoreRepository::one('SELECT * FROM orders WHERE id=?', array($oid));
        if (!$o) continue;
        if ((int)$o['doctor_id'] > 0) $doctors[(int)$o['doctor_id']] = $o['doctor_name'];
        $its = CoreRepository::q('SELECT * FROM order_items WHERE order_id=?', array($oid));
        foreach ($its as $it) {
            if ($it['status'] !== 'paid' && $it['status'] !== 'open') $allPaid = false;
            if ($o['order_type'] === 'lab' && in_array($it['status'], array('registered', 'done'), true)) $needLab = true;
            if ($o['order_type'] === 'imaging' && in_array($it['status'], array('registered', 'done'), true)) $needImaging = true;
            if ($o['order_type'] === 'prescription' && $it['status'] === 'dispensed') $needPharmacy = true;
            if ($o['order_type'] === 'procedure' && in_array($it['status'], array('done', 'dispensing', 'dispensed'), true)) $needNurse = true;
        }
    }
    // 全部未执行（仅 paid）→ 无需审批，直接退费
    if ($allPaid) json_fail('该批次项目均未开始执行，可直接退费，无需申请审批');
    foreach ($doctors as $did => $dname) {
        $approvers[] = array('role' => 'doctor', 'user_id' => (int)$did, 'user_name' => $dname);
    }
    // 科室角色各取一个在职用户（开单医生已含则不重复角色，科室用户首条）
    $roleUser = function ($role) {
        return CoreRepository::one("SELECT id, name FROM users WHERE role=? AND status=1 ORDER BY id LIMIT 1", array($role));
    };
    if ($needLab) {
        $ru = $roleUser('lab');
        if ($ru) $approvers[] = array('role' => 'lab', 'user_id' => (int)$ru['id'], 'user_name' => $ru['name']);
    }
    if ($needImaging) {
        $ru = $roleUser('imaging');
        if ($ru) $approvers[] = array('role' => 'imaging', 'user_id' => (int)$ru['id'], 'user_name' => $ru['name']);
    }
    if ($needPharmacy) {
        $ru = $roleUser('pharmacy');
        if ($ru) $approvers[] = array('role' => 'pharmacy', 'user_id' => (int)$ru['id'], 'user_name' => $ru['name']);
    }
    if ($needNurse) {
        $ru = $roleUser('nurse');
        if ($ru) $approvers[] = array('role' => 'nurse', 'user_id' => (int)$ru['id'], 'user_name' => $ru['name']);
    }
    // 去重（同一角色+用户只保留一个）
    $seen = array();
    $approvers = array_values(array_filter($approvers, function ($a) use (&$seen) {
        $k = $a['role'] . ':' . $a['user_id'];
        if (isset($seen[$k])) return false;
        $seen[$k] = 1;
        return true;
    }));
    if (!$approvers) json_fail('未找到可审批的相关人员');
    // 事务：创建申请 + 审批人记录 + 站内消息
    $pdo = DatabaseManager::getMain();
    $pdo->beginTransaction();
    try {
        $reqId = CoreRepository::insert('INSERT INTO refund_requests(visit_id, patient_no, flow_no, payment_no, order_ids, reason, status, created_by, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            $visitId, $patient ? $patient['patient_no'] : $pays[0]['patient_no'], $pays[0]['flow_no'],
            $paymentNo, json_encode($orderIds), $reason, 'pending', (int)$u['id'], now_str(),
        ));
        foreach ($approvers as $a) {
            CoreRepository::insert('INSERT INTO refund_approvals(request_id, role, user_id, user_name, verdict, note, decided_at) VALUES(?,?,?,?,?,?,?)', array(
                $reqId, $a['role'], (int)$a['user_id'], $a['user_name'], 'pending', '', '',
            ));
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_fail('退费申请创建失败：' . $ex->getMessage());
    }
    // 站内消息通知各审批人（点击跳转审批页）
    $reqCode = oid($reqId);
    $link = '/refund/approve?id=' . $reqCode;
    foreach ($approvers as $a) {
        send_msg($a['role'], (int)$a['user_id'],
            '退费申请待审批：' . ($patient ? $patient['name'] : '患者') . '（' . ($pays[0]['flow_no']) . '）',
            '患者「' . ($patient ? $patient['name'] : '') . '」的缴费批次 ' . $paymentNo . ' 申请退费' .
            ($reason !== '' ? '，理由：' . $reason : '') . '，该批次存在已开始执行的项目，请核对执行进度后确认是否同意退费。',
            '', '', array('msg_type' => 'system', 'link_url' => $link, 'visit_id' => $visitId));
    }
    json_ok(array('request_id' => $reqCode, 'approvers' => count($approvers)),
        '退费申请已提交，已通知 ' . count($approvers) . ' 位相关人员审批');
    return;
}

/* ==================== 申请详情（审批页/收费员查看） ==================== */
if ($action === 'detail') {
    $reqId = did(get('id'));
    $req = CoreRepository::one('SELECT * FROM refund_requests WHERE id=?', array($reqId));
    if (!$req) json_fail('退费申请不存在');
    $approvals = CoreRepository::q('SELECT * FROM refund_approvals WHERE request_id=? ORDER BY id ASC', array($reqId));
    // 项目执行状态
    $orderIds = json_decode($req['order_ids'], true);
    $orders = array();
    foreach ($orderIds as $oid) {
        $o = CoreRepository::one('SELECT * FROM orders WHERE id=?', array($oid));
        if (!$o) continue;
        $items = CoreRepository::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($oid));
        $orders[] = array(
            'order_no' => $o['order_no'],
            'order_type' => $o['order_type'],
            'doctor_name' => $o['doctor_name'],
            'flow' => order_flow_steps($o, $items),
            'items' => array_map(function ($it) {
                return array('name' => $it['item_name'], 'quantity' => (int)$it['quantity'], 'price' => (float)$it['price'], 'status' => $it['status'], 'executed_by' => $it['executed_by']);
            }, $items),
        );
    }
    $visitRow = get_visit_row((int)$req['visit_id']);
    json_ok(array(
        'request' => array(
            'id' => oid($reqId), 'payment_no' => $req['payment_no'], 'reason' => $req['reason'],
            'status' => $req['status'], 'created_at' => $req['created_at'],
            'patient' => array('name' => $visitRow ? $visitRow['patient']['name'] : $req['patient_no'], 'patient_no' => $req['patient_no'], 'flow_no' => $req['flow_no'], 'visit_status' => $visitRow ? $visitRow['visit']['status'] : ''),
        ),
        'approvals' => array_map(function ($a) {
            return array('role' => $a['role'], 'user_name' => $a['user_name'], 'verdict' => $a['verdict'], 'note' => $a['note'], 'decided_at' => $a['decided_at']);
        }, $approvals),
        'orders' => $orders,
    ));
    return;
}

/* ==================== 审批（同意/拒绝） ==================== */
if ($action === 'approve') {
    $reqId = did(post('id'));
    $verdict = post('verdict', '');   // approve / reject
    $note = trim((string)post('note', ''));
    if (!in_array($verdict, array('approve', 'reject'), true)) json_fail('审批指令无效');
    $req = CoreRepository::one('SELECT * FROM refund_requests WHERE id=?', array($reqId));
    if (!$req) json_fail('退费申请不存在');
    if ($req['status'] !== 'pending') json_fail('该申请已完结，不可再审批');
    // 仅申请关联的审批人可审批（管理员兜底）
    $myApproval = CoreRepository::one('SELECT * FROM refund_approvals WHERE request_id=? AND user_id=?', array($reqId, (int)$u['id']));
    if (!$myApproval && $u['role'] !== 'admin') json_fail('您不是该申请的审批人');
    // 记录审批
    CoreRepository::exec("UPDATE refund_approvals SET verdict=?, note=?, decided_at=? WHERE request_id=? AND user_id=?",
        array($verdict, $note, now_str(), $reqId, (int)$u['id']));
    // 汇总：全部同意 → approved；任一拒绝 → rejected
    $all = CoreRepository::q('SELECT verdict FROM refund_approvals WHERE request_id=?', array($reqId));
    $allApprove = true;
    $anyReject = false;
    foreach ($all as $a) {
        if ($a['verdict'] !== 'approve') $allApprove = false;
        if ($a['verdict'] === 'reject') $anyReject = true;
    }
    $newStatus = $anyReject ? 'rejected' : ($allApprove ? 'approved' : 'pending');
    if ($newStatus !== 'pending') {
        CoreRepository::exec("UPDATE refund_requests SET status=? WHERE id=?", array($newStatus, $reqId));
        // 通知收费处
        send_msg('cashier', 0,
            '退费申请' . ($newStatus === 'approved' ? '已全部同意' : '被拒绝'),
            '缴费批次 ' . $req['payment_no'] . ' 的退费申请' . ($newStatus === 'approved' ? '已获全部相关人员同意，可在缴费管理执行退费。' : '被拒绝，未获同意，不可退费。'),
            '', '', array('msg_type' => 'system'));
    }
    json_ok(array('status' => $newStatus), $verdict === 'approve' ? '已同意退费' : '已拒绝退费');
    return;
}

json_fail('未知操作');
