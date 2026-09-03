<?php
/**
 * ============================================================
 * helpers.d/visit.php — 就诊记录 / 患者档案 / 状态名
 * ============================================================
 * 说明：就诊记录联查、诊断证明快照回退、患者就诊搜索、挂号/开单
 * 明细/订单聚合状态中文名。由 helpers.php 统一加载，拆分后引用
 * 方式不变。
 * ============================================================ */

/* ============================================================
 * 业务辅助：就诊记录/患者档案联查、状态中文名
 * ============================================================ */

/** 按就诊ID联查 挂号记录 + 患者档案 */
function get_visit_row($visitId) {
    $v = DB::one('SELECT * FROM registrations WHERE id=?', array((int)$visitId));
    if (!$v) {
        return null;
    }
    $p = DB::one('SELECT * FROM patients WHERE patient_no=?', array($v['patient_no']));
    return array('visit' => $v, 'patient' => $p);
}

/**
 * 诊断证明固化快照回退：证书存有开具时的病历摘要则原样使用——
 * 补打/展示内容与开具时完全一致，不随后续续写漂移。
 * @param array|null $record 病历数据（可为 null）
 * @param array      $cert   诊断证明行
 * @return array 合并快照后的病历数据
 */
function cert_fallback_snapshot($record, $cert) {
    $record = is_array($record) ? $record : array();
    if ((isset($cert['chief_complaint']) && $cert['chief_complaint'] !== '') ||
        (isset($cert['present_illness']) && $cert['present_illness'] !== '') ||
        (isset($cert['preliminary_diagnosis']) && $cert['preliminary_diagnosis'] !== '')) {
        $record['chief_complaint'] = $cert['chief_complaint'];
        $record['present_illness'] = $cert['present_illness'];
        $record['preliminary_diagnosis'] = $cert['preliminary_diagnosis'];
    }
    return $record;
}

/**
 * 患者就诊搜索（统一规则，收费处/护士站共用）：
 * 1. 按身份证/患者编号查患者 → 该患者全部就诊；
 * 2. 未命中则按患者编号/流水号直接查单条就诊。
 * @return array [ ['visit'=>行,'patient'=>行], ... ]（visit.id 已混淆）
 */
function search_visit_records($kw) {
    $kw = trim((string)$kw);
    if ($kw === '') return array();
    $list = array();
    $patient = PatientRepository::byCardOrNo($kw);
    if ($patient) {
        $visits = CashierRepository::visitsOfPatient($patient['patient_no']);
        foreach ($visits as $v) {
            $v['id'] = oid($v['id']);
            $list[] = array('visit' => $v, 'patient' => $patient);
        }
    } else {
        $v = CashierRepository::one('SELECT * FROM registrations WHERE patient_no=? OR flow_no=? ORDER BY registered_at DESC, id DESC LIMIT 1', array($kw, $kw));
        if ($v) {
            $v['id'] = oid($v['id']);
            $list[] = array('visit' => $v, 'patient' => PatientRepository::byPatientNo($v['patient_no']));
        }
    }
    return $list;
}

