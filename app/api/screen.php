<?php
/**
 * ============================================================
 * screen.php — 叫号大屏免登数据接口
 * ============================================================
 * 说明：大屏页面通过 screen_token 访问本接口轮询数据（每 3 秒），
 * 不依赖登录会话；本文件在 _init.php 之前直接按 token 鉴权。
 * 1. heartbeat  大屏心跳上报 + 返回该诊室当前叫号数据
 * 2. data       获取当前叫号数据（不更新心跳，供预览/调试）
 * ============================================================ */
require __DIR__ . '/../config/bootstrap.php';

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
    // 当前医生
    $doctor = '';
    if ((int)$room['current_doctor_id'] > 0) {
        $doc = DB::one('user', 'SELECT name, title FROM users WHERE id=?', array($room['current_doctor_id']));
        $doctor = $doc ? $doc['name'] : '';
    }
    $fmt = function ($r) use ($mask) {
        if (!$r) return null;
        $nm = $r['pname'];
        if ($mask && mb_strlen($nm) > 1) {
            $nm = mb_substr($nm, 0, 1) . str_repeat('*', mb_strlen($nm) - 1);
        }
        return array(
            'name' => $nm, 'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['register_time']),
            'visit_seq' => (int)$r['visit_seq'], 'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'],
        );
    };
    return array(
        'room' => array('id' => (int)$room['id'], 'name' => $roomName, 'type' => $room['room_type'], 'dept' => $dept ? $dept['name'] : ''),
        'enable_voice' => (int)$room['enable_voice'],
        'enable_mask' => (int)$room['enable_mask'],
        'current' => $fmt($current),
        'next' => $fmt($next),
        'waiting' => array_map($fmt, $waiting),
        'doctor' => $doctor,
        'servertime' => now_str(),
    );
}

/* ==================== 心跳 + 数据 ==================== */
if ($action === 'heartbeat' || $action === 'data') {
    if ($action === 'heartbeat') {
        DB::exec('clinic_rooms', 'UPDATE clinic_rooms SET screen_last_heartbeat=?, is_screen_online=1, updated_at=? WHERE id=?',
            array(now_str(), now_str(), (int)$room['id']));
    }
    json_response(true, 'ok', screen_payload($room));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'msg' => '未知操作', 'data' => null), JSON_UNESCAPED_UNICODE);
