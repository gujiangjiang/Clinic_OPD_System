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
        $rows = DB::q('disp', 'SELECT * FROM disposal_items ORDER BY id');
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 个处置项目</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无处置项目</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>处置名称</th><th>费用</th><th>描述备注</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr><td class="fw-600">' . e($r['name']) . '</td><td>¥' . money($r['fee']) . '</td>' .
                    '<td class="fs-12 text-muted">' . e($r['description']) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'disposal_form\',id:' . (int)$r['id'] . '},\'编辑处置\')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDisposal(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 处置表单 ==================== */
    if ($action === 'disposal_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（否则编辑弹窗空白）
        $id = (int)req('id', 0);
        $r = $id ? DB::one('disp', 'SELECT * FROM disposal_items WHERE id=?', array($id)) : array('name' => '', 'fee' => '0', 'description' => '');
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-group"><label class="form-label">处置名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '" placeholder="如：清创缝合、换药"></div>
        <div class="form-group"><label class="form-label">费用（元）</label>
            <input class="input" type="number" step="0.01" min="0" id="f_fee" value="' . e($r['fee']) . '"></div>
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
        if ($name === '') json_fail('请填写处置名称');
        if ($id > 0) {
            DB::exec('disp', 'UPDATE disposal_items SET name=?, fee=?, description=? WHERE id=?', array($name, $fee, $desc, $id));
            json_ok(array(), '处置项目已保存');
        }
        $newId = DB::insert('disp', 'INSERT INTO disposal_items(name, fee, description, status, created_at) VALUES(?,?,?,?,?)', array($name, $fee, $desc, 'pending', now_str()));
        DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
            'item_disp', $newId, '处置项目添加：' . $name,
            '新增处置项目「' . $name . '」（费用：¥' . money($fee) . '），请审核',
            'pending', $u['name'], $u['id'], now_str(),
        ));
        json_ok(array(), '处置项目已添加，请到【审核中心】审核后即可开单');
    }

    /* ==================== 删除处置 ==================== */
    if ($action === 'disposal_delete') {
        $id = (int)post('id');
        DB::exec('disp', 'DELETE FROM disposal_items WHERE id=?', array($id));
        json_ok(array(), '处置项目已删除');
    }

    json_fail('未知操作');
}