/** 挂号状态中文名 */
function visit_status_name($s) {
    $map = array(
        'pending'   => '待缴费',
        'paid'      => '待就诊',
        'visiting'  => '就诊中',
        'finished'  => '就诊完毕',
        'refunded'  => '已退费',
        'cancelled' => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 开单明细流程状态中文名 */
function item_status_name($s) {
    $map = array(
        'open'       => '待缴费',
        'paid'       => '已缴费',
        'registered' => '已登记',
        'executing'  => '执行中',
        'done'       => '已完成',
        'dispensing' => '发药中',
        'dispensed'  => '已发药',
        'refunded'   => '已退费',
        'cancelled'  => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 计算订单聚合状态（open/paid/registered/in_progress/done/dispensed/refunded/cancelled/rejected） */
function order_agg_status($orderType, $items) {
    $sts = array();
    foreach ($items as $it) $sts[] = $it['status'];
    if (!$sts) return 'open';
    if (count(array_unique($sts)) === 1) {
        $only = $sts[0];
        if ($only === 'refunded') return 'refunded';
        if ($only === 'cancelled') return 'cancelled';
        if ($only === 'rejected') return 'rejected';
        if ($only === 'dispensed') return 'dispensed';
        if ($only === 'done') return 'done';
    }
    if (in_array('open', $sts, true)) return 'open';
    if (in_array('paid', $sts, true)) return 'paid';
    if ($orderType === 'prescription') {
        // 处方审方通过后：非护士站药品 dispensed、护士站药品 dispensing（护士站执行中）——
        // 只要无 open/paid 残留即视为已发药完成（含全护士站执行场景）
        if (!in_array('open', $sts, true) && !in_array('paid', $sts, true) &&
            (in_array('dispensed', $sts, true) || in_array('dispensing', $sts, true))) {
            return 'dispensed';
        }
        return 'paid';
    }
    if (in_array('executing', $sts, true)) return 'in_progress';
    if (in_array('registered', $sts, true)) return 'registered';
    if (in_array('done', $sts, true)) return 'done';
    return 'open';
}

/**
 * 处方是否已由药房审方发药（护士站执行药品医嘱的前置条件）。
 * 判定依据：orders.status='dispensed' 且 dispensed_at 非空。
 * @param int $orderId 处方单 id
 * @return bool
 */
function rx_dispensed($orderId) {
    $o = OrderRepository::one('SELECT status, dispensed_at FROM orders WHERE id=?', array((int)$orderId));
    return $o && $o['status'] === 'dispensed' && !empty($o['dispensed_at']);
}

/**
 * 订单执行流程节点（操作人+时间）：开单→缴费→登记/执行→完成，
 * 供开单详情/病历流程/缴费凭条详情展示（doctor/order/cashier 共用）。
 * · 开单 = 开单医生 / 创建时间
 * · 缴费 = 收费员 / 缴费时间（payments 表，无则未缴费）
 * · 登记 = 首个执行操作人 / 时间（lab/imaging 登记环节；处方无此步）
 * · 发药(完成) = 执行操作人 / 时间（发药或执行完成时写入）
 * 返回 [{label, operator, time, done, rejected?}]
 */
function order_flow_steps($o, $items) {
    $flow = array();
    $flow[] = array('label' => '开单', 'operator' => (string)$o['doctor_name'], 'time' => (string)$o['created_at'], 'done' => 1);
    // 缴费：payments 表（order_id 关联，任取一条）
    $pay = OrderRepository::one('SELECT cashier_name, created_at FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1', array($o['id']));
    $payDone = !empty($o['paid_at']) || $pay;
    $flow[] = array('label' => '缴费',
        'operator' => $pay ? (string)$pay['cashier_name'] : '',
        'time' => !empty($o['paid_at']) ? (string)$o['paid_at'] : ($pay ? (string)$pay['created_at'] : ''),
        'done' => $payDone ? 1 : 0);
    $reg = null; $disp = null;
    foreach ($items as $it) {
        if (!empty($it['executed_by'])) {
            if (!$reg) $reg = array('operator' => (string)$it['executed_by'], 'time' => (string)$it['executed_at']);
            $disp = array('operator' => (string)$it['executed_by'], 'time' => (string)$it['executed_at']);
        }
    }
    if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
        $flow[] = array('label' => '登记',
            'operator' => $reg ? $reg['operator'] : '',
            'time' => $reg ? $reg['time'] : '',
            'done' => $reg ? 1 : 0);
        $flow[] = array('label' => '报告完成',
            'operator' => $disp ? $disp['operator'] : '',
            'time' => $disp ? $disp['time'] : '',
            'done' => ($disp || in_array($o['status'], array('done'), true)) ? 1 : 0);
    } elseif ($o['order_type'] === 'prescription') {
        // 处方进度按整单审方流转：药房处理（审方通过/驳回）→ 发药完成
        // 通过：orders.status='dispensed' + dispensed_at；驳回：status='rejected'
        $rxDisp = !empty($o['dispensed_at']) ? $o['dispensed_at'] : ($disp ? $disp['time'] : '');
        $rxDone = $o['status'] === 'dispensed' || $disp;
        $rxRejected = $o['status'] === 'rejected';
        $flow[] = array('label' => '审方通过',
            'operator' => $rxDone ? ($reg ? $reg['operator'] : ($o['done_by'] ? $o['done_by'] : '')) : '',
            'time' => $rxDisp,
            'done' => $rxDone ? 1 : 0,
            'rejected' => $rxRejected ? 1 : 0);
        $flow[] = array('label' => '发药完成',
            'operator' => $rxDone ? ($o['done_by'] ? $o['done_by'] : ($disp ? $disp['operator'] : '')) : '',
            'time' => $rxDisp,
            'done' => $rxDone ? 1 : 0);
    } else {
        $flow[] = array('label' => '执行完成',
            'operator' => $disp ? $disp['operator'] : '',
            'time' => $disp ? $disp['time'] : '',
            'done' => $disp ? 1 : 0);
    }
    return $flow;
}