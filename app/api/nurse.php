<?php
/**
 * ============================================================
 * nurse.php — 护士站接口
 * ============================================================
 * 数据访问统一委托 EmrRepository / OrderRepository / PatientRepository
 * / QueueRepository，本文件不含原生 SQL。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 护士可见科室ID数组（未设置则全部科室，委托公共函数） */
function nurse_dept_ids() {
    global $u;
    return user_dept_ids($u);
}

switch ($action) {

    /* ==================== 护士站首页统计 ==================== */
    case 'home_stats':
        $today = date('Y-m-d');
        $todayDone = OrderRepository::todayDisposalDone($today);
        $pendingExec = OrderRepository::pendingDisposal();
        $todayFee = OrderRepository::todayDisposalFee($today);
        $dispTotal = (int)OrderRepository::val("SELECT COUNT(*) FROM disposal_items WHERE status='approved'");
        $trend = trend_7_days(function ($day) {
            return (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='procedure' AND status='done' AND date(executed_at)=?", array($day));
        });
        json_ok(array(
            'kpi' => array('today_done' => $todayDone, 'pending_exec' => $pendingExec, 'today_fee' => round($todayFee, 2), 'disp_total' => $dispTotal),
            'trend' => $trend,
        ));
        break;

    /* ==================== 今日患者列表 ==================== */
    case 'patients':
        $deptIds = nurse_dept_ids();
        $where = "date(r.registered_at)=? AND r.status IN ('paid','visiting','finished')";
        $params = array(today_str());
        if ($deptIds) {
            $where .= ' AND r.current_dept_id IN (' . implode(',', array_fill(0, count($deptIds), '?')) . ')';
            $params = array_merge($params, $deptIds);
        }
        $rows = QueueRepository::q("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page, p.id_card AS pid_card, p.birth_date AS pbirth
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE $where ORDER BY r.visit_seq", $params);
        json_ok(array('list' => array_map(function ($r) {
            return array(
                'visit_id' => oid($r['id']),
                'name' => $r['pname'],
                'gender' => $r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                'id_card' => $r['pid_card'],
                'patient_no' => $r['patient_no'],
                'flow_no' => $r['flow_no'],
                'dept_name' => $r['current_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'status' => $r['status'],
                'registered_at' => $r['registered_at'],
            );
        }, $rows)));
        break;

    /* ==================== 待处置列表（护士站执行） ==================== */
    case 'treatments':
        $rows = OrderRepository::nurseTreatments(100);
        $html = '';
        if (!$rows) {
            $html = '<div class="empty"><div class="empty-ico">✅</div>暂无待处置项目</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>处置项目</th><th>流水号</th><th>科室</th><th>开单医生</th><th>开单时间</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $v = CashierRepository::one('SELECT current_dept_name FROM registrations WHERE id=?', array($r['visit_id']));
                $p = PatientRepository::byPatientNo($r['patient_no']);
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . ($p ? age_format($p['birth_date']) : '—') . '</span></td>' .
                    '<td>' . e($r['item_name']) . ' ×' . (int)$r['quantity'] . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($v ? $v['current_dept_name'] : '') . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                    '<td><button class="btn btn-success btn-sm" onclick="completeTreatment(\'' . e(oid($r['id'])) . '\')">完成处置</button></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 完成处置 ==================== */
    case 'complete':
        $itemId = did(post('item_id'));
        $it = OrderRepository::itemById($itemId);
        if (!$it || $it['status'] !== 'paid') json_fail('该处置不存在或状态异常');
        OrderRepository::updateItem($itemId, array('status' => 'done', 'executed_by' => $u['name'], 'executed_at' => now_str()));
        if ($it['doctor_id'] > 0) {
            $pName = PatientRepository::byPatientNo($it['patient_no']);
            send_msg('doctor', $it['doctor_id'],
                '处置已完成：' . $it['item_name'],
                '护士 ' . $u['name'] . ' 已完成患者「' . ($pName ? $pName['name'] : '') . '」（' . $it['patient_no'] . '）的处置「' . $it['item_name'] . '」',
                '', '',
                array('msg_type' => 'patient', 'patient_name' => $pName ? $pName['name'] : '', 'visit_id' => (int)$it['visit_id']));
        }
        json_ok(array(), '处置已完成（执行护士：' . $u['name'] . '）');
        break;

    /* ==================== 患者搜索 ==================== */
    case 'search':
        $kw = trim(get('kw', ''));
        if ($kw === '') json_ok(array('list' => array()));
        json_ok(array('list' => search_visit_records($kw)));
        break;

    /* ==================== 就诊详情 ==================== */
    case 'visit_detail':
        $visitId = did(get('visit_id', ''));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $p = $row['patient'];
        $html = '<div class="card" style="padding:14px;margin-bottom:12px">' .
            '<div class="flex-between">' .
            '  <div><a href="javascript:void(0)" class="fw-700 fs-16" onclick="Clinic.patient.editModal(\'' . e($p['patient_no']) . '\')">' . e($p['name']) . '</a>' .
            '  <span class="text-muted fs-13"> ' . e($p['gender']) . ' / ' . age_format($p['birth_date'], $visit['registered_at']) . '</span>' .
            '  <span class="badge badge-gray" style="margin-left:6px">' . e($visit['current_dept_name']) . ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号</span></div>' .
            '  <span class="badge badge-primary">' . e($visit['flow_no']) . '</span></div>' .
            '<div class="fs-12 text-muted mt-4">患者ID ' . e($visit['patient_no']) . ' ｜ 首次科室 ' . e($visit['first_dept_name']) . ' ｜ 挂号 ' . e(substr($visit['registered_at'], 0, 16)) . ' ｜ 状态 ' . e(visit_status_name($visit['status'])) . '</div>' .
            '<div class="flex gap-8 mt-8">' .
            '<button class="btn btn-outline btn-sm" onclick="openVitals(\'' . e(oid($visitId)) . '\')">🌡️ 生命体征</button>' .
            '<button class="btn btn-outline btn-sm" onclick="openNursing(\'' . e(oid($visitId)) . '\')">📝 护理记录</button></div></div>';

        $orders = OrderRepository::byVisit($visitId);
        $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
        $html .= '<div class="fs-14 fw-700 mb-8">医生开单（检验/检查/处置/处方）</div>';
        if (!$orders) {
            $html .= '<div class="fs-13 text-muted">暂无开单</div>';
        }
        foreach ($orders as $o) {
            $items = OrderRepository::itemsByOrder($o['id']);
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">' .
                '<div class="flex-between fs-13"><span class="fw-600">' . e(isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : $o['order_type']) . ' ' . e($o['order_no']) . '</span>' .
                '<span class="fs-12 text-muted">' . e($o['doctor_name']) . ' ｜ ' . e(substr($o['created_at'], 5, 11)) . ' ｜ ' . e(order_agg_status($o['order_type'], $items)) . '</span></div>';
            foreach ($items as $it) {
                $html .= '<div class="fs-12 text-muted">· ' . e($it['item_name']) .
                    (($it['is_nurse'] && $it['item_type'] === 'prescription') ? '（护士站执行）' : '') .
                    ' ×' . (int)$it['quantity'] . '（' . e(item_status_name($it['status'])) . '）</div>';
            }
            $html .= '</div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 待执行医嘱 ==================== */
    case 'med_orders':
        $rows = OrderRepository::nurseMedOrders(100);
        $html = '<div class="fs-13 text-muted mb-8">待执行医嘱：' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">💉</div>暂无待执行医嘱</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>医嘱</th><th>流水号</th><th>开单医生</th><th>开单时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $p = PatientRepository::byPatientNo($r['patient_no']);
                $v = CashierRepository::one('SELECT current_dept_name, visit_seq FROM registrations WHERE id=?', array($r['visit_id']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . ($p ? age_format($p['birth_date']) : '—') . '</span><br>' .
                    '<span class="fs-12 text-muted">' . e($v ? $v['current_dept_name'] : '') . ' 第' . str_pad((string)($v ? $v['visit_seq'] : 0), 3, '0', STR_PAD_LEFT) . '号</span></td>' .
                    '<td>' . e($r['item_name']) . ' ×' . (int)$r['quantity'] . ' <span class="fs-12 text-muted">' . e($r['route']) . '</span></td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($r['odoc']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                    '<td>' . ($r['status'] === 'paid' ? '<span class="badge badge-warning">待执行</span>' : '<span class="badge badge-primary">执行中</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" onclick="medDetail(\'' . e(oid($r['order_id'])) . '\')">详情</button>' .
                    ($r['status'] === 'paid'
                        ? '<button class="btn btn-primary btn-sm" onclick="medStart(\'' . e(oid($r['id'])) . '\')">等待执行</button>'
                        : '<button class="btn btn-success btn-sm" onclick="medDone(\'' . e(oid($r['id'])) . '\')">执行完成</button>') .
                    '</div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 医嘱详情 ==================== */
    case 'med_detail':
        $orderId = did(get('order_id'));
        $order = OrderRepository::byId($orderId);
        if (!$order) json_fail('处方不存在');
        $items = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? ORDER BY sub_of, id', array($orderId));
        $html = '<div class="fs-13 text-muted mb-8">处方单号：' . e($order['order_no']) . ' ｜ 开单医生：' . e($order['doctor_name']) . ' ｜ ' . e($order['created_at']) . '</div>';
        $idx = 0;
        foreach ($items as $it) {
            if ((int)$it['sub_of'] > 0) continue;
            $idx++;
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">' .
                '<div class="fs-13 fw-600">' . e($it['item_name']) . ' ×' . (int)$it['quantity'] . '</div>' .
                '<div class="fs-12 text-muted">剂量 ' . e($it['single_dose']) . ' ｜ 频次 ' . e($it['frequency']) . ' ｜ 途径 ' . e($it['route']) . '（护士站执行）</div>';
            foreach ($items as $sub) {
                if ((int)$sub['sub_of'] === $idx) {
                    $html .= '<div class="fs-12 text-muted" style="margin-left:16px;border-left:2px solid var(--warning);padding-left:10px">└ ' . e($sub['item_name']) . ' ｜ 剂量 ' . e($sub['single_dose']) . '</div>';
                }
            }
            $html .= '</div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 等待执行 ==================== */
    case 'med_start':
        $itemId = did(post('item_id'));
        $it = OrderRepository::itemById($itemId);
        if (!$it || $it['item_type'] !== 'prescription' || $it['status'] !== 'paid') {
            json_fail('医嘱不存在或状态异常');
        }
        OrderRepository::updateItem($itemId, array('status' => 'dispensing'));
        json_ok(array(), '已标记为等待执行，执行完成后请点击【执行完成】');
        break;

    /* ==================== 执行完成 ==================== */
    case 'med_done':
        $itemId = did(post('item_id'));
        $it = OrderRepository::itemById($itemId);
        if (!$it || $it['item_type'] !== 'prescription' || $it['status'] !== 'dispensing') {
            json_fail('医嘱不存在或状态异常');
        }
        OrderRepository::updateItem($itemId, array('status' => 'dispensed', 'executed_by' => $u['name'], 'executed_at' => now_str()));
        if ($it['doctor_id'] > 0) {
            $pName = PatientRepository::byPatientNo($it['patient_no']);
            send_msg('doctor', $it['doctor_id'],
                '医嘱已执行：' . $it['item_name'],
                '护士 ' . $u['name'] . ' 已完成患者「' . ($pName ? $pName['name'] : '') . '」（' . $it['patient_no'] . '）的医嘱「' . $it['item_name'] . '」执行（' . $it['route'] . '）',
                '', '',
                array('msg_type' => 'patient', 'patient_name' => $pName ? $pName['name'] : '', 'visit_id' => (int)$it['visit_id']));
        }
        json_ok(array(), '执行成功（执行护士：' . $u['name'] . '）');
        break;

    /* ==================== 生命体征：读取 ==================== */
    case 'vitals':
        $visitId = did(get('visit_id'));
        $all = EmrRepository::vitalsByVisit($visitId);
        $latest = $all ? end($all) : null;
        json_ok(array('vitals' => $latest ? $latest : null, 'vitals_history' => $all ? $all : array()));
        break;

    /* ==================== 生命体征：保存 ==================== */
    case 'save_vitals':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $recordId = (int)post('record_id', 0);
        EmrRepository::insertVitals(array(
            'visit_id' => $visitId, 'patient_no' => $row['visit']['patient_no'], 'flow_no' => $row['visit']['flow_no'],
            'vital_sbp' => (int)post('vital_sbp', 0), 'vital_dbp' => (int)post('vital_dbp', 0),
            'vital_heart_rate' => post('vital_heart_rate'), 'vital_pulse' => post('vital_pulse'),
            'vital_spo2' => post('vital_spo2'), 'vital_respiration' => post('vital_respiration'),
            'operator' => $u['name'], 'created_at' => now_str(), 'record_id' => $recordId,
        ));
        json_ok(array(), '生命体征已保存（医生工作站将同步显示）');
        break;

    /* ==================== 护理记录列表 ==================== */
    case 'nursing_list':
        $visitId = did(get('visit_id'));
        $rows = EmrRepository::nursingByVisit($visitId);
        $html = '';
        if (!$rows) {
            $html = '<div class="text-muted fs-13">暂无护理记录</div>';
        }
        foreach ($rows as $r) {
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px">' .
                '<div class="fs-13">' . nl2br(e($r['content'])) . '</div>' .
                '<div class="fs-12 text-muted mt-4">' . e($r['operator']) . ' ｜ ' . e($r['created_at']) . '</div></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 新增护理记录 ==================== */
    case 'nursing_add':
        $visitId = did(post('visit_id'));
        $content = post('content');
        if ($content === '') json_fail('请输入护理记录内容');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        EmrRepository::insertNursing(array(
            'visit_id' => $visitId, 'patient_no' => $row['visit']['patient_no'], 'flow_no' => $row['visit']['flow_no'],
            'content' => $content, 'operator' => $u['name'],
        ));
        json_ok(array(), '护理记录已添加');
        break;

    default:
        json_fail('未知操作');
}