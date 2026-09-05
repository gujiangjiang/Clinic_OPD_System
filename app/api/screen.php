<?php
/**
 * ============================================================
 * screen.php — 叫号大屏免登数据接口
 * ============================================================
 * 说明：通过 URL /api/screen?token=xxx 访问（index.php 自动加载 bootstrap），
 * 不依赖登录会话；大屏前端每 3 秒轮询获取心跳 + 叫号数据。
 * 1. heartbeat  大屏心跳上报 + 返回该诊室当前叫号数据
 * 2. data       获取当前叫号数据（不更新心跳，供预览/调试）
 * ============================================================ */

$action = isset($_GET['action']) ? trim($_GET['action']) : 'data';
$token = isset($_GET['token']) ? trim($_GET['token']) : (isset($_POST['token']) ? trim($_POST['token']) : '');

/** 按 token 找大屏 */
$room = $token !== '' ? QueueRepository::roomByToken($token) : null;
if (!$room) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'msg' => '大屏链接无效或已失效，请联系管理员', 'data' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 通用：返回该诊室叫号数据 ---------- */
function screen_payload($room) {
    $deptId = (int)$room['dept_id'];
    $dept = DeptRepository::one('SELECT * FROM departments WHERE id=?', array($deptId));
    $roomName = $room['room_name'];
    $mask = (int)$room['enable_mask'] === 1;

    // 温馨提示：优先取诊室自定义（JSON 数组），为空则按类型返回默认提示
    $tips = array();
    if (!empty($room['screen_tips'])) {
        $decoded = json_decode($room['screen_tips'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $t) {
                $t = trim((string)$t);
                if ($t !== '') $tips[] = $t;
            }
        }
    }
    if (!$tips) {
        $tips = default_screen_tips($room['room_type']);
    }
    $base = array(
        'room' => array('id' => (int)$room['id'], 'name' => $roomName, 'type' => $room['room_type'], 'dept' => $dept ? $dept['name'] : ''),
        'enable_voice' => (int)$room['enable_voice'],
        'enable_mask' => (int)$room['enable_mask'],
        'tips' => $tips,
        'tip_interval' => max(2, (int)$room['tip_interval']),
        'servertime' => now_str(),
    );

    // 姓名脱敏（仅屏幕文字；raw_name 始终为真实全名，供语音播报）
    $maskName = function ($nm) use ($mask) {
        $nm = (string)$nm;
        if (!$mask || mb_strlen($nm) <= 1) return $nm;
        $len = mb_strlen($nm);
        if (mb_substr($nm, 0, 3) === '无名氏') return $nm;   // 匿名患者保留原样
        if ($len === 2) return mb_substr($nm, 0, 1) . '*';
        if ($len === 3) return mb_substr($nm, 0, 1) . '*' . mb_substr($nm, -1);
        return mb_substr($nm, 0, 1) . str_repeat('*', $len - 2) . mb_substr($nm, -1);
    };
    $fmt = function ($r, $missed = 0) use ($deptId, $maskName) {
        if (!$r) return null;
        // 转诊标记：挂号科室与当前就诊科室不一致 => 转诊患者（序号需显示完整 + 转标记）
        $isTransfer = !empty($r['first_dept_id']) && (int)$r['first_dept_id'] !== $deptId;
        return array(
            'name' => $maskName($r['pname']),
            'raw_name' => $r['pname'],
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'],
            'is_transfer' => $isTransfer ? 1 : 0,
            'first_dept_name' => $r['first_dept_name'],
            'missed' => (int)$missed,
        );
    };

    // 当前医生（完整信息：姓名/工号/职称/介绍/照片，供医生大屏展示）
    $doctor = null;
    if ((int)$room['current_doctor_id'] > 0) {
        $doc = QueueRepository::doctorInfo($room['current_doctor_id']);
        if ($doc) {
            $doctor = array(
                'name' => $doc['name'],
                'emp_no' => $doc['emp_no'],
                'title' => $doc['title'],
                'intro' => $doc['intro'],
                'photo' => $doc['photo'] ? img_data($doc['photo']) : '',
            );
        }
    }

    // ===== 医生诊室大屏：数据由医生工作站推送 + 回库校验 =====
    // 未绑定医生（或无存活心跳）时一律不显示任何患者，避免「无医生接诊却出现患者」的错乱
    if ($room['room_type'] === 'doctor') {
        $bound = QueueRepository::roomBound($room);
        if (!$bound) {
            return array_merge($base, array(
                'bound' => false,
                'current' => null, 'next' => null, 'waiting' => array(),
                'missed' => array(), 'doctor' => null,
            ));
        }
        // 当前就诊：以医生工作站推送的 current_visit_id 为准，回库校验（防前端篡改）
        $current = QueueRepository::roomCurrentVisit($room);
        // 号源池：未被任何医生认领的患者（动态拼接，多医生并发不重复）
        $pool = QueueRepository::deptPool($deptId, 8);
        $next = $pool ? $pool[0] : null;
        $missed = QueueRepository::deptMissed($deptId, 5);
        // 候诊列表 = 号源池剩余（除去已展示的「下一位」）+ 过号患者（末尾追加，带（过号）标记）
        $waiting = array();
        foreach (array_slice($pool, 1) as $r) $waiting[] = $fmt($r, 0);
        foreach ($missed as $m) $waiting[] = $fmt($m, 1);
        // 当前患者附叫号时间（called_at）：大屏据此识别「再次叫号」触发重复播报
        $curFmt = $fmt($current);
        if ($curFmt) $curFmt['called_at'] = (string)$room['current_called_at'];
        return array_merge($base, array(
            'bound' => true,
            'current' => $curFmt,
            'next' => $fmt($next),
            'waiting' => $waiting,
            'missed' => array_map(function ($m) use ($fmt) { return $fmt($m, 1); }, $missed),
            'doctor' => $doctor,
        ));
    }

    // ===== 医技大屏（lab/imaging/pharmacy/nurse）：科室排队看板，保持原逻辑 =====
    // 当前就诊中患者（该科室）
    $current = QueueRepository::currentVisit($deptId);
    // 下一位候诊
    $next = QueueRepository::nextWaiting($deptId);
    // 候诊队列（前 8 位）
    $waiting = QueueRepository::waitingList($deptId, 8);
    return array_merge($base, array(
        'bound' => true,
        'current' => $fmt($current),
        'next' => $fmt($next),
        'waiting' => array_map(function ($r) use ($fmt) { return $fmt($r, 0); }, $waiting),
        'missed' => array(),
        'doctor' => $doctor,
    ));
}

