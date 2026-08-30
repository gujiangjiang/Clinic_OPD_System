<?php
/**
 * ============================================================
 * parts/admin_item.php v1.1.0 — 管理端：检验/检查项目与分类
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. item_list / item_form / item_save / item_delete  项目管理
 *   2. cat_list / cat_add / cat_delete                  项目分类管理
 * 项目表单由 includes/forms.php 统一渲染（检验科/影像科共用）。
 * 新增项目默认待审核（pending），在审核中心通过后方可开单。
 * ============================================================ */

/**
 * 处理检验/检查项目管理动作
 * @param string $action 动作名
 */
function admin_part_item($action) {
    $u = Auth::user();

    /* ==================== 项目列表 ==================== */
    if ($action === 'item_list') {
        $type = get('type', 'lab');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $isAdmin = $u['role'] === 'admin';
        if ($type === 'lab') {
            // ===== 检验项目管理：主列表展示「全部检验项目」——所有单项
            // （含已加入组合的成员），是否成组与本列表无关；组合本体在
            // 「检验组合管理」中维护 =====
            $singles = DB::q("SELECT * FROM lab_items WHERE is_group=0 ORDER BY category, id");
            $rowsHtml = '<thead><tr>' .
                '<th>名称</th><th>分类</th><th>价格</th><th>单位</th><th>正常范围</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($singles as $r) {
                $rowsHtml .= '<tr data-kind="single" data-cat="' . e($r['category']) . '">' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td>' . e($r['unit']) . '</td><td class="fs-12">' . e($r['normal_range']) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? badge_html('success', '可用') : badge_html('warning', '待审核')) . '</td>' .
                    '<td>' . ($isAdmin
                        ? '<div class="flex gap-4">' .
                        '<button class="btn btn-outline btn-sm" onclick="openItemForm(' . (int)$r['id'] . ')">编辑</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="delItem(\'lab\',' . (int)$r['id'] . ')">删除</button></div>'
                        : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
            }
            $rowsHtml .= '</tbody>';
            $html = render_list_wrapper('检验项目共 ' . count($singles) . ' 项（全部单项，含已加入组合的成员；组合本体请在「检验组合管理」中维护）',
                '暂无检验项目，请先添加', $rowsHtml, 'labCountDiv');
            json_ok(array('html' => $html));
            return;
        } else {
            // ===== 检查项目管理：无成组逻辑，保持简单 =====
            $rows = DB::q("SELECT * FROM $table ORDER BY category, id");
            $rowsHtml = '<thead><tr>' .
                '<th>名称</th><th>分类</th><th>价格</th><th>描述</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $rowsHtml .= '<tr data-kind="single" data-cat="' . e($r['category']) . '">' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td class="fs-12 text-muted">' . e(mb_substr($r['description'], 0, 20)) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? badge_html('success', '可用') : badge_html('warning', '待审核')) . '</td>' .
                    '<td>' . ($isAdmin
                        ? '<div class="flex gap-4">' .
                        '<button class="btn btn-outline btn-sm" onclick="openItemForm(' . (int)$r['id'] . ')">编辑</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="delItem(\'exam\',' . (int)$r['id'] . ')">删除</button></div>'
                        : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
            }
            $rowsHtml .= '</tbody>';
            $html = render_list_wrapper('检查项目共 ' . count($rows) . ' 项', '暂无检查项目，请先添加', $rowsHtml, 'examCountDiv');
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 检验组合表单（组名/分类/组价 + 成员多选） ==================== */
    /* ==================== 检验组合列表（组合管理左侧栏） ==================== */
    if ($action === 'lab_groups') {
        $groups = array();
        foreach (DB::q('SELECT g.id, g.name, g.category, g.price, COUNT(m.item_id) AS cnt FROM lab_items g ' .
            'LEFT JOIN lab_group_members m ON m.group_id=g.id WHERE g.is_group=1 GROUP BY g.id ORDER BY g.category, g.id') as $g) {
            $groups[] = array(
                'id' => (int)$g['id'], 'name' => $g['name'], 'category' => $g['category'],
                'price' => (float)$g['price'], 'member_count' => (int)$g['cnt'],
            );
        }
        json_ok(array('list' => $groups));
    }

    /* ==================== 检验组合详情（组合 + 成员列表） ==================== */
    if ($action === 'lab_group_get') {
        $id = (int)req('id', 0);
        $g = DB::one('SELECT * FROM lab_items WHERE id=? AND is_group=1', array($id));
        if (!$g) json_fail('检验组合不存在');
        $members = array();
        foreach (DB::q('SELECT * FROM lab_items WHERE id IN (SELECT item_id FROM lab_group_members WHERE group_id=?) ORDER BY id', array($id)) as $m) {
            $members[] = array('id' => (int)$m['id'], 'name' => $m['name'], 'category' => $m['category'],
                'price' => (float)$m['price'], 'unit' => $m['unit'], 'normal_range' => $m['normal_range']);
        }
        json_ok(array('group' => array('id' => (int)$g['id'], 'name' => $g['name'], 'category' => $g['category'], 'price' => (float)$g['price']), 'members' => $members));
    }

    /* ==================== 可加入组合的独立单项（添加项目面板候选；一个项目可加入多个组合） ==================== */
    if ($action === 'lab_group_candidates') {
        $list = array();
        foreach (DB::q("SELECT id, name, category, price FROM lab_items WHERE is_group=0 ORDER BY category, id") as $r) {
            $list[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'price' => (float)$r['price']);
        }
        json_ok(array('list' => $list));
    }

    /* ==================== 组合添加/移除成员（实时保存，多对多） ==================== */
    if ($action === 'lab_group_add_item') {
        $gid = (int)post('group_id');
        $iid = (int)post('item_id');
        $g = DB::one('SELECT id FROM lab_items WHERE id=? AND is_group=1', array($gid));
        if (!$g) json_fail('检验组合不存在');
        $it = DB::one('SELECT id, name FROM lab_items WHERE id=? AND is_group=0', array($iid));
        if (!$it) json_fail('检验项目不存在');
        if (DB::one('SELECT 1 FROM lab_group_members WHERE group_id=? AND item_id=?', array($gid, $iid))) {
            json_fail('「' . $it['name'] . '」已在本组合中');
        }
        DB::insert('INSERT INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($gid, $iid));
        json_ok(array(), '已加入组合');
    }
    if ($action === 'lab_group_remove_item') {
        $gid = (int)post('group_id');
        $iid = (int)post('item_id');
        DB::exec('DELETE FROM lab_group_members WHERE group_id=? AND item_id=?', array($gid, $iid));
        json_ok(array(), '已从组合移除');
    }

    if ($action === 'lab_group_form') {
        $id = (int)req('id', 0);
        $r = $id ? DB::one('SELECT * FROM lab_items WHERE id=? AND is_group=1', array($id)) : array('category' => '', 'name' => '', 'price' => '0');
        if (!$r) {
            $r = array('category' => '', 'name' => '', 'price' => '0');
        }
        $cats = DB::q("SELECT name FROM item_categories WHERE ctype='lab' ORDER BY sort, id");
        $catOpts = '<option value="">请选择/输入分类</option>';
        foreach ($cats as $c) {
            $catOpts .= '<option value="' . e($c['name']) . '"' . ($r['category'] === $c['name'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        // 可选成员：独立检验项目（未被其他组占用）+ 本组当前成员
        $cands = DB::q('SELECT * FROM lab_items WHERE is_group=0 AND (parent_id=0 OR parent_id=?) ORDER BY category, id', array($id));
        $sel = array();
        if ($id) {
            foreach (DB::q('SELECT id FROM lab_items WHERE parent_id=?', array($id)) as $m) $sel[] = (int)$m['id'];
        }
        $memberBox = '';
        foreach ($cands as $c) {
            $checked = in_array((int)$c['id'], $sel, true) ? ' checked' : '';
            $memberBox .= '<label class="flex gap-4" style="font-size:13px;margin:0 14px 6px 0;cursor:pointer">' .
                '<input type="checkbox" class="grpMem" value="' . (int)$c['id'] . '"' . $checked . '> ' .
                e($c['name']) . ' <span class="text-muted">（¥' . money($c['price']) . '）</span></label>';
        }
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-group"><label class="form-label">检验组合名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '" placeholder="如：血细胞分析"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">所属分类</label>
                <select class="select" id="f_category">' . $catOpts . '</select></div>
            <div class="form-group"><label class="form-label">组合价格（元）<span class="req">*</span></label>
                <input class="input" type="number" step="0.01" min="0" id="f_price" value="' . e($r['price']) . '"
                placeholder="整体收费价格，如：5"></div>
        </div>
        <div class="form-group"><label class="form-label">组内检验项目（多选）<span class="req">*</span></label>
            <div class="flex" style="flex-wrap:wrap">' . ($memberBox !== '' ? $memberBox : '<span class="fs-12 text-muted">暂无可选检验项目，请先添加单个检验项目</span>') . '</div></div>
        <div class="fs-12 text-muted">组合项目按「组合价格」整体收费；医生开单时可单独开组内项目，也可直接开整个组合。</div>';
        json_ok(array('html' => $html));
    }

    /* ==================== 保存检验组合 ==================== */
    if ($action === 'lab_group_save') {
        $id = (int)post('id');
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        $memberIds = array();
        $memberParam = post('member_ids', null);
        foreach (explode(',', (string)$memberParam) as $m) {
            if ((int)$m > 0) $memberIds[] = (int)$m;
        }
        if ($name === '') json_fail('请填写检验组合名称');
        if (trim((string)DB::val('SELECT name FROM lab_items WHERE is_group=1 AND name=? AND id<>?', array($name, $id))) !== '') {
            json_fail('组合名称「' . $name . '」已存在，请勿重复');
        }
        if ($id > 0) {
            DB::exec('UPDATE lab_items SET category=?, name=?, price=?, status=? WHERE id=? AND is_group=1', array($category, $name, $price, 'approved', $id));
            // 仅当显式提交成员列表（member_ids 非空串）时才重建成员——
            // 组合信息保存不应清空成员（修复保存后成员丢失）
            if ($memberParam !== null && $memberParam !== '') {
                DB::exec('DELETE FROM lab_group_members WHERE group_id=?', array($id));
                foreach ($memberIds as $mid) {
                    DB::insert('INSERT OR IGNORE INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($id, $mid));
                }
            }
            // 管理员保存即通过：清理该项目的待审核记录
            DB::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type IN ('item_lab','item_exam') AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            json_ok(array('id' => $id), '检验组合已保存（管理员添加免审核，可直接使用）');
        } else {
            // 管理员添加的项目免审核：直接可用，无需创建审核记录；允许先建空组合后补成员
            $newId = DB::insert("INSERT INTO lab_items(category, name, price, description, status, created_at, is_group) VALUES(?,?,?,?,?,?,1)", array(
                $category, $name, $price, '检验组合', 'approved', now_str(),
            ));
            foreach ($memberIds as $mid) {
                DB::insert('INSERT OR IGNORE INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($newId, $mid));
            }
            json_ok(array('id' => $newId), '检验组合已添加，可直接开单使用');
        }
    }

    /* ==================== 删除检验组合（成员还原为独立项目） ==================== */
    if ($action === 'lab_group_delete') {
        $id = (int)post('id');
        $used = (int)DB::val("SELECT COUNT(*) FROM order_items WHERE item_type='lab' AND item_id=?", array($id));
        if ($used > 0) json_fail('该检验组合已有开单记录，不能删除（可将其成员停用）');
        DB::exec('DELETE FROM lab_group_members WHERE group_id=?', array($id));
        DB::exec('DELETE FROM lab_items WHERE id=? AND is_group=1', array($id));
        json_ok(array(), '检验组合已删除');
    }

    /* ==================== 项目表单（共享模块渲染） ==================== */
    if ($action === 'item_form') {
        // 表单弹窗通过 POST 提交 type/id，必须用 req() 兼容读取（否则编辑弹窗拿不到 id/type）
        $type = req('type', 'lab');
        $id = (int)req('id', 0);
        json_ok(array('html' => form_item($type, $id)));
    }

    /* ==================== 保存项目 ==================== */
    if ($action === 'item_save') {
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        if ($name === '') json_fail('请填写项目名称');
        $isAdmin = $u['role'] === 'admin';
        $auditType = $type === 'lab' ? 'item_lab' : 'item_exam';
        $finalStatus = $isAdmin ? 'approved' : 'pending';   // 非管理员提交需管理员审核
        $content = '提交检验/检查项目：' . $name;
        if ($id > 0) {
            // 管理员编辑保存即通过；非管理员保存置 pending 并提交审核
            if ($type === 'lab') {
                DB::exec('UPDATE lab_items SET category=?, name=?, unit=?, price=?, normal_range=?, critical_low=?, critical_high=?, description=?, status=? WHERE id=?', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $finalStatus, $id,
                ));
            } else {
                DB::exec('UPDATE exam_items SET category=?, name=?, price=?, description=?, status=? WHERE id=?', array($category, $name, $price, post('description'), $finalStatus, $id));
            }
            if ($isAdmin) {
                // 清理该项目的待审核记录（管理员保存即视为已通过）
                DB::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type IN ('item_lab','item_exam') AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            } else {
                // 关闭旧审核（pending/rejected），提交新审核
                DB::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type=? AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $auditType, $id));
                submit_audit($auditType, $id, '修改' . ($type === 'lab' ? '检验' : '检查') . '项目：' . $name, $content);
                send_msg('admin', 0, '待审核提醒', '有新的' . ($type === 'lab' ? '检验' : '检查') . '项目修改待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            }
            json_ok(array(), $isAdmin ? '项目已保存' : '修改已提交，待管理员审核');
        } else {
            // 管理员添加免审核；非管理员置 pending 并提交审核
            if ($type === 'lab') {
                $newId = DB::insert('INSERT INTO lab_items(category, name, unit, price, normal_range, critical_low, critical_high, description, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $finalStatus, now_str(),
                ));
            } else {
                $newId = DB::insert('INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
                    $category, $name, $price, post('description'), $finalStatus, now_str(),
                ));
            }
            if (!$isAdmin) {
                submit_audit($auditType, $newId, '新增' . ($type === 'lab' ? '检验' : '检查') . '项目：' . $name, $content);
                send_msg('admin', 0, '待审核提醒', '有新的' . ($type === 'lab' ? '检验' : '检查') . '项目待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            }
            json_ok(array(), $isAdmin ? '项目已添加，可直接开单使用' : '项目已提交，待管理员审核');
        }
        json_ok(array(), '项目已保存');
    }

    /* ==================== 删除项目 ==================== */
    if ($action === 'item_delete') {
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        DB::exec("DELETE FROM $table WHERE id=?", array($id));
        json_ok(array(), '项目已删除');
    }

    /* ==================== 项目分类管理 ==================== */
    if ($action === 'cat_list') {
        $type = get('type', 'lab');
        $rows = DB::q('SELECT * FROM item_categories WHERE ctype=? ORDER BY sort, id', array($type));
        json_ok(array('list' => array_map(function ($c) {
            return array('id' => (int)$c['id'], 'name' => $c['name']);
        }, $rows)));
    }

    if ($action === 'cat_add') {
        $type = post('type', 'lab');
        $name = post('name');
        if ($name === '') json_fail('请输入分类名称');
        DB::insert('INSERT INTO item_categories(ctype, name, sort) VALUES(?,?,0)', array($type, $name));
        json_ok(array(), '分类已添加');
    }

    if ($action === 'cat_delete') {
        $id = (int)post('id');
        DB::exec('DELETE FROM item_categories WHERE id=?', array($id));
        json_ok(array(), '分类已删除');
    }

    json_fail('未知操作');
}
