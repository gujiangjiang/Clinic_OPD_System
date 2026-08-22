<?php
/**
 * ============================================================
 * order.php — 开单接口（检验/检查/处置/处方）
 * ============================================================
 * 说明：
 * 1. 项目目录：仅返回管理员审核通过（approved）的项目/药品
 * 2. 提交开单：处方开单即减库存，删除未缴费开单恢复库存，
 *    退费恢复库存（见 cashier.php）
 * 3. 开单后按类型向对应科室发送站内消息（含打印提醒）
 * 4. 就诊开单列表：含流程状态（开单-缴费-登记-执行-完成）
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 项目目录 ==================== */
    case 'catalog':
        $type = get('type', 'lab');
        $list = array();
        if ($type === 'lab') {
            // 检验：独立项目（含组内成员，可单独开）+ 检验组合（按组价整体收费，可整体开组）
            $rows = DB::q('lab', "SELECT * FROM lab_items WHERE is_group=0 AND status='approved' ORDER BY category, id");
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'unit_name' => $r['unit'], 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
                    'is_group' => 0, 'members' => '',
                );
            }
            $groups = DB::q('lab', "SELECT * FROM lab_items WHERE is_group=1 AND status='approved' ORDER BY category, id");
            foreach ($groups as $g) {
                $mNames = array();
                $mIds = array();
                foreach (DB::q('lab', 'SELECT id, name FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array($g['id'])) as $m) {
                    $mNames[] = $m['name'];
                    $mIds[] = (int)$m['id'];
                }
                $list[] = array(
                    'id' => (int)$g['id'], 'name' => $g['name'], 'price' => (float)$g['price'],
                    'unit_name' => '', 'category_name' => $g['category'],
                    'spec' => implode('、', $mNames), 'stock' => 0,
                    'is_group' => 1, 'members' => implode('、', $mNames),
                    'member_ids' => implode(',', $mIds),   // 组合包含的单项 ID，供前端互斥判断
                );
            }
        } elseif ($type === 'imaging') {
            $rows = DB::q('lab', "SELECT * FROM exam_items WHERE status='approved' ORDER BY category, id");
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'unit_name' => '', 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
                );
            }
        } elseif ($type === 'procedure') {
            $rows = DB::q('disp', "SELECT * FROM disposal_items WHERE status='approved' ORDER BY id");
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['fee'],
                    'unit_name' => '次', 'category_name' => '', 'spec' => '', 'stock' => 0,
                );
            }
        } elseif ($type === 'prescription') {
            $rows = DB::q('drug', "SELECT * FROM drugs WHERE status='approved' ORDER BY category, id");
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'spec' => $r['spec'], 'unit_name' => $r['package_unit'],
                    'company_short' => $r['vendor_short'], 'category_name' => $r['category'],
                    'single_dose' => $r['single_dose'], 'frequency_name' => $r['frequency_name'],
                    'route_name' => $r['route_name'], 'route_nurse_required' => (int)$r['need_nurse'],
                    'stock' => (int)$r['qty'], 'nurse_required' => (int)$r['need_nurse'],
                );
            }
        }
        json_ok(array('list' => $list));
        break;

    /* ==================== 提交开单 ==================== */
    case 'submit':
        $visitId = (int)post('visit_id');
        $orderType = post('order_type', 'lab');
        $nurseReq = (int)post('nurse_required', 0);
        $rawItems = post('items', '[]');
        $items = json_decode($rawItems, true);
        if (!is_array($items) || !$items) {
            json_fail('请至少选择一个项目');
        }
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] !== 'visiting') {
            json_fail('请先接诊该患者后再开单');
        }
        // ===== 病历完整性校验：开检验/检查/处置/处方前，病历必须已完善并保存（主诉/现病史/初步诊断为必填） =====
        // 结构化病历优先（patient_records），兼容旧 records 扁平数据
        $savedRecord = DB::one('medical', 'SELECT id FROM patient_records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array($visitId));
        if (!$savedRecord) {
            $savedRecord = DB::one('medical', 'SELECT id FROM records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array($visitId));
        }
        if (!$savedRecord) {
            json_fail('请先在病历中完善主诉、现病史与初步诊断并保存，再开单');
        }

        // ===== 药品库存预检 + 组装明细 =====
        $orderItems = array();
        $total = 0;
        foreach ($items as $i => $it) {
            $itemId = (int)(isset($it['item_id']) ? $it['item_id'] : 0);
            $qty = max(1, (int)(isset($it['quantity']) ? $it['quantity'] : 1));
            $price = (float)(isset($it['price']) ? $it['price'] : 0);
            $subOf = (int)(isset($it['sub_of']) ? $it['sub_of'] : 0);
            $needNurse = 0;
            if ($orderType === 'prescription' && $subOf === 0 && $itemId > 0) {
                $drug = DB::one('drug', 'SELECT * FROM drugs WHERE id=?', array($itemId));
                if (!$drug || $drug['status'] !== 'approved') {
                    json_fail('药品不存在或未通过审核：' . (isset($it['item_name']) ? $it['item_name'] : ''));
                }
                if ((int)$drug['qty'] < $qty) {
                    json_fail('药品【' . $drug['name'] . '】库存不足（当前库存 ' . (int)$drug['qty'] . '）');
                }
                // 【护士站执行】按给药途径设置自动默认勾选，可手动取消
                $needNurse = ((int)$drug['need_nurse'] === 1 && $nurseReq === 1) ? 1 : 0;
            } elseif ($orderType === 'procedure') {
                $needNurse = $nurseReq;
            }
            // ===== 项目存在性校验（非处方类）：防止空名明细混入病历/打印 =====
            if ($orderType !== 'prescription' && $subOf === 0 && $itemId > 0) {
                $catTable = array('lab' => array('lab', 'lab_items'), 'imaging' => array('lab', 'exam_items'), 'procedure' => array('disp', 'disposal_items'));
                if (isset($catTable[$orderType])) {
                    $itemRow = DB::one($catTable[$orderType][0], 'SELECT name, status FROM ' . $catTable[$orderType][1] . ' WHERE id=?', array($itemId));
                    if (!$itemRow || $itemRow['status'] !== 'approved') {
                        json_fail('开单项目不存在或未通过审核，请刷新后重试');
                    }
                }
            }
            $orderItems[] = array(
                'item_type' => $orderType, 'item_id' => $itemId,
                'item_name' => isset($it['item_name']) ? $it['item_name'] : '',
                'spec' => isset($it['spec']) ? $it['spec'] : '',
                'unit_name' => isset($it['unit_name']) ? $it['unit_name'] : '',
                'company_short' => isset($it['company_short']) ? $it['company_short'] : '',
                'price' => $price, 'quantity' => $qty,
                'single_dose' => isset($it['dose']) ? $it['dose'] : '',
                'frequency_name' => isset($it['frequency']) ? $it['frequency'] : '',
                'route_name' => isset($it['route']) ? $it['route'] : '',
                'need_nurse' => $needNurse, 'sub_of' => $subOf,
            );
            if ($subOf === 0) {
                $total += $price * $qty;
            }
        }

        // ===== 生成单号并写入订单 =====
        $typeCode = array('lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ', 'prescription' => 'CF');
        $orderNo = (isset($typeCode[$orderType]) ? $typeCode[$orderType] : 'DD') . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);

        $orderId = DB::insert('order', 'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, total_amount, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $visit['patient_no'], $visit['flow_no'], $orderType, $orderNo,
            $u['id'], $u['name'], $total, 'open', now_str(),
        ));
        foreach ($orderItems as $it) {
            DB::insert('order', 'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit_name, company_short, price, quantity, single_dose, frequency_name, route_name, need_nurse, sub_of, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $orderId, $visitId, $visit['patient_no'], $visit['flow_no'], $it['item_type'], $it['item_id'],
                $it['item_name'], $it['spec'], $it['unit_name'], $it['company_short'], $it['price'], $it['quantity'],
                $it['single_dose'], $it['frequency_name'], $it['route_name'], $it['need_nurse'], $it['sub_of'],
                'open', $u['id'], $u['name'], now_str(),
            ));
        }

        // ===== 处方开单即减库存 =====
        if ($orderType === 'prescription') {
            foreach ($orderItems as $it) {
                if ($it['item_id'] > 0 && $it['sub_of'] === 0) {
                    DB::exec('drug', 'UPDATE drugs SET qty = qty - ? WHERE id=?', array($it['quantity'], $it['item_id']));
                    DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                        $it['item_id'], -$it['quantity'], 'order_out', $orderNo, $u['name'], now_str(),
                    ));
                }
            }
        }

        // ===== 站内消息 + 打印提醒（通知方式：纯站内消息+打印提醒） =====
        $printUrl = '/api/print?action=order&order_id=' . $orderId;
        $titles = array('lab' => '新的检验申请单', 'imaging' => '新的检查申请单', 'procedure' => '新的处置单', 'prescription' => '新的处方单');
        $targets = array('lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'nurse', 'prescription' => 'pharmacy');
        if (isset($targets[$orderType])) {
            // 患者消息：标记患者姓名与就诊ID，收件人可在消息中心点击直达该次病历
            send_msg($targets[$orderType], 0,
                $titles[$orderType],
                '患者：' . $row['patient']['name'] . '（' . $visit['patient_no'] . '），流水号 ' . $visit['flow_no'] . '，请及时处理',
                'order', $printUrl,
                array('msg_type' => 'patient', 'patient_name' => $row['patient']['name'], 'visit_id' => $visitId));
        }

        json_ok(array('order_id' => $orderId, 'total' => $total, 'order_no' => $orderNo), '开单成功');
        break;

    /* ==================== 既往开具记录（互斥/复查二次确认） ==================== */
    // 说明：返回该患者历史上开具过的项目（同一项目只保留最近一次，含未缴费），
    //      前端在重复开具时提示「何时开具过，是否再次开具」（如复查场景）。
    case 'prev_items':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $patientNo = $row['visit']['patient_no'];
        $type = get('type', 'lab');
        $rows = DB::q('order', "SELECT oi.item_id, oi.item_name, o.created_at, o.order_no FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.patient_no=? AND oi.item_type=? AND oi.sub_of=0
            ORDER BY o.id DESC LIMIT 200", array($patientNo, $type));
        $seen = array();
        $out = array();
        foreach ($rows as $r) {
            $key = (int)$r['item_id'];
            if (isset($seen[$key])) continue;   // 同一项目只保留最近一次
            $seen[$key] = 1;
            $out[] = array(
                'item_id' => $key,
                'item_name' => $r['item_name'],
                'time' => $r['created_at'],
                'order_no' => $r['order_no'],
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ==================== 申请单/处方单打印 ==================== */
    case 'print':
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array((int)get('order_id')));
        if (!$order) json_fail('开单记录不存在');
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($order['id']));
        $titles = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置申请单', 'prescription' => '门诊处方笺');
        $title = isset($titles[$order['order_type']]) ? $titles[$order['order_type']] : '申请单';
        $order['need_nurse_any'] = 0;
        foreach ($items as $it) {
            if (!empty($it['need_nurse'])) $order['need_nurse_any'] = 1;
        }
        json_ok(array('html' => pt_order($order, $items, $title)));
        break;

    /* ==================== 就诊开单列表（病历处置区） ==================== */
    case 'visit_orders':
        $visitId = (int)get('visit_id');
        $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $out = array();
        foreach ($orders as $o) {
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $doneBy = '';
            foreach ($items as $it) {
                if ($it['executed_by']) $doneBy = $it['executed_by'];
            }
            $out[] = array(
                'id' => (int)$o['id'], 'order_no' => $o['order_no'], 'order_type' => $o['order_type'],
                'status' => order_agg_status($o['order_type'], $items),
                'total_amount' => (float)$o['total_amount'], 'doctor_name' => $o['doctor_name'],
                'created_at' => $o['created_at'], 'done_by' => $doneBy,
                'items' => array_map(function ($it) {
                    // 扩展字段：处方在病历正文/打印中的所见即所得展示需要剂量/用法/途径等
                    return array(
                        'item_name'     => $it['item_name'],
                        'quantity'      => (int)$it['quantity'],
                        'spec'          => $it['spec'],
                        'single_dose'   => $it['single_dose'],
                        'frequency_name'=> $it['frequency_name'],
                        'route_name'    => $it['route_name'],
                        'price'         => (float)$it['price'],
                    );
                }, $items),
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ==================== 删除开单（未缴费或已退费，恢复库存） ==================== */
    case 'delete':
        $orderId = (int)post('order_id');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order) json_fail('开单记录不存在');
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=?', array($orderId));
        foreach ($items as $it) {
            // 允许删除：未缴费（open）或已退费（refunded）
            if (!in_array($it['status'], array('open', 'refunded'), true)) {
                json_fail('该开单已进入执行流程，不能删除（如需删除请先在收费处退费）');
            }
        }
        // 恢复药品库存：仅未缴费的处方需要恢复（已退费的处方在退费时已恢复库存）
        if ($order['order_type'] === 'prescription') {
            foreach ($items as $it) {
                if ($it['item_id'] > 0 && (int)$it['sub_of'] === 0 && $it['status'] === 'open') {
                    DB::exec('drug', 'UPDATE drugs SET qty = qty + ? WHERE id=?', array((int)$it['quantity'], $it['item_id']));
                    DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                        $it['item_id'], (int)$it['quantity'], 'order_restore', $order['order_no'], $u['name'], now_str(),
                    ));
                }
            }
        }
        DB::exec('order', 'DELETE FROM order_items WHERE order_id=?', array($orderId));
        DB::exec('order', 'DELETE FROM orders WHERE id=?', array($orderId));
        json_ok(array(), '开单已删除' . ($order['order_type'] === 'prescription' ? '，药品库存已恢复' : ''));
        break;

    default:
        json_fail('未知操作');
}
