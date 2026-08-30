<?php
/**
 * ============================================================
 * parts/order_write.php — 开单：写入（提交/删除）
 * ============================================================
 * order.php 按功能拆分的一部分，动作：submit 提交开单、delete 删除
 * ============================================================ */

function order_part_write($action) {
    $u = Auth::user();

    if ($action === 'submit') {
        $visitId = did(post('visit_id'));
        $orderType = post('order_type', 'lab');
        $nurseReq = (int)post('nurse_required', 0);
        // 开单所在病历文书（首诊/续写/会诊）——用于开单与病历强关联展示
        $recId = (int)post('record_id', 0);
        // 开单科室固化：优先取前端所在科室，否则回退医生当前科室（打印/展示不随转科漂移）
        $deptId = (int)post('dept_id', 0);
        $deptName = '';
        if ($deptId > 0) {
            $dn = DB::one('dept', 'SELECT name FROM departments WHERE id=?', array($deptId));
            if ($dn) $deptName = (string)$dn['name'];
        }
        if ($deptName === '') {
            $curRow = DB::one('user', 'SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
            $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
            if ($deptId > 0) {
                $dn2 = DB::one('dept', 'SELECT name FROM departments WHERE id=?', array($deptId));
                if ($dn2) $deptName = (string)$dn2['name'];
            }
        }
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
        // ===== 病历完整性校验：开检验/检查/处置/处方前，本人病历必须已完善并保存 =====
        // （主诉/现病史/初步诊断为必填，与前端 isRecordComplete 一致）
        $myRec = DB::one('medical', 'SELECT emr_data, record_type FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array($visitId, $u['id']));
        if (!$myRec) {
            json_fail('请先保存本人病历后再开单');
        }
        $myEmr = emr_merge_defaults(emr_normalize(json_decode($myRec['emr_data'], true)), emr_default_data(null));
        if ($myRec['record_type'] === 'progress') {
            $progC = trim((string)(isset($myEmr['progress']['content']) ? $myEmr['progress']['content'] : ''));
            $hasDiag = !empty($myEmr['diagnoses']);
            if ($progC === '' || !$hasDiag) {
                json_fail('请先完善并保存本人病历（病历续写内容与初步诊断）后再开单');
            }
        } else {
            $cc = trim((string)(isset($myEmr['chief_complaint']['symptom']) ? $myEmr['chief_complaint']['symptom'] : ''));
            $pi = trim((string)(isset($myEmr['history_present']['content']) ? $myEmr['history_present']['content'] : ''));
            $hasDiag = !empty($myEmr['diagnoses']);
            if ($cc === '' || $pi === '' || !$hasDiag) {
                json_fail('请先完善并保存本人病历（主诉、现病史与初步诊断）后再开单');
            }
        }

        // ===== 药品库存预检 + 组装明细 =====
        // 安全：价格一律以服务端数据库权威值为准（不信任前端提交的 price），
        // 防止医生篡改金额（0 元开单 / 高价开单）
        $orderItems = array();
        $total = 0;
        foreach ($items as $i => $it) {
            $itemId = (int)(isset($it['item_id']) ? $it['item_id'] : 0);
            $qty = max(1, (int)(isset($it['quantity']) ? $it['quantity'] : 1));
            $price = 0;
            $subOf = (int)(isset($it['sub_of']) ? $it['sub_of'] : 0);
            $needNurse = 0;
            $skinChoice = '';
            $routeBindId = 0;
            if ($orderType === 'prescription' && $itemId > 0) {
                $drug = DB::one('drug', 'SELECT * FROM drugs WHERE id=?', array($itemId));
                if (!$drug || $drug['status'] !== 'approved') {
                    json_fail('药品不存在或未通过审核：' . (isset($it['item_name']) ? $it['item_name'] : ''));
                }
                $price = (float)$drug['price'];   // 权威价格
                if ($subOf === 0) {
                    if ((int)$drug['qty'] < $qty) {
                        json_fail('药品【' . $drug['name'] . '】库存不足（当前库存 ' . (int)$drug['qty'] . '）');
                    }
                    // 【护士站执行】逐项独立设置（默认取管理员设置的 need_nurse，医生可自由修改）
                    $needNurse = (isset($it['need_nurse']) && (int)$it['need_nurse'] === 1) ? 1 : 0;

                    // ===== 皮试判定（阻断式）：需皮试药品必须由医生明确选择方案 =====
                    if ((int)$drug['need_skin_test'] === 1) {
                        $choice = isset($skinChoices[$i]) ? strtolower(trim((string)$skinChoices[$i])) : '';
                        if ($choice !== 'yes' && $choice !== 'no') {
                            json_fail('【' . $drug['name'] . '】为需皮试药品，请先选择本次处置方案（需要皮试 / 免试）');
                        }
                        $skinChoice = $choice;
                    }
                    // ===== 给药途径 → 绑定计费处置（如 静脉输液 → 静脉输液费）=====
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
                }
            } elseif ($orderType === 'procedure') {
                // 处置：是否需护士站处置按「单项」独立设置（默认取管理员设置的 need_nurse，医生可逐项修改）
                $needNurse = (isset($it['need_nurse']) && (int)$it['need_nurse'] === 1) ? 1 : 0;
            }
            // ===== 项目存在性校验 + 权威核价（非处方类）：防止空名明细混入病历/打印 =====
            if ($orderType !== 'prescription' && $subOf === 0 && $itemId > 0) {
                $catTable = array(
                    'lab' => array('lab', 'lab_items', 'price'),
                    'imaging' => array('lab', 'exam_items', 'price'),
                    'procedure' => array('disp', 'disposal_items', 'fee'),
                );
                if (isset($catTable[$orderType])) {
                    $itemRow = DB::one($catTable[$orderType][0],
                        'SELECT name, status, ' . $catTable[$orderType][2] . ' AS p FROM ' . $catTable[$orderType][1] . ' WHERE id=?',
                        array($itemId));
                    if (!$itemRow || $itemRow['status'] !== 'approved') {
                        json_fail('开单项目不存在或未通过审核，请刷新后重试');
                    }
                    $price = (float)$itemRow['p'];
                }
            }
            // 皮试标注写入药名（随明细持久化：病历/打印/药房队列全链路可见）
            // 安全：项目名以服务端权威值为准（防存储型 XSS / 名称篡改），
            // 处方取 drugs.name、检验/检查/处置取对应表 name
            $rxName = isset($it['item_name']) ? $it['item_name'] : '';
            if ($orderType === 'prescription' && $itemId > 0) {
                $rxName = isset($drug) ? $drug['name'] : $rxName;
            } elseif ($orderType !== 'prescription' && $subOf === 0 && $itemId > 0) {
                $rxName = isset($itemRow) ? $itemRow['name'] : $rxName;
            }
            if ($orderType === 'prescription' && $skinChoice !== '') {
                $rxName .= $skinChoice === 'yes' ? '(需要皮试)' : '(无需皮试)';
            }
            // ===== 结构化剂量：单次剂量展示串 + 数量不足校验（主药/子医嘱统一） =====
            // 所需数量 = 剂量/单剂量值 向上取整；数量不足直接拦截（医生可手动改数量，
            // 但不得低于该剂量所需），与前端自动计算逻辑一致（逻辑闭环）。
            $singleDoseShow = '';
            $needQty = 0;
            if ($orderType === 'prescription' && $itemId > 0 && isset($drug)) {
                $sdose = (float)$drug['spec_dose'];
                if ($sdose > 0) {
                    $doseVal = (float)(isset($it['dose']) ? $it['dose'] : 0);
                    $doseUnit = trim((string)(isset($it['dose_unit']) ? $it['dose_unit'] : ''));
                    $doseVal = round($doseVal, 4);
                    if ($doseVal > 0) {
                        $needQty = max(1, (int)ceil($doseVal / $sdose));
                        if ((int)$qty < $needQty) {
                            json_fail('【' . $drug['name'] . '】数量不足：该剂量需 ' . $needQty . ' ' .
                                ($drug['spec_pack_unit'] !== '' && $drug['spec_pack_unit'] !== null ? $drug['spec_pack_unit'] : '个') . '，请修改数量');
                        }
                        // 单次剂量展示串：如 1g / 110ml / 0.7g
                        $singleDoseShow = rtrim(rtrim(number_format($doseVal, 4, '.', ''), '0'), '.') . $doseUnit;
                    }
                }
            }
            if ($singleDoseShow === '') {
                $singleDoseShow = isset($it['dose']) ? (string)$it['dose'] : '';
            }
            $orderItems[] = array(
                'item_type' => $orderType, 'item_id' => $itemId,
                'item_name' => $rxName,
                'spec' => isset($it['spec']) ? $it['spec'] : '',
                'unit_name' => isset($it['unit_name']) ? $it['unit_name'] : '',
                'company_short' => isset($it['company_short']) ? $it['company_short'] : '',
                'price' => $price, 'quantity' => $qty,
                'single_dose' => $singleDoseShow, 'frequency_name' => isset($it['frequency']) ? $it['frequency'] : '',
                'route_name' => isset($it['route']) ? $it['route'] : '',
                'need_nurse' => $needNurse, 'sub_of' => $subOf,
            );
            $total += $price * $qty;   // 主药与子医嘱均独立计费
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
                }
                $groupTotal += (float)$orderItems[$i]['price'] * max(1, (int)$orderItems[$i]['quantity']);   // 主药与子医嘱均计费
            }

            do {
                $orderNo = (isset($typeCode[$orderType]) ? $typeCode[$orderType] : 'DD') . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
            } while ((int)DB::val('order', 'SELECT COUNT(*) FROM orders WHERE order_no=?', array($orderNo)) > 0);

            $orderId = DB::insert('order', 'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, cat_name, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $visit['patient_no'], $visit['flow_no'], $orderType, $orderNo, $g['cat'],
                $u['id'], $u['name'], $recId, $deptId, $deptName, $groupTotal, 'open', now_str(),
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

            // ===== 处方开单即减库存（按组处理，原子条件更新防并发竞态） =====
            if ($orderType === 'prescription') {
                foreach ($g['idx'] as $i) {
                    $it = $orderItems[$i];
                    if ((int)$it['item_id'] > 0) {
                        // 原子条件更新：仅当库存充足时扣减，避免 TOCTOU 竞态
                        // 预检（line 前段）仅作快速提示，此处才是最终校验
                        $affected = DB::exec('drug', 'UPDATE drugs SET qty = qty - ? WHERE id=? AND qty >= ?',
                            array($it['quantity'], $it['item_id'], $it['quantity']));
                        if (!$affected) {
                            json_fail('药品【' . $it['item_name'] . '】库存不足（并发扣减），请重试');
                        }
                        DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                            $it['item_id'], -$it['quantity'], 'order_out', $orderNo, $u['name'], now_str(),
                        ));
                    }
                }
            }

            // ===== 站内消息 + 打印提醒（每张申请单一条，可独立处理/打印） =====
            $printUrl = '/api/print?action=order&order_id=' . oid($orderId);
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
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, cat_name, doctor_id, doctor_name, record_id, dept_id, dept_name, total_amount, status, created_at, source_order_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($visitId, $visit['patient_no'], $visit['flow_no'], 'procedure', $autoOrderNo, '',
                      $u['id'], $u['name'], $recId, $deptId, $deptName, $autoTotal, 'open', now_str(), $orderId));

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
        return;
    }

    if ($action === 'delete') {
        $orderId = did(post('order_id'));
        if ($orderId <= 0) json_fail('参数无效');
        $order = DB::one('order', 'SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order) json_fail('开单记录不存在');
        // 删除校验：开单人本人 + 开单所属病历（record_id）等于当前编辑病历——
        // 只有处于开单所在病历的可编辑状态下才可删除（前端 canDeleteOrder 同步拦截）
        $curRecordId = (int)post('record_id', 0);
        $orderRecId = (int)(isset($order['record_id']) ? $order['record_id'] : 0);
        if ((int)$order['doctor_id'] !== (int)$u['id']) {
            json_fail('仅开单医生本人可删除该' . ($order['order_type'] === 'prescription' ? '处方' : '申请单') . '（开单医生：' . $order['doctor_name'] . '）');
        }
        // 病历ID强关联：新数据（record_id>0）必须与当前编辑病历一致；旧数据（record_id=0）回退按医生归属
        if ($orderRecId > 0 && ($curRecordId <= 0 || $orderRecId !== $curRecordId)) {
            json_fail('该开单不属于当前编辑的病历，不可删除（开单与病历强关联）');
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
        // 处方：级联删除自动生成的联动处置单（皮试/途径绑定，source_order_id 指向本处方）
        $autoOrders = array();
        if ($order['order_type'] === 'prescription') {
            $autoOrders = DB::q('order', "SELECT * FROM orders WHERE source_order_id=? AND order_type='procedure'", array($orderId));
            foreach ($autoOrders as $ao) {
                $aoItems = DB::q('order', 'SELECT * FROM order_items WHERE order_id=?', array($ao['id']));
                foreach ($aoItems as $aoIt) {
                    if (!in_array($aoIt['status'], array('open', 'refunded'), true)) {
                        json_fail('该处方的联动处置单已进入执行流程，不能自动删除（请先在收费处处理联动处置单）');
                    }
                }
            }
        }
        DB::exec('order', 'DELETE FROM order_items WHERE order_id=?', array($orderId));
        DB::exec('order', 'DELETE FROM orders WHERE id=?', array($orderId));
        foreach ($autoOrders as $ao) {
            DB::exec('order', 'DELETE FROM order_items WHERE order_id=?', array($ao['id']));
            DB::exec('order', 'DELETE FROM orders WHERE id=?', array($ao['id']));
        }
        json_ok(array(), '开单已删除' . ($order['order_type'] === 'prescription' ? '，药品库存已恢复' : '') . ($autoOrders ? '，联动处置单已同步删除' : ''));
        return;
    }
}
