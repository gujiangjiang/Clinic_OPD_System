<?php
/**
 * ============================================================
 * parts/order_read.php — 开单：读取（目录/既往/打印/就诊开单列表）
 * ============================================================
 * order.php 按功能拆分的一部分，动作：catalog/prev_items/print/
 * visit_orders
 * ============================================================ */

function order_part_read($action) {
    $u = Auth::user();

    if ($action === 'catalog') {
        $type = get('type', 'lab');
        $list = array();
        if ($type === 'lab') {
            // 检验：全部单项（含被组合包含的成员，均可单独开具）+ 检验组合（按组价整体收费，可整体开组）
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
                foreach (DB::q('lab', 'SELECT id, name FROM lab_items WHERE id IN (SELECT item_id FROM lab_group_members WHERE group_id=?) ORDER BY id', array($g['id'])) as $m) {
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
                    'nurse_required' => (int)$r['need_nurse'],
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
                    // 规格结构化：单剂量值/单位 + 包装数量/单位 + 单次使用数量
                    'spec_dose' => (float)$r['spec_dose'],
                    'spec_dose_unit' => $r['spec_dose_unit'],
                    'spec_pack_qty' => (int)$r['spec_pack_qty'],
                    'spec_pack_unit' => $r['spec_pack_unit'],
                    'single_use_qty' => (float)$r['single_use_qty'],
                    // 皮试联动：开方时前端据此弹确认框并标注
                    'need_skin_test' => (int)(isset($r['need_skin_test']) ? $r['need_skin_test'] : 0),
                    'skin_test_item_id' => (int)(isset($r['skin_test_item_id']) ? $r['skin_test_item_id'] : 0),
                );
            }
        }
        // 联动字典：皮试处置详情（id→名称/费用）+ 给药途径绑定计费处置（途径名→处置）
        //           + 频次/途径选项列表（供已选列表下拉选择）
        $dicts = array('skin_tests' => array(), 'route_bindings' => array(), 'frequencies' => array(), 'routes' => array());
        foreach (DB::q('drug', "SELECT name FROM drug_settings WHERE stype='freq' ORDER BY sort, id") as $fq) {
            $dicts['frequencies'][] = $fq['name'];
        }
        foreach (DB::q('drug', "SELECT name FROM drug_settings WHERE stype='route' ORDER BY sort, id") as $rt) {
            $dicts['routes'][] = $rt['name'];
        }
        $stIds = array();
        foreach ($list as $it) {
            if (!empty($it['skin_test_item_id'])) $stIds[(int)$it['skin_test_item_id']] = true;
        }
        if ($stIds) {
            $ph = implode(',', array_fill(0, count($stIds), '?'));
            foreach (DB::q('disp', "SELECT id, name, fee FROM disposal_items WHERE id IN ($ph)", array_keys($stIds)) as $d) {
                $dicts['skin_tests'][(int)$d['id']] = array('name' => $d['name'], 'fee' => (float)$d['fee']);
            }
        }
        foreach (DB::q('drug', "SELECT name, bind_disposal_item_id FROM drug_settings WHERE stype='route' AND bind_disposal_item_id > 0") as $rb) {
            $dd = DB::one('disp', 'SELECT id, name, fee FROM disposal_items WHERE id=?', array((int)$rb['bind_disposal_item_id']));
            if ($dd) $dicts['route_bindings'][$rb['name']] = array('id' => (int)$dd['id'], 'name' => $dd['name'], 'fee' => (float)$dd['fee']);
        }
        json_ok(array('list' => $list, 'link_dicts' => $dicts));
        return;
    }

    if ($action === 'prev_items') {
        $visitId = did(get('visit_id'));
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
        return;
    }

    if ($action === 'print') {
        $orderIdP = did(get('order_id'));
        if ($orderIdP <= 0) json_fail('链接无效或已过期');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderIdP));
        if (!$order) json_fail('开单记录不存在');
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($order['id']));
        $titles = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置申请单', 'prescription' => '门诊处方笺');
        $title = isset($titles[$order['order_type']]) ? $titles[$order['order_type']] : '申请单';
        $order['need_nurse_any'] = 0;
        foreach ($items as $it) {
            if (!empty($it['need_nurse'])) $order['need_nurse_any'] = 1;
        }
        json_ok(array('html' => pt_order($order, $items, $title)));
        return;
    }

    if ($action === 'visit_orders') {
        $visitId = did(get('visit_id'));
        $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id ASC', array($visitId));
        $out = array();
        foreach ($orders as $o) {
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $doneBy = '';
            foreach ($items as $it) {
                if ($it['executed_by']) $doneBy = $it['executed_by'];
            }
            // 检验/检查：批量取各明细的报告链（results.order_item_id → reports.result_id），
            // 供开单详情展示登记/报告状态与「查看报告」入口
            $reportMap = array();
            if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
                $itemIds = array();
                foreach ($items as $it) $itemIds[] = (int)$it['id'];
                if ($itemIds) {
                    $ph = implode(',', array_fill(0, count($itemIds), '?'));
                    $resRows = DB::q('lab', "SELECT id, order_item_id FROM results WHERE order_item_id IN ($ph)", $itemIds);
                    $resIds = array(); $resToItem = array();
                    foreach ($resRows as $rr) { $resIds[] = (int)$rr['id']; $resToItem[(int)$rr['id']] = (int)$rr['order_item_id']; }
                    if ($resIds) {
                        $ph2 = implode(',', array_fill(0, count($resIds), '?'));
                        // 每个结果取最新一份有效报告
                        foreach (DB::q('lab', "SELECT result_id, MAX(id) AS rid FROM reports WHERE result_id IN ($ph2) AND status<>'withdrawn' GROUP BY result_id", $resIds) as $rp) {
                            $itemId = isset($resToItem[(int)$rp['result_id']]) ? $resToItem[(int)$rp['result_id']] : 0;
                            if ($itemId > 0) $reportMap[$itemId] = (int)$rp['rid'];
                        }
                    }
                }
            }
            $out[] = array(
                // 混淆串：前端删除/打印外链回传时后端统一 did 解码
                'id' => oid($o['id']), 'order_no' => $o['order_no'], 'order_type' => $o['order_type'],
                // 检查分类名称快照：检查申请单按分类拆分后，前端动态显示「XX申请单」
                'cat_name' => isset($o['cat_name']) ? (string)$o['cat_name'] : '',
                'status' => order_agg_status($o['order_type'], $items),
                'total_amount' => (float)$o['total_amount'], 'doctor_name' => $o['doctor_name'],
                // 开单医生 id：多医生接诊下病历正文按医生归属展示已开项目、
                // 删除/毁方按钮仅对开单医生本人可见（后端 delete 亦有硬拦截）
                'doctor_id' => (int)$o['doctor_id'],
                'created_at' => $o['created_at'], 'done_by' => $doneBy,
                'items' => array_map(function ($it) use ($reportMap) {
                    // 扩展字段：处方在病历正文/打印中的所见即所得展示需要剂量/用法/途径等；
                    // group_no/is_parent 供成组医嘱树形展示（子药缩进、组内要素仅主药行一次）；
                    // item_status/report_id 供检验/检查开单详情展示登记/报告状态与查看报告入口
                    return array(
                        'id'             => oid($it['id']),
                        'item_name'      => $it['item_name'],
                        'quantity'       => (int)$it['quantity'],
                        'spec'           => $it['spec'],
                        'single_dose'    => $it['single_dose'],
                        'frequency_name' => $it['frequency_name'],
                        'route_name'     => $it['route_name'],
                        'price'          => (float)$it['price'],
                        'group_no'       => (int)$it['group_no'],
                        'is_parent'      => (int)$it['is_parent'],
                        'status'         => (string)$it['status'],
                        'report_id'      => isset($reportMap[(int)$it['id']]) ? oid($reportMap[(int)$it['id']]) : '',
                    );
                }, $items),
            );
        }
        json_ok(array('list' => $out));
        return;
    }
}
