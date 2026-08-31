<?php
/**
 * ============================================================
 * parts/doctor_read.php — 医生端：读取
 * ============================================================
 * doctor.php 按功能拆分的一部分，读取类动作。
 * ============================================================ */

function doctor_part_read($action) {
    $u = Auth::user();

    if ($action === 'home_stats') {
        $uid = (int)$u['id'];
        $today = date('Y-m-d');
        // 今日接诊人次（本人）
        $todayVisits = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $today));
        // 今日开单金额（本人、已缴费、排除退费取消）
        $sums = array('drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
        foreach (EmrRepository::q("SELECT order_type, COALESCE(SUM(total_amount),0) s FROM orders WHERE doctor_id=? AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=? GROUP BY order_type", array($uid, $today)) as $r) {
            if (isset($sums[$r['order_type']])) $sums[$r['order_type']] = round((float)$r['s'], 2);
        }
        // 我的草稿病历（待完成接诊）
        $drafts = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND status='draft'", array($uid));
        // 今日门诊人次（全部科室）
        $todayReg = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=?", array($today));
        // 近7天本人接诊趋势
        $labels = array();
        $series = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $series[] = (int)EmrRepository::val("SELECT COUNT(*) FROM patient_records WHERE doctor_id=? AND date(created_at)=?", array($uid, $day));
        }
        json_ok(array(
            'kpi' => array('today_visits' => $todayVisits, 'today_reg' => $todayReg, 'total' => round(array_sum($sums), 2),
                'drug' => $sums['drug'], 'lab' => $sums['lab'], 'imaging' => $sums['imaging'], 'procedure' => $sums['procedure'], 'drafts' => $drafts),
            'trend' => array('labels' => $labels, 'data' => $series),
        ));
        return;
    }

    if ($action === 'depts') {
        $ids = doctor_dept_ids($u);
        $curRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
        $curDeptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $list = EmrRepository::q("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id", $ids);
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
        return;
    }

    if ($action === 'list') {
        $status = get('status', 'waiting');
        $deptId = (int)get('dept_id', 0);
        if ($status === 'waiting') {
            $where = "r.status='paid'";
        } elseif ($status === 'visiting') {
            $where = "r.status='visiting'";
        } else {
            $where = "r.status='finished' AND date(r.registered_at)=?";
        }
        $where .= ' AND r.current_dept_id=' . $deptId;
        $params = array();
        if ($status === 'done') $params[] = today_str();
        $rows = EmrRepository::q("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page, p.birth_date AS pbirth
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
                ' <span class="fs-13 text-muted fw-400">' . e($r['pgender']) . ' / ' . age_format($r['pbirth'], $r['registered_at']) . '</span>' .
                ($r['is_extra'] ? ' <span class="badge badge-warning" style="font-size:11px">加号</span>' : '') .
                '</div>' .
                '<div class="fs-12 text-muted">' . e($r['first_dept_name']) . ' 第' . str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ 患者ID ' . e($r['patient_no']) . ' ｜ 流水号 ' . e($r['flow_no']) . '</div>' .
                '<div class="fs-12 text-muted">挂号 ' . e(substr($r['registered_at'], 5, 11)) . ' ｜ 费用类别 ' . e($r['fee_type']) . '</div>' .
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
        return;
    }

    if ($action === 'call_queue') {
        $deptId = (int)get('dept_id', 0);
        if ($deptId <= 0) {
            $curRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
            $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        }
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=? AND status=1', array($deptId));
        if (!$dept) {
            $ids = doctor_dept_ids($u);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $first = EmrRepository::one("SELECT * FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
                if ($first) $dept = $first;
            }
        }
        if (!$dept) json_fail('当前医生未关联可用科室');
        $deptId = (int)$dept['id'];

        // 该科室当前就诊中患者（最新的）
        $current = EmrRepository::one("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.current_dept_id=? AND r.status='visiting' ORDER BY r.id DESC LIMIT 1", array($deptId));
        // 下一位候诊患者（按就诊序号取最早的一位）
        $next = EmrRepository::one("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.registered_at LIMIT 1", array($deptId));
        $waiting = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE current_dept_id=? AND status='paid'", array($deptId));

        // 该科室出诊医生（按用户-科室关联过滤）
        // 注意：必须 SELECT dept_ids，否则下面 explode() 拿不到关联科室，doctors 恒为空
        $doctors = array();
        $docs = EmrRepository::q("SELECT name, emp_no, title, photo, intro, dept_ids FROM users WHERE role='doctor' AND status=1 ORDER BY id");
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
                $follow = (int)EmrRepository::val("SELECT COUNT(*) FROM registrations
                    WHERE patient_no=? AND current_dept_id=? AND date(registered_at)=?
                    AND status IN ('paid','visiting','finished') AND id<>?",
                    array($r['patient_no'], $deptId, today_str(), $r['id']));
            }
            return array(
                'name' => $r['pname'], 'gender' => $r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
                'patient_no' => $r['patient_no'], 'registered_at' => $r['registered_at'],
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
        return;
    }

    if ($action === 'queue_list') {
        $deptId = (int)get('dept_id', 0);
        if ($deptId <= 0) {
            $curRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
            $deptId = $curRow ? (int)$curRow['current_dept_id'] : 0;
        }
        if ($deptId <= 0) {
            $ids = doctor_dept_ids($u);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $first = EmrRepository::one("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph) ORDER BY sort, id LIMIT 1", $ids);
                if ($first) $deptId = (int)$first['id'];
            }
        }
        if ($deptId <= 0) json_fail('当前医生未关联可用科室');
        // 候诊列表可显示天数（管理员配置 2-7，缺省 3）；会话快照无此字段时回查数据库
        $queueDays = 3;
        if (isset($u['queue_days'])) {
            $queueDays = (int)$u['queue_days'];
        } else {
            $ud = EmrRepository::one('SELECT queue_days FROM users WHERE id=?', array($u['id']));
            if ($ud && (int)$ud['queue_days'] >= 2 && (int)$ud['queue_days'] <= 7) $queueDays = (int)$ud['queue_days'];
        }
        $since = date('Y-m-d', strtotime('-' . ($queueDays - 1) . ' days'));   // 近 N 天（含今日）
        $rows = EmrRepository::q("SELECT r.id, r.patient_no, r.visit_seq, r.first_dept_name, r.session,
                r.status, r.registered_at, r.finished_at,
                p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE r.current_dept_id=? AND date(r.registered_at)>=?
            AND r.status IN ('paid','visiting','finished')
            ORDER BY r.registered_at DESC", array($deptId, $since));
        // 号源直接显示挂号时确定的 session（急诊存 all=昼夜，转科/会诊不重新计算）
        $list = array_map(function ($r) {
            return array(
                'code' => oid($r['id']),
                'name' => $r['pname'], 'gender' => $r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                'date' => substr($r['registered_at'], 0, 10),
                'time' => substr($r['registered_at'], 11, 5),
                'dept_name' => $r['first_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'session_text' => session_display_text($r['session']),
                'status' => $r['status'],
                'finish_date' => !empty($r['finished_at']) ? substr($r['finished_at'], 0, 10) : '',
                'finished_at' => !empty($r['finished_at']) ? substr($r['finished_at'], 11, 5) : '',
            );
        }, $rows);
        $pref = (isset($_SESSION['queue_pref']) && is_array($_SESSION['queue_pref'])) ? $_SESSION['queue_pref'] : array();
        // 会诊请求（目标科室=当前科室，近 N 天，与候诊同受可见天数限制）。
        // 直接返回完整候诊行结构（与 $list 同构 + consult_status/consult_code），
        // 前端会诊 Tab 复用候诊列表样式渲染。
        $consVisits = array();
        $consStatus = array();
        foreach (EmrRepository::q("SELECT id, visit_id, status, created_at, accepted_by, record_id FROM consultations WHERE target_dept_id=? AND date(created_at)>=? ORDER BY id DESC", array($deptId, $since)) as $c) {
            $vid = (int)$c['visit_id'];
            if (!isset($consStatus[$vid])) {
                $consVisits[] = $vid;
                $consStatus[$vid] = array('id' => (int)$c['id'], 'code' => oid($c['id']), 'status' => (string)$c['status'], 'created_at' => (string)$c['created_at'], 'accepted_by' => (string)$c['accepted_by'], 'record_id' => (int)(isset($c['record_id']) ? $c['record_id'] : 0));
            }
        }
        $consultations = array();
        if ($consVisits) {
            $phC = implode(',', array_fill(0, count($consVisits), '?'));
            // 注意：不加 current_dept_id 过滤——患者转科不影响已发会诊的展示
            $cRows = EmrRepository::q("SELECT r.id, r.patient_no, r.visit_seq, r.first_dept_id, r.first_dept_name, r.session,
                    r.status, r.registered_at, r.finished_at,
                    p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
                FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
                WHERE r.id IN ($phC)
                ORDER BY r.registered_at DESC", $consVisits);
            foreach ($cRows as $r) {
                $cs = $consStatus[(int)$r['id']];
                $consultations[] = array(
                    'code' => oid($r['id']),
                    'consult_code' => $cs['code'],
                    'consult_status' => $cs['status'],
                    'accepted_by' => $cs['accepted_by'],
                    'record_id' => $cs['record_id'],
                    'name' => $r['pname'], 'gender' => $r['pgender'],
                    'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                    'date' => substr($r['registered_at'], 0, 10),
                    'time' => substr($r['registered_at'], 11, 5),
                    'dept_name' => $r['first_dept_name'],
                    'visit_seq' => (int)$r['visit_seq'],
                    'session_text' => session_display_text($r['session']),
                    'status' => $r['status'],
                    'created_at' => $cs['created_at'],
                );
            }
        }
        json_ok(array(
            'dept_id' => $deptId,
            'waiting' => (int)EmrRepository::val("SELECT COUNT(*) FROM registrations WHERE current_dept_id=? AND status='paid'", array($deptId)),
            'list' => $list,
            'consultations' => $consultations,
            'pref' => array('seen' => empty($pref['seen']) ? 0 : 1, 'today' => empty($pref['today']) ? 0 : 1, 'consult' => empty($pref['consult']) ? 0 : 1),
        ));
        return;
    }

    if ($action === 'queue_pref') {
        $_SESSION['queue_pref'] = array(
            'seen' => post('seen', 0) ? 1 : 0,
            'today' => post('today', 0) ? 1 : 0,
            'consult' => post('consult', 0) ? 1 : 0,
        );
        json_ok();
        return;
    }

    if ($action === 'report_detail') {
        $rid = did(get('report_id'));
        $report = EmrRepository::one('SELECT * FROM reports WHERE id=?', array($rid));
        if (!$report) json_fail('报告不存在');
        $result = EmrRepository::one('SELECT * FROM results WHERE id=?', array($report['result_id']));
        $itemName = '';
        $rows = array();
        $findings = '';
        $conclusion = '';
        if ($result && $result['type'] === 'lab') {
            $li = EmrRepository::one('SELECT * FROM lab_items WHERE id=?', array($result['item_id']));
            $itemName = $li ? $li['name'] : '';
            $values = json_decode($result['values_json'], true);
            if (is_array($values) && !empty($values['group'])) {
                $members = EmrRepository::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$result['item_id']));
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
            $ei = EmrRepository::one('SELECT * FROM exam_items WHERE id=?', array($result['item_id']));
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
        return;
    }

    if ($action === 'get_available_rooms') {
        $deptId = (int)get('dept_id');
        if ($deptId <= 0) json_fail('请先选择科室');
        $rows = EmrRepository::q("SELECT * FROM clinic_rooms WHERE dept_id=? AND room_type='doctor' ORDER BY id", array($deptId));
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
        $myBound = EmrRepository::one("SELECT * FROM clinic_rooms WHERE current_doctor_id=? ORDER BY id DESC LIMIT 1", array($u['id']));
        json_ok(array('list' => $list, 'bound' => $myBound ? array('id' => (int)$myBound['id'], 'name' => $myBound['room_name']) : null));
        return;
    }
}
