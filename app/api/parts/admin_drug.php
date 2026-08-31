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
        $rows = DrugRepository::q('SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
        $html = '<div class="table-wrap"><table class="table"><thead><tr><th>名称</th>' .
            ($stype === 'route' ? '<th>需护士站处理</th><th>绑定计费处置</th>' : '') . '<th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            // 绑定处置名称回显
            $bindName = '';
            if (!empty($r['bind_disposal_item_id'])) {
                $bn = DrugRepository::val('SELECT name FROM disposal_items WHERE id=?', array((int)$r['bind_disposal_item_id']));
                $bindName = (string)$bn;
            }
            $html .= '<tr><td class="fw-600">' . e($r['name']) . '</td>' .
                ($stype === 'route'
                    ? '<td>' . ($r['is_nurse'] ? '<span class="badge badge-warning">是（护士站执行）</span>' : '<span class="badge badge-gray">否</span>') . '</td>'
                      . '<td>' . ($bindName !== '' ? '<span class="badge badge-primary">' . e($bindName) . '</span>' : '<span class="badge badge-gray">未绑定</span>') . '</td>'
                    : '') .
                '<td>' . ($u['role'] === 'admin'
                    ? '<div class="flex gap-4">' .
                '<button class="btn btn-outline btn-sm" onclick="editDrugSetting(\'' . $stype . '\',' . (int)$r['id'] . ',\'' . e($r['name']) . '\',' . (int)$r['is_nurse'] . ',' . (int)(isset($r['bind_disposal_item_id']) ? $r['bind_disposal_item_id'] : 0) . ',\'' . e($bindName) . '\')">编辑</button>' .
                '<button class="btn btn-outline btn-sm" onclick="delDrugSetting(' . (int)$r['id'] . ')">删除</button></div>'
                    : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        json_ok(array('html' => $html));
    }

    /* ==================== 保存药品设置 ==================== */
    if ($action === 'drugsetting_save') {
        $id = (int)post('id');
        $stype = post('stype');
        $name = post('name');
        $needNurse = (int)post('is_nurse', 0);
        $bindDisp = (int)post('bind_disposal_item_id', 0);
        // 绑定处置校验：必须为已审核通过的处置项目（0=不绑定）
        if ($bindDisp > 0) {
            $ex = DrugRepository::val("SELECT COUNT(*) FROM disposal_items WHERE id=? AND status='approved'", array($bindDisp));
            if (!$ex) json_fail('绑定的处置项目不存在或未通过审核');
        }
        if ($name === '') json_fail('请输入名称');
        // 非管理员：新增 / 修改提交需管理员审核（药品设置项由管理员统一管理）
        if ($u['role'] !== 'admin') {
            if ($id > 0) {
                DrugRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='drugsetting' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
            }
            $typeNames = array('category' => '药品分类', 'package' => '包装单位', 'form' => '药品剂型', 'freq' => '用药频次', 'route' => '给药途径');
            submit_audit('drugsetting', $id, ($typeNames[$stype] ?? $stype) . '：' . $name,
                '提交药品设置项（' . ($typeNames[$stype] ?? $stype) . '）：' . $name,
                array('data' => json_encode(array('id' => $id, 'stype' => $stype, 'name' => $name, 'is_nurse' => $needNurse, 'bind_disposal_item_id' => $bindDisp), JSON_UNESCAPED_UNICODE)));
            send_msg('admin', 0, '待审核提醒', '有新的药品设置项待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            json_ok(array(), '设置项已提交，待管理员审核');
        }
        if ($id > 0) {
            DrugRepository::exec('UPDATE drug_settings SET name=?, is_nurse=?, bind_disposal_item_id=? WHERE id=?', array($name, $needNurse, $bindDisp, $id));
        } else {
            DrugRepository::insert('INSERT INTO drug_settings(stype, name, is_nurse, bind_disposal_item_id, sort) VALUES(?,?,?,?,0)', array($stype, $name, $needNurse, $bindDisp));
        }
        json_ok(array(), '已保存');
    }

    /* ==================== 删除药品设置 ==================== */
    if ($action === 'drugsetting_delete') {
        $id = (int)post('id');
        $used = (int)DrugRepository::val('SELECT COUNT(*) FROM drugs WHERE route IN (SELECT name FROM drug_settings WHERE id=?) OR package_unit IN (SELECT name FROM drug_settings WHERE id=?) OR form IN (SELECT name FROM drug_settings WHERE id=?) OR frequency IN (SELECT name FROM drug_settings WHERE id=?) OR category IN (SELECT name FROM drug_settings WHERE id=?)', array($id, $id, $id, $id, $id));
        DrugRepository::exec('DELETE FROM drug_settings WHERE id=?', array($id));
        json_ok(array(), '已删除');
    }

    /* ==================== 药品信息列表 ==================== */
    if ($action === 'drug_list') {
        $rows = DrugRepository::q('SELECT * FROM drugs ORDER BY category, id');
        $rowsHtml = '<thead><tr>' .
            '<th>药品名称</th><th>通用名</th><th>厂家简称</th><th>分类</th><th>规格</th><th>剂型</th><th>频次</th><th>途径</th><th>库存</th><th>价格</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $rowsHtml .= '<tr data-cat="' . e($r['category']) . '">' .
                '<td class="fw-600">' . e($r['name']) . '</td>' .
                '<td class="fs-12">' . e($r['generic_name']) . '</td>' .
                '<td>' . e($r['vendor_short']) . '</td>' .
                '<td>' . e($r['category']) . '</td>' .
                '<td class="fs-12">' . e($r['spec']) . '</td>' .
                '<td>' . e($r['form']) . '</td>' .
                '<td class="fs-12">' . e($r['frequency']) . '</td>' .
                '<td class="fs-12">' . e($r['route']) . ($r['is_nurse'] ? '（护士站）' : '') . '</td>' .
                '<td>' . (int)$r['qty'] . '</td>' .
                '<td>¥' . money($r['price']) . '</td>' .
                '<td>' . ($r['status'] === 'approved' ? badge_html('success', '可用') : badge_html('warning', '待审核')) . '</td>' .
                '<td>' . ($u['role'] === 'admin'
                    ? '<div class="flex gap-4">' .
                    // 编辑按钮与「新增」共用 openDrugForm(id)
                    '<button class="btn btn-outline btn-sm" onclick="openDrugForm(' . (int)$r['id'] . ')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDrug(' . (int)$r['id'] . ')">删除</button></div>'
                    : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
        }
        $rowsHtml .= '</tbody>';
        $html = render_list_wrapper('共 ' . count($rows) . ' 种药品', '暂无药品，请先添加', $rowsHtml, 'drugCountDiv');
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
        $isAdmin = $u['role'] === 'admin';
        $finalStatus = $isAdmin ? 'approved' : 'pending';   // 非管理员提交需管理员审核
        $data = array(
            'generic_name' => post('generic_name'), 'category' => post('category'),
            'vendor' => post('vendor'), 'vendor_short' => post('vendor_short'),
            'package_unit' => post('package_unit'), 'spec' => post('spec'), 'form' => post('form'),
            'single_dose' => post('single_dose'), 'frequency' => post('frequency'),
            'route' => post('route'), 'price' => (float)post('price', 0), 'qty' => (int)post('qty', 0),
            'is_rx' => (int)post('is_rx', 0), 'is_limited' => (int)post('is_limited', 0),
            'note' => post('note'), 'is_nurse' => (int)post('is_nurse', 0),
            // 皮试联动：标记需皮试时必须关联已审核的皮试处置项目
            'is_skin_test' => (int)post('is_skin_test', 0),
            'skin_test_item_id' => (int)post('skin_test_item_id', 0),
            // 规格结构化：单剂量值/单位 + 包装数量/单位 + 单次使用数量
            'spec_dose' => (float)post('spec_dose', 0),
            'spec_dose_unit' => post('spec_dose_unit'),
            'spec_pack_qty' => (int)post('spec_pack_qty', 1),
            'spec_pack_unit' => post('spec_pack_unit'),
            'single_use_qty' => (float)post('single_use_qty', 1),
        );
        if ((int)$data['is_skin_test'] === 1) {
            $stOk = DrugRepository::val("SELECT COUNT(*) FROM disposal_items WHERE id=? AND status='approved'", array($data['skin_test_item_id']));
            if (!$stOk) json_fail('请关联有效的皮试处置项目（需已通过审核）');
        } else {
            $data['skin_test_item_id'] = 0;
        }
        if ($id > 0) {
            $set = array(); $params = array();
            foreach ($data as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
            $set[] = 'status=?'; $params[] = $finalStatus;
            $params[] = $id;
            DrugRepository::exec('UPDATE drugs SET ' . implode(',', $set) . ' WHERE id=?', $params);
            if ($isAdmin) {
                // 清理该药品的待审核记录（管理员保存即视为已通过）
                DrugRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            } else {
                DrugRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
                submit_audit('item_drug', $id, '修改药品：' . $name, '提交药品信息修改：' . $name);
                send_msg('admin', 0, '待审核提醒', '有新的药品修改待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            }
            json_ok(array(), $isAdmin ? '药品已保存' : '修改已提交，待管理员审核');
        }
        $params = array_values($data);   // 23 值（含规格结构化 5 列）
        $params[] = $finalStatus;
        $params[] = now_str();
        // INSERT 列与 $data 键顺序严格对应：
        // 16 业务列 + name + is_skin_test + skin_test_item_id + 规格结构化5列 + status + created_at
        $newId = DrugRepository::insert('INSERT INTO drugs(generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency, route, price, qty, is_rx, is_limited, note, is_nurse, name, is_skin_test, skin_test_item_id, spec_dose, spec_dose_unit, spec_pack_qty, spec_pack_unit, single_use_qty, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge(
                array_slice($params, 0, 16), array($name),
                array($params[16], $params[17]),
                array_slice($params, 18, 5),   // spec_dose..single_use_qty
                array_slice($params, 23)       // status, now_str
            )
        );
        if (!$isAdmin) {
            submit_audit('item_drug', $newId, '新增药品：' . $name, '提交新增药品：' . $name);
            send_msg('admin', 0, '待审核提醒', '有新的药品待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
        }
        json_ok(array(), $isAdmin ? '药品已添加，可直接开方使用' : '药品已提交，待管理员审核');
    }

    /* ==================== 删除药品 ==================== */
    if ($action === 'drug_delete') {
        $id = (int)post('id');
        // 引用检查：有关联处方/库存流水时禁止物理删除
        if ((int)DrugRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='prescription' AND item_id=?", array($id)) > 0) {
            json_fail('该药品已有处方记录，不能删除（可改为停用）');
        }
        if ((int)DrugRepository::val('SELECT COUNT(*) FROM inventory_trans WHERE drug_id=?', array($id)) > 0) {
            json_fail('该药品已有库存流水记录，不能删除（可改为停用）');
        }
        DrugRepository::exec('DELETE FROM drugs WHERE id=?', array($id));
        json_ok(array(), '药品已删除');
    }

    json_fail('未知操作');
}
