<?php
/**
 * ============================================================
 * pharmacy.php — 药房接口
 * ============================================================
 * 数据访问统一委托 DrugRepository / OrderRepository / PatientRepository，
 * 本文件不含原生 SQL。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/forms.php';
require_once APP_ROOT . '/app/includes/print_templates.php';   // pt_rx_slip 处方提示凭条

$u = Auth::user();

switch ($action) {

    /* ==================== 药房首页统计 ==================== */
    case 'home_stats':
        $today = date('Y-m-d');
        $todayDisp = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND status='dispensed' AND date(executed_at)=?", array($today));
        $todayFee = (float)OrderRepository::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_type='prescription' AND status='dispensed' AND date(paid_at)=?", array($today));
        $pendingRx = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND status='paid'");
        $drugTotal = (int)DrugRepository::val("SELECT COUNT(*) FROM drugs WHERE status='approved'");
        $lowStock = (int)DrugRepository::val("SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty<=50");
        $pendingAudit = (int)DrugRepository::val("SELECT COUNT(*) FROM drugs WHERE status='pending'");
        $trend = trend_7_days(function ($day) {
            return (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND status='dispensed' AND date(executed_at)=?", array($day));
        });
        json_ok(array(
            'kpi' => array('today_disp' => $todayDisp, 'today_fee' => round($todayFee, 2), 'pending_rx' => $pendingRx,
                'drug_total' => $drugTotal, 'low_stock' => $lowStock, 'pending_audit' => $pendingAudit),
            'trend' => $trend,
        ));
        break;

    /* ==================== 发药队列（按处方单维度聚合；审方/发药分开展示） ==================== */
    case 'queue':
        $status = get('status', 'paid');
        // 待审方：处方单已缴费（orders.status='paid'）且存在未发药的主药明细
        // 待发药：审方通过待发药（orders.status='reviewed'）
        // 已发药：处方单已发药（orders.status='dispensed'）
        // 说明：不按 is_nurse 过滤——护士站执行的药品也随处方整单进入审方（审方是处方层面动作）
        $statusMap = array('paid' => '待审方', 'reviewed' => '待发药', 'dispensed' => '已发药');
        $itemSt = array('paid' => 'paid', 'reviewed' => 'paid', 'dispensed' => 'dispensed');
        $statusWhere = "o.order_type='prescription' AND o.status='" . (isset($statusMap[$status]) ? $status : 'paid') . "'";
        if ($status === 'reviewed') $statusWhere .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type='prescription' AND oi.sub_of=0 AND oi.status='paid')";
        $rows = OrderRepository::q("SELECT o.* FROM orders o
            WHERE $statusWhere
            AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id
                AND oi.item_type='prescription' AND oi.sub_of=0
                AND oi.status='" . $itemSt[isset($statusMap[$status]) ? $status : 'paid'] . "')
            ORDER BY o.id DESC", array());
        $label = isset($statusMap[$status]) ? $statusMap[$status] : '待审方';
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 张' . $label . '处方</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">💊</div>暂无' . $label . '处方</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>处方号</th><th>药品明细</th>' .
                ($status === 'dispensed' ? '<th>发药药师</th><th>发药时间</th>' : '') . '<th>操作</th></tr></thead><tbody>';
            foreach ($rows as $o) {
                $p = PatientRepository::byPatientNo($o['patient_no']);
                $names = array();
                $allNurse = true;
                foreach ($rxItems = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND sub_of=0 ORDER BY id', array((int)$o['id'])) as $ri) {
                    if ((int)$ri['is_nurse'] === 0) $allNurse = false;
                    $names[] = e($ri['item_name']) . ($ri['is_nurse'] ? ' <span class="badge badge-warning" style="font-size:11px">护士站执行</span>' : '');
                    $subs = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND group_no=? AND is_parent=0 ORDER BY id', array((int)$o['id'], (int)$ri['group_no']));
                    foreach ($subs as $s) $names[] = '　└ ' . e($s['item_name']);
                }
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '—') . '</td>' .
                    '<td>' . e($o['order_no']) . '</td>' .
                    '<td class="fs-12">' . implode('<br>', $names) . '</td>';
                if ($status === 'dispensed') {
                    $html .= '<td>' . e($o['done_by'] ? $o['done_by'] : '') . '</td><td class="fs-12">' . e(substr((string)$o['dispensed_at'], 5, 11)) . '</td>';
                }
                $html .= '<td>' .
                    ($status === 'paid'
                        ? '<button class="btn btn-primary btn-sm" onclick="reviewRx(\'' . oid($o['id']) . '\')">审方</button>'
                        : ($status === 'reviewed'
                            ? '<button class="btn btn-success btn-sm" onclick="dispenseRx(\'' . oid($o['id']) . '\')">发药</button>'
                            // 全部为护士站执行：无药房取药凭条，操作列显示「护士站执行」徽章（不可补打）
                            : ($allNurse
                                ? '<span class="badge badge-warning">护士站执行</span>'
                                : '<button class="btn btn-outline btn-sm" onclick="reprintRxSlip(\'' . oid($o['id']) . '\')">🖨️ 处方提示</button>'))) .
                    '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 处方详情（审方模态框数据） ==================== */
    case 'rx_detail':
        $orderId = did(get('order_id'));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'prescription') json_fail('处方不存在');
        $patient = PatientRepository::byPatientNo($order['patient_no']);
        $mainItems = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND sub_of=0 ORDER BY id', array($orderId));
        $mainList = array();
        foreach ($mainItems as $m) {
            $m['subs'] = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND group_no=? AND is_parent=0 ORDER BY id', array($orderId, (int)$m['group_no']));
            $mainList[] = $m;
        }
        json_ok(array(
            'order' => array(
                'id' => oid($order['id']), 'order_no' => $order['order_no'],
                'doctor_name' => $order['doctor_name'], 'dept_name' => $order['dept_name'],
                'created_at' => $order['created_at'], 'total_amount' => (float)$order['total_amount'],
                'status' => $order['status'],
                'review_by' => isset($order['review_by']) ? $order['review_by'] : '',
                'reviewed_at' => isset($order['reviewed_at']) ? $order['reviewed_at'] : '',
            ),
            'patient' => array('name' => $patient ? $patient['name'] : '', 'patient_no' => $order['patient_no'], 'flow_no' => $order['flow_no']),
            'items' => $mainList,
        ));
        break;

    /* ==================== 审方（整张处方一次通过/拒绝；通过仅标记待发药） ==================== */
    case 'audit':
        $orderId = did(post('order_id'));
        $verdict = post('verdict', '');   // pass 审方通过 / reject 拒绝
        $reason = trim((string)post('reason', ''));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'prescription') json_fail('处方不存在');
        if (!in_array($verdict, array('pass', 'reject'), true)) json_fail('审方指令无效');
        if ($verdict === 'reject' && $reason === '') json_fail('请填写拒绝理由');
        // 待审方状态：已缴费未审方（paid）；审方通过后（reviewed）不可再拒绝，只能发药
        if ($order['status'] !== 'paid') json_fail('该处方当前状态不可审方（已审方/已处理/已取消）');
        // 全部主药明细（子药随主药一并处理；拒绝时库存恢复需同时覆盖子药）
        $items = OrderRepository::q("SELECT * FROM order_items WHERE order_id=? AND item_type='prescription' AND sub_of=0 AND status='paid'", array($orderId));
        if (!$items) json_fail('该处方无待审方明细');
        $allRxItems = OrderRepository::q("SELECT * FROM order_items WHERE order_id=? AND item_type='prescription' AND item_id>0 AND status='paid'", array($orderId));
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            if ($verdict === 'pass') {
                // 审方通过：仅置待发药（orders.status='reviewed' + 审方人/时间），
                // 不移动明细、不发药、不打印凭条——发药由 dispense 动作单独完成，
                // 审方人与发药人可为同一人，也可不同人。
                OrderRepository::exec("UPDATE orders SET status='reviewed', review_by=?, reviewed_at=? WHERE id=?", array($u['name'], now_str(), $orderId));
            }
            if ($verdict === 'reject') {
                // 拒绝：恢复库存（开方时主药+子药均已减库存）+ 全部明细置 rejected
                foreach ($allRxItems as $it) {
                    OrderRepository::exec('UPDATE drugs SET qty = qty + ? WHERE id=?', array((int)$it['quantity'], $it['item_id']));
                    OrderRepository::insert('INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                        $it['item_id'], (int)$it['quantity'], 'order_reject', $order['order_no'], $u['name'], now_str(),
                    ));
                }
                OrderRepository::exec("UPDATE order_items SET status='rejected', executed_by=?, executed_at=? WHERE order_id=? AND status='paid'", array($u['name'], now_str(), $orderId));
                OrderRepository::exec('UPDATE orders SET status=? WHERE id=?', array('rejected', $orderId));
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('审方失败：' . $ex->getMessage());
        }
        $pName = PatientRepository::byPatientNo($order['patient_no']);
        $pNameStr = $pName ? $pName['name'] : '';
        if ($verdict === 'pass') {
            $msgTitle = '处方已审方通过';
            $msgContent = '药剂师 ' . $u['name'] . ' 已审方通过患者「' . $pNameStr . '」（' . $order['patient_no'] . '）处方 ' . $order['order_no'] . '，待发药。';
        } else {
            $msgTitle = '处方被驳回';
            $msgContent = '药剂师 ' . $u['name'] . ' 驳回了患者「' . $pNameStr . '」（' . $order['patient_no'] . '）处方 ' . $order['order_no'] . '：' . $reason;
        }
        if ((int)$order['doctor_id'] > 0) {
            send_msg('doctor', (int)$order['doctor_id'], $msgTitle, $msgContent, '', '',
                array('msg_type' => 'patient', 'patient_name' => $pNameStr, 'visit_id' => (int)$order['visit_id']));
        }
        json_ok(array('order_id' => oid($orderId), 'verdict' => $verdict),
            $verdict === 'pass' ? '审方通过，待发药（已通知开单医生）' : '已驳回并通知开单医生，库存已恢复');
        break;

    /* ==================== 发药（审方通过后单独执行；可审方人与发药人不同） ==================== */
    case 'dispense':
        $orderId = did(post('order_id'));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'prescription') json_fail('处方不存在');
        if ($order['status'] !== 'reviewed') json_fail('该处方尚未审方通过，不可发药');
        // 全部含子药的药品明细（发药：主药+子药按各自 is_nurse 发药/转交护士站）
        $allRxItems = OrderRepository::q("SELECT * FROM order_items WHERE order_id=? AND item_type='prescription' AND item_id>0 AND status='paid'", array($orderId));
        if (!$allRxItems) json_fail('该处方无待发药明细');
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            foreach ($allRxItems as $it) {
                $newStatus = ((int)$it['is_nurse'] === 1) ? 'dispensing' : 'dispensed';
                OrderRepository::exec('UPDATE order_items SET status=?, executed_by=?, executed_at=? WHERE id=?', array($newStatus, $u['name'], now_str(), (int)$it['id']));
            }
            OrderRepository::exec('UPDATE orders SET status=?, done_by=?, dispensed_at=? WHERE id=?', array('dispensed', $u['name'], now_str(), $orderId));
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('发药失败：' . $ex->getMessage());
        }
        $pName = PatientRepository::byPatientNo($order['patient_no']);
        $pNameStr = $pName ? $pName['name'] : '';
        if ((int)$order['doctor_id'] > 0) {
            send_msg('doctor', (int)$order['doctor_id'], '处方已发药',
                '药剂师 ' . $u['name'] . ' 已完成患者「' . $pNameStr . '」（' . $order['patient_no'] . '）处方 ' . $order['order_no'] . ' 的发药，可引导患者取药。',
                '', '', array('msg_type' => 'patient', 'patient_name' => $pNameStr, 'visit_id' => (int)$order['visit_id']));
        }
        // 判断是否需要打印取药凭条：存在非护士站执行（is_nurse=0）的主药即需打印；
        // 全部为护士站执行 → has_slip=0（前端不弹凭条，后台 rx_slip 亦拦截）
        $hasSlip = 0;
        foreach (OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND sub_of=0 ORDER BY id', array($orderId)) as $mi) {
            if ((int)$mi['is_nurse'] === 0) { $hasSlip = 1; break; }
        }
        json_ok(array('order_id' => oid($orderId), 'has_slip' => $hasSlip), '发药成功' . ($hasSlip ? '，已打印取药凭条' : '（全部护士站执行，无取药凭条）'));
        break;

    /* ==================== 处方提示（发药完成补打/凭条） ==================== */
    case 'rx_slip':
        $orderId = did(get('order_id'));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'prescription') json_fail('处方不存在');
        if ($order['status'] !== 'dispensed') json_fail('该处方尚未发药，无法打印处方提示');
        // 主药按护士站执行过滤：护士站执行（is_nurse=1）的药品不出现在取药凭条
        $items = array();
        foreach (OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND sub_of=0 ORDER BY id', array($orderId)) as $mi) {
            if ((int)$mi['is_nurse'] === 1) continue;   // 护士站执行 → 药房不直接发药，隐藏
            $mi['_subs'] = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND group_no=? AND is_parent=0 ORDER BY id', array($orderId, (int)$mi['group_no']));
            $items[] = $mi;
        }
        // 全部为护士站执行：无药房发药项，后台拦截不打印
        if (!$items) json_fail('该处方全部由护士站执行，无需打印取药提示');
        $patient = PatientRepository::byPatientNo($order['patient_no']);
        json_ok(array('html' => pt_rx_slip($order, $items, $patient)));
        break;

    /* ==================== 库存列表 ==================== */
    case 'inventory':
        $rows = DrugRepository::all();
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 种药品</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">📦</div>暂无药品，请管理员先在【药品信息】中添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>药品</th><th>分类</th><th>规格</th><th>包装</th><th>库存</th><th>单价</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $low = (int)$r['qty'] <= 10;
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($r['name']) . (!empty($r['vendor_short']) ? '（' . e($r['vendor_short']) . '）' : '') . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>' . e($r['spec']) . '</td>' .
                    '<td>' . e($r['package_unit']) . '</td>' .
                    '<td class="' . ($low ? 'text-danger fw-700' : '') . '">' . (int)$r['qty'] . ($low ? ' <span class="badge badge-danger" style="font-size:11px">库存不足</span>' : '') . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><button class="btn btn-outline btn-sm" onclick="stockModal(' . (int)$r['id'] . ',\'' . e($r['name']) . '\')">入库/出库</button></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 新增药品（提交后需管理员审核） ==================== */
    case 'drug_form':
        json_ok(form_drug((int)req('id', 0)));
        break;

    case 'drug_save':
        $id = (int)post('id', 0);
        $name = post('name');
        if ($name === '') json_fail('请填写药品名称');
        $data = array(
            'generic_name' => post('generic_name'), 'category' => post('category'),
            'vendor' => post('vendor'), 'vendor_short' => post('vendor_short'),
            'package_unit' => post('package_unit'), 'spec' => post('spec'), 'form' => post('form'),
            'single_dose' => post('single_dose'), 'frequency' => post('frequency'),
            'route' => post('route'), 'price' => (float)post('price', 0),
            'qty' => (int)post('qty', 0), 'is_rx' => (int)post('is_rx', 0),
            'is_limited' => (int)post('is_limited', 0), 'note' => post('note'),
            'is_nurse' => (int)post('is_nurse', 0), 'name' => $name,
            // 规格结构化 + 皮试关联（与管理员 drug_save 对齐，药房新增药品时一并落库）
            'spec_dose' => (float)post('spec_dose', 0),
            'spec_dose_unit' => post('spec_dose_unit'),
            'spec_pack_qty' => max(1, (int)post('spec_pack_qty', 1)),
            'spec_pack_unit' => post('spec_pack_unit'),
            'single_use_qty' => max(1, (int)post('single_use_qty', 1)),
            'is_skin_test' => (int)post('is_skin_test', 0),
            'skin_test_item_id' => (int)post('skin_test_item_id', 0),
        );
        if ($id > 0) {
            // 编辑已审核药品：置为待审核状态，重新走管理员审核（与管理员端/检验/检查口径一致），
            // 防止药房直接篡改已生效药品的价格/库存影响计费
            $data['status'] = 'pending';
            DrugRepository::update($id, $data);
            DrugRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status IN ('pending','rejected')",
                array($u['name'], now_str(), $id));
            submit_audit('item_drug', $id, '修改药品：' . $name, '提交药品信息修改：' . $name);
            send_msg('admin', 0, '待审核提醒', '有新的药品修改待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            json_ok(array(), '修改已提交，待管理员审核');
        } else {
            $data['status'] = 'pending';
            $data['created_at'] = now_str();
            $newId = DrugRepository::create($data);
            submit_audit('item_drug', $newId, '药品新增：' . $name, '药品名称：' . $name . '，分类：' . post('category'), array('data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'creation_source' => 'pharmacy'));
            json_ok(array('id' => $newId), '药品已提交审核');
        }
        break;

    /* ==================== 药品分类设置 ==================== */
    case 'category_form':
        json_ok(form_drug(0));
        break;

    case 'category_save':
        $name = post('name');
        if ($name === '') json_fail('请填写分类名称');
        if (DrugRepository::settingByName('category', $name)) json_fail('该分类已存在');
        DrugRepository::createSetting('category', $name);
        json_ok(array(), '分类已保存');
        break;

    /* ==================== 库存变动（入库/出库） ==================== */
    case 'stock':
        $drugId = (int)post('drug_id');
        $type = post('type', 'in');
        $change = (int)post('qty', 0);
        // type 白名单：仅允许 in/out，防止拼写/伪造值误走入库分支
        if (!in_array($type, array('in', 'out'), true)) json_fail('库存变动类型无效');
        if ($change <= 0) json_fail('数量必须大于 0');
        $drug = DrugRepository::byId($drugId);
        if (!$drug) json_fail('药品不存在');
        if ($type === 'out') {
            // 原子条件更新防并发超扣库存（读判写分离的 TOCTOU：改为一并校验充足性）
            $affected = DrugRepository::exec('UPDATE drugs SET qty = qty - ? WHERE id=? AND qty >= ?', array($change, $drugId, $change));
            if ($affected === 0) json_fail('库存不足（当前库存 ' . (int)$drug['qty'] . '）');
        } else {
            DrugRepository::restoreStock($drugId, $change);
        }
        DrugRepository::createInventoryTrans($drugId, $type === 'out' ? -$change : $change, $type, post('note', ''), $u['name']);
        // 回读最新库存（原子更新后的权威值）
        $newQty = (int)DrugRepository::val('SELECT qty FROM drugs WHERE id=?', array($drugId));
        json_ok(array('qty' => $newQty), '库存已更新');
        break;

    default:
        json_fail('未知操作');
}