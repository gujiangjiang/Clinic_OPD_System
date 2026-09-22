<?php
/**
 * ============================================================
 * parts/order_read.php — 开单：读取（目录/既往/打印/就诊开单列表）
 * ============================================================
 * order.php 按功能拆分的一部分，动作：catalog/prev_items/print/
 * visit_orders
 * ============================================================ */

/**
 * 开单流程节点（操作人+时间）：供开单详情/病历流程展示。
 * · 开单 = 开单医生 / 创建时间
 * · 缴费 = 收费员 / 缴费时间（payments 表，无则未缴费）
 * · 登记 = 首个执行操作人 / 时间（lab/imaging 登记环节；处方无此步）
 * · 发药(完成) = 执行操作人 / 时间（发药或执行完成时写入）
 * 返回 [{label, operator, time, done}]
 */
function order_part_read($action) {
    $u = Auth::user();

    if ($action === 'catalog') {
        $type = get('type', 'lab');
        if (catalog_table_name($type) === '') $type = 'lab';
        // 分页：前端滚动分段加载（infiniteList），首屏 1 页，滚到底自动续加载；
        // 关键字搜索 / 检验筛选走服务端过滤（避免分页下仅过滤已加载页的旧问题）
        $page = max(1, (int)get('page', 1));
        $pageSize = max(1, min(100, (int)get('size', 20)));
        // 共享目录查询（includes/catalog_query.php）：四类项目分页+搜索+筛选+行映射
        $opts = array(
            'kw' => get('kw', ''),
            'cat' => get('cat', ''),
            'page' => $page,
            'pageSize' => $pageSize,
        );
        if ($type === 'lab') {
            $f = get('f', '');   // lab: single=单个 / group=组合（空=全部）
            if ($f === 'single') $opts['is_group'] = 0;
            elseif ($f === 'group') $opts['is_group'] = 1;
        } elseif ($type === 'prescription') {
            // 处方：库存为 0 的药品不显示（缺货不可开具，原前端过滤下沉到服务端）
            $opts['status_sql'] = "status='approved' AND qty > 0";
        }
        $r = catalog_paged_query($type, $opts);
        $list = $r['list'];
        $total = $r['total'];
        // 联动字典 / 组合映射：仅随首页返回（后续分页无需重复携带）
        if ($page <= 1) {
            $skinIds = array();
            foreach ($list as $it) {
                if (!empty($it['skin_test_item_id'])) $skinIds[(int)$it['skin_test_item_id']] = true;
            }
            $dicts = catalog_link_dicts(true, $skinIds);
            $resp = array('list' => $list, 'total' => $total, 'has_more' => ($page * $pageSize) < $total);
            if ($type === 'lab') $resp['lab_map'] = catalog_lab_map();
            $resp['link_dicts'] = $dicts;
            json_ok($resp);
            return;
        }
        json_ok(array('list' => $list, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
        return;
    }

    if ($action === 'prev_items') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 科室数据隔离：医生仅可查看其就诊科室/本人接诊过的就诊既往开单
        if (!visit_dept_authorized($row['visit'], $u)) json_fail('无权限查看该就诊的既往开单');
        $patientNo = $row['visit']['patient_no'];
        $type = get('type', 'lab');
        $rows = OrderRepository::q("SELECT oi.item_id, oi.item_name, o.created_at, o.order_no FROM order_items oi
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

    if ($action === 'visit_orders') {
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        // 科室数据隔离：医生仅可查看其就诊科室/本人接诊过的就诊开单
        if (!visit_dept_authorized($row['visit'], $u)) json_fail('无权限查看该就诊的开单');
        // 排序：按开单时间正序；同一皮试开单拆出的皮试处方/正式处方由开单侧先建皮试后建正式，
        // 自然 id 序即「皮试在前、正式随后」（不干扰其他处方相对顺序）
        $orders = OrderRepository::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id ASC', array($visitId));
        $out = array();
        foreach ($orders as $o) {
            $items = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
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
                    $ph = in_placeholders($itemIds);
                    $resRows = OrderRepository::q("SELECT id, order_item_id FROM results WHERE order_item_id IN ($ph)", $itemIds);
                    $resIds = array(); $resToItem = array();
                    foreach ($resRows as $rr) { $resIds[] = (int)$rr['id']; $resToItem[(int)$rr['id']] = (int)$rr['order_item_id']; }
                    if ($resIds) {
                        $ph2 = in_placeholders($resIds);
                        // 每个结果取最新一份有效报告
                        foreach (OrderRepository::q("SELECT result_id, MAX(id) AS rid FROM reports WHERE result_id IN ($ph2) AND status<>'withdrawn' GROUP BY result_id", $resIds) as $rp) {
                            $itemId = isset($resToItem[(int)$rp['result_id']]) ? $resToItem[(int)$rp['result_id']] : 0;
                            if ($itemId > 0) $reportMap[$itemId] = (int)$rp['rid'];
                        }
                    }
                }
            }
            $out[] = array(
                // 混淆串：前端删除/打印外链回传时后端统一 did 解码
                'id' => oid($o['id']), 'order_no' => $o['order_no'], 'order_type' => $o['order_type'],
                // 皮试单标记：需皮试药品开单拆分的皮试处方/皮试处置（导航与正文置前展示）
                'is_skin_test' => (int)(isset($o['is_skin_test']) ? $o['is_skin_test'] : 0),
                // 检查分类名称快照：检查申请单按分类拆分后，前端动态显示「XX申请单」
                'category_name' => isset($o['category_name']) ? (string)$o['category_name'] : '',
                'status' => order_agg_status($o['order_type'], $items),
                'total_amount' => (float)$o['total_amount'], 'doctor_name' => $o['doctor_name'],
                // 开单医生 id：多医生接诊下病历正文按医生归属展示已开项目、
                // 删除/毁方按钮仅对开单医生本人可见（后端 delete 亦有硬拦截）
                'doctor_id' => (int)$o['doctor_id'],
                // 开单所在病历（首诊/续写/会诊）：前端按记录归属展示开单，杜绝跨病历串显示
                'record_id' => (int)(isset($o['record_id']) ? $o['record_id'] : 0),
                // 开单科室固化快照（打印/展示不随转科漂移）
                'dept_id' => (int)(isset($o['dept_id']) ? $o['dept_id'] : 0),
                'dept_name' => (string)(isset($o['dept_name']) ? $o['dept_name'] : ''),
                'created_at' => $o['created_at'], 'done_by' => $doneBy,
                // 流程节点（操作人+时间）：开单/缴费/登记/发药(或执行完成)
                'flow' => order_flow_steps($o, $items),
                'items' => array_map(function ($it) use ($reportMap) {
                    // 扩展字段：处方在病历正文/打印中的所见即所得展示需要剂量/用法/途径等；
                    // group_no/is_parent 供成组医嘱树形展示（子药缩进、组内要素仅主药行一次）；
                    // item_status/report_id 供检验/检查开单详情展示登记/报告状态与查看报告入口
                    return array(
                        'id'             => oid($it['id']),
                        'item_name'      => $it['item_name'],
                        'quantity'       => (int)$it['quantity'],
                        'unit'           => isset($it['unit']) ? (string)$it['unit'] : '',
                        'spec'           => $it['spec'],
                        'single_dose'    => $it['single_dose'],
                        'frequency' => $it['frequency'],
                        'route'     => $it['route'],
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
