<?php
/**
 * ============================================================
 * doctor.php — 医生工作站接口
 * ============================================================
 * 说明：
 * 1. 医生关联多个科室时先选科室；单科室直接进入患者列表
 * 2. 患者列表：待就诊 / 就诊中 / 就诊完毕 三个状态
 *    就诊序号显示首次挂号科室（XX门诊XXX号，不随转科改变）
 * 3. 接诊：进入就诊中，若该就诊有转科记录则返回原病历ID
 *    （新科室医生可一键引用）
 * 4. 加号：号源满时医生可为指定患者加号（仅该患者可用）
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 当前医生关联科室ID列表（用于权限校验与科室回退） */
function doctor_dept_ids($u) {
    $ids = array();
    // 会话快照中的 dept_ids 可能为 NULL（如管理员登录医生端接口时），
    // 先判空再拆分，避免 PHP 8 的 Undefined key / Deprecated 告警污染 JSON
    foreach (explode(',', isset($u['dept_ids']) ? (string)$u['dept_ids'] : '') as $id) {
        if ((int)$id > 0) $ids[] = (int)$id;
    }
    return $ids;
}

/** 科室是否为限号（门诊且上/下午号源数量 > 0；急诊与 0 号源为不限号） */
function dept_is_limited($d) {
    return $d['type'] === 'clinic' && ((int)$d['am_quota'] > 0 || (int)$d['pm_quota'] > 0);
}

