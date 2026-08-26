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
        $html = '<div class="fs-13 text-muted mb-8" id="userCountDiv">共 ' . count($rows) . ' 个用户</div>';
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
                $html .= '<tr data-role="' . e($r['role']) . '">' .
                    '<td>' . e($r['emp_no']) . '</td>'.
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
            'queue_days' => 3,
        );
        // 注意：包含 admin 选项，否则编辑管理员用户时角色会被错误替换
        $roles = array('admin' => '系统管理员', 'doctor' => '医生', 'nurse' => '护士', 'lab' => '检验技师', 'imaging' => '影像技师', 'pharmacy' => '药剂师', 'cashier' => '挂号收费员');
        $roleOpts = '';
        foreach ($roles as $k => $v) {
            $roleOpts .= '<option value="' . $k . '"' . ($r['role'] === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        // 科室树仅列临床科室（门诊/急诊）；医技/其他为叫号大屏专用，医生不可关联
        $depts = DB::q('dept', "SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY sort, id");
        $selDept = array();
        // dept_ids 可能为 NULL，先转字符串再拆分，避免 PHP 8 告警污染 JSON 响应
        foreach (explode(',', (string)$r['dept_ids']) as $d) if ((int)$d > 0) $selDept[] = (int)$d;
        // 三级分类树（可折叠，默认折叠）：全院 → 门诊/急诊（按类型分组）→ 各科室（多选）
        $byType = array(
            'clinic' => array('label' => '门诊', 'items' => array()),
            'emergency' => array('label' => '急诊', 'items' => array()),
        );
        foreach ($depts as $d) {
            $t = ($d['type'] === 'emergency') ? 'emergency' : 'clinic';
            $byType[$t]['items'][] = $d;
        }
        $deptBox = '<div class="send-grp">' .
            '<div class="send-grp-head-row">' .
            '<button type="button" class="tree-toggle" onclick="treeToggle(this)" data-toggle="deptL2">+</button>' .
            '<label class="send-grp-head"><input type="checkbox" id="deptAll" onchange="deptToggleAll(this.checked)"> <b>全院（全部科室）</b></label>' .
            '</div>' .
            '<div class="send-grp-children send-tree-level-2" id="deptL2" style="display:none">';
        foreach ($byType as $type => $g) {
            if (!count($g['items'])) continue;
            $deptBox .= '<div class="send-grp">' .
                '<div class="send-grp-head-row">' .
                '<button type="button" class="tree-toggle" onclick="treeToggle(this)" data-toggle="deptT_' . $type . '">+</button>' .
                '<label class="send-grp-head"><input type="checkbox" class="deptGrpChk" data-type="' . $type . '" onchange="deptToggleGroup(\'' . $type . '\', this.checked)"> <b>' . $g['label'] . '</b>（' . count($g['items']) . ' 个科室）</label>' .
                '</div>' .
                '<div class="send-grp-children send-tree-level-3" id="deptT_' . $type . '" style="display:none">';
            foreach ($g['items'] as $d) {
                $checked = in_array((int)$d['id'], $selDept, true) ? ' checked' : '';
                $deptBox .= '<label class="send-user"><input type="checkbox" class="deptChk" data-type="' . $type . '" value="' . (int)$d['id'] . '"' . $checked . ' onchange="deptSyncGroups()"> ' . e($d['name']) . '</label>';
            }
            $deptBox .= '</div></div>';
        }
        $deptBox .= '</div></div>';
        $html = '<div class="flex" style="justify-content:center;margin-bottom:12px">
            <div class="avatar-picker" onclick="document.getElementById(\'f_photo\').click()">
                <span class="avatar" id="avatarPreview">' .
                ($r['photo'] && ($__ava = img_data($r['photo'])) !== '' ? '<img src="' . e($__ava) . '">' : '👤') . '
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
        <div class="form-row">
            <div class="form-group"><label class="form-label">默认密码</label>
                <input class="input" type="password" id="f_password" placeholder="' . ($id ? '留空表示不修改密码' : '默认密码 123456') . '"></div>
            <div class="form-group" id="queueDaysWrap"><label class="form-label">候诊列表可显示天数</label>
                <input class="input" id="f_queue_days" type="number" min="2" max="7" value="' . (int)$r['queue_days'] . '" placeholder="2-7"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education">' . opt_options('education', $r['education']) . '</select></div>
            <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree">' . opt_options('degree', $r['degree']) . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group" id="titleWrap" style="display:none"><label class="form-label">职称</label><select class="select" id="f_title"></select></div>
            <div class="form-group"><label class="form-label">职务</label><select class="select" id="f_position">' . opt_options('position', $r['position']) . '</select></div>
        </div>
        <div class="form-group" id="deptWrap" style="display:none"><label class="form-label">所属科室（医生可选多个，支持按全院 / 门诊 / 急诊快速勾选）</label>
            <div class="tree-box">
                <input class="input tree-box-search" id="deptSearchQ" placeholder="🔍 搜索科室，可定位到列表" autocomplete="off">
                <div id="deptSearchRes" class="tree-search-res" style="display:none"></div>
                <div class="send-tree" id="deptTreeBox" style="max-height:220px">' . $deptBox . '</div>
            </div></div>
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
        // 科室关联仅允许临床科室（门诊/急诊）：医技/其他为叫号大屏专用，
        // 前端树已过滤，此处后端兜底剔除（防伪造请求混入）
        $idsArr = array();
        foreach (explode(',', (string)$deptIds) as $di) {
            $di = (int)$di;
            if ($di > 0 && !in_array($di, $idsArr, true)) $idsArr[] = $di;
        }
        if ($idsArr) {
            $ph = implode(',', array_fill(0, count($idsArr), '?'));
            $valid = DB::q('dept', "SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph)", $idsArr);
            $okMap = array();
            foreach ($valid as $v) $okMap[(int)$v['id']] = 1;
            $idsArr = array_values(array_filter($idsArr, function ($x) use ($okMap) { return isset($okMap[$x]); }));
        }
        $deptIds = implode(',', $idsArr);
        // 候诊可显示天数：留空默认 3；填写则必须 2-7（前端已校验，后端兜底拦截）
        $queueDaysRaw = trim((string)post('queue_days', ''));
        if ($queueDaysRaw === '') {
            $queueDays = 3;
        } else {
            $queueDays = (int)$queueDaysRaw;
            if ($queueDays < 2 || $queueDays > 7) json_fail('候诊列表可显示天数需在 2-7 天之间');
        }
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
            $set = 'emp_no=?, username=?, name=?, role=?, dept_ids=?, education=?, degree=?, title=?, position=?, intro=?, queue_days=?, status=?';
            $params = array($empNo, $username, $name, $role, $deptIds, $education, $degree, $title, $position, $intro, $queueDays, $status);
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
            DB::insert('user', 'INSERT INTO users(emp_no, username, password, name, role, dept_ids, education, degree, title, position, intro, queue_days, photo, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $empNo, $username, password_hash($password !== '' ? $password : '123456', PASSWORD_DEFAULT),
                $name, $role, $deptIds, $education, $degree, $title, $position, $intro, $queueDays, $photo, $status, now_str(),
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
