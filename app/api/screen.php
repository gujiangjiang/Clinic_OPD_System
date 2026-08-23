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
$room = $token !== '' ? DB::one('clinic_rooms', 'SELECT * FROM clinic_rooms WHERE screen_token=?', array($token)) : null;
if (!$room) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'msg' => '大屏链接无效或已失效，请联系管理员', 'data' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 通用：返回该诊室叫号数据 ---------- */
function screen_payload($room) {
    $deptId = (int)$room['dept_id'];
    $dept = DB::one('dept', 'SELECT * FROM departments WHERE id=?', array($deptId));
    $roomName = $room['room_name'];
    $mask = (int)$room['enable_mask'] === 1;

    // 当前就诊中患者（该科室）
    $current = DB::one('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
        FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
        WHERE r.current_dept_id=? AND r.status='visiting' ORDER BY r.id DESC LIMIT 1", array($deptId));
    // 下一位候诊
    $next = DB::one('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
        FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
        WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.register_time LIMIT 1", array($deptId));
    // 候诊队列（前 8 位）
    $waiting = DB::q('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
        FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
        WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.register_time LIMIT 8", array($deptId));
    // 当前医生（完整信息：姓名/工号/职称/介绍/照片，供医生大屏展示）
    $doctor = null;
    if ((int)$room['current_doctor_id'] > 0) {
        $doc = DB::one('user', 'SELECT name, emp_no, title, intro, photo FROM users WHERE id=?', array($room['current_doctor_id']));
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
    $fmt = function ($r) use ($mask, $deptId) {
        if (!$r) return null;
        $rawName = $r['pname'];
        $nm = $rawName;
        if ($mask && mb_strlen($nm) > 1) {
            $len = mb_strlen($nm);
            if (mb_substr($nm, 0, 3) === '无名氏') {
                // 无名氏（匿名患者）：无真实姓名，脱敏无意义，保留原样
                $nm = $rawName;
            } elseif ($len === 2) {
                // 2个字：保留首字，其余*（如"张三"→"张*"）
                $nm = mb_substr($nm, 0, 1) . '*';
            } elseif ($len === 3) {
                // 3个字：保留首尾，中间*（如"张小三"→"张*三"）
                $nm = mb_substr($nm, 0, 1) . '*' . mb_substr($nm, -1);
            } else {
                // 4个字及以上：保留首尾各1字，中间*（如"王小明三"→"王**三"、"买买提·肉孜"→"买****孜"）
                $nm = mb_substr($nm, 0, 1) . str_repeat('*', $len - 2) . mb_substr($nm, -1);
            }
        }
        // 转诊标记：挂号科室与当前就诊科室不一致 => 转诊患者（序号需显示完整 + 转标记）
        $isTransfer = !empty($r['first_dept_id']) && (int)$r['first_dept_id'] !== $deptId;
        return array(
            'name' => $nm,
            'raw_name' => $rawName,   // 原始姓名（语音播报用，不受脱敏影响）
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['register_time']),
            'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'],
            'is_transfer' => $isTransfer ? 1 : 0,          // 是否转诊患者
            'first_dept_name' => $r['first_dept_name'],     // 挂号（原）科室名
        );
    };
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
    return array(
        'room' => array('id' => (int)$room['id'], 'name' => $roomName, 'type' => $room['room_type'], 'dept' => $dept ? $dept['name'] : ''),
        'enable_voice' => (int)$room['enable_voice'],
        'enable_mask' => (int)$room['enable_mask'],
        'current' => $fmt($current),
        'next' => $fmt($next),
        'waiting' => array_map($fmt, $waiting),
        'doctor' => $doctor,
        'tips' => $tips,
        'tip_interval' => max(2, (int)$room['tip_interval']),
        'servertime' => now_str(),
    );
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
        $stale = (int)DB::val('clinic_rooms',
            "SELECT COUNT(*) FROM clinic_rooms WHERE id=? AND (doctor_heartbeat IS NULL OR (strftime('%s','now','localtime') - strftime('%s',doctor_heartbeat)) > 90)",
            array((int)$room['id']));
        if ($stale) {
            DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE id=?',
                array(now_str(), (int)$room['id']));
            $room = DB::one('clinic_rooms', 'SELECT * FROM clinic_rooms WHERE id=?', array((int)$room['id']));
        }
    }
    if ($action === 'heartbeat') {
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET screen_last_heartbeat=?, is_screen_online=1, updated_at=? WHERE id=?',
            array(now_str(), now_str(), (int)$room['id']));
    }
    json_response(true, 'ok', screen_payload($room));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'msg' => '未知操作', 'data' => null), JSON_UNESCAPED_UNICODE);
