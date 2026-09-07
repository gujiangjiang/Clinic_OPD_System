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
 * 将患者档案字段合并到就诊行（打印模板使用）。
 * 说明：打印页此前 5 处各自手写 name/gender/age/birth_date 五行赋值，
 * 统一收敛到本函数。返回合并后的 $visit（原数组就地追加字段）。
 * @param array $visit 就诊行
 * @param array|null $patient 患者行（可为 null）
 * @return array
 */
function decorate_visit_patient($visit, $patient) {
    if (is_array($patient)) {
        foreach (array('name', 'gender', 'age', 'birth_date') as $k) {
            if (isset($patient[$k])) $visit[$k] = $patient[$k];
        }
    }
    return $visit;
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
 * 单项执行流程节点（精简，无操作人）：开单→缴费→登记/审方/执行→完成。
 * 用于按「明细」独立展示进度（缴费凭条详情逐项生成：同一凭条内不同医生开单、
 * 不同项目执行进度各不相同，以 item 自身状态为准逐项判定，不整单共用）。
 * 已退费：仅退费项目追加「已退费」节点（缴费下方），后续执行节点照常显示——
 * 已执行的项目经站内消息确认退费后，执行记录仍保留（按 executed_by/result 痕迹判定）。
 * @param array $it         order_items 行
 * @param array $order      orders 行（含 order_type）
 * @param bool  $isRefunded 整单是否已退费
 * @return array [{label, done, refunded?, rejected?}]
 */
function item_flow_steps($it, $order, $isRefunded) {
    $flow = array();
    $flow[] = array('label' => '开单', 'done' => 1);
    $st = (string)$it['status'];
    $refunded = ($isRefunded || $st === 'refunded');
    // 缴费：无论是否退费均保留缴费记录
    $flow[] = array('label' => '缴费', 'done' => 1);
    if ($refunded) {
        $flow[] = array('label' => '已退费', 'done' => 0, 'refunded' => 1);
    }
    // 执行痕迹：退费后 status=refunded，须按 executed_by/report 判断是否已执行
    $executed = !empty($it['executed_by']) || !empty($it['executed_at']);
    // 报告已出：order_items.result_id 关联 results 行（有结果即已出具/登记）
    $hasReport = !empty($it['result_id']) || $st === 'done';
    if ($order['order_type'] === 'lab' || $order['order_type'] === 'imaging') {
        // 检验/检查：登记 + 报告完成（两者进度独立，登记完成未必出报告）
        $regDone = $executed || in_array($st, array('registered', 'done'), true);
        $repDone = $hasReport || $st === 'done';
        $flow[] = array('label' => '登记', 'done' => $regDone ? 1 : 0);
        $flow[] = array('label' => '报告完成', 'done' => $repDone ? 1 : 0);
    } elseif ($order['order_type'] === 'prescription') {
        // 处方：审方通过（dispensed/dispensing）+ 发药完成（dispensed）
        $rxDone = $executed || in_array($st, array('dispensed', 'dispensing'), true);
        $dispDone = $st === 'dispensed';
        $flow[] = array('label' => '审方通过', 'done' => $rxDone ? 1 : 0, 'rejected' => $st === 'rejected' ? 1 : 0);
        $flow[] = array('label' => '发药完成', 'done' => $dispDone ? 1 : 0);
    } else {
        // 处置：执行完成（护士站执行 done）
        $flow[] = array('label' => '执行完成', 'done' => ($executed || $st === 'done') ? 1 : 0);
    }
    return $flow;
}

/**
 * 订单执行流程节点（操作人+时间）：开单→缴费→登记/执行→完成，
 * 供开单详情/病历流程/缴费凭条详情展示（doctor/order/cashier 共用）。
 * · 开单 = 开单医生 / 创建时间
 * · 缴费 = 收费员 / 缴费时间（payments 表，无则未缴费）
 * · 登记 = 首个执行操作人 / 时间（lab/imaging 登记环节；处方无此步）
 * · 发药(完成) = 执行操作人 / 时间（发药或执行完成时写入）
 * 退费后：保留「缴费」记录，缴费下方追加「已退费」节点（refunded=1，红色 ✕）；
 * 后续执行节点（登记/报告/发药/执行完成）照常显示——已执行的项目经站内消息确认
 * 退费后，执行记录仍保留（按实际执行状态判定 done），流程含缴费与退费完整记录。
 * 返回 [{label, operator, time, done, rejected?, refunded?}]
 */
function order_flow_steps($o, $items) {
    $flow = array();
    $refunded = $o['status'] === 'refunded';
    $flow[] = array('label' => '开单', 'operator' => (string)$o['doctor_name'], 'time' => (string)$o['created_at'], 'done' => 1);
    // 缴费：payments 表（order_id 关联，任取一条）——退费单也保留缴费记录
    $pay = OrderRepository::one('SELECT cashier_name, created_at FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1', array($o['id']));
    $payDone = !empty($o['paid_at']) || $pay;
    $flow[] = array('label' => '缴费',
        'operator' => $pay ? (string)$pay['cashier_name'] : '',
        'time' => !empty($o['paid_at']) ? (string)$o['paid_at'] : ($pay ? (string)$pay['created_at'] : ''),
        'done' => $payDone ? 1 : 0);
    // 已退费：仅在退费单追加（缴费下方），默认不显示；后续执行节点照常显示——
    // 已执行的项目经站内消息确认退费后，执行记录仍保留（按实际执行状态判定）
    if ($refunded) {
        $ref = OrderRepository::one('SELECT cashier_name, created_at, reason FROM refunds WHERE order_id=? ORDER BY id DESC LIMIT 1', array($o['id']));
        $flow[] = array('label' => '已退费',
            'operator' => $ref ? (string)$ref['cashier_name'] : '',
            'time' => $ref ? (string)$ref['created_at'] : (!empty($o['refunded_at']) ? (string)$o['refunded_at'] : ''),
            'done' => 0, 'refunded' => 1,
            'reason' => $ref ? (string)$ref['reason'] : '');
    }
    $reg = null; $disp = null;
    foreach ($items as $it) {
        if (!empty($it['executed_by'])) {
            if (!$reg) $reg = array('operator' => (string)$it['executed_by'], 'time' => (string)$it['executed_at']);
            $disp = array('operator' => (string)$it['executed_by'], 'time' => (string)$it['executed_at']);
        }
    }
    if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
        $regDone = (bool)$reg;
        $flow[] = array('label' => '登记',
            'operator' => $regDone ? $reg['operator'] : '',
            'time' => $regDone ? $reg['time'] : '',
            'done' => $regDone ? 1 : 0);
        $flow[] = array('label' => '报告完成',
            'operator' => $regDone && $disp ? $disp['operator'] : '',
            'time' => $regDone && $disp ? $disp['time'] : '',
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
        $procDone = (bool)$disp;
        $flow[] = array('label' => '执行完成',
            'operator' => $procDone ? $disp['operator'] : '',
            'time' => $procDone ? $disp['time'] : '',
            'done' => $procDone ? 1 : 0);
    }
    return $flow;
}