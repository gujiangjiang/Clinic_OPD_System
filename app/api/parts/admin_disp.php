<?php
/**
 * ============================================================
 * parts/admin_disp.php v1.1.0 — 管理端：处置项目管理
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. disposal_list   处置项目列表（HTML）
 *   2. disposal_form   新增/编辑处置表单（HTML）
 *   3. disposal_save   保存处置（名称/费用/描述备注）
 *   4. disposal_delete 删除处置项目
 * 新增处置项目默认待审核，管理员审核通过后方可开单。
 * ============================================================ */

/**
 * 处理处置项目管理动作
 * @param string $action 动作名
 */
function admin_part_disp($action) {
    $u = Auth::user();

    /* ==================== 处置项目列表 ==================== */
    if ($action === 'disposal_list') {
        // v8.17.3 统一分页：page/size/kw 服务端过滤，无限滚动分段加载
        list($page, $pageSize) = paged_params(20);
        $kw = trim(get('kw', ''));
        $where = "1=1";
        $params = array();
        if ($kw !== '') { $where .= " AND name LIKE ?"; $params[] = '%' . $kw . '%'; }
        $total = (int)OrderRepository::val("SELECT COUNT(*) FROM disposal_items WHERE $where", $params);
        $rows = OrderRepository::q("SELECT * FROM disposal_items WHERE $where ORDER BY id LIMIT ? OFFSET ?",
            paged_suffix($params, $page, $pageSize));
        $thead = '<thead><tr>' .
            '<th>处置名称</th><th>费用</th><th>需护士站处置</th><th>描述备注</th><th>状态</th><th>操作</th></tr></thead>';
        $list = array();
        foreach ($rows as $r) {
            $list[] = '<tr><td class="fw-600">' . e($r['name']) . '</td><td>¥' . money($r['fee']) . '</td>' .
                '<td>' . ((int)$r['is_nurse'] === 1 ? badge_html('warning', '是') : badge_html('gray', '否')) . '</td>' .
                '<td class="fs-12 text-muted">' . e($r['description']) . '</td>' .
                '<td>' . item_status_badge((string)$r['status']) . '</td>' .
                '<td><div class="flex gap-4">' .
                // 编辑按钮与「新增」共用 openDisposalForm(id)
                '<button class="btn btn-outline btn-sm" onclick="openDisposalForm(' . (int)$r['id'] . ')">编辑</button>' .
                '<button class="btn btn-outline btn-sm" onclick="delDisposal(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
        }
        json_ok(array('list' => $list, 'total' => $total, 'has_more' => paged_has_more($page, $pageSize, $total), 'page' => $page, 'thead' => $thead,
            'count_text' => '共 ' . $total . ' 个处置项目' . ($kw !== '' ? '（搜索「' . $kw . '」）' : '')));
    }

    /* ==================== 处置表单 ==================== */
    if ($action === 'disposal_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（否则编辑弹窗空白）
        $id = (int)req('id', 0);
        $r = $id ? OrderRepository::one('SELECT * FROM disposal_items WHERE id=?', array($id)) : array('name' => '', 'fee' => '0', 'description' => '', 'is_nurse' => 0, 'status' => '');
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        ' . form_enabled_switch(isset($r['status']) ? $r['status'] : '') . '
        <div class="form-group"><label class="form-label">处置名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '" placeholder="如：清创缝合、换药"></div>
        <div class="form-group"><label class="form-label">费用（元）</label>
            <input class="input" type="number" step="0.01" min="0" id="f_fee" value="' . e($r['fee']) . '"></div>
        <div class="form-group"><label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_nurse"' . ((int)$r['is_nurse'] === 1 ? ' checked' : '') . '> 需护士站处置（开单时默认勾选，医生可逐项修改）</label></div>
        <div class="form-group"><label class="form-label">描述备注</label>
            <textarea class="textarea" id="f_desc" rows="3">' . e($r['description']) . '</textarea></div>';
        json_ok(array('html' => $html));
    }

    /* ==================== 保存处置 ==================== */
    if ($action === 'disposal_save') {
        $id = (int)post('id');
        $name = post('name');
        $fee = (float)post('fee', 0);
        $desc = post('description');
        $needNurse = (int)post('is_nurse', 0);
        $enabled = (int)post('enabled', 1);
        if ($name === '') json_fail('请填写处置名称');
        // 启用开关：未勾选=禁用（开单列表不显示，已开单流程不受影响）；勾选按原审核规则
        $saveStatus = $enabled ? 'approved' : 'disabled';
        if ($id > 0) {
            OrderRepository::exec('UPDATE disposal_items SET name=?, fee=?, description=?, is_nurse=?, status=? WHERE id=?', array($name, $fee, $desc, $needNurse, $saveStatus, $id));
            // 清理该处置的待审核记录（管理员保存即视为已通过）
            OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_disp' AND ref_id=? AND status='pending'", array($u['name'], now_str(), $id));
            json_ok(array(), '处置项目已保存');
        }
        // 管理员添加的处置免审核：直接可用，无需创建审核记录
        $newId = OrderRepository::insert('INSERT INTO disposal_items(name, fee, description, is_nurse, status, created_at) VALUES(?,?,?,?,?,?)', array($name, $fee, $desc, $needNurse, $saveStatus, now_str()));
        json_ok(array(), $enabled ? '处置项目已添加，可直接开单使用' : '处置项目已添加（当前禁用，医生开单列表不显示）');
    }

    /* ==================== 删除处置 ==================== */
    if ($action === 'disposal_delete') {
        $id = (int)post('id');
        // 流程占用检查：仅当存在未完成（待缴费/已缴费未执行/登记/执行中）开单时禁止删除
        $chk = item_delete_check('procedure', $id);
        if (!$chk['ok']) json_fail($chk['msg']);
        // 配置绑定检查（皮试/计费绑定，删除将破坏绑定定义）
        if ((int)OrderRepository::val('SELECT COUNT(*) FROM drugs WHERE skin_test_item_id=?', array($id)) > 0) {
            json_fail('该处置项目已被药品绑定为皮试项目，不能删除');
        }
        if ((int)OrderRepository::val('SELECT COUNT(*) FROM drug_settings WHERE bind_disposal_item_id=?', array($id)) > 0) {
            json_fail('该处置项目已被药品设置绑定计费，不能删除');
        }
        OrderRepository::exec('DELETE FROM disposal_items WHERE id=?', array($id));
        // 待审核的处置请求一并撤销（项目已不存在无需再审核）；
        // 已处理（通过/驳回）的审核记录保留，供追溯预览
        OrderRepository::exec("DELETE FROM audits WHERE type='item_disp' AND ref_id=? AND status='pending'", array($id));
        json_ok(array(), '处置项目已删除');
    }



    /* ==================== 通用检索：处置项目（供通用选择器组件调用） ==================== */
    if ($action === 'disposal_search') {
        $kw = trim(get('kw', ''));
        $rows = OrderRepository::q("SELECT id, name, fee FROM disposal_items WHERE status='approved'" .
            ($kw !== '' ? ' AND name LIKE ?' : '') . ' ORDER BY id DESC LIMIT 20',
            $kw !== '' ? array('%' . $kw . '%') : array());
        json_ok(array('list' => $rows));
    }

    /* ==================== 快捷创建处置项目（关联创建 + 来源审计） ====================
     * 管理员：直接生效入库（approved）；非管理员：入审核池（pending）。
     * creation_source 强制记录创建场景，审核中心据此高亮展示。 */
    if ($action === 'disposal_quick_create') {
        $name = trim(post('name'));
        $fee = (float)post('fee', 0);
        $source = trim(post('creation_source', ''));
        if ($name === '') json_fail('请填写处置名称');
        if (mb_strlen($name) > 50) json_fail('处置名称过长');
        // 同名去重：已存在直接复用（幂等），避免重复建项
        $exId = (int)OrderRepository::val('SELECT id FROM disposal_items WHERE name=? ORDER BY id DESC LIMIT 1', array($name));
        if ($exId > 0) {
            $exStatus = (string)OrderRepository::val('SELECT status FROM disposal_items WHERE id=?', array($exId));
            json_ok(array('id' => $exId, 'name' => $name,
                'fee' => (float)OrderRepository::val('SELECT fee FROM disposal_items WHERE id=?', array($exId)),
                'status' => $exStatus, 'existed' => true), '已存在同名处置，已直接关联');
        }
        $isAdmin = $u['role'] === 'admin';
        $newId = OrderRepository::insert('INSERT INTO disposal_items(name, fee, description, status, created_at) VALUES(?,?,?,?,?)',
            array($name, $fee, '【关联创建】' . ($source !== '' ? $source : '快捷创建'), $isAdmin ? 'approved' : 'pending', now_str()));
        if (!$isAdmin) {
            // 审核预览快照（audits.data）：保存提交时的完整字段，发起者删除处置后
            // 已处理审核仍可按原始内容预览追溯
            $snapJson = json_encode(array(
                'name' => $name, 'fee' => $fee,
                'description' => '【关联创建】' . ($source !== '' ? $source : '快捷创建'),
                'status' => 'pending',
            ), JSON_UNESCAPED_UNICODE);
            submit_audit('item_disp', $newId, '快捷创建处置：' . $name,
                ($source !== '' ? $source . '；' : '') . '费用 ' . money($fee) . ' 元',
                array('creation_source' => $source, 'data' => $snapJson));
        }
        json_ok(array(
            'id' => $newId, 'name' => $name, 'fee' => $fee,
            'status' => $isAdmin ? 'approved' : 'pending',
            'pending' => !$isAdmin,
            'creation_source' => $source,
        ), $isAdmin ? '处置已创建并生效' : '已提交审核，通过后自动关联生效');
    }

    json_fail('未知操作');
}
