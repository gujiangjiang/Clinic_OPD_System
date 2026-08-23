<?php
/**
 * ============================================================
 * parts/admin_user.php v1.1.0 — 管理端：用户管理
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. user_list   用户列表（HTML）
 *   2. user_form   新增/编辑用户表单（HTML，职称按角色、医生多科室）
 *   3. user_save   保存用户（工号/姓名/默认密码/照片/学历学位职称等）
 *   4. user_delete 删除用户（不可删除当前登录用户与管理员）
 * ============================================================ */

/**
 * 处理用户管理动作
 * @param string $action 动作名
 */
function admin_part_user($action) {
    $u = Auth::user();

    /* ==================== 用户列表 ==================== */
    if ($action === 'user_list') {
        $rows = DB::q('user', 'SELECT * FROM users ORDER BY role, id');
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 个用户</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无用户</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>工号</th><th>用户名</th><th>姓名</th><th>角色</th><th>职称</th><th>关联科室</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $deptNames = '';
                $ids = array();
                // dept_ids 可能为 NULL（如管理员等无科室用户），先转字符串再拆分，避免 PHP 8 告警
                foreach (explode(',', (string)$r['dept_ids']) as $d) if ((int)$d > 0) $ids[] = (int)$d;
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $ds = DB::q('dept', "SELECT name FROM departments WHERE id IN ($ph)", $ids);
                    $deptNames = implode('、', array_map(function ($d) { return $d['name']; }, $ds));
                }
                $html .= '<tr>' .
                    '<td>' . e($r['emp_no']) . '</td>' .
                    '<td>' . e($r['username']) . '</td>' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e(Auth::roleName($r['role'])) . '</td>' .
                    '<td>' . e($r['title']) . '</td>' .
                    '<td class="fs-12">' . e($deptNames) . '</td>' .
                    '<td>' . ($r['status'] == 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-gray">停用</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    // 编辑按钮与「新增」共用 openUserForm(id)：会执行 onRoleChange() 初始化职称/科室显示，
                    // 保证医生编辑时能看到并勾选所属科室（loadModal 通用逻辑不会初始化页面控件）
                    '<button class="btn btn-outline btn-sm" onclick="openUserForm(' . (int)$r['id'] . ')">编辑</button>' .
                    ($r['role'] !== 'admin' ? '<button class="btn btn-outline btn-sm" onclick="delUser(' . (int)$r['id'] . ')">删除</button>' : '') .
                    '</div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 用户表单 ==================== */
    if ($action === 'user_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（get() 读不到导致编辑弹窗空白）
        $id = (int)req('id', 0);
        $r = $id ? DB::one('user', 'SELECT * FROM users WHERE id=?', array($id)) : array(
            'emp_no' => '', 'username' => '', 'name' => '', 'role' => 'doctor', 'dept_ids' => '',
            'education' => '', 'degree' => '', 'title' => '', 'position' => '', 'intro' => '', 'photo' => '', 'status' => 1,
        );
        // 注意：包含 admin 选项，否则编辑管理员用户时角色会被错误替换
        $roles = array('admin' => '系统管理员', 'doctor' => '医生', 'nurse' => '护士', 'lab' => '检验技师', 'imaging' => '影像技师', 'pharmacy' => '药剂师', 'cashier' => '挂号收费员');
        $roleOpts = '';
        foreach ($roles as $k => $v) {
            $roleOpts .= '<option value="' . $k . '"' . ($r['role'] === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        $depts = DB::q('dept', 'SELECT * FROM departments WHERE status=1 ORDER BY sort, id');
        $selDept = array();
        // dept_ids 可能为 NULL，先转字符串再拆分，避免 PHP 8 告警污染 JSON 响应
        foreach (explode(',', (string)$r['dept_ids']) as $d) if ((int)$d > 0) $selDept[] = (int)$d;
        $deptBox = '';
        foreach ($depts as $d) {
            $checked = in_array((int)$d['id'], $selDept, true) ? ' checked' : '';
            $deptBox .= '<label class="flex gap-4" style="font-size:13px;margin-right:12px;cursor:pointer">' .
                '<input type="checkbox" class="deptChk" value="' . (int)$d['id'] . '"' . $checked . '> ' . e($d['name']) . '</label>';
        }
        $html = '<div class="flex" style="justify-content:center;margin-bottom:12px">
            <div class="avatar-picker" onclick="document.getElementById(\'f_photo\').click()">
                <span class="avatar" id="avatarPreview">' .
                ($r['photo'] ? '<img src="' . e(upload_url($r['photo'])) . '">' : '👤') . '
                <span class="avatar-badge">📷</span>
                </span>
                <span class="avatar-picker-tip">点击头像上传照片</span>
            </div>
        </div>
        <input type="file" id="f_photo" accept="image/*" style="display:none">
        <input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-row">
            <div class="form-group"><label class="form-label">职工工号</label><input class="input" id="f_emp_no" value="' . e($r['emp_no']) . '"></div>
            <div class="form-group"><label class="form-label">登录用户名 <span class="req">*</span></label><input class="input" id="f_username" value="' . e($r['username']) . '" placeholder="英文字母开头，可含数字/下划线"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">姓名 <span class="req">*</span></label><input class="input" id="f_name" value="' . e($r['name']) . '"></div>
            <div class="form-group"><label class="form-label">角色 <span class="req">*</span></label><select class="select" id="f_role" onchange="onRoleChange()">' . $roleOpts . '</select></div>
        </div>
        <div class="form-group"><label class="form-label">默认密码' . ($id ? '（不修改请留空）' : '（默认 123456）') . '</label>
            <input class="input" type="password" id="f_password" placeholder="' . ($id ? '留空表示不修改密码' : '默认密码 123456') . '"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education">' . opt_options('education', $r['education']) . '</select></div>
            <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree">' . opt_options('degree', $r['degree']) . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group" id="titleWrap" style="display:none"><label class="form-label">职称</label><select class="select" id="f_title"></select></div>
            <div class="form-group"><label class="form-label">职务</label><select class="select" id="f_position">' . opt_options('position', $r['position']) . '</select></div>
        </div>
        <div class="form-group" id="deptWrap" style="display:none"><label class="form-label">所属科室（医生可选多个）</label>
            <div class="flex" style="flex-wrap:wrap">' . $deptBox . '</div></div>
        <div class="form-group"><label class="form-label">个人介绍</label><textarea class="textarea" id="f_intro" rows="2">' . e($r['intro']) . '</textarea></div>
        <div class="form-group"><label class="form-label">状态</label>
            <select class="select" id="f_status"><option value="1"' . ($r['status'] == 1 ? ' selected' : '') . '>启用</option>
            <option value="0"' . ($r['status'] == 0 ? ' selected' : '') . '>停用</option></select></div>';
        json_ok(array('html' => $html, 'title' => $r['title']));
    }

    /* ==================== 保存用户 ==================== */
    if ($action === 'user_save') {
        $id = (int)post('id');
        $username = post('username');
        $name = post('name');
        $role = post('role', 'doctor');
        $empNo = post('emp_no');
        // 默认密码原样读取（不 trim），避免含空格的密码被误删
        $password = post_raw('password');
        $title = post('title');
        $position = post('position');
        $education = post('education');
        $degree = post('degree');
        $intro = post('intro');
        $status = (int)post('status', 1);
        $deptIds = post('dept_ids');
        if ($username === '') json_fail('请填写登录用户名');
        // 用户名必须英文字母开头：与工号登录并存时避免纯数字/数字开头用户名
        // 与他人工号混淆（工号可用于登录，见 Auth::login）
        if (!preg_match('/^[A-Za-z]/', $username)) {
            json_fail('登录用户名必须以英文字母开头，不允许纯数字或数字开头');
        }
        if ($name === '') json_fail('请填写姓名');
        if (!in_array($role, array('admin', 'cashier', 'doctor', 'nurse', 'lab', 'imaging', 'pharmacy'), true)) $role = 'doctor';
        // 用户名唯一
        $exists = DB::one('user', 'SELECT id FROM users WHERE username=? AND id<>?', array($username, $id));
        if ($exists) json_fail('登录用户名已存在');
        // 照片上传（可选）
        $photo = '';
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $res = Upload::save('photo', 'user/' . $role);
            if (isset($res['error'])) json_fail($res['error']);
            $photo = $res['path'];
        }
        if ($id > 0) {
            $set = 'emp_no=?, username=?, name=?, role=?, dept_ids=?, education=?, degree=?, title=?, position=?, intro=?, status=?';
            $params = array($empNo, $username, $name, $role, $deptIds, $education, $degree, $title, $position, $intro, $status);
            if ($password !== '') {
                $set .= ', password=?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($photo !== '') {
                $set .= ', photo=?';
                $params[] = $photo;
            }
            $params[] = $id;
            DB::exec('user', 'UPDATE users SET ' . $set . ' WHERE id=?', $params);
        } else {
            DB::insert('user', 'INSERT INTO users(emp_no, username, password, name, role, dept_ids, education, degree, title, position, intro, photo, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $empNo, $username, password_hash($password !== '' ? $password : '123456', PASSWORD_DEFAULT),
                $name, $role, $deptIds, $education, $degree, $title, $position, $intro, $photo, $status, now_str(),
            ));
        }
        json_ok(array(), '用户已保存');
    }

    /* ==================== 删除用户 ==================== */
    if ($action === 'user_delete') {
        $id = (int)post('id');
        if ($id === Auth::id()) json_fail('不能删除当前登录用户');
        DB::exec('user', 'DELETE FROM users WHERE id=? AND role<>?', array($id, 'admin'));
        json_ok(array(), '用户已删除');
    }

    json_fail('未知操作');
}
