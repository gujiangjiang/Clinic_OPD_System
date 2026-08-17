<?php
/**
 * ============================================================
 * parts/admin_dept.php v1.1.0 — 管理端：科室管理
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. dept_list   科室列表（HTML）
 *   2. dept_form   新增/编辑科室表单（HTML）
 *   3. dept_save   保存科室（门诊科室设置上下午号源，急诊无需）
 *   4. dept_delete 删除科室（已有挂号记录的科室不可删除）
 * ============================================================ */

/**
 * 处理科室管理动作
 * @param string $action 动作名
 */
function admin_part_dept($action) {
    $u = Auth::user();

    /* ==================== 科室列表 ==================== */
    if ($action === 'dept_list') {
        $rows = DB::q('dept', 'SELECT * FROM departments ORDER BY type DESC, sort, id');
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 个科室</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无科室，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>科室名称</th><th>类型</th><th>挂号费</th><th>上午号源</th><th>下午号源</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . ($r['type'] === 'emergency' ? '<span class="badge badge-danger">急诊</span>' : '<span class="badge badge-primary">门诊</span>') . '</td>' .
                    '<td>¥' . money($r['fee']) . '</td>' .
                    '<td>' . ($r['type'] === 'clinic' ? (int)$r['am_quota'] : '—') . '</td>' .
                    '<td>' . ($r['type'] === 'clinic' ? (int)$r['pm_quota'] : '—') . '</td>' .
                    '<td>' . ($r['status'] == 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-gray">停用</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    // 编辑按钮与「新增」共用 openDeptForm(id)（同一表单与初始化逻辑，保证编辑回填一致）
                    '<button class="btn btn-outline btn-sm" onclick="openDeptForm(' . (int)$r['id'] . ')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDept(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 科室表单 ==================== */
    if ($action === 'dept_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（get() 读不到导致编辑弹窗空白）
        $id = (int)req('id', 0);
        $r = $id ? DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($id)) : array(
            'name' => '', 'type' => 'clinic', 'fee' => '10', 'am_quota' => '30', 'pm_quota' => '30', 'status' => 1,
        );
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-group"><label class="form-label">科室名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '" placeholder="如：呼吸内科"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">科室类型 <span class="req">*</span></label>
                <select class="select" id="f_type" onchange="toggleQuota()">
                    <option value="clinic"' . ($r['type'] === 'clinic' ? ' selected' : '') . '>门诊（需设置号源）</option>
                    <option value="emergency"' . ($r['type'] === 'emergency' ? ' selected' : '') . '>急诊（无需号源）</option>
                </select></div>
            <div class="form-group"><label class="form-label">挂号费（元）</label>
                <input class="input" type="number" step="0.01" min="0" id="f_fee" value="' . e($r['fee']) . '"></div>
        </div>
        <div class="form-row" id="quotaRow"' . ($r['type'] === 'emergency' ? ' style="display:none"' : '') . '>
            <div class="form-group"><label class="form-label">上午号源数量</label>
                <input class="input" type="number" min="0" id="f_am" value="' . (int)$r['am_quota'] . '"></div>
            <div class="form-group"><label class="form-label">下午号源数量</label>
                <input class="input" type="number" min="0" id="f_pm" value="' . (int)$r['pm_quota'] . '"></div>
        </div>
        <div class="form-group"><label class="form-label">状态</label>
            <select class="select" id="f_status">
                <option value="1"' . ($r['status'] == 1 ? ' selected' : '') . '>启用</option>
                <option value="0"' . ($r['status'] == 0 ? ' selected' : '') . '>停用</option>
            </select></div>';
        json_ok(array('html' => $html));
    }

    /* ==================== 保存科室 ==================== */
    if ($action === 'dept_save') {
        $id = (int)post('id');
        $name = post('name');
        $type = post('type', 'clinic');
        $fee = (float)post('fee', 0);
        $am = (int)post('am_quota', 30);
        $pm = (int)post('pm_quota', 30);
        $status = (int)post('status', 1);
        if ($name === '') json_fail('请填写科室名称');
        if (!in_array($type, array('clinic', 'emergency'), true)) $type = 'clinic';
        if ($id > 0) {
            DB::exec('dept', 'UPDATE departments SET name=?, type=?, fee=?, am_quota=?, pm_quota=?, status=? WHERE id=?', array($name, $type, $fee, $am, $pm, $status, $id));
        } else {
            DB::insert('dept', 'INSERT INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,0,?,?)', array($name, $type, $fee, $am, $pm, $status, now_str()));
        }
        json_ok(array(), '科室已保存');
    }

    /* ==================== 删除科室 ==================== */
    if ($action === 'dept_delete') {
        $id = (int)post('id');
        $used = (int)DB::val('patient', 'SELECT COUNT(*) FROM registrations WHERE first_dept_id=?', array($id));
        if ($used > 0) json_fail('该科室已有挂号记录，不能删除（可改为停用）');
        DB::exec('dept', 'DELETE FROM departments WHERE id=?', array($id));
        json_ok(array(), '科室已删除');
    }

    json_fail('未知操作');
}
