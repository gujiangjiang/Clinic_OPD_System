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
                    // 皮试联动：开方时前端据此弹确认框并标注
                    'need_skin_test' => (int)(isset($r['need_skin_test']) ? $r['need_skin_test'] : 0),
                    'skin_test_item_id' => (int)(isset($r['skin_test_item_id']) ? $r['skin_test_item_id'] : 0),
                );
            }
        }
        // 联动字典：皮试处置详情（id→名称/费用）+ 给药途径绑定计费处置（途径名→处置）
        $dicts = array('skin_tests' => array(), 'route_bindings' => array());
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
        break;

    /* ==================== 提交开单 ==================== */
    case 'submit':
        $visitId = did(post('visit_id'));
        $orderType = post('order_type', 'lab');
        $nurseReq = (int)post('nurse_required', 0);
        // 皮试判定结果（与 items 下标对齐）：yes=需要皮试 / no=免试 / 空=非皮试药品
        $skinChoices = json_decode(post('skin_choices', '[]'), true);
        if (!is_array($skinChoices)) $skinChoices = array();
        // 联动处置聚合容器：disposal_id => [name, fee, qty]
        $autoDisp = array();
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

                // ===== 皮试判定（阻断式）：需皮试药品必须由医生明确选择方案 =====
                $skinChoice = '';
                if ((int)$drug['need_skin_test'] === 1) {
                    $choice = isset($skinChoices[$i]) ? strtolower(trim((string)$skinChoices[$i])) : '';
                    if ($choice !== 'yes' && $choice !== 'no') {
                        json_fail('【' . $drug['name'] . '】为需皮试药品，请先选择本次处置方案（需要皮试 / 免试）');
                    }
                    $skinChoice = $choice;
                }
                // ===== 给药途径 → 绑定计费处置（如 静脉输液 → 静脉输液费）=====
                $routeBindId = 0;
                $routeBind = DB::one('drug', "SELECT bind_disposal_item_id FROM drug_settings WHERE stype='route' AND name=? LIMIT 1", array($drug['route_name']));
                if ($routeBind && (int)$routeBind['bind_disposal_item_id'] > 0) {
                    $routeBindId = (int)$routeBind['bind_disposal_item_id'];
                }
                // 聚合联动处置（按处置项目累加数量，稍后统一生成一张处置单）
                if ($skinChoice === 'yes' && (int)$drug['skin_test_item_id'] > 0) {
                    $stId = (int)$drug['skin_test_item_id'];
                    if (!isset($autoDisp[$stId])) {
                        $stInfo = DB::one('disp', 'SELECT name, fee FROM disposal_items WHERE id=?', array($stId));
                        if (!$stInfo) json_fail('皮试处置项目不存在：#' . $stId);
                        $autoDisp[$stId] = array('name' => $stInfo['name'], 'fee' => (float)$stInfo['fee'], 'qty' => 0);
                    }
                    $autoDisp[$stId]['qty'] += 1;
                }
                if ($routeBindId > 0) {
                    if (!isset($autoDisp[$routeBindId])) {
                        $rbInfo = DB::one('disp', 'SELECT name, fee FROM disposal_items WHERE id=?', array($routeBindId));
                        if (!$rbInfo) json_fail('途径绑定处置不存在：#' . $routeBindId);
                        $autoDisp[$routeBindId] = array('name' => $rbInfo['name'], 'fee' => (float)$rbInfo['fee'], 'qty' => 0);
                    }
                    // 按组数核算（1.9.0）：一个主药 = 一个组，同组内子药不叠加——
                    // 同一瓶液体加入多种药只产生 1 次注射/输液处置费
                    $autoDisp[$routeBindId]['qty'] += 1;
                }
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
            // 皮试标注写入药名（随明细持久化：病历/打印/药房队列全链路可见）
            $rxName = isset($it['item_name']) ? $it['item_name'] : '';
            if ($orderType === 'prescription' && isset($skinChoice) && $skinChoice !== '') {
                $rxName .= $skinChoice === 'yes' ? '(需要皮试)' : '(无需皮试)';
            }
            $orderItems[] = array(
                'item_type' => $orderType, 'item_id' => $itemId,
                'item_name' => $rxName,
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

        // ===== 成组医嘱：分配组号 / 主药 / 父条目关联 =====
        // 主项目（sub_of=0）各自成组（group_no 递增）；子项目（sub_of>0）继承其
        // 主药的组号、给药途径与频次，并记录 parent_item_id 指向本单内主药序号。
        // 说明：sub_of 沿用来自主药在该次提交中的 1 基位置（前端 idx+1 标记）。
        $groupCounter = 0;
        $parentMap = array();   // 前端 sub_of 值 => 该主药分配到的 group_no
        foreach ($orderItems as $i => &$it) {
            $parentSeq = (int)$it['sub_of'];
            if ($parentSeq === 0) {
                $groupCounter++;
                $it['group_no'] = $groupCounter;
                $it['is_parent'] = 1;
                $it['parent_item_id'] = 0;
                $parentMap[$i + 1] = $groupCounter;   // 主药在本次提交中的 1 基序号
            } else {
                $it['group_no'] = $groupCounter + 1;  // 暂记，下方统一回填
            }
        }
        unset($it);
        // 回填子项目组号与父条目（子项目 sub_of 即其主药的前端序号，等于主药下标+1）
        foreach ($orderItems as $i => &$it) {
            if ((int)$it['sub_of'] > 0) {
                $pidx = (int)$it['sub_of'] - 1;
                if (isset($orderItems[$pidx])) {
                    $it['group_no'] = (int)$orderItems[$pidx]['group_no'];
                    $it['is_parent'] = 0;
                    $it['parent_item_id'] = $pidx + 1;   // 主药在本次提交中的 1 基序号
                    // 子药强制继承主药途径与频次（成组医嘱约束）
                    $it['route_name'] = $orderItems[$pidx]['route_name'];
                    if ($it['frequency_name'] === '') $it['frequency_name'] = $orderItems[$pidx]['frequency_name'];
                } else {
                    $it['group_no'] = 0; $it['is_parent'] = 0; $it['parent_item_id'] = 0;
                }
            }
        }
        unset($it);

        // ===== 分组组装：检查申请单按「检查分类」自动拆分 =====
        // 检查需前往不同地点分散执行，不同分类（如 CT / MR / DR）拆分为
        // 不同申请单（各自独立单号），同分类合并为一张；
        // 检验/处置/处方保持单张。分组后每组独立走建单流程。
        $itemSeq = array();          // $orderItems 下标 => 全局主项目序号(1基)
        $mainSeq = 0;
        foreach ($orderItems as $i => $it) {
            if ((int)$it['sub_of'] === 0) { $mainSeq++; $itemSeq[$i] = $mainSeq; }
        }
        if ($orderType === 'imaging') {
            // 主项目 → exam_items.category（管理员自定义分类名称，如 CT / DR（数字化X线））
            $examIds = array();
            foreach ($itemSeq as $i => $seq) {
                if ((int)$orderItems[$i]['item_id'] > 0) $examIds[(int)$orderItems[$i]['item_id']] = 1;
            }
            $catMap = array();
            if ($examIds) {
                $ph = implode(',', array_fill(0, count($examIds), '?'));
                foreach (DB::q('lab', "SELECT id, category FROM exam_items WHERE id IN ($ph)", array_keys($examIds)) as $r) {
                    $catMap[(int)$r['id']] = trim((string)$r['category']);
                }
            }
            $groups = array();
            $seqGroup = array();
            foreach ($itemSeq as $i => $seq) {
                $cat = (isset($catMap[(int)$orderItems[$i]['item_id']]) && $catMap[(int)$orderItems[$i]['item_id']] !== '')
                    ? $catMap[(int)$orderItems[$i]['item_id']] : '检查';
                if (!isset($groups[$cat])) $groups[$cat] = array('cat' => $cat, 'idx' => array());
                $groups[$cat]['idx'][] = $i;
                $seqGroup[$seq] = $cat;
            }
            // 子项目跟随其主项目所在分组（sub_of 为全局主项目 1 基序号）
            foreach ($orderItems as $i => $it) {
                $ps = (int)$it['sub_of'];
                if ($ps > 0) {
                    $gk = isset($seqGroup[$ps]) ? $seqGroup[$ps] : '检查';
                    $groups[$gk]['idx'][] = $i;
                } elseif ($ps < 0) {
                    json_fail('开单明细参数错误');
                }
            }
            $groupList = array_values($groups);
        } else {
            $groupList = array(array('cat' => '', 'idx' => array_keys($orderItems)));
        }

        // ===== 逐组生成申请单（单号遵循原规则；循环查重保证多张同时创建不撞号） =====
        $typeCode = array('lab' => 'JY', 'imaging' => 'JC', 'procedure' => 'CZ', 'prescription' => 'CF');
        $typeTitle = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置单', 'prescription' => '处方单');
        $targets = array('lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'nurse', 'prescription' => 'pharmacy');
        $createdIds = array();
        $createdNos = array();
        $totalAll = 0;
        foreach ($groupList as $g) {
            // 组内主项目重新编号（子项 sub_of 引用同步改写为本组新序号）
            $localNo = 0;
            $mapSeq = array();   // 全局主项目序号 => 本组新序号
            $groupTotal = 0;
            foreach ($g['idx'] as $i) {
                if ((int)$orderItems[$i]['sub_of'] === 0) {
                    $localNo++;
                    $mapSeq[$itemSeq[$i]] = $localNo;
                    $groupTotal += (float)$orderItems[$i]['price'] * max(1, (int)$orderItems[$i]['quantity']);
                }
            }

            do {
                $orderNo = (isset($typeCode[$orderType]) ? $typeCode[$orderType] : 'DD') . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
            } while ((int)DB::val('order', 'SELECT COUNT(*) FROM orders WHERE order_no=?', array($orderNo)) > 0);

            $orderId = DB::insert('order', 'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, cat_name, doctor_id, doctor_name, total_amount, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], $orderType, $orderNo, $g['cat'],
                $u['id'], $u['name'], $groupTotal, 'open', now_str(),
            ));
            foreach ($g['idx'] as $i) {
                $it = $orderItems[$i];
                $sub = (int)$it['sub_of'];
                $newSub = ($sub > 0 && isset($mapSeq[$sub])) ? $mapSeq[$sub] : 0;
                DB::insert('order', 'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit_name, company_short, price, quantity, single_dose, frequency_name, route_name, need_nurse, sub_of, group_no, is_parent, parent_item_id, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                    $orderId, $visitId, $visit['patient_no'], $visit['flow_no'], $it['item_type'], $it['item_id'],
                    $it['item_name'], $it['spec'], $it['unit_name'], $it['company_short'], $it['price'], $it['quantity'],
                    $it['single_dose'], $it['frequency_name'], $it['route_name'], $it['need_nurse'], $newSub,
                    (int)$it['group_no'], (int)$it['is_parent'], (int)$it['parent_item_id'],
                    'open', $u['id'], $u['name'], now_str(),
                ));
            }

            // ===== 处方开单即减库存（按组处理） =====
            if ($orderType === 'prescription') {
                foreach ($g['idx'] as $i) {
                    $it = $orderItems[$i];
                    if ((int)$it['item_id'] > 0 && (int)$it['sub_of'] === 0) {
                        DB::exec('drug', 'UPDATE drugs SET qty = qty - ? WHERE id=?', array($it['quantity'], $it['item_id']));
                        DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                            $it['item_id'], -$it['quantity'], 'order_out', $orderNo, $u['name'], now_str(),
                        ));
                    }
                }
            }

            // ===== 站内消息 + 打印提醒（每张申请单一条，可独立处理/打印） =====
            $printUrl = '/api/print?action=order&order_id=' . $orderId;
            if ($orderType === 'imaging') {
                $msgTitle = ($g['cat'] !== '' && $g['cat'] !== '检查') ? '新的' . $g['cat'] . '申请单' : $typeTitle[$orderType];
            } else {
                $msgTitle = isset($typeTitle[$orderType]) ? '新的' . $typeTitle[$orderType] : '新的申请单';
            }
            if (isset($targets[$orderType])) {
                send_msg($targets[$orderType], 0,
                    $msgTitle,
                    '患者：' . $row['patient']['name'] . '（' . $visit['patient_no'] . '），流水号 ' . $visit['flow_no'] . '，请及时处理',
                    'order', $printUrl,
                    array('msg_type' => 'patient', 'patient_name' => $row['patient']['name'], 'visit_id' => $visitId));
            }

            $createdIds[] = $orderId;
            $createdNos[] = $orderNo;
            $totalAll += $groupTotal;
        }

        // ===== 皮试/途径联动处置单（仅处方开单且存在联动项时生成） =====
        // 与处方同一请求内完成写入；任一步失败即终止（此前处方已入库，
        // 由调用方收到错误后整体重开，避免半张联动单）。
        if ($orderType === 'prescription' && $autoDisp) {
            $autoTotal = 0;
            foreach ($autoDisp as $d) { $autoTotal += (float)$d['fee'] * (int)$d['qty']; }
            do {
                $autoOrderNo = 'CZ' . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
            } while ((int)DB::val('order', 'SELECT COUNT(*) FROM orders WHERE order_no=?', array($autoOrderNo)) > 0);

            $autoOrderId = DB::insert('order',
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, cat_name, doctor_id, doctor_name, total_amount, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $visit['patient_no'], $visit['flow_no'], 'procedure', $autoOrderNo, '',
                      $u['id'], $u['name'], $autoTotal, 'open', now_str()));

            foreach ($autoDisp as $dispId => $d) {
                DB::insert('order',
                    'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, price, quantity, unit_name, need_nurse, sub_of, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($autoOrderId, $visitId, $visit['patient_no'], $visit['flow_no'],
                          'procedure', $dispId, $d['name'], (float)$d['fee'], (int)$d['qty'],
                          '次', 1, 0, 'open', $u['id'], $u['name'], now_str()));
            }

            // 通知护士站执行 + 打印提醒
            $firstAuto = reset($autoDisp);
            send_msg('nurse', 0,
                '新的联动处置单（' . count($autoDisp) . '项）',
                '患者：' . $row['patient']['name'] . '（' . $visit['patient_no'] . '），流水号 ' . $visit['flow_no'] .
                    '，含皮试/注射类处置，请及时处理',
                'order', '/api/print?action=order&order_id=' . oid($autoOrderId),
                array('msg_type' => 'patient', 'patient_name' => $row['patient']['name'], 'visit_id' => $visitId));

            $createdIds[] = $autoOrderId;
            $createdNos[] = $autoOrderNo;
            $totalAll += $autoTotal;
        }

        json_ok(array(
            'order_id' => oid($createdIds[0]),
            'order_ids' => array_map('oid', $createdIds),
            'order_no' => $createdNos[0],
            'order_nos' => $createdNos,
            'total' => $totalAll,
        ), count($createdIds) > 1 ? '已按检查分类拆分为 ' . count($createdIds) . ' 张申请单' : '开单成功');
        break;

    /* ==================== 既往开具记录（互斥/复查二次确认） ==================== */
    // 说明：返回该患者历史上开具过的项目（同一项目只保留最近一次，含未缴费），
    //      前端在重复开具时提示「何时开具过，是否再次开具」（如复查场景）。
    case 'prev_items':
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
        break;

    /* ==================== 申请单/处方单打印 ==================== */
    case 'print':
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
        break;

    /* ==================== 就诊开单列表（病历处置区） ==================== */
    case 'visit_orders':
        $visitId = did(get('visit_id'));
        $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
        $out = array();
        foreach ($orders as $o) {
            $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $doneBy = '';
            foreach ($items as $it) {
                if ($it['executed_by']) $doneBy = $it['executed_by'];
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

    /* ==================== 删除开单（未缴费或已退费，恢复库存） ====================
     * 权限硬拦截：仅开单医生本人可删除/毁方自己的处方或申请单——
     * 多医生接诊下其他医生（含强制提交）一律拒绝，谁开单谁负责。 */
    case 'delete':
        $orderId = did(post('order_id'));
        if ($orderId <= 0) json_fail('参数无效');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order) json_fail('开单记录不存在');
        if ((int)$order['doctor_id'] !== (int)$u['id']) {
            json_fail('仅开单医生本人可删除该' . ($order['order_type'] === 'prescription' ? '处方' : '申请单') . '（开单医生：' . $order['doctor_name'] . '）');
        }
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
