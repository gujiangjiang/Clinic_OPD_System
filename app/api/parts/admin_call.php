<?php
/**
 * ============================================================
 * parts/admin_call.php v1.0.0 — 管理端：叫号大屏/诊室管理
 * ============================================================
 * 说明：
 * 1. room_list     按科室列出大屏配置（含绑定/在线状态）
 * 2. room_save     新增/编辑诊室（名称/类型/语音/脱敏）
 * 3. room_create   新建诊室：自动生成 32 位随机 screen_token
 * 4. room_token    重新生成 Token（旧链接立即失效）
 * 5. room_release  强制释放诊室绑定（解除医生占用）
 * 6. room_delete   删除诊室
 * ============================================================ */

/**
 * 处理叫号管理动作
 * @param string $action 动作名
 */
function admin_part_call($action) {
    $u = Auth::user();

    /* ==================== 按科室列出大屏 ==================== */
    if ($action === 'room_list') {
        $deptId = (int)get('dept_id');
        if ($deptId <= 0) json_fail('请选择科室');
        $dept = DeptRepository::one('SELECT * FROM departments WHERE id=?', array($deptId));
        if (!$dept) json_fail('科室不存在');
        $rows = DeptRepository::q('SELECT * FROM clinic_rooms WHERE dept_id=? ORDER BY id', array($deptId));
        $typeNames = array('doctor' => '医生诊室', 'lab' => '检验科', 'imaging' => '影像科', 'pharmacy' => '药房', 'nurse' => '护士站');
        $rowsHtml = '<thead><tr>' .
            '<th>诊室/窗口</th><th>类型</th><th>大屏状态</th><th>绑定</th><th>Token</th><th>设置</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $online = (!empty($r['screen_last_heartbeat']) && (time() - strtotime($r['screen_last_heartbeat'])) <= 30);
            $st = $online
                ? '<span class="badge badge-success">🟢 在线运行中</span><div class="fs-12 text-muted mt-4">最后活跃 ' . e(substr((string)$r['screen_last_heartbeat'], 5, 16)) . '</div>'
                : '<span class="badge badge-gray">⚫ 离线未连接</span>';
            $bind = $r['current_doctor_id'] > 0
                ? '<span class="badge badge-warning">' . e($r['current_doctor_name']) . ' 正在坐诊</span>'
                : '<span class="badge badge-gray">空闲</span>';
            $rowsHtml .= '<tr data-id="' . (int)$r['id'] . '" data-token="' . e($r['screen_token']) . '"' .
                ' data-tips="' . e($r['screen_tips']) . '" data-interval="' . (int)$r['tip_interval'] . '"' .
                ' data-room-name="' . e($r['room_name']) . '" data-room-type="' . e($r['room_type']) . '"' .
                ' data-room-voice="' . (int)$r['enable_voice'] . '" data-room-mask="' . (int)$r['enable_mask'] . '"' .
                ' data-room-cross="' . (int)$r['allow_cross_day'] . '">' .
                '<td class="fw-600">' . e($r['room_name']) . '</td>' .
                '<td>' . e(isset($typeNames[$r['room_type']]) ? $typeNames[$r['room_type']] : $r['room_type']) . '</td>' .
                '<td>' . $st . '</td>' .
                '<td>' . $bind . '</td>' .
                '<td class="fs-12" style="font-family:monospace;word-break:break-all;max-width:180px">' . e($r['screen_token']) . '</td>' .
                '<td>' .
                    '<span class="fs-12">' . ($r['enable_voice'] ? '🔊' : '🔇') . ' ' . ($r['enable_mask'] ? '脱敏' : '实名') .
                    ' ' . ((int)$r['allow_cross_day'] === 1 ? '🌙跨天' : '') . '</span></td>' .
                // 操作按钮改为事件委托（data-room-id）：用户可控名称/Token 不再嵌入 onclick
                // 字符串，杜绝引号/HTML 注入（原 e() 转义在属性值解码后无法覆盖单引号截断）
                '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" data-room-action="preview" data-room-id="' . (int)$r['id'] . '">预览</button>' .
                    '<button class="btn btn-outline btn-sm" data-room-action="copy" data-room-id="' . (int)$r['id'] . '">复制链接</button>' .
                    '<button class="btn btn-outline btn-sm" data-room-action="reset" data-room-id="' . (int)$r['id'] . '">重置Token</button>' .
                    ((int)$r['current_doctor_id'] > 0 ? '<button class="btn btn-warning btn-sm" data-room-action="release" data-room-id="' . (int)$r['id'] . '">强制释放</button>' : '') .
                    '<button class="btn btn-outline btn-sm" data-room-action="edit" data-room-id="' . (int)$r['id'] . '">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" data-room-action="del" data-room-id="' . (int)$r['id'] . '">删除</button>' .
                    '</div></td></tr>';
        }
        $rowsHtml .= '</tbody>';
        $html = render_list_wrapper('「' . e($dept['name']) . '」共 ' . count($rows) . ' 块大屏', '暂无大屏配置，请先新建', $rowsHtml);
        json_ok(array('html' => $html, 'dept_name' => $dept['name'],
            'total_count' => count($rows),
            'online_count' => (int)DeptRepository::val("SELECT COUNT(*) FROM clinic_rooms WHERE dept_id=? AND screen_last_heartbeat IS NOT NULL AND (strftime('%s','now','localtime') - strftime('%s',screen_last_heartbeat)) <= 30", array($deptId))));
    }

    /* ==================== 全科室大屏统计（选择科室模态框实时数据源） ==================== */
    if ($action === 'room_stats') {
        $depts = DeptRepository::q('SELECT id FROM departments WHERE status=1 ORDER BY sort, id');
        $stats = array();
        foreach ($depts as $d) {
            $total = (int)DeptRepository::val('SELECT COUNT(*) FROM clinic_rooms WHERE dept_id=?', array((int)$d['id']));
            $online = (int)DeptRepository::val("SELECT COUNT(*) FROM clinic_rooms WHERE dept_id=? AND screen_last_heartbeat IS NOT NULL AND (strftime('%s','now','localtime') - strftime('%s',screen_last_heartbeat)) <= 30", array((int)$d['id']));
            $stats[] = array('id' => (int)$d['id'], 'room_count' => $total, 'online_count' => $online);
        }
        json_ok(array('list' => $stats));
    }

    /* ==================== 新建诊室 ==================== */
    if ($action === 'room_create') {
        $deptId = (int)post('dept_id');
        $roomName = trim(post('room_name'));
        $roomType = post('room_type', 'doctor');
        if ($deptId <= 0) json_fail('请选择科室');
        if ($roomName === '') json_fail('请填写诊室/窗口名称');
        if (!in_array($roomType, array('doctor', 'lab', 'imaging', 'pharmacy', 'nurse'), true)) $roomType = 'doctor';
        $token = bin2hex(random_bytes(16));
        DeptRepository::insert(            'INSERT INTO clinic_rooms(dept_id, room_name, room_type, screen_token, enable_voice, enable_mask, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)',
            array($deptId, $roomName, $roomType, $token, 1, 1, now_str(), now_str()));
        json_ok(array('token' => $token), '诊室已创建');
    }

    /* ==================== 编辑诊室（名称/类型/语音/脱敏/跨天/温馨提示） ==================== */
    if ($action === 'room_save') {
        $id = (int)post('id');
        $roomName = trim(post('room_name'));
        $roomType = post('room_type', 'doctor');
        $voice = (int)post('enable_voice', 1);
        $mask = (int)post('enable_mask', 1);
        $crossDay = (int)post('allow_cross_day', 0);
        // 温馨提示：JSON 数组字符串（前端提交），空字符串则清空
        $tips = trim(post('screen_tips', ''));
        $tipInterval = max(2, (int)post('tip_interval', 5));
        if ($id <= 0) json_fail('参数错误');
        if ($roomName === '') json_fail('请填写诊室名称');
        DeptRepository::exec('UPDATE clinic_rooms SET room_name=?, room_type=?, enable_voice=?, enable_mask=?, allow_cross_day=?, screen_tips=?, tip_interval=?, updated_at=? WHERE id=?',
            array($roomName, $roomType, $voice, $mask, $crossDay, $tips, $tipInterval, now_str(), $id));
        json_ok(array(), '诊室已更新');
    }

    /* ==================== 重置 Token（旧链接立即失效） ==================== */
    if ($action === 'room_reset_token') {
        $id = (int)post('id');
        $newToken = bin2hex(random_bytes(16));
        DeptRepository::exec('UPDATE clinic_rooms SET screen_token=?, updated_at=? WHERE id=?', array($newToken, now_str(), $id));
        json_ok(array('token' => $newToken), 'Token 已重置，旧大屏链接已失效');
    }

    /* ==================== 强制释放诊室绑定 ==================== */
    if ($action === 'room_release') {
        $id = (int)post('id');
        DeptRepository::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE id=?', array(now_str(), $id));
        json_ok(array(), '诊室已强制释放');
    }

    /* ==================== 删除诊室 ==================== */
    if ($action === 'room_delete') {
        $id = (int)post('id');
        DeptRepository::exec('DELETE FROM clinic_rooms WHERE id=?', array($id));
        json_ok(array(), '诊室已删除');
    }

    json_fail('未知操作');
}
