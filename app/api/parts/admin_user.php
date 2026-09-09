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
        $rows = UserRepository::q('SELECT * FROM users ORDER BY role, id');
        $rowsHtml = '<thead><tr>' .
            '<th>工号</th><th>用户名</th><th>姓名</th><th>角色</th><th>职称</th><th>关联科室</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $deptNames = '';
            $ids = user_dept_ids($r);
            if ($ids) {
                $ph = in_placeholders($ids);
                $ds = UserRepository::q("SELECT name FROM departments WHERE id IN ($ph)", $ids);
                $deptNames = implode('、', array_map(function ($d) { return $d['name']; }, $ds));
            }
            // 状态三态徽章：正常启用 / 安全锁定(密码超限) / 已停用——
            // 区分系统自动锁定（password_error_locked）与管理员主动停用
            $statusHtml = badge_html('success', '正常启用');
            if ((int)$r['status'] !== 1) {
                $statusHtml = ((string)$r['lock_reason'] === 'password_error_locked')
                    ? badge_html('danger', '安全锁定 (密码超限)')
                    : badge_html('gray', '已停用');
            }
            $rowsHtml .= '<tr data-role="' . e($r['role']) . '">' .
                '<td>' . e($r['emp_no']) . '</td>'.
                '<td>' . e($r['username']) . '</td>' .
                '<td class="fw-600">' . e($r['name']) . '</td>' .
                '<td>' . e(Auth::roleName($r['role'])) . '</td>' .
                '<td>' . e($r['title']) . '</td>' .
                '<td class="fs-12">' . e($deptNames) . '</td>' .
                '<td>' . $statusHtml . '</td>' .
                '<td><div class="flex gap-4">' .
                // 编辑按钮与「新增」共用 openUserForm(id)：会执行 onRoleChange() 初始化职称/科室显示，
                // 保证医生编辑时能看到并勾选所属科室（loadModal 通用逻辑不会初始化页面控件）
                '<button class="btn btn-outline btn-sm" onclick="openUserForm(' . (int)$r['id'] . ')">编辑</button>' .
                ($r['role'] !== 'admin' ? '<button class="btn btn-outline btn-sm" onclick="delUser(' . (int)$r['id'] . ')">删除</button>' : '') .
                '</div></td></tr>';
        }
        $rowsHtml .= '</tbody>';
        $html = render_list_wrapper('共 ' . count($rows) . ' 个用户', '暂无用户', $rowsHtml, 'userCountDiv');
        json_ok(array('html' => $html));
    }

    /* ==================== 用户表单 ==================== */
    if ($action === 'user_form') {
        // 表单弹窗通过 POST 提交 id，必须用 req() 兼容读取（get() 读不到导致编辑弹窗空白）
        $id = (int)req('id', 0);
        $r = $id ? UserRepository::one('SELECT * FROM users WHERE id=?', array($id)) : array(
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
        $depts = UserRepository::q("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY sort, id");
        $selDept = user_dept_ids($r);
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
        // 安全锁定警告框：password_error_locked 状态时置顶展示归因信息，
        // 并提供【解除锁定并启用】快捷按钮（置 status=1，保存时后端同步清零）
        $lockWarn = '';
        if ((int)$r['status'] !== 1 && (string)$r['lock_reason'] === 'password_error_locked') {
            $lockWarn = '<div class="mb-12" style="background:var(--warning-soft,#fef3c7);border:1px solid var(--warning,#f59e0b);color:var(--warning,#b45309);border-radius:8px;padding:10px 12px;font-size:13px;line-height:1.8">' .
                '⚠️ 该账号于 <b>' . e((string)(isset($r['locked_at']) ? $r['locked_at'] : '-')) . '</b>' .
                ' 因密码连续错误达 <b>' . (int)(isset($r['login_fail_count']) ? $r['login_fail_count'] : 0) . '</b> 次' .
                '已被系统锁定，来源 IP: <b>' . e((string)(isset($r['lock_ip']) ? $r['lock_ip'] : '-')) . '</b><br>' .
                '<button type="button" class="btn btn-warning btn-sm" style="margin-top:6px" onclick="unlockUser()">🔓 解除锁定并启用</button>' .
                '</div>';
        }
        $html = '<div class="flex" style="justify-content:center;margin-bottom:12px">
            <div class="avatar-picker" onclick="document.getElementById(\'f_photo\').click()">
                <span class="avatar" id="avatarPreview">' .
                ($r['photo'] && ($__ava = img_data($r['photo'])) !== '' ? '<img src="' . e($__ava) . '">' : '👤') . '
                <span class="avatar-badge">📷</span>
                </span>
                <span class="avatar-picker-tip">点击头像上传照片</span>
            </div>
        </div>
        $lockWarn . '
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
            <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education" data-csd-search="1" data-csd-clear="1">' . opt_options('education', $r['education']) . '</select></div>
            <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree" data-csd-search="1" data-csd-clear="1">' . opt_options('degree', $r['degree']) . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group" id="titleWrap" style="display:none"><label class="form-label">职称</label><select class="select" id="f_title" data-csd-search="1" data-csd-clear="1"></select></div>
            <div class="form-group"><label class="form-label">职务</label><select class="select" id="f_position" data-csd-search="1" data-csd-clear="1">' . opt_options('position', $r['position']) . '</select></div>
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
            $ph = in_placeholders($idsArr);
            $valid = UserRepository::q("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph)", $idsArr);
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
        $exists = UserRepository::one('SELECT id FROM users WHERE username=? AND id<>?', array($username, $id));
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
            // 取锁定归因旧值（启用/解锁时同步清零 + 写安全审计日志）
            $before = UserRepository::one('SELECT status, lock_reason, login_fail_count FROM users WHERE id=?', array($id));
            UserRepository::exec('UPDATE users SET ' . $set . ' WHERE id=?', $params);
            $wasLocked = $before && (int)$before['status'] === 0 && (string)$before['lock_reason'] === 'password_error_locked';
            if ((int)$status === 1) {
                // 启用（含一键解锁）：重置锁定归因三件套 + 失败计数——
                // 该用户即可恢复无障碍正常登录，无需任何二次繁琐操作
                UserRepository::exec(
                    'UPDATE users SET lock_reason=NULL, locked_at=NULL, lock_ip=?, login_fail_count=0, login_locked_until=NULL WHERE id=?',
                    array('', $id)
                );
                if ($wasLocked) {
                    // 安全审计日志（audits 已处理池）：记录解锁动作供追溯
                    UserRepository::insert("INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, handled_by, handled_at, note, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)", array(
                        'user_unlock', $id,
                        '解除账号安全锁定：' . $name,
                        '用户「' . $name . '」（工号 ' . $empNo . '）因密码连续错误达 ' . (int)($before['login_fail_count'] ?? 0) . ' 次被系统安全锁定，管理员已手动解锁并启用',
                        'approved', $u['name'], (int)$u['id'], $u['name'], now_str(), '解锁后 login_fail_count 已清零', now_str(),
                    ));
                }
            }
        } else {
            UserRepository::insert('INSERT INTO users(emp_no, username, password, name, role, dept_ids, education, degree, title, position, intro, queue_days, photo, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
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
        UserRepository::exec('DELETE FROM users WHERE id=? AND role<>?', array($id, 'admin'));
        json_ok(array(), '用户已删除');
    }

    json_fail('未知操作');
}
