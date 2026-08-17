<?php
/**
 * ============================================================
 * admin.php — 管理端接口
 * ============================================================
 * 说明：系统设置、科室管理、用户管理、检验/检查项目管理、
 * 药品设置与药品信息、处置项目管理、审核中心。
 * 新增项目（检验/检查/药品/处置/全科全院模板）默认待审核，
 * 在【审核中心】通过后（approved）方可被业务科室使用。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 工作台统计 ==================== */
    case 'stats':
        $today = today_str();
        $regToday = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=? AND status IN ('paid','visiting','finished')", array($today));
        $waiting = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=? AND status='paid'", array($today));
        $revenue = (float)DB::val('order', "SELECT COALESCE(SUM(total),0) FROM payments WHERE date(created_at)=?", array($today));
        $pendingAudits = (int)DB::val('core', "SELECT COUNT(*) FROM audits WHERE status='pending'");
        $lowStock = (int)DB::val('drug', "SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty<=10");
        $deptCount = (int)DB::val('dept', 'SELECT COUNT(*) FROM departments WHERE status=1');
        $userCount = (int)DB::val('user', 'SELECT COUNT(*) FROM users WHERE status=1');
        $msgCount = (int)DB::val('core', 'SELECT COUNT(*) FROM messages WHERE is_read=0 AND (to_role=? OR to_user_id=?)', array($u['role'], $u['id']));
        json_ok(array(
            'reg_today' => $regToday, 'waiting' => $waiting, 'revenue' => money($revenue),
            'pending_audits' => $pendingAudits, 'low_stock' => $lowStock,
            'dept_count' => $deptCount, 'user_count' => $userCount, 'msg_count' => $msgCount,
        ));
        break;

    /* ==================== 系统设置保存 ==================== */
    case 'settings':
        $hospital = post('hospital_name');
        if ($hospital === '') json_fail('医院名称不能为空');
        $tz = post('timezone', 'Asia/Shanghai');
        $tzList = DateTimeZone::listIdentifiers();
        if (!in_array($tz, $tzList, true)) $tz = 'Asia/Shanghai';
        set_setting('hospital_name', $hospital);
        set_setting('hospital_name2', post('hospital_name2'));
        set_setting('footer', post('footer'));
        set_setting('timezone', $tz);
        date_default_timezone_set($tz);
        json_ok(array(), '系统设置已保存');
        break;

    /* ==================== 上传医院 LOGO（同时作为 favicon） ==================== */
    case 'upload_logo':
        $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
        if (isset($res['error'])) json_fail($res['error']);
        set_setting('logo', $res['path']);
        json_ok(array('path' => $res['path']), 'LOGO 已上传');
        break;

    /* ==================== 科室管理 ==================== */
    case 'dept_list':
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
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'dept_form\',id:' . (int)$r['id'] . '},\'编辑科室\')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDept(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    case 'dept_form':
        $id = (int)get('id', 0);
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
        break;

    case 'dept_save':
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
        break;

    case 'dept_delete':
        $id = (int)post('id');
        $used = (int)DB::val('patient', 'SELECT COUNT(*) FROM registrations WHERE first_dept_id=?', array($id));
        if ($used > 0) json_fail('该科室已有挂号记录，不能删除（可改为停用）');
        DB::exec('dept', 'DELETE FROM departments WHERE id=?', array($id));
        json_ok(array(), '科室已删除');
        break;

    /* ==================== 用户管理 ==================== */
    case 'user_list':
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
                foreach (explode(',', $r['dept_ids']) as $d) if ((int)$d > 0) $ids[] = (int)$d;
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
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'user_form\',id:' . (int)$r['id'] . '},\'编辑用户\')">编辑</button>' .
                    ($r['role'] !== 'admin' ? '<button class="btn btn-outline btn-sm" onclick="delUser(' . (int)$r['id'] . ')">删除</button>' : '') .
                    '</div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    case 'user_form':
        $id = (int)get('id', 0);
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
        foreach (explode(',', $r['dept_ids']) as $d) if ((int)$d > 0) $selDept[] = (int)$d;
        $deptBox = '';
        foreach ($depts as $d) {
            $checked = in_array((int)$d['id'], $selDept, true) ? ' checked' : '';
            $deptBox .= '<label class="flex gap-4" style="font-size:13px;margin-right:12px;cursor:pointer">' .
                '<input type="checkbox" class="deptChk" value="' . (int)$d['id'] . '"' . $checked . '> ' . e($d['name']) . '</label>';
        }
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-row">
            <div class="form-group"><label class="form-label">职工工号</label><input class="input" id="f_emp_no" value="' . e($r['emp_no']) . '"></div>
            <div class="form-group"><label class="form-label">登录用户名 <span class="req">*</span></label><input class="input" id="f_username" value="' . e($r['username']) . '"></div>
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
        <div class="form-group"><label class="form-label">照片（可选）</label>
            <input type="file" class="input" id="f_photo" accept="image/*"></div>
        <div class="form-group"><label class="form-label">状态</label>
            <select class="select" id="f_status"><option value="1"' . ($r['status'] == 1 ? ' selected' : '') . '>启用</option>
            <option value="0"' . ($r['status'] == 0 ? ' selected' : '') . '>停用</option></select></div>';
        json_ok(array('html' => $html, 'title' => $r['title']));
        break;

    case 'user_save':
        $id = (int)post('id');
        $username = post('username');
        $name = post('name');
        $role = post('role', 'doctor');
        $empNo = post('emp_no');
        $password = post('password');
        $title = post('title');
        $position = post('position');
        $education = post('education');
        $degree = post('degree');
        $intro = post('intro');
        $status = (int)post('status', 1);
        $deptIds = post('dept_ids');
        if ($username === '') json_fail('请填写登录用户名');
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
        break;

    case 'user_delete':
        $id = (int)post('id');
        if ($id === Auth::id()) json_fail('不能删除当前登录用户');
        DB::exec('user', 'DELETE FROM users WHERE id=? AND role<>?', array($id, 'admin'));
        json_ok(array(), '用户已删除');
        break;

    /* ==================== 检验/检查项目 ==================== */
    case 'item_list':
        $type = get('type', 'lab');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $rows = DB::q('lab', "SELECT * FROM $table ORDER BY category, id");
        $html = '<div class="fs-13 text-muted mb-8">' . ($type === 'lab' ? '检验项目' : '检查项目') . '共 ' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无项目，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>名称</th><th>分类</th><th>价格</th>' .
                ($type === 'lab' ? '<th>单位</th><th>正常范围</th><th>危急值</th>' : '') .
                '<th>描述</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    ($type === 'lab' ? '<td>' . e($r['unit']) . '</td><td class="fs-12">' . e($r['normal_range']) . '</td>' .
                        '<td class="fs-12">' . e(($r['critical_low'] !== '' ? '低' . $r['critical_low'] . ' ' : '') . ($r['critical_high'] !== '' ? '高' . $r['critical_high'] : '')) . '</td>' : '') .
                    '<td class="fs-12 text-muted">' . e(mb_substr($r['description'], 0, 20)) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'item_form\',type:\'' . $type . '\',id:' . (int)$r['id'] . '},\'编辑项目\')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delItem(\'' . $type . '\',' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    case 'item_form':
        $type = get('type', 'lab');
        $id = (int)get('id', 0);
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $r = $id ? DB::one('lab', "SELECT * FROM $table WHERE id=?", array($id)) : array(
            'category' => '', 'name' => '', 'unit' => '', 'price' => '0', 'normal_range' => '',
            'critical_low' => '', 'critical_high' => '', 'description' => '',
        );
        $cats = DB::q('lab', "SELECT name FROM item_categories WHERE ctype=? ORDER BY sort, id", array($type));
        $catOpts = '<option value="">请选择/输入分类</option>';
        foreach ($cats as $c) {
            $catOpts .= '<option value="' . e($c['name']) . '"' . ($r['category'] === $c['name'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-row">
            <div class="form-group"><label class="form-label">项目名称 <span class="req">*</span></label>
                <input class="input" id="f_name" value="' . e($r['name']) . '"></div>
            <div class="form-group"><label class="form-label">所属分类（CT/MR 等）</label>
                <select class="select" id="f_category">' . $catOpts . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">价格（元）</label>
                <input class="input" type="number" step="0.01" min="0" id="f_price" value="' . e($r['price']) . '"></div>' .
            ($type === 'lab' ? '<div class="form-group"><label class="form-label">计量单位</label>
                <input class="input" id="f_unit" value="' . e($r['unit']) . '" placeholder="如：mmol/L"></div>' : '') .
        '</div>' .
        ($type === 'lab' ? '<div class="form-row">
            <div class="form-group"><label class="form-label">正常范围值</label>
                <input class="input" id="f_normal" value="' . e($r['normal_range']) . '" placeholder="如：3.5-5.5"></div>
            <div class="form-group"><label class="form-label">危急值下限</label>
                <input class="input" id="f_clow" value="' . e($r['critical_low']) . '"></div>
            <div class="form-group"><label class="form-label">危急值上限</label>
                <input class="input" id="f_chigh" value="' . e($r['critical_high']) . '"></div>
        </div>' : '') .
        '<div class="form-group"><label class="form-label">项目描述</label>
            <textarea class="textarea" id="f_desc" rows="2">' . e($r['description']) . '</textarea></div>' .
        '<div class="fs-12 text-muted">新增项目提交后需在【审核中心】审核通过方可开单使用。</div>';
        json_ok(array('html' => $html));
        break;

    case 'item_save':
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        if ($name === '') json_fail('请填写项目名称');
        if ($id > 0) {
            if ($type === 'lab') {
                DB::exec('lab', 'UPDATE lab_items SET category=?, name=?, unit=?, price=?, normal_range=?, critical_low=?, critical_high=?, description=? WHERE id=?', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $id,
                ));
            } else {
                DB::exec('lab', 'UPDATE exam_items SET category=?, name=?, price=?, description=? WHERE id=?', array($category, $name, $price, post('description'), $id));
            }
        } else {
            // 新增项目默认待审核
            if ($type === 'lab') {
                $newId = DB::insert('lab', 'INSERT INTO lab_items(category, name, unit, price, normal_range, critical_low, critical_high, description, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), 'pending', now_str(),
                ));
            } else {
                $newId = DB::insert('lab', 'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
                    $category, $name, $price, post('description'), 'pending', now_str(),
                ));
            }
            DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
                $type === 'lab' ? 'item_lab' : 'item_exam', $newId,
                ($type === 'lab' ? '检验项目添加' : '检查项目添加') . '：' . $name,
                '新增项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核',
                'pending', $u['name'], $u['id'], now_str(),
            ));
            json_ok(array(), '项目已添加，请到【审核中心】审核后即可使用');
        }
        json_ok(array(), '项目已保存');
        break;

    case 'item_delete':
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        DB::exec('lab', "DELETE FROM $table WHERE id=?", array($id));
        json_ok(array(), '项目已删除');
        break;

    /* ==================== 项目分类管理 ==================== */
    case 'cat_list':
        $type = get('type', 'lab');
        $rows = DB::q('lab', 'SELECT * FROM item_categories WHERE ctype=? ORDER BY sort, id', array($type));
        json_ok(array('list' => array_map(function ($c) {
            return array('id' => (int)$c['id'], 'name' => $c['name']);
        }, $rows)));
        break;

    case 'cat_add':
        $type = post('type', 'lab');
        $name = post('name');
        if ($name === '') json_fail('请输入分类名称');
        DB::insert('lab', 'INSERT INTO item_categories(ctype, name, sort) VALUES(?,?,0)', array($type, $name));
        json_ok(array(), '分类已添加');
        break;

    case 'cat_delete':
        $id = (int)post('id');
        DB::exec('lab', 'DELETE FROM item_categories WHERE id=?', array($id));
        json_ok(array(), '分类已删除');
        break;

    /* ==================== 药品设置（分类/包装单位/剂型/频次/途径） ==================== */
    case 'drugsetting_list':
        $stype = get('stype', 'category');
        $rows = DB::q('drug', 'SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
        $html = '<div class="table-wrap"><table class="table"><thead><tr><th>名称</th><th>' .
            ($stype === 'route' ? '需护士站处理</th><th>' : '') . '操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><td class="fw-600">' . e($r['name']) . '</td>' .
                ($stype === 'route' ? '<td>' . ($r['need_nurse'] ? '<span class="badge badge-warning">是（护士站执行）</span>' : '<span class="badge badge-gray">否</span>') . '</td>' : '') .
                '<td><div class="flex gap-4">' .
                '<button class="btn btn-outline btn-sm" onclick="editDrugSetting(\'' . $stype . '\',' . (int)$r['id'] . ',\'' . e($r['name']) . '\',' . (int)$r['need_nurse'] . ')">编辑</button>' .
                '<button class="btn btn-outline btn-sm" onclick="delDrugSetting(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
        }
        $html .= '</tbody></table></div>';
        json_ok(array('html' => $html));
        break;

    case 'drugsetting_save':
        $id = (int)post('id');
        $stype = post('stype');
        $name = post('name');
        $needNurse = (int)post('need_nurse', 0);
        if ($name === '') json_fail('请输入名称');
        if ($id > 0) {
            DB::exec('drug', 'UPDATE drug_settings SET name=?, need_nurse=? WHERE id=?', array($name, $needNurse, $id));
        } else {
            DB::insert('drug', 'INSERT INTO drug_settings(stype, name, need_nurse, sort) VALUES(?,?,?,0)', array($stype, $name, $needNurse));
        }
        json_ok(array(), '已保存');
        break;

    case 'drugsetting_delete':
        $id = (int)post('id');
        $used = (int)DB::val('drug', 'SELECT COUNT(*) FROM drugs WHERE route_name IN (SELECT name FROM drug_settings WHERE id=?) OR package_unit IN (SELECT name FROM drug_settings WHERE id=?) OR form IN (SELECT name FROM drug_settings WHERE id=?) OR frequency_name IN (SELECT name FROM drug_settings WHERE id=?) OR category IN (SELECT name FROM drug_settings WHERE id=?)', array($id, $id, $id, $id, $id));
        DB::exec('drug', 'DELETE FROM drug_settings WHERE id=?', array($id));
        json_ok(array(), '已删除');
        break;

    /* ==================== 药品信息 ==================== */
    case 'drug_list':
        $rows = DB::q('drug', 'SELECT * FROM drugs ORDER BY category, id');
        $html = '<div class="fs-13 text-muted mb-8">共 ' . count($rows) . ' 种药品</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无药品，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>药品名称</th><th>通用名</th><th>厂家简称</th><th>分类</th><th>规格</th><th>剂型</th><th>频次</th><th>途径</th><th>库存</th><th>价格</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>' .
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
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'drug_form\',id:' . (int)$r['id'] . '},\'编辑药品\')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delDrug(' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    case 'drug_form':
        $id = (int)get('id', 0);
        $r = $id ? DB::one('drug', 'SELECT * FROM drugs WHERE id=?', array($id)) : array(
            'name' => '', 'generic_name' => '', 'category' => '', 'vendor' => '', 'vendor_short' => '',
            'package_unit' => '', 'spec' => '', 'form' => '', 'single_dose' => '', 'frequency_name' => '',
            'route_name' => '', 'price' => '0', 'qty' => '0', 'is_rx' => 0, 'is_limited' => 0, 'note' => '', 'need_nurse' => 0,
        );
        $sel = function ($stype, $cur) {
            $rows = DB::q('drug', 'SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
            $html = '<option value="">请选择</option>';
            foreach ($rows as $x) {
                $html .= '<option value="' . e($x['name']) . '"' . ($cur === $x['name'] ? ' selected' : '') . '>' . e($x['name']) . '</option>';
            }
            return $html;
        };
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-row">
            <div class="form-group"><label class="form-label">药品名称 <span class="req">*</span></label><input class="input" id="f_name" value="' . e($r['name']) . '"></div>
            <div class="form-group"><label class="form-label">通用名称</label><input class="input" id="f_generic" value="' . e($r['generic_name']) . '"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">分类（西药/中成药/中药）</label><select class="select" id="f_category">' . $sel('category', $r['category']) . '</select></div>
            <div class="form-group"><label class="form-label">包装单位</label><select class="select" id="f_pkg">' . $sel('package', $r['package_unit']) . '</select></div>
            <div class="form-group"><label class="form-label">药品剂型</label><select class="select" id="f_form">' . $sel('form', $r['form']) . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">药品企业名称</label><input class="input" id="f_vendor" value="' . e($r['vendor']) . '"></div>
            <div class="form-group"><label class="form-label">企业名称缩写</label><input class="input" id="f_vendor_short" value="' . e($r['vendor_short']) . '" placeholder="处方打印显示"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">药物规格/含量</label><input class="input" id="f_spec" value="' . e($r['spec']) . '" placeholder="如：0.25g×24片"></div>
            <div class="form-group"><label class="form-label">单次使用剂量</label><input class="input" id="f_dose" value="' . e($r['single_dose']) . '" placeholder="如：2片 / 2g"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">用药频次</label><select class="select" id="f_freq">' . $sel('freq', $r['frequency_name']) . '</select></div>
            <div class="form-group"><label class="form-label">使用途径</label><select class="select" id="f_route" onchange="syncNurse()">' . $sel('route', $r['route_name']) . '</select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">价格（元）</label><input class="input" type="number" step="0.01" min="0" id="f_price" value="' . e($r['price']) . '"></div>
            <div class="form-group"><label class="form-label">药品数量（库存）</label><input class="input" type="number" min="0" id="f_qty" value="' . (int)$r['qty'] . '"></div>
        </div>
        <div class="flex gap-16 mb-8">
            <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_rx"' . ($r['is_rx'] ? ' checked' : '') . '> 处方药</label>
            <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_limited"' . ($r['is_limited'] ? ' checked' : '') . '> 限制类药品</label>
            <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_nurse"' . ($r['need_nurse'] ? ' checked' : '') . '> 需护士站执行</label>
        </div>
        <div class="form-group"><label class="form-label">备注</label><textarea class="textarea" id="f_note" rows="2">' . e($r['note']) . '</textarea></div>';
        // 给药途径 → 是否需护士站处理 映射（供前端自动勾选）
        $routeMap = array();
        foreach (DB::q('drug', "SELECT name, need_nurse FROM drug_settings WHERE stype='route'") as $rt) {
            $routeMap[$rt['name']] = (int)$rt['need_nurse'];
        }
        json_ok(array('html' => $html, 'route_nurse' => $routeMap, 'need_nurse' => (int)$r['need_nurse']));
        break;

    case 'drug_save':
        $id = (int)post('id');
        $name = post('name');
        if ($name === '') json_fail('请填写药品名称');
        $data = array(
            'generic_name' => post('generic_name'), 'category' => post('category'),
            'vendor' => post('vendor'), 'vendor_short' => post('vendor_short'),
            'package_unit' => post('package_unit'), 'spec' => post('spec'), 'form' => post('form'),
            'single_dose' => post('single_dose'), 'frequency_name' => post('frequency_name'),
            'route_name' => post('route_name'), 'price' => (float)post('price', 0), 'qty' => (int)post('qty', 0),
            'is_rx' => (int)post('is_rx', 0), 'is_limited' => (int)post('is_limited', 0),
            'note' => post('note'), 'need_nurse' => (int)post('need_nurse', 0),
        );
        if ($id > 0) {
            $set = array(); $params = array();
            foreach ($data as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
            $params[] = $id;
            DB::exec('drug', 'UPDATE drugs SET ' . implode(',', $set) . ' WHERE id=?', $params);
            json_ok(array(), '药品已保存');
        }
        // 新增药品：待审核
        $params = array_values($data);
        $params[] = 'pending';
        $params[] = now_str();
        $newId = DB::insert('drug', 'INSERT INTO drugs(generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency_name, route_name, price, qty, is_rx, is_limited, note, need_nurse, name, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge(
            array_slice($params, 0, 16), array($name), array_slice($params, 16)
        ));
        DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
            'item_drug', $newId, '药品添加：' . $name,
            '新增药品「' . $name . '」（分类：' . $data['category'] . '，价格：¥' . money($data['price']) . '），请审核',
            'pending', $u['name'], $u['id'], now_str(),
        ));
        json_ok(array(), '药品已添加，请到【审核中心】审核后即可开方');
        break;

    case 'drug_delete':
        $id = (int)post('id');
        DB::exec('drug', 'DELETE FROM drugs WHERE id=?', array($id));
        json_ok(array(), '药品已删除');
        break;

    /* ==================== 处置项目 ==================== */
    case 'disposal_list':
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
        break;

    case 'disposal_form':
        $id = (int)get('id', 0);
        $r = $id ? DB::one('disp', 'SELECT * FROM disposal_items WHERE id=?', array($id)) : array('name' => '', 'fee' => '0', 'description' => '');
        $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
        <div class="form-group"><label class="form-label">处置名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '" placeholder="如：清创缝合、换药"></div>
        <div class="form-group"><label class="form-label">费用（元）</label>
            <input class="input" type="number" step="0.01" min="0" id="f_fee" value="' . e($r['fee']) . '"></div>
        <div class="form-group"><label class="form-label">描述备注</label>
            <textarea class="textarea" id="f_desc" rows="3">' . e($r['description']) . '</textarea></div>';
        json_ok(array('html' => $html));
        break;

    case 'disposal_save':
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
        break;

    case 'disposal_delete':
        $id = (int)post('id');
        DB::exec('disp', 'DELETE FROM disposal_items WHERE id=?', array($id));
        json_ok(array(), '处置项目已删除');
        break;

    /* ==================== 打印中心：某就诊可打印单据一览 ==================== */
    case 'print_items':
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $hasRecord = (int)DB::val('medical', 'SELECT COUNT(*) FROM records WHERE visit_id=?', array($visitId)) > 0;
        $hasCert = (int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($visitId)) > 0;
        $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id', array($visitId));
        $typeNames = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置单', 'prescription' => '处方单');
        $html = '<div class="card" style="padding:14px">' .
            '<div class="fw-700 fs-15">' . e($row['patient']['name']) . '（' . e($visit['flow_no']) . '）</div>' .
            '<div class="fs-13 text-muted mt-4 mb-12">' . e($visit['first_dept_name']) . ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ ' . e(substr($visit['register_time'], 0, 16)) . '</div>';
        $html .= '<div class="flex gap-8" style="flex-wrap:wrap">' .
            '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=receipt&visit_id=' . (int)$visitId . '\',null)">挂号凭条</button>' .
            ($hasRecord ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' . (int)$visitId . '\',null)">电子病历</button>' : '') .
            ($hasCert ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=certificate&visit_id=' . (int)$visitId . '\',null)">诊断证明</button>' : '') .
            '</div>';
        if ($orders) {
            $html .= '<div class="fs-13 fw-600 mt-12 mb-4">开单单据</div><div class="flex gap-8" style="flex-wrap:wrap">';
            foreach ($orders as $o) {
                $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=order&order_id=' . (int)$o['id'] . '\',null)">' .
                    e(isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : $o['order_type']) . '（' . e($o['order_no']) . '）</button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        json_ok(array('html' => $html));
        break;

    /* ==================== 审核中心 ==================== */
    case 'audit_list':
        $status = get('status', 'pending');
        $rows = DB::q('core', 'SELECT * FROM audits WHERE status=? ORDER BY id DESC', array($status));
        $html = '<div class="fs-13 text-muted mb-8">' . ($status === 'pending' ? '待审核' : '已处理') . '：' . count($rows) . ' 条</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">📋</div>暂无待审核事项</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>类型</th><th>事项</th><th>申请人</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            $typeNames = array(
                'template' => '病历模板', 'item_lab' => '检验项目添加', 'item_exam' => '检查项目添加',
                'item_drug' => '药品添加', 'item_disp' => '处置项目添加', 'report_withdraw' => '报告撤回',
            );
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td><span class="badge badge-primary">' . e(isset($typeNames[$r['type']]) ? $typeNames[$r['type']] : $r['type']) . '</span></td>' .
                    '<td><div class="fw-600 fs-13">' . e($r['title']) . '</div><div class="fs-12 text-muted">' . e($r['content']) . '</div></td>' .
                    '<td>' . e($r['proposer']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 0, 16)) . '</td>' .
                    '<td>' . ($r['status'] === 'pending' ? '<span class="badge badge-warning">待审核</span>' : ($r['status'] === 'approved' ? '<span class="badge badge-success">已通过</span>' : '<span class="badge badge-gray">已驳回</span>')) . '</td>' .
                    '<td>';
                if ($r['status'] === 'pending') {
                    $html .= '<div class="flex gap-4">' .
                        '<button class="btn btn-success btn-sm" onclick="doAudit(' . (int)$r['id'] . ',1)">通过</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="doAudit(' . (int)$r['id'] . ',0)">驳回</button></div>';
                } else {
                    $html .= '<span class="fs-12 text-muted">' . e($r['handled_by']) . ' ' . e(substr($r['handled_at'], 5, 11)) . '</span>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    case 'audit':
        $id = (int)post('id');
        $approve = (int)post('approve', 0);
        $audit = DB::one('core', 'SELECT * FROM audits WHERE id=? AND status=?', array($id, 'pending'));
        if (!$audit) json_fail('审核事项不存在或已处理');
        $newStatus = $approve ? 'approved' : 'rejected';
        DB::exec('core', "UPDATE audits SET status=?, handled_by=?, handled_at=?, note=? WHERE id=?", array($newStatus, $u['name'], now_str(), post('note'), $id));
        $refId = (int)$audit['ref_id'];
        $proposerId = (int)$audit['proposer_id'];
        switch ($audit['type']) {
            case 'template':
                DB::exec('medical', "UPDATE templates SET status=? WHERE id=?", array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg('doctor', $proposerId, '病历模板审核结果',
                        '您的病历模板审核' . ($approve ? '已通过' : '未通过') . ($approve ? '，现在可以使用' : '：' . post('note')), '', '');
                }
                break;
            case 'item_lab':
                DB::exec('lab', "UPDATE lab_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_exam':
                DB::exec('lab', "UPDATE exam_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_drug':
                DB::exec('drug', "UPDATE drugs SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_disp':
                DB::exec('disp', "UPDATE disposal_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'report_withdraw':
                if ($approve) {
                    // 批准撤回：报告作废，结果回到草稿，检验/检查项目回到已登记可重新录入
                    // 注意：分散式数据库下 results（lab 库）与 order_items（order 库）不可跨库子查询，
                    // 必须先从 results 取出 order_item_id，再更新 order 库
                    $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($refId));
                    if ($report) {
                        DB::exec('lab', "UPDATE reports SET status='withdrawn', withdraw_reason=?, withdraw_by=?, withdraw_at=? WHERE id=?", array($audit['content'], $u['name'], now_str(), $refId));
                        DB::exec('lab', "UPDATE results SET status='draft' WHERE id=?", array($report['result_id']));
                        $result = DB::one('lab', 'SELECT order_item_id FROM results WHERE id=?', array($report['result_id']));
                        if ($result && (int)$result['order_item_id'] > 0) {
                            DB::exec('order', "UPDATE order_items SET status='registered' WHERE id=?", array((int)$result['order_item_id']));
                        }
                    }
                }
                break;
        }
        json_ok(array(), $approve ? '已通过审核' : '已驳回');
        break;

    default:
        json_fail('未知操作');
}
