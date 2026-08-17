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
require_once APP_ROOT . '/app/includes/forms.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 发药队列（待发药 / 发药完成，需求20） ==================== */
    case 'queue':
        // 说明：orders 与 order_items 同库可 JOIN；患者信息跨库按 patient_no 逐条补充
        // 勾选【护士站执行】的处方由护士站执行（need_nurse=1），药房不显示
        $status = get('status', 'paid');
        if ($status === 'dispensed') {
            $where = "oi.item_type='prescription' AND oi.sub_of=0 AND oi.need_nurse=0 AND oi.status='dispensed'";
        } else {
            $where = "oi.item_type='prescription' AND oi.sub_of=0 AND oi.need_nurse=0 AND oi.status='paid'";
        }
        $rows = DB::q('order', "SELECT oi.*, o.order_no FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE $where ORDER BY oi.id DESC LIMIT 200");
        $title = $status === 'dispensed' ? '发药完成' : '待发药';
        $html = '<div class="fs-13 text-muted mb-8">' . $title . '：' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">💊</div>暂无' . $title . '处方</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>药品</th><th>处方单号</th><th>流水号</th><th>数量</th><th>开单医生</th>' .
                ($status === 'dispensed' ? '<th>发药药师</th><th>发药时间</th>' : '') .
                '<th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $p = DB::one('patient', 'SELECT name, gender, age FROM patients WHERE patient_no=?', array($r['patient_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . (int)($p ? $p['age'] : 0) . '岁</span></td>' .
                    '<td>' . e($r['item_name']) . (!empty($r['company_short']) ? '（' . e($r['company_short']) . '）' : '') . '</td>' .
                    '<td>' . e($r['order_no']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . (int)$r['quantity'] . ' ' . e($r['unit_name']) . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    ($status === 'dispensed' ? '<td>' . e($r['executed_by']) . '</td><td class="fs-12">' . e(substr($r['executed_at'], 5, 11)) . '</td>' : '') .
                    '<td>' . ($status === 'paid'
                        ? '<button class="btn btn-success btn-sm" onclick="dispenseDrug(' . (int)$r['id'] . ')">发药</button>'
                        : '<span class="badge badge-success">已发放</span>') . '</td></tr>';
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

    /* ==================== 新增药品（需求20：提交后需管理员审核） ==================== */
    case 'drug_form':
        json_ok(form_drug(0));
        break;

    case 'drug_save':
        $name = post('name');
        if ($name === '') json_fail('请填写药品名称');
        $data = array(
            'generic_name' => post('generic_name'), 'category' => post('category'),
            'vendor' => post('vendor'), 'vendor_short' => post('vendor_short'),
            'package_unit' => post('package_unit'), 'spec' => post('spec'), 'form' => post('form'),
            'single_dose' => post('single_dose'), 'frequency_name' => post('frequency_name'),
            'route_name' => post('route_name'), 'price' => (float)post('price', 0), 'qty' => (int)post('qty', 0),
            'is_rx' => (int)post('is_rx', 0), 'is_limited' => (int)post('is_limited', 0),
            'note' => post('note'), 'need_nurse' => (int)post('need_nurse', 0),
        );
        $params = array_values($data);
        $params[] = 'pending';
        $params[] = now_str();
        $newId = DB::insert('drug', 'INSERT INTO drugs(generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency_name, route_name, price, qty, is_rx, is_limited, note, need_nurse, name, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge(
            array_slice($params, 0, 16), array($name), array_slice($params, 16)
        ));
        DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
            'item_drug', $newId, '药品添加：' . $name,
            '药房 ' . $u['name'] . ' 提交新增药品「' . $name . '」（分类：' . $data['category'] . '，价格：¥' . money($data['price']) . '），请审核',
            'pending', $u['name'], $u['id'], now_str(),
        ));
        json_ok(array(), '药品已提交，待管理员审核通过后即可开方使用');
        break;

    /* ==================== 新增药品分类（需求20：药房可添加分类） ==================== */
    case 'category_form':
        $html = '<div class="form-group"><label class="form-label">药品分类名称 <span class="req">*</span></label>' .
            '<input class="input" id="f_cat_name" placeholder="如：生物制品"></div>' .
            '<div class="fs-12 text-muted">新增分类后可在新增药品时选择该分类。</div>';
        json_ok(array('html' => $html));
        break;

    case 'category_save':
        $name = post('name');
        if ($name === '') json_fail('请输入分类名称');
        $dup = DB::one('drug', "SELECT id FROM drug_settings WHERE stype='category' AND name=?", array($name));
        if ($dup) json_fail('该分类已存在');
        DB::insert('drug', "INSERT INTO drug_settings(stype, name, need_nurse, sort) VALUES('category',?,0,0)", array($name));
        json_ok(array(), '药品分类已添加');
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
