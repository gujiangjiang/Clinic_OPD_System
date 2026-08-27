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
            ($stype === 'route' ? '<th>需护士站处理</th><th>绑定计费处置</th>' : '') . '<th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            // 绑定处置名称回显
            $bindName = '';
            if (!empty($r['bind_disposal_item_id'])) {
                $bn = DB::val('disp', 'SELECT name FROM disposal_items WHERE id=?', array((int)$r['bind_disposal_item_id']));
                $bindName = (string)$bn;
            }
            $html .= '<tr><td class="fw-600">' . e($r['name']) . '</td>' .
                ($stype === 'route'
                    ? '<td>' . ($r['need_nurse'] ? '<span class="badge badge-warning">是（护士站执行）</span>' : '<span class="badge badge-gray">否</span>') . '</td>'
                      . '<td>' . ($bindName !== '' ? '<span class="badge badge-primary">' . e($bindName) . '</span>' : '<span class="badge badge-gray">未绑定</span>') . '</td>'
                    : '') .
                '<td>' . ($u['role'] === 'admin'
                    ? '<div class="flex gap-4">' .
                '<button class="btn btn-outline btn-sm" onclick="editDrugSetting(\'' . $stype . '\',' . (int)$r['id'] . ',\'' . e($r['name']) . '\',' . (int)$r['need_nurse'] . ',' . (int)(isset($r['bind_disposal_item_id']) ? $r['bind_disposal_item_id'] : 0) . ',\'' . e($bindName) . '\')">编辑</button>' .
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
        $needNurse = (int)post('need_nurse', 0);
        $bindDisp = (int)post('bind_disposal_item_id', 0);
        // 绑定处置校验：必须为已审核通过的处置项目（0=不绑定）
        if ($bindDisp > 0) {
            $ex = DB::val('disp', "SELECT COUNT(*) FROM disposal_items WHERE id=? AND status='approved'", array($bindDisp));
            if (!$ex) json_fail('绑定的处置项目不存在或未通过审核');
        }
        if ($name === '') json_fail('请输入名称');
        // 非管理员：新增 / 修改提交需管理员审核（药品设置项由管理员统一管理）
        if ($u['role'] !== 'admin') {
            if ($id > 0) {
                DB::exec('core', "UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='drugsetting' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
            }
            $typeNames = array('category' => '药品分类', 'package' => '包装单位', 'form' => '药品剂型', 'freq' => '用药频次', 'route' => '给药途径');
            DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, data, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
                'drugsetting', $id, ($typeNames[$stype] ?? $stype) . '：' . $name,
                '提交药品设置项（' . ($typeNames[$stype] ?? $stype) . '）：' . $name,
                json_encode(array('id' => $id, 'stype' => $stype, 'name' => $name, 'need_nurse' => $needNurse, 'bind_disposal_item_id' => $bindDisp), JSON_UNESCAPED_UNICODE),
                'pending', $u['name'], $u['id'], now_str(),
            ));
            send_msg('admin', 0, '待审核提醒', '有新的药品设置项待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            json_ok(array(), '设置项已提交，待管理员审核');
        }
        if ($id > 0) {
            DB::exec('drug', 'UPDATE drug_settings SET name=?, need_nurse=?, bind_disposal_item_id=? WHERE id=?', array($name, $needNurse, $bindDisp, $id));
        } else {
            DB::insert('drug', 'INSERT INTO drug_settings(stype, name, need_nurse, bind_disposal_item_id, sort) VALUES(?,?,?,?,0)', array($stype, $name, $needNurse, $bindDisp));
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
        $html = '<div class="fs-13 text-muted mb-8" id="drugCountDiv">共 ' . count($rows) . ' 种药品</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无药品，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>药品名称</th><th>通用名</th><th>厂家简称</th><th>分类</th><th>规格</th><th>剂型</th><th>频次</th><th>途径</th><th>库存</th><th>价格</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr data-cat="' . e($r['category']) . '">' .
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
                    '<td>' . ($r['status'] === 'approved' ? badge_html('success', '可用') : badge_html('warning', '待审核')) . '</td>' .
                    '<td>' . ($u['role'] === 'admin'
                        ? '<div class="flex gap-4">' .
                        // 编辑按钮与「新增」共用 openDrugForm(id)
                        '<button class="btn btn-outline btn-sm" onclick="openDrugForm(' . (int)$r['id'] . ')">编辑</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="delDrug(' . (int)$r['id'] . ')">删除</button></div>'
                        : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
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
        $isAdmin = $u['role'] === 'admin';
        $finalStatus = $isAdmin ? 'approved' : 'pending';   // 非管理员提交需管理员审核
        $data = array(
            'generic_name' => post('generic_name'), 'category' => post('category'),
            'vendor' => post('vendor'), 'vendor_short' => post('vendor_short'),
            'package_unit' => post('package_unit'), 'spec' => post('spec'), 'form' => post('form'),
            'single_dose' => post('single_dose'), 'frequency_name' => post('frequency_name'),
            'route_name' => post('route_name'), 'price' => (float)post('price', 0), 'qty' => (int)post('qty', 0),
            'is_rx' => (int)post('is_rx', 0), 'is_limited' => (int)post('is_limited', 0),
            'note' => post('note'), 'need_nurse' => (int)post('need_nurse', 0),
            // 皮试联动：标记需皮试时必须关联已审核的皮试处置项目
            'need_skin_test' => (int)post('need_skin_test', 0),
            'skin_test_item_id' => (int)post('skin_test_item_id', 0),
        );
        if ((int)$data['need_skin_test'] === 1) {
            $stOk = DB::val('disp', "SELECT COUNT(*) FROM disposal_items WHERE id=? AND status='approved'", array($data['skin_test_item_id']));
            if (!$stOk) json_fail('请关联有效的皮试处置项目（需已通过审核）');
        } else {
            $data['skin_test_item_id'] = 0;
        }
        if ($id > 0) {
            $set = array(); $params = array();
            foreach ($data as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
            $set[] = 'status=?'; $params[] = $finalStatus;
            $params[] = $id;
            DB::exec('drug', 'UPDATE drugs SET ' . implode(',', $set) . ' WHERE id=?', $params);
            if ($isAdmin) {
                // 清理该药品的待审核记录（管理员保存即视为已通过）
                DB::exec('core', "UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            } else {
                DB::exec('core', "UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_drug' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
                DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
                    'item_drug', $id, '修改药品：' . $name, '提交药品信息修改：' . $name, 'pending', $u['name'], $u['id'], now_str(),
                ));
                send_msg('admin', 0, '待审核提醒', '有新的药品修改待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            }
            json_ok(array(), $isAdmin ? '药品已保存' : '修改已提交，待管理员审核');
        }
        $params = array_values($data);   // 18 值（含 need_skin_test/skin_test_item_id）
        $params[] = $finalStatus;
        $params[] = now_str();
        // INSERT 列与 $data 键顺序严格对应：16 业务列 + name + need_skin_test + skin_test_item_id + status + created_at
        $newId = DB::insert('drug', 'INSERT INTO drugs(generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency_name, route_name, price, qty, is_rx, is_limited, note, need_nurse, name, need_skin_test, skin_test_item_id, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge(
                array_slice($params, 0, 16), array($name),
                array($params[16], $params[17]), // need_skin_test, skin_test_item_id
                array_slice($params, 18)         // status, now_str
            )
        );
        if (!$isAdmin) {
            DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
                'item_drug', $newId, '新增药品：' . $name, '提交新增药品：' . $name, 'pending', $u['name'], $u['id'], now_str(),
            ));
            send_msg('admin', 0, '待审核提醒', '有新的药品待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
        }
        json_ok(array(), $isAdmin ? '药品已添加，可直接开方使用' : '药品已提交，待管理员审核');
    }

    /* ==================== 删除药品 ==================== */
    if ($action === 'drug_delete') {
        $id = (int)post('id');
        DB::exec('drug', 'DELETE FROM drugs WHERE id=?', array($id));
        json_ok(array(), '药品已删除');
    }

    json_fail('未知操作');
}