/** 按大屏类型返回默认温馨提示 */
function default_screen_tips($type) {
    $map = array(
        'doctor'   => array('请按序排队候诊，保持安静', '请主动拒绝医托，谨防上当受骗', '复诊患者请携带既往病历资料', '请如实告知医生病史与用药情况'),
        'lab'      => array('请空腹检验项目提前禁食 8 小时', '采血后请按压针眼 3-5 分钟', '请按取单时间到自助机或窗口领取报告'),
        'imaging'  => array('检查前请去除金属饰品与衣物', '孕妇及备孕者请提前告知技师', '请按叫号顺序进入检查室'),
        'pharmacy' => array('请按医嘱服用药品，勿自行增减剂量', '服药期间如有不适请及时就诊', '用药疑问请咨询药师'),
        'nurse'    => array('请按序排队，保持安静', '治疗前请告知过敏史', '请妥善保管个人物品'),
    );
    return isset($map[$type]) ? $map[$type] : array('请保持安静，有序排队');
}

/* ==================== 心跳 + 数据 ==================== */
if ($action === 'heartbeat' || $action === 'data') {
    // 绑定医生保活检查：医生心跳超过 90 秒未更新（异常退出浏览器 / 会话过期等
    // 未走正常登出流程的场景）时，大屏自动取消与该医生的关联
    if ((int)$room['current_doctor_id'] > 0) {
        if (QueueRepository::doctorHeartbeatStale($room['id'])) {
            QueueRepository::unbindDoctor($room['id']);
            $room = QueueRepository::roomById($room['id']);
        }
    }
    if ($action === 'heartbeat') {
        QueueRepository::updateHeartbeat($room['id']);
    }
    json_response(true, 'ok', screen_payload($room));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'msg' => '未知操作', 'data' => null), JSON_UNESCAPED_UNICODE);
