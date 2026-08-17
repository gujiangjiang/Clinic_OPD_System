<?php
/**
 * ============================================================
 * pharmacy.php — 药房接口
 * ============================================================
 * 说明：
 * 1. 医生开方即减库存，删除处方/退费恢复库存（见 order/cashier）
 * 2. 患者缴费后处方进入【待发药】，药房发药后通知开单医生
 * 3. 库存管理：入库/出库记录库存流水（inventory_trans）
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 待发药队列（HTML） ==================== */
    case 'queue':
        // 说明：orders 与 order_items 同库可 JOIN；患者信息跨库按 patient_no 逐条补充
        $rows = DB::q('order', "SELECT oi.*, o.order_no FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.item_type='prescription' AND oi.sub_of=0 AND oi.status='paid'
            ORDER BY oi.id DESC LIMIT 200");
        $html = '<div class="fs-13 text-muted mb-8">待发药：' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">💊</div>暂无待发药处方</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>药品</th><th>处方单号</th><th>流水号</th><th>数量</th><th>开单医生</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $p = DB::one('patient', 'SELECT name, gender, age FROM patients WHERE patient_no=?', array($r['patient_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . (int)($p ? $p['age'] : 0) . '岁</span></td>' .
                    '<td>' . e($r['item_name']) . (!empty($r['company_short']) ? '（' . e($r['company_short']) . '）' : '') . '</td>' .
                    '<td>' . e($r['order_no']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . (int)$r['quantity'] . ' ' . e($r['unit_name']) . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    '<td><button class="btn btn-success btn-sm" onclick="dispenseDrug(' . (int)$r['id'] . ')">发药</button></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 发药 ==================== */
    case 'dispense':
        $itemId = (int)post('item_id');
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'prescription' || $it['status'] !== 'paid') {
            json_fail('处方不存在或状态异常');
        }
        DB::exec('order', "UPDATE order_items SET status='dispensed', executed_by=?, executed_at=? WHERE id=?", array($u['name'], now_str(), $itemId));
        if ($it['doctor_id'] > 0) {
            send_msg('doctor', $it['doctor_id'],
                '药品已发：' . $it['item_name'],
                '药剂师 ' . $u['name'] . ' 已发放患者（' . $it['patient_no'] . '）的药品「' . $it['item_name'] . '」×' . (int)$it['quantity'],
                '', '');
        }
        json_ok(array(), '发药成功');
        break;

    /* ==================== 库存列表（HTML） ==================== */
    case 'inventory':
        // 说明：inventory_trans 位于 order 库（跨库不可 JOIN），库存直接读 drugs.qty
        $rows = DB::q('drug', 'SELECT * FROM drugs ORDER BY category, id');
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

    /* ==================== 库存变动（入库/出库） ==================== */
    case 'stock':
        $drugId = (int)post('drug_id');
        $qty = (int)post('qty');
        $type = post('type', 'in'); // in 入库 / out 出库
        if ($qty <= 0) json_fail('数量必须大于0');
        $drug = DB::one('drug', 'SELECT * FROM drugs WHERE id=?', array($drugId));
        if (!$drug) json_fail('药品不存在');
        $change = ($type === 'in') ? $qty : -$qty;
        if ($change < 0 && (int)$drug['qty'] + $change < 0) {
            json_fail('出库数量超过当前库存');
        }
        DB::exec('drug', 'UPDATE drugs SET qty = qty + ? WHERE id=?', array($change, $drugId));
        DB::insert('order', 'INSERT INTO inventory_trans(drug_id, qty_change, type, ref, operator, created_at) VALUES(?,?,?,?,?,?)', array(
            $drugId, $change, $type === 'in' ? 'stock_in' : 'stock_out', post('note', ''), $u['name'], now_str(),
        ));
        json_ok(array('qty' => (int)$drug['qty'] + $change), '库存已更新');
        break;

    default:
        json_fail('未知操作');
}
