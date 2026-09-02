<?php
/**
 * ============================================================
 * parts/order/order_delete.php — 开单：删除
 * ============================================================
 * order_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function order_part_delete($u) {
    $orderId = did(post('order_id'));
    if ($orderId <= 0) json_fail('参数无效');
    $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
    if (!$order) json_fail('开单记录不存在');
    // 删除校验：开单人本人 + 开单所属病历（record_id）等于当前编辑病历——
    // 只有处于开单所在病历的可编辑状态下才可删除（前端 canDeleteOrder 同步拦截）
    $curRecordId = (int)post('record_id', 0);
    $orderRecId = (int)(isset($order['record_id']) ? $order['record_id'] : 0);
    if ((int)$order['doctor_id'] !== (int)$u['id']) {
        json_fail('仅开单医生本人可删除该' . ($order['order_type'] === 'prescription' ? '处方' : '申请单') . '（开单医生：' . $order['doctor_name'] . '）');
    }
    // ===== 统一上下文断言（SSOT 守卫）=====
    // 第一层根判定：当前是否有可写容器（无 → 熔断删除）；
    // 第二层归属判定：目标订单绑定的 record_id 必须等于活跃容器 id。
    $rowDel = get_visit_row($order['visit_id']);
    if (!$rowDel) json_fail('就诊记录不存在');
    $ctxDel = EmrContextResolver::assertCanWrite($rowDel['visit'], $u, EmrRepository::one('SELECT * FROM patient_records WHERE id=?', array($curRecordId > 0 ? $curRecordId : 0)) ?: null, $curRecordId > 0 ? $curRecordId : null);
    // 病历ID强关联：新数据（record_id>0）必须与当前编辑病历一致；旧数据（record_id=0）回退按医生归属
    $activeDelId = $ctxDel['active']['container_id'];
    if ($orderRecId > 0 && $curRecordId <= 0) {
        json_fail('缺少当前病历标识，无法校验删除归属');
    }
    if ($orderRecId > 0 && $curRecordId > 0 && $orderRecId !== $curRecordId) {
        json_fail('该开单不属于当前编辑的病历，不可删除（开单与病历强关联）');
    }
    $items = OrderRepository::q('SELECT * FROM order_items WHERE order_id=?', array($orderId));
    foreach ($items as $it) {
        // 允许删除：未缴费（open）或已退费（refunded）
        if (!in_array($it['status'], array('open', 'refunded'), true)) {
            json_fail('该开单已进入执行流程，不能删除（如需删除请先在收费处退费）');
        }
    }
    // 数据变更（库存恢复 + 级联删除）为复合写操作：原生事务保证原子性
    $pdoDel = DatabaseManager::getMain();
    $pdoDel->beginTransaction();
    try {
        // 恢复药品库存：仅未缴费的处方需要恢复（已退费的处方在退费时已恢复库存）
        if ($order['order_type'] === 'prescription') {
            foreach ($items as $it) {
                if ($it['item_id'] > 0 && (int)$it['sub_of'] === 0 && $it['status'] === 'open') {
                    OrderRepository::exec('UPDATE drugs SET qty = qty + ? WHERE id=?', array((int)$it['quantity'], $it['item_id']));
                    OrderRepository::insert('INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
                        $it['item_id'], (int)$it['quantity'], 'order_restore', $order['order_no'], $u['name'], now_str(),
                    ));
                }
            }
        }
        // 处方：级联删除自动生成的联动处置单（皮试/途径绑定，source_order_id 指向本处方）
        $autoOrders = array();
        if ($order['order_type'] === 'prescription') {
            $autoOrders = OrderRepository::q("SELECT * FROM orders WHERE source_order_id=? AND order_type='procedure'", array($orderId));
            foreach ($autoOrders as $ao) {
                $aoItems = OrderRepository::q('SELECT * FROM order_items WHERE order_id=?', array($ao['id']));
                foreach ($aoItems as $aoIt) {
                    if (!in_array($aoIt['status'], array('open', 'refunded'), true)) {
                        $pdoDel->rollBack();
                        json_fail('该处方的联动处置单已进入执行流程，不能自动删除（请先在收费处处理联动处置单）');
                    }
                }
            }
        }
        OrderRepository::exec('DELETE FROM order_items WHERE order_id=?', array($orderId));
        OrderRepository::exec('DELETE FROM orders WHERE id=?', array($orderId));
        foreach ($autoOrders as $ao) {
            OrderRepository::exec('DELETE FROM order_items WHERE order_id=?', array($ao['id']));
            OrderRepository::exec('DELETE FROM orders WHERE id=?', array($ao['id']));
        }
        $pdoDel->commit();
    } catch (Exception $ex) {
        if ($pdoDel->inTransaction()) $pdoDel->rollBack();
        json_fail('删除失败：' . $ex->getMessage());
    }
    json_ok(array(), '开单已删除' . ($order['order_type'] === 'prescription' ? '，药品库存已恢复' : '') . ($autoOrders ? '，联动处置单已同步删除' : ''));
    return;
}