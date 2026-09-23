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
        // 角色锁定 type：检验科=lab、影像科=exam（仅 admin 自由选择），
        // 杜绝跨科室读取/篡改他科项目
        if ($u['role'] === 'lab') $type = 'lab';
        elseif ($u['role'] === 'imaging') $type = 'exam';
        else $type = get('type', 'lab');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $isAdmin = $u['role'] === 'admin';
        // v8.17.3 统一分页：page/size/kw/cat 服务端过滤，无限滚动分段加载；
        // 共享目录查询（includes/catalog_query.php）：管理端不过滤状态（含待审核/已禁用）
        $r = catalog_paged_rows($type, array(
            'kw' => get('kw', ''),
            'cat' => get('cat', ''),
            'is_group' => $type === 'lab' ? 0 : null,
            'status_sql' => '',
            'page' => get('page', 1),
            'pageSize' => get('size', 20),
        ));
        $total = $r['total'];
        $rows = $r['rows'];
        $kw = trim(get('kw', ''));
        $thead = ($type === 'lab')
            ? '<thead><tr><th>名称</th><th>分类</th><th>价格</th><th>单位</th><th>正常范围</th><th>状态</th><th>操作</th></tr></thead>'
            : '<thead><tr><th>名称</th><th>分类</th><th>价格</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>';
        $list = array();
        foreach ($rows as $r) {
            if ($type === 'lab') {
                $rowHtml = '<tr data-kind="single" data-cat="' . e($r['category']) . '">' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td>' . e($r['unit']) . '</td><td class="fs-12">' . e($r['normal_range']) . '</td>' .
                    '<td>' . item_status_badge((string)$r['status']) . '</td>' .
                    '<td>' . ($isAdmin
                        ? '<div class="flex gap-4">' .
                        '<button class="btn btn-outline btn-sm" onclick="openItemForm(' . (int)$r['id'] . ')">编辑</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="delItem(\'lab\',' . (int)$r['id'] . ')">删除</button></div>'
                        : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
            } else {
                $rowHtml = '<tr data-kind="single" data-cat="' . e($r['category']) . '">' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    '<td class="fs-12 text-muted">' . e(mb_substr($r['description'], 0, 20)) . '</td>' .
                    '<td>' . item_status_badge((string)$r['status']) . '</td>' .
                    '<td>' . ($isAdmin
                        ? '<div class="flex gap-4">' .
                        '<button class="btn btn-outline btn-sm" onclick="openItemForm(' . (int)$r['id'] . ')">编辑</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="delItem(\'exam\',' . (int)$r['id'] . ')">删除</button></div>'
                        : '<span class="text-muted fs-12">只读</span>') . '</td></tr>';
            }
            $list[] = $rowHtml;
        }
        $cats = array();
        list($page, $pageSize) = paged_params(20);
        if ($page === 1) {
            foreach (OrderRepository::q("SELECT name FROM item_categories WHERE ctype=? ORDER BY sort, id", array($type)) as $c) $cats[] = $c['name'];
        }
        json_ok(array(
            'list' => $list, 'total' => $total, 'has_more' => paged_has_more($page, $pageSize, $total), 'page' => $page, 'thead' => $thead,
            'cats' => $cats,
            'count_text' => ($type === 'lab' ? '检验项目共 ' : '检查项目共 ') . $total . ' 项' . ($kw !== '' ? '（搜索「' . $kw . '」）' : ''),
        ));
    }

    /* ==================== 检验组合表单（组名/分类/组价 + 成员多选） ==================== */
    /* ==================== 检验组合列表（组合管理左侧栏） ==================== */
    if ($action === 'lab_groups') {
        $groups = array();
        foreach (OrderRepository::q('SELECT g.id, g.name, g.category, g.price, COUNT(m.item_id) AS cnt FROM lab_items g ' .
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
        $g = OrderRepository::one('SELECT * FROM lab_items WHERE id=? AND is_group=1', array($id));
        if (!$g) json_fail('检验组合不存在');
        $members = array();
        foreach (OrderRepository::q('SELECT * FROM lab_items WHERE id IN (SELECT item_id FROM lab_group_members WHERE group_id=?) ORDER BY id', array($id)) as $m) {
            $members[] = array('id' => (int)$m['id'], 'name' => $m['name'], 'category' => $m['category'],
                'price' => (float)$m['price'], 'unit' => $m['unit'], 'normal_range' => $m['normal_range']);
        }
        json_ok(array('group' => array('id' => (int)$g['id'], 'name' => $g['name'], 'category' => $g['category'], 'price' => (float)$g['price']), 'members' => $members));
    }

    /* ==================== 可加入组合的独立单项（添加项目面板候选；一个项目可加入多个组合） ==================== */
    if ($action === 'lab_group_candidates') {
        $list = array();
        foreach (OrderRepository::q("SELECT id, name, category, price FROM lab_items WHERE is_group=0 ORDER BY category, id") as $r) {
            $list[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'price' => (float)$r['price']);
        }
        json_ok(array('list' => $list));
    }

    /* ==================== 组合添加/移除成员（实时保存，多对多） ==================== */
    if ($action === 'lab_group_add_item') {
        $gid = (int)post('group_id');
        $iid = (int)post('item_id');
        $g = OrderRepository::one('SELECT id FROM lab_items WHERE id=? AND is_group=1', array($gid));
        if (!$g) json_fail('检验组合不存在');
        $it = OrderRepository::one('SELECT id, name FROM lab_items WHERE id=? AND is_group=0', array($iid));
        if (!$it) json_fail('检验项目不存在');
        if (OrderRepository::one('SELECT 1 FROM lab_group_members WHERE group_id=? AND item_id=?', array($gid, $iid))) {
            json_fail('「' . $it['name'] . '」已在本组合中');
        }
        OrderRepository::insert('INSERT INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($gid, $iid));
        json_ok(array(), '已加入组合');
    }
    if ($action === 'lab_group_remove_item') {
        $gid = (int)post('group_id');
        $iid = (int)post('item_id');
        OrderRepository::exec('DELETE FROM lab_group_members WHERE group_id=? AND item_id=?', array($gid, $iid));
        json_ok(array(), '已从组合移除');
    }

    if ($action === 'lab_group_form') {
        $id = (int)req('id', 0);
        $r = $id ? OrderRepository::one('SELECT * FROM lab_items WHERE id=? AND is_group=1', array($id)) : array('category' => '', 'name' => '', 'price' => '0');
        if (!$r) {
            $r = array('category' => '', 'name' => '', 'price' => '0');
        }
        $cats = OrderRepository::q("SELECT name FROM item_categories WHERE ctype='lab' ORDER BY sort, id");
        $catOpts = '<option value="">请选择/输入分类</option>';
        foreach ($cats as $c) {
            $catOpts .= '<option value="' . e($c['name']) . '"' . ($r['category'] === $c['name'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        // 可选成员：独立检验项目（未被其他组占用）+ 本组当前成员
        $cands = OrderRepository::q('SELECT * FROM lab_items WHERE is_group=0 AND (parent_id=0 OR parent_id=?) ORDER BY category, id', array($id));
        $sel = array();
        if ($id) {
            foreach (OrderRepository::q('SELECT id FROM lab_items WHERE parent_id=?', array($id)) as $m) $sel[] = (int)$m['id'];
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
        if (trim((string)OrderRepository::val('SELECT name FROM lab_items WHERE is_group=1 AND name=? AND id<>?', array($name, $id))) !== '') {
            json_fail('组合名称「' . $name . '」已存在，请勿重复');
        }
        if ($id > 0) {
            OrderRepository::exec('UPDATE lab_items SET category=?, name=?, price=?, status=? WHERE id=? AND is_group=1', array($category, $name, $price, 'approved', $id));
            // 仅当显式提交成员列表（member_ids 非空串）时才重建成员——
            // 组合信息保存不应清空成员（修复保存后成员丢失）
            if ($memberParam !== null && $memberParam !== '') {
                OrderRepository::exec('DELETE FROM lab_group_members WHERE group_id=?', array($id));
                foreach ($memberIds as $mid) {
                    OrderRepository::insert('INSERT OR IGNORE INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($id, $mid));
                }
            }
            // 管理员保存即通过：清理该项目的待审核记录
            OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type IN ('item_lab','item_exam') AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            json_ok(array('id' => $id), '检验组合已保存（管理员添加免审核，可直接使用）');
        } else {
            // 管理员添加的项目免审核：直接可用，无需创建审核记录；允许先建空组合后补成员
            $newId = OrderRepository::insert("INSERT INTO lab_items(category, name, price, description, status, created_at, is_group) VALUES(?,?,?,?,?,?,1)", array(
                $category, $name, $price, '检验组合', 'approved', now_str(),
            ));
            foreach ($memberIds as $mid) {
                OrderRepository::insert('INSERT OR IGNORE INTO lab_group_members(group_id, item_id) VALUES(?,?)', array($newId, $mid));
            }
            json_ok(array('id' => $newId), '检验组合已添加，可直接开单使用');
        }
    }

    /* ==================== 删除检验组合（成员还原为独立项目） ==================== */
    if ($action === 'lab_group_delete') {
        $id = (int)post('id');
        $used = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type='lab' AND item_id=?", array($id));
        if ($used > 0) json_fail('该检验组合已有开单记录，不能删除（可将其成员停用）');
        OrderRepository::exec('DELETE FROM lab_group_members WHERE group_id=?', array($id));
        OrderRepository::exec('DELETE FROM lab_items WHERE id=? AND is_group=1', array($id));
        json_ok(array(), '检验组合已删除');
    }

    /* ==================== 项目表单（共享模块渲染） ==================== */
    if ($action === 'item_form') {
        // 表单弹窗通过 POST 提交 type/id，必须用 req() 兼容读取（否则编辑弹窗拿不到 id/type）
        // 角色锁定 type：检验科=lab、影像科=exam（仅 admin 自由选择）
        if ($u['role'] === 'lab') $type = 'lab';
        elseif ($u['role'] === 'imaging') $type = 'exam';
        else $type = req('type', 'lab');
        $id = (int)req('id', 0);
        json_ok(array('html' => form_item($type, $id)));
    }

    /* ==================== 保存项目 ==================== */
    if ($action === 'item_save') {
        // 角色锁定：检验科只能改检验项目、影像科只能改检查项目、
        // 药房无权改检验/检查项目——杜绝跨科室篡改字典
        if ($u['role'] === 'lab') $type = 'lab';
        elseif ($u['role'] === 'imaging') $type = 'exam';
        else $type = post('type', 'lab');   // admin 自由选择
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        $enabled = (int)post('enabled', 1);
        if ($name === '') json_fail('请填写项目名称');
        $isAdmin = $u['role'] === 'admin';
        $auditType = $type === 'lab' ? 'item_lab' : 'item_exam';
        // 启用开关：未勾选=禁用（医生开单列表不显示，已开单流程不受影响）；勾选按原审核规则
        if (!$enabled) {
            $finalStatus = 'disabled';
        } else {
            $finalStatus = $isAdmin ? 'approved' : 'pending';   // 非管理员提交需管理员审核
        }
        $content = '提交检验/检查项目：' . $name;
        // 审核预览快照（audits.data）：保存提交时的完整字段，发起者删除项目后
        // 已处理审核仍可在审核中心按原始内容预览追溯（audit_preview 优先用快照）
        $snapJson = json_encode(array(
            'type' => $type,
            'name' => $name,
            'category' => $category,
            'price' => $price,
            'unit' => post('unit'),
            'normal_range' => post('normal_range'),
            'critical_low' => post('critical_low'),
            'critical_high' => post('critical_high'),
            'description' => post('description'),
            'enabled' => $enabled,
            'status' => $finalStatus,
        ), JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            // 管理员编辑保存即通过；非管理员保存置 pending 并提交审核
            if ($type === 'lab') {
                OrderRepository::exec('UPDATE lab_items SET category=?, name=?, unit=?, price=?, normal_range=?, critical_low=?, critical_high=?, description=?, status=? WHERE id=?', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $finalStatus, $id,
                ));
            } else {
                OrderRepository::exec('UPDATE exam_items SET category=?, name=?, price=?, description=?, status=? WHERE id=?', array($category, $name, $price, post('description'), $finalStatus, $id));
            }
            if ($isAdmin) {
                // 清理该项目的待审核记录（管理员保存即视为已通过）
                OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type IN ('item_lab','item_exam') AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            } else {
                // 关闭旧审核（pending/rejected），提交新审核
                OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type=? AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $auditType, $id));
                submit_audit($auditType, $id, '修改' . ($type === 'lab' ? '检验' : '检查') . '项目：' . $name, $content, array('data' => $snapJson));
                send_msg('admin', 0, '待审核提醒', '有新的' . ($type === 'lab' ? '检验' : '检查') . '项目修改待审核：' . $name . '，请前往审核中心处理', '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
            }
            json_ok(array(), $isAdmin ? '项目已保存' : '修改已提交，待管理员审核');
        } else {
            // 管理员添加免审核；非管理员置 pending 并提交审核
            if ($type === 'lab') {
                $newId = OrderRepository::insert('INSERT INTO lab_items(category, name, unit, price, normal_range, critical_low, critical_high, description, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $finalStatus, now_str(),
                ));
            } else {
                $newId = OrderRepository::insert('INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
                    $category, $name, $price, post('description'), $finalStatus, now_str(),
                ));
            }
            if (!$isAdmin) {
                submit_audit($auditType, $newId, '新增' . ($type === 'lab' ? '检验' : '检查') . '项目：' . $name, $content, array('data' => $snapJson));
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
        $itemType = $type === 'lab' ? 'lab' : 'imaging';
        // 流程占用检查：仅当存在未完成（待缴费/已缴费未执行/登记/执行中/发药中）开单时禁止删除；
        // 已完成/终态的历史开单不影响删除（过期作废可删）
        $chk = item_delete_check($itemType, $id);
        if (!$chk['ok']) json_fail($chk['msg']);
        // 组合引用检查（配置绑定，删除将破坏组合定义）
        if ($type === 'lab' && (int)OrderRepository::val('SELECT COUNT(*) FROM lab_group_members WHERE item_id=?', array($id)) > 0) {
            json_fail('该检验项目已加入检验组合，不能删除（可先移除组合成员）');
        }
        OrderRepository::exec("DELETE FROM $table WHERE id=?", array($id));
        // 待审核的删除请求一并撤销（项目已不存在无需再审核）；
        // 已处理（通过/驳回/使用）的审核记录保留，供追溯预览
        $auditType = $type === 'lab' ? 'item_lab' : 'item_exam';
        OrderRepository::exec("DELETE FROM audits WHERE type=? AND ref_id=? AND status='pending'", array($auditType, $id));
        json_ok(array(), '项目已删除');
    }

    /* ==================== 项目分类管理 ==================== */
    if ($action === 'cat_list') {
        // 角色锁定 type：检验科=lab、影像科=exam（仅 admin 自由选择）
        if ($u['role'] === 'lab') $type = 'lab';
        elseif ($u['role'] === 'imaging') $type = 'exam';
        else $type = get('type', 'lab');
        $rows = OrderRepository::q('SELECT * FROM item_categories WHERE ctype=? ORDER BY sort, id', array($type));
        json_ok(array('list' => array_map(function ($c) use ($type) {
            return array('id' => (int)$c['id'], 'name' => $c['name'], 'type' => $type);
        }, $rows)));
    }

    if ($action === 'cat_add') {
        $type = post('type', 'lab');
        $name = trim((string)post('name', ''));
        if ($name === '') json_fail('请输入分类名称');
        $dup = (int)OrderRepository::val('SELECT COUNT(*) FROM item_categories WHERE ctype=? AND name=?', array($type, $name));
        if ($dup > 0) json_fail('该分类已存在');
        OrderRepository::insert('INSERT INTO item_categories(ctype, name, sort) VALUES(?,?,0)', array($type, $name));
        json_ok(array(), '分类已添加');
    }

    /** 分类重命名：同步更新该分类下全部检验/检查项目的分类字段（编辑即时生效） */
    if ($action === 'cat_rename') {
        $id = (int)post('id');
        $name = trim((string)post('name', ''));
        if ($name === '') json_fail('请输入分类名称');
        $cat = OrderRepository::one('SELECT * FROM item_categories WHERE id=?', array($id));
        if (!$cat) json_fail('分类不存在');
        $dup = (int)OrderRepository::val('SELECT COUNT(*) FROM item_categories WHERE ctype=? AND name=? AND id<>?', array($cat['ctype'], $name, $id));
        if ($dup > 0) json_fail('该分类已存在');
        $old = (string)$cat['name'];
        // 先改分类目录，再级联同步该项目表（lab_items / exam_items 按 ctype 区分）
        OrderRepository::exec('UPDATE item_categories SET name=? WHERE id=?', array($name, $id));
        if ($cat['ctype'] === 'exam') {
            OrderRepository::exec('UPDATE exam_items SET category=? WHERE category=?', array($name, $old));
        } else {
            OrderRepository::exec('UPDATE lab_items SET category=? WHERE category=?', array($name, $old));
        }
        json_ok(array('renamed' => 1), '分类已更名，该分类下项目已同步更新');
    }

    if ($action === 'cat_delete') {
        $id = (int)post('id');
        $cat = OrderRepository::one('SELECT * FROM item_categories WHERE id=?', array($id));
        if (!$cat) json_fail('分类不存在');
        $name = (string)$cat['name'];
        $table = $cat['ctype'] === 'exam' ? 'exam_items' : 'lab_items';
        $itemType = $cat['ctype'] === 'exam' ? 'imaging' : 'lab';
        // 级联处理该分类下全部项目：
        //  - 从未被开单（order_items 无引用）→ 视为该分类下的测试/占位项目，
        //    连同其测试结果记录（results）一并删除，避免残留孤儿项目无法删除
        //  - 已被开单（order_items 有引用）→ 真实历史数据保留，分类置空转未分类
        $cleaned = 0;
        foreach (OrderRepository::q("SELECT id FROM $table WHERE category=?", array($name)) as $it) {
            $iid = (int)$it['id'];
            $used = (int)OrderRepository::val(
                'SELECT COUNT(*) FROM order_items WHERE item_type=? AND item_id=?', array($itemType, $iid));
            if ($used > 0) {
                OrderRepository::exec("UPDATE $table SET category='' WHERE id=?", array($iid));
            } else {
                OrderRepository::exec('DELETE FROM results WHERE item_id=?', array($iid));
                OrderRepository::exec("DELETE FROM $table WHERE id=?", array($iid));
                $cleaned++;
            }
        }
        OrderRepository::exec('DELETE FROM item_categories WHERE id=?', array($id));
        json_ok(array('cleaned' => $cleaned), $cleaned > 0
            ? '分类已删除，已清理 ' . $cleaned . ' 个未被开单的测试项目，其余项目转为未分类'
            : '分类已删除，相关项目已转为未分类');
    }

    json_fail('未知操作');
}