switch ($action) {

    /* ==================== 医生首页统计 ==================== */
    case 'home_stats':
        $uid = (int)$u['id'];
        $today = date('Y-m-d');
        // 今日接诊人次（本人）
        $todayVisits = (int)DB::val('medical', "SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $today));
        // 今日开单金额（本人、已缴费、排除退费取消）
        $sums = array('drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
        foreach (DB::q('order', "SELECT order_type, COALESCE(SUM(total_amount),0) s FROM orders WHERE doctor_id=? AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=? GROUP BY order_type", array($uid, $today)) as $r) {
            if (isset($sums[$r['order_type']])) $sums[$r['order_type']] = round((float)$r['s'], 2);
        }
        // 我的草稿病历（待完成接诊）
        $drafts = (int)DB::val('medical', "SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND status='draft'", array($uid));
        // 今日门诊人次（全部科室）
        $todayReg = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=?", array($today));
        // 近7天本人接诊趋势
        $labels = array();
        $series = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $series[] = (int)DB::val('medical', "SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $day));
        }
        json_ok(array(
            'kpi' => array('today_visits' => $todayVisits, 'today_reg' => $todayReg, 'total' => round(array_sum($sums), 2),
                'drug' => $sums['drug'], 'lab' => $sums['lab'], 'imaging' => $sums['imaging'], 'procedure' => $sums['procedure'], 'drafts' => $drafts),
            'trend' => array('labels' => $labels, 'data' => $series),
        ));
        break;

    /* ==================== 医生关联科室 ==================== */
    case 'depts':
        $ids = doctor_dept_ids($u);
        $curRow = DB::one('user', 'SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
        $curDeptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $list = DB::q('dept', "SELECT * FROM departments WHERE status=1 AND id IN ($ph) ORDER BY sort, id", $ids);
        } else {
            $list = array();
        }
        json_ok(array(
            'list' => array_map(function ($d) {
                return array(
                    'id' => (int)$d['id'],
                    'name' => $d['name'],
                    'type' => $d['type'],
                    'limited' => dept_is_limited($d) ? 1 : 0,
                );
            }, $list),
            'current' => $curDeptId,
        ));
        break;

    /* ==================== 医生切换当前科室（叫号屏跟随该选择动态显示） ==================== */
    case 'set_dept':
        $deptId = (int)post('dept_id');
        $ids = doctor_dept_ids($u);
        if (!in_array($deptId, $ids, true)) json_fail('无权选择该科室');
        $dept = DB::one('dept', 'SELECT id FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) json_fail('科室不存在或已停用');
        DB::exec('user', 'UPDATE users SET current_dept_id=? WHERE id=?', array($deptId, $u['id']));
        Auth::updateSession('current_dept_id', $deptId);
        json_ok(array('dept_id' => $deptId), '科室已切换');
        break;

    /* ==================== 患者列表（HTML 片段） ==================== */
    case 'list':
        $status = get('status', 'waiting');
        $deptId = (int)get('dept_id', 0);
        if ($status === 'waiting') {
            $where = "r.status='paid'";
        } elseif ($status === 'visiting') {
            $where = "r.status='visiting'";
        } else {
            $where = "r.status='finished' AND date(r.register_time)=?";
        }
        $where .= ' AND r.current_dept_id=' . $deptId;
        $params = array();
        if ($status === 'done') $params[] = today_str();
        $rows = DB::q('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page, p.birth_date AS pbirth
            FROM registrations r LEFT JOIN patients p ON p.patient_no = r.patient_no
            WHERE $where ORDER BY r.visit_seq", $params);

        $html = '';
        if (!$rows) {
            $html = '<div class="empty"><div class="empty-ico">📭</div>' . ($status === 'waiting' ? '暂无候诊患者' : ($status === 'visiting' ? '暂无就诊中患者' : '今日暂无就诊完毕患者')) . '</div>';
        }
        foreach ($rows as $r) {
            $html .= '<div class="card" style="margin-bottom:10px;padding:14px 16px">';
            $html .= '<div class="flex-between">';
            $html .= '<div class="flex gap-12" style="align-items:center;min-width:0">' .
                '<div style="width:42px;height:42px;border-radius:50%;background:var(--primary-soft);color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0">' .
                str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '</div>';
            $html .= '<div style="min-width:0">' .
                '<div class="fs-16 fw-700">' . e($r['pname']) .
                ' <span class="fs-13 text-muted fw-400">' . e($r['pgender']) . ' / ' . age_format($r['pbirth'], $r['register_time']) . '</span>' .
                ($r['is_extra'] ? ' <span class="badge badge-warning" style="font-size:11px">加号</span>' : '') .
                '</div>' .
                '<div class="fs-12 text-muted">' . e($r['first_dept_name']) . ' 第' . str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ 患者ID ' . e($r['patient_no']) . ' ｜ 流水号 ' . e($r['flow_no']) . '</div>' .
                '<div class="fs-12 text-muted">挂号 ' . e(substr($r['register_time'], 5, 11)) . ' ｜ 费用类别 ' . e($r['fee_type']) . '</div>' .
                '</div></div>';
            // 操作按钮
            $html .= '<div class="flex gap-8" style="flex-shrink:0">';
            if ($status === 'waiting') {
                $html .= '<button class="btn btn-primary btn-sm" onclick="takePatient(\'' . e(oid($r['id'])) . '\')">接诊</button>';
                $html .= '<button class="btn btn-outline btn-sm" onclick="showPatientHistory(' . e($r['patient_no']) . ')">历史</button>';
            } elseif ($status === 'visiting') {
                $html .= '<button class="btn btn-primary btn-sm" onclick="location.href=\'/doctor/emr?visit_id=' . e(oid($r['id'])) . '\'">继续就诊</button>';
                $html .= '<button class="btn btn-outline btn-sm" onclick="showPatientHistory(\'' . e($r['patient_no']) . '\')">历史</button>';
            } else {
                $html .= '<button class="btn btn-outline btn-sm" onclick="location.href=\'/doctor/emr?visit_id=' . e(oid($r['id'])) . '\'">查看病历</button>';
                $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' . e(oid($r['id'])) . '\',null,\'a5\')">打印病历</button>';
            }
            $html .= '</div></div></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 接诊 ==================== */
    case 'take':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        if ($visit['status'] !== 'paid') {
            json_fail('该患者当前状态不可接诊');
        }
        DB::exec('patient', 'UPDATE registrations SET status=? WHERE id=?', array('visiting', $visitId));
        // 转科引用：返回最近一次转科的原始病历ID（新科室医生一键引用）
        $ref = DB::one('medical', 'SELECT ref_record_id FROM referrals WHERE visit_id=? ORDER BY id DESC', array($visitId));
        json_ok(array('ref_record_id' => $ref ? oid($ref['ref_record_id']) : 0), '接诊成功');
        break;

    /* ==================== 叫号屏队列（需求22：诊室门口叫号屏幕） ==================== */
    // 科室完全跟随医生端选择：不传 dept_id（或为 0）时取用户记录中的
    // current_dept_id；医生尚未选择时回退到其关联的第一个可用科室。
    case 'call_queue':
        $deptId = (int)get('dept_id', 0);
        if ($deptId <= 0) {
            $curRow = DB::one('user', 'SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
            $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        }
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) {
            $ids = doctor_dept_ids($u);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $first = DB::one('dept', "SELECT * FROM departments WHERE status=1 AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
                if ($first) $dept = $first;
            }
        }
        if (!$dept) json_fail('当前医生未关联可用科室');
        $deptId = (int)$dept['id'];

        // 该科室当前就诊中患者（最新的）
        $current = DB::one('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.current_dept_id=? AND r.status='visiting' ORDER BY r.id DESC LIMIT 1", array($deptId));
        // 下一位候诊患者（按就诊序号取最早的一位）
        $next = DB::one('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.register_time LIMIT 1", array($deptId));
        $waiting = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE current_dept_id=? AND status='paid'", array($deptId));

        // 该科室出诊医生（按用户-科室关联过滤）
        // 注意：必须 SELECT dept_ids，否则下面 explode() 拿不到关联科室，doctors 恒为空
        $doctors = array();
        $docs = DB::q('user', "SELECT name, emp_no, title, photo, intro, dept_ids FROM users WHERE role='doctor' AND status=1 ORDER BY id");
        foreach ($docs as $doc) {
            $ids = array();
            foreach (explode(',', isset($doc['dept_ids']) ? $doc['dept_ids'] : '') as $x) {
                if ((int)$x > 0) $ids[] = (int)$x;
            }
            if (in_array($deptId, $ids, true)) {
                $doctors[] = array(
                    'name' => $doc['name'], 'emp_no' => $doc['emp_no'],
                    'title' => $doc['title'],
                    'photo' => $doc['photo'] ? img_data($doc['photo']) : '',
                    'intro' => $doc['intro'],
                );
            }
        }

        $fmt = function ($r) use ($deptId) {
            if (!$r) return null;
            // 复诊标记（预留）：同一患者当日在本科室已有其他就诊记录（已缴费/就诊中/已诊毕）
            $follow = 0;
            if (!empty($r['patient_no'])) {
                $follow = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations
                    WHERE patient_no=? AND current_dept_id=? AND date(register_time)=?
                    AND status IN ('paid','visiting','finished') AND id<>?",
                    array($r['patient_no'], $deptId, today_str(), $r['id']));
            }
            return array(
                'name' => $r['pname'], 'gender' => $r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['register_time']),
                'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
                'patient_no' => $r['patient_no'], 'register_time' => $r['register_time'],
                'is_followup' => $follow > 0 ? 1 : 0,
            );
        };
        json_ok(array(
            'dept' => array('id' => (int)$dept['id'], 'name' => $dept['name'], 'type' => $dept['type']),
            'current' => $fmt($current),
            'next' => $fmt($next),
            'waiting' => $waiting,
            'doctors' => $doctors,
        ));
        break;

    /* ==================== 报告文字结果（病历项目详情内联展示） ====================
     * 按 report_id 返回结构化报告：检验=指标行（名称/结果/单位/范围/危急值）；
     * 检查=影像所见与诊断结论。供医生站项目详情弹窗内联渲染（打印预览另有接口）。 */
    case 'report_detail':
        $rid = did(get('report_id'));
        $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($rid));
        if (!$report) json_fail('报告不存在');
        $result = DB::one('lab', 'SELECT * FROM results WHERE id=?', array($report['result_id']));
        $itemName = '';
        $rows = array();
        $findings = '';
        $conclusion = '';
        if ($result && $result['type'] === 'lab') {
            $li = DB::one('lab', 'SELECT * FROM lab_items WHERE id=?', array($result['item_id']));
            $itemName = $li ? $li['name'] : '';
            $values = json_decode($result['values_json'], true);
            if (is_array($values) && !empty($values['group'])) {
                $members = DB::q('lab', 'SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$result['item_id']));
                foreach ($members as $m) {
                    $v = isset($values['values'][(string)$m['id']]) ? $values['values'][(string)$m['id']] : '';
                    $rows[] = array(
                        'name' => $m['name'], 'value' => $v, 'unit' => $m['unit'],
                        'range' => $m['normal_range'],
                        'critical' => trim(($m['critical_low'] !== '' ? '低' . $m['critical_low'] : '') .
                            ($m['critical_high'] !== '' ? ' 高' . $m['critical_high'] : '')),
                    );
                }
            } else {
                $rows[] = array(
                    'name' => $itemName, 'value' => is_array($values) && isset($values['value']) ? $values['value'] : '',
                    'unit' => $li ? $li['unit'] : '',
                    'range' => $li ? $li['normal_range'] : '',
                    'critical' => $li ? trim(($li['critical_low'] !== '' ? '低' . $li['critical_low'] : '') .
                        ($li['critical_high'] !== '' ? ' 高' . $li['critical_high'] : '')) : '',
                );
            }
        }
        if ($result && $result['type'] === 'imaging') {
            $ei = DB::one('lab', 'SELECT * FROM exam_items WHERE id=?', array($result['item_id']));
            $itemName = $ei ? $ei['name'] : '';
            $findings = (string)$result['findings'];
            $conclusion = (string)$result['conclusion'];
        }
        json_ok(array(
            'type' => $result ? $result['type'] : 'lab',
            'item_name' => $itemName,
            'rows' => $rows,
            'findings' => $findings,
            'conclusion' => $conclusion,
            'executor' => $report['doctor'],
            'time' => $report['created_at'],
        ));
        break;

    /* ==================== 医生加号（号源满时，加号仅限该患者使用） ==================== */
    case 'add_slot':
        $deptId = (int)post('dept_id');
        $idCard = strtoupper(post('id_card'));
        $name = post('name');
        if ($deptId <= 0) json_fail('请选择科室');
        if ($idCard === '' || !idcard_valid($idCard)) json_fail('请输入正确的18位身份证号码');
        if ($name === '') json_fail('请填写患者姓名');
        $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) json_fail('科室不存在');
        // 不限号科室无需加号（仅限号科室提供医生加号功能）
        if (!dept_is_limited($dept)) json_fail('该科室为不限号科室，无需加号');
        // 同一患者当日同科室已有加号未使用时，不重复添加
        $exists = DB::one('dept', "SELECT id FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0", array($deptId, today_str(), $idCard));
        if ($exists) json_fail('该患者今日已存在未使用的加号');
        DB::insert('dept', 'INSERT INTO extra_slots(dept_id, reg_date, id_card, name, doctor_id, doctor_name, used, created_at) VALUES(?,?,?,?,?,?,0,?)', array(
            $deptId, today_str(), $idCard, $name, $u['id'], $u['name'], now_str(),
        ));
        json_ok(array(), '加号成功：患者凭本人身份证至挂号处挂号即可');
        break;

    /* ==================== 获取可用大屏列表（诊室绑定选择器） ==================== */
    // 状态判定（心跳阈值 30 秒）：
    //   offline 大屏离线（未开启/断网） → 禁用，提示联系管理员
    //   occupied 已被其他医生绑定 → 禁用，显示占用医生
    //   available 在线空闲 → 可选
    //   bound 当前医生已绑定 → 显示为当前绑定
    case 'get_available_rooms':
        $deptId = (int)get('dept_id');
        if ($deptId <= 0) json_fail('请先选择科室');
        $rows = DB::q('clinic_rooms', "SELECT * FROM clinic_rooms WHERE dept_id=? AND room_type='doctor' ORDER BY id", array($deptId));
        $list = array();
        foreach ($rows as $room) {
            $isOnline = (!empty($room['screen_last_heartbeat']) && (time() - strtotime($room['screen_last_heartbeat'])) <= 30);
            if (!$isOnline) {
                $status = 'offline'; $text = '大屏离线，请联系管理员'; $sel = false;
            } elseif ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
                $status = 'occupied'; $text = $room['current_doctor_name'] . ' 正在坐诊'; $sel = false;
            } else {
                $status = ($room['current_doctor_id'] == $u['id']) ? 'bound' : 'available';
                $text = $status === 'bound' ? '已绑定' : '在线空闲'; $sel = true;
            }
            $list[] = array(
                'id' => (int)$room['id'], 'name' => $room['room_name'],
                'status' => $status, 'status_text' => $text, 'selectable' => $sel,
            );
        }
        // 当前医生已绑定的诊室（跨科室，供右上角显示）
        $myBound = DB::one('clinic_rooms', "SELECT * FROM clinic_rooms WHERE current_doctor_id=? ORDER BY id DESC LIMIT 1", array($u['id']));
        json_ok(array('list' => $list, 'bound' => $myBound ? array('id' => (int)$myBound['id'], 'name' => $myBound['room_name']) : null));
        break;

    /* ==================== 绑定大屏（防冲突强拦截） ==================== */
    case 'bind_room':
        $roomId = (int)post('room_id');
        $room = DB::one('clinic_rooms', 'SELECT * FROM clinic_rooms WHERE id=?', array($roomId));
        if (!$room) json_fail('诊室不存在');
        // 后端强拦截：大屏必须在线
        if (empty($room['screen_last_heartbeat']) || (time() - strtotime($room['screen_last_heartbeat'])) > 30) {
            json_fail('该大屏当前处于离线状态，无法绑定，请确保大屏已开启并在运行！');
        }
        // 已被其他医生占用 → 拒绝
        if ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
            json_fail('该大屏已被 ' . $room['current_doctor_name'] . ' 占用，无法绑定');
        }
        // 释放该医生此前绑定的其他诊室（一人一块屏）
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL WHERE current_doctor_id=?', array($u['id']));
        // 绑定当前诊室
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET current_doctor_id=?, current_doctor_name=?, doctor_heartbeat=?, updated_at=? WHERE id=?',
            array($u['id'], $u['name'], now_str(), now_str(), $roomId));
        json_ok(array('room_id' => $roomId, 'room_name' => $room['room_name']), '已绑定大屏「' . $room['room_name'] . '」');
        break;

    /* ==================== 解除绑定 / 释放诊室 ==================== */
    case 'unbind_room':
        $roomId = (int)post('room_id');
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE id=? AND current_doctor_id=?',
            array(now_str(), $roomId, $u['id']));
        json_ok(array(), '已释放诊室');
        break;

    /* ==================== 医生端心跳保活（每 30 秒） ==================== */
    case 'room_heartbeat':
        $roomId = (int)post('room_id');
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET doctor_heartbeat=?, updated_at=? WHERE id=? AND current_doctor_id=?',
            array(now_str(), now_str(), $roomId, $u['id']));
        json_ok(array());
        break;

    default:
        json_fail('未知操作');
}
