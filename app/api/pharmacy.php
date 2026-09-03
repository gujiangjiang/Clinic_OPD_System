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

    /* ==================== 发药队列 ==================== */
    case 'queue':
        $status = get('status', 'paid');
        $statusWhere = ($status === 'dispensed')
            ? "oi.item_type='prescription' AND oi.sub_of=0 AND oi.is_nurse=0 AND oi.status='dispensed'"
            : "oi.item_type='prescription' AND oi.sub_of=0 AND oi.is_nurse=0 AND oi.status='paid'";
        $rows = OrderRepository::q("SELECT oi.*, o.order_no FROM order_items oi
            LEFT JOIN orders o ON o.id=oi.order_id
            WHERE $statusWhere ORDER BY oi.id DESC", array());
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 条记录</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">💊</div>暂无待处理处方</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>药品</th><th>剂量</th><th>用量</th>' .
                ($status === 'dispensed' ? '<th>发药药师</th><th>发药时间</th>' : '') . '<th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $p = PatientRepository::byPatientNo($r['patient_no']);
                $subs = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? AND group_no=? AND is_parent=0 ORDER BY id', array((int)$r['order_id'], (int)$r['group_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '—') . '</td>' .
                    '<td>' . e($r['item_name']) . ($subs ? '（含' . implode('', array_map(function ($s, $i) use ($subs) {
                        return ($i > 0 ? '、' : '') . e($s['item_name']);
                    }, $subs, array_keys($subs))) . '）' : '') . '</td>' .
                    '<td class="fs-12">' . e($r['single_dose']) . ' ' . e($r['frequency']) . ' ' . e($r['route']) . '</td>' .
                    '<td>' . (int)$r['quantity'] . e($r['unit']) . '</td>';
                if ($status === 'dispensed') {
                    $html .= '<td>' . e($r['executed_by']) . '</td><td class="fs-12">' . e(substr($r['executed_at'], 5, 11)) . '</td>';
                }
                $html .= '<td>' .
                    ($status === 'paid'
                        ? '<button class="btn btn-primary btn-sm" onclick="dispenseDrug(\'' . oid($r['id']) . '\')">发药</button>'
                        : '<span class="badge badge-success">已发药</span>') .
                    '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 发药操作 ==================== */
    case 'dispense':
        $itemId = did(post('item_id'));
        $it = OrderRepository::itemById($itemId);
        if (!$it || $it['item_type'] !== 'prescription' || $it['status'] !== 'paid') {
            json_fail('处方不存在或状态异常');
        }
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            OrderRepository::updateItem($itemId, array('status' => 'dispensed', 'executed_by' => $u['name'], 'executed_at' => now_str()));
            $pdo->commit();
            if ($it['doctor_id'] > 0) {
                $pName = PatientRepository::byPatientNo($it['patient_no']);
                send_msg('doctor', $it['doctor_id'],
                    '药品已发：' . $it['item_name'],
                    '药剂师 ' . $u['name'] . ' 已发放患者「' . ($pName ? $pName['name'] : '') . '」（' . $it['patient_no'] . '）的药品「' . $it['item_name'] . '」×' . (int)$it['quantity'],
                    '', '',
                    array('msg_type' => 'patient', 'patient_name' => $pName ? $pName['name'] : '', 'visit_id' => (int)$it['visit_id']));
            }
            json_ok(array(), '发药成功');
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('发药失败：' . $ex->getMessage());
        }
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
        );
        if ($id > 0) {
            DrugRepository::update($id, $data);
            DrugRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status IN ('pending','rejected')",
                array($u['name'], now_str(), $id));
            json_ok(array(), '药品已更新');
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
        if ($change <= 0) json_fail('数量必须大于 0');
        $drug = DrugRepository::byId($drugId);
        if (!$drug) json_fail('药品不存在');
        if ($type === 'out') {
            if ((int)$drug['qty'] < $change) json_fail('库存不足（当前库存 ' . (int)$drug['qty'] . '）');
            DrugRepository::restoreStock($drugId, -$change);
        } else {
            DrugRepository::restoreStock($drugId, $change);
        }
        DrugRepository::createInventoryTrans($drugId, $type === 'out' ? -$change : $change, $type, post('note', ''), $u['name']);
        json_ok(array('qty' => (int)$drug['qty'] + ($type === 'out' ? -$change : $change)), '库存已更新');
        break;

    default:
        json_fail('未知操作');
}