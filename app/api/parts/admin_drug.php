<?php
/**
 * ============================================================
 * parts/admin_drug.php v1.1.0 — 管理端：药品设置与药品信息
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. drugsetting_list / drugsetting_save / drugsetting_delete  药品基础设置
 *      （分类/包装单位/剂型/用药频次/给药途径，途径含需护士站处理）
 *   2. drug_list / drug_form / drug_save / drug_delete           药品信息
 * 药品表单由 includes/forms.php 统一渲染（药房共用）。
 * ============================================================ */

/**
 * 处理药品管理动作
 * @param string $action 动作名
 */
function admin_part_drug($action) {
    $u = Auth::user();

    /* ==================== 药品设置列表 ==================== */
    if ($action === 'drugsetting_list') {
        $stype = get('stype', 'category');
        $rows = DB::q('drug', 'SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
        $html = '<div class="table-wrap"><table class="table"><thead><tr><th>名称</th>' .
            ($stype === 'route' ? '<th>需护士站处理</th>' : '') . '操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><td class="fw-600">' . e($r['name']) . '</td>' .
                ($stype === 'route' ? '<td>' . ($r['need_nurse'] ? '<span class="badge badge-warning">是（护士站执行）</span>' : '<span class="badge badge-gray">否</span>') . '</td>' : '') .
                '<td><div class="flex gap-4">' .
                '<button class="btn btn-outline btn-sm" onclick="editDrugSetting(\'' . $stype . '\',' . (int)$r['id'] . ',\'' . e($r['name']) . '\',' . (int)$r['need_nurse'] . ')">编辑</button>' .
                '<button class="btn btn-outline btn-sm" onclick="delDrugSetting(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
        }
        $html .= '</tbody></table></div>';
        json_ok(array('html' => $html));
    }

    /* ==================== 保存药品设置 ==================== */
    if ($action === 'drugsetting_save') {
        $id = (int)post('id');
        $stype = post('stype');
        $name = post('name');
        $needNurse = (int)post('need_nurse', 0);
        if ($name === '') json_fail('请输入名称');
        if ($id > 0) {
            DB::exec('drug', 'UPDATE drug_settings SET name=?, need_nurse=? WHERE id=?', array($name, $needNurse, $id));
        } else {
            DB::insert('drug', 'INSERT INTO drug_settings(stype, name, need_nurse, sort) VALUES(?,?,?,0)', array($stype, $name, $needNurse));
        }
        json_ok(array(), '已保存');
    }

    /* ==================== 删除药品设置 ==================== */
    if ($action === 'drugsetting_delete') {
        $id = (int)post('id');
        $used = (int)DB::val('drug', 'SELECT COUNT(*) FROM drugs WHERE route_name IN (SELECT name FROM drug_settings WHERE id=?) OR package_unit IN (SELECT name FROM drug_settings WHERE id=?) OR form IN (SELECT name FROM drug_settings WHERE id=?) OR frequency_name IN (SELECT name FROM drug_settings WHERE id=?) OR category IN (SELECT name FROM drug_settings WHERE id=?)', array($id, $id, $id, $id, $id));
        DB::exec('drug', 'DELETE FROM drug_settings WHERE id=?', array($id));
        json_ok(array(), '已删除');
    }

    /* ==================== 药品信息列表 ==================== */
    if ($action === 'drug_list') {
        $rows = DB::q('drug', 'SELECT * FROM drugs ORDER BY category, id');
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 种药品</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无药品，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>药品名称</th><th>通用名</th><th>厂家简称</th><th>分类</th><th>规格</th><th>剂型</th><th>频次</th><th>途径</th><th>库存</th><th>价格</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td class="fs-12">' . e($r['generic_name']) . '</td>' .
                    '<td>' . e($r['vendor_short']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td class="fs-12">' . e($r['spec']) . '</td>' .
                    '<td>' . e($r['form']) . '</td>' .
                    '<td class="fs-12">' . e($r['frequency_name']) . '</td>' .
                    '<td class="fs-12">' . e($r['route_name']) . ($r['need_nurse'] ? '（护士站）' : '') . '</td>' .
                    '<td>' . (int)$r['qty'] . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    // 编辑按钮与「新增」共用 openDrugForm(id)
                    '<button class="btn btn-outline btn-sm" onclick="openDrugForm(' . (int)$r['id'] . ')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDrug(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 药品表单（共享模块渲染） ==================== */
    if ($action === 'drug_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（否则编辑弹窗空白）
        $id = (int)req('id', 0);
        json_ok(form_drug($id));
    }

    /* ==================== 保存药品 ==================== */
    if ($action === 'drug_save') {
        $id = (int)post('id');
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
        if ($id > 0) {
            $set = array(); $params = array();
            foreach ($data as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
            $params[] = $id;
            DB::exec('drug', 'UPDATE drugs SET ' . implode(',', $set) . ' WHERE id=?', $params);
            json_ok(array(), '药品已保存');
        }
        // 新增药品：待审核
        $params = array_values($data);
        $params[] = 'pending';
        $params[] = now_str();
        $newId = DB::insert('drug', 'INSERT INTO drugs(generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency_name, route_name, price, qty, is_rx, is_limited, note, need_nurse, name, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge(
            array_slice($params, 0, 16), array($name), array_slice($params, 16)
        ));
        DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
            'item_drug', $newId, '药品添加：' . $name,
            '新增药品「' . $name . '」（分类：' . $data['category'] . '，价格：¥' . money($data['price']) . '），请审核',
            'pending', $u['name'], $u['id'], now_str(),
        ));
        json_ok(array(), '药品已添加，请到【审核中心】审核后即可开方');
    }

    /* ==================== 删除药品 ==================== */
    if ($action === 'drug_delete') {
        $id = (int)post('id');
        DB::exec('drug', 'DELETE FROM drugs WHERE id=?', array($id));
        json_ok(array(), '药品已删除');
    }

    json_fail('未知操作');
}
