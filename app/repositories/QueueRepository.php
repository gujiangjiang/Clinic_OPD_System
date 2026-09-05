<?php
/**
 * ============================================================
 * QueueRepository.php — 候诊排队与叫号大屏仓库
 * ============================================================
 * 说明：封装候诊队列、分诊叫号、医生绑定、大屏数据等 SQL，
 * 统一经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class QueueRepository extends BaseRepository {

    /** 按 token 查找叫号大屏 */
    public static function roomByToken($token) {
        return self::one('SELECT * FROM clinic_rooms WHERE screen_token=?', array($token));
    }

    /** 按 id 查找叫号大屏 */
    public static function roomById($id) { return self::findById('clinic_rooms', $id); }

    /** 按科室查找当前就诊中患者（最近一条） */
    public static function currentVisit($deptId) {
        return self::one(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.current_dept_id=? AND r.status='visiting' ORDER BY r.id DESC LIMIT 1",
            array((int)$deptId)
        );
    }

    /** 按科室查找下一位候诊患者 */
    public static function nextWaiting($deptId) {
        return self::one(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.registered_at LIMIT 1",
            array((int)$deptId)
        );
    }

    /** 按科室查找候诊队列（前 N 位） */
    public static function waitingList($deptId, $limit = 8) {
        return self::q(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.registered_at LIMIT " . (int)$limit,
            array((int)$deptId)
        );
    }

    /**
     * 科室动态号源池（未认领患者）——叫号大屏 / 医生叫号悬浮窗的数据源
     * 规则：
     *  · 仅统计当前在该科室、状态 paid（已缴费候诊）的就诊记录
     *  · 已被任何医生「叫号认领」（call_events 存在 action='call'）的患者不再入池，
     *    保证多医生并发时号源不重复；过号（miss）患者同样不再入池
     *  · 排序按「到本科室的生效时间」：转入患者按转入时间、普通挂号按挂号时间，
     *    与转科/挂号真实先后一致
     */
    public static function deptPool($deptId, $limit = 0) {
        $sql = "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth,
                COALESCE(tr.transfer_at, r.registered_at) AS eff_time
             FROM registrations r
             LEFT JOIN patients p ON p.patient_no=r.patient_no
             LEFT JOIN (SELECT visit_id, MAX(created_at) AS transfer_at FROM referrals WHERE to_dept_id=? GROUP BY visit_id) tr ON tr.visit_id=r.id
             WHERE r.current_dept_id=? AND r.status='paid'
               AND NOT EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='call')
             ORDER BY eff_time, r.registered_at, r.id";
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
        return self::q($sql, array((int)$deptId, (int)$deptId));
    }

    /** 科室号源池首条（下一位） */
    public static function deptPoolNext($deptId) {
        $rows = self::deptPool($deptId, 1);
        return $rows ? $rows[0] : null;
    }

    /** 科室号源池总数 */
    public static function deptPoolCount($deptId) {
        return (int)self::val(
            "SELECT COUNT(*) FROM registrations r
             WHERE r.current_dept_id=? AND r.status='paid'
               AND NOT EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='call')",
            array((int)$deptId)
        );
    }

    /** 过号患者列表（最近 N 位，供大屏显示（过号）标记） */
    public static function deptMissed($deptId, $limit = 8) {
        return self::q(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.current_dept_id=? AND r.status='paid'
               AND EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='miss')
             ORDER BY (SELECT MAX(created_at) FROM call_events ce2 WHERE ce2.visit_id=r.id AND ce2.action='miss') DESC
             LIMIT " . (int)$limit,
            array((int)$deptId)
        );
    }

    /** 大屏当前就诊患者（按医生工作站推送的 current_visit_id 回库校验）
     *  返回 null 表示：未推送 / 患者已转科离开本科室 / 已退号取消等无效状态 */
    public static function roomCurrentVisit($room) {
        $vid = (int)$room['current_visit_id'];
        if ($vid <= 0) return null;
        $r = self::one(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.id=? AND r.current_dept_id=?",
            array($vid, (int)$room['dept_id'])
        );
        if (!$r) return null;
        // 状态校验：退号取消 / 已退款等无效状态一律不显示（转科离开已由 current_dept_id 排除）
        if (!in_array($r['status'], array('paid', 'visiting', 'finished'), true)) return null;
        return $r;
    }

    /** 大屏是否已绑定医生且医生心跳存活 */
    public static function roomBound($room) {
        return (int)$room['current_doctor_id'] > 0 && !self::doctorHeartbeatStale($room['id']);
    }

    /** 按科室查询候诊队列（护士站使用，含多种状态） */
    public static function doctorInfo($doctorId) {
        return UserRepository::doctorProfile($doctorId);
    }

    /** 大屏医生心跳保活检测：超过 300 秒未更新视为异常断开
     *  （医生端 room_heartbeat.js 全局每 30 秒心跳一次，跨页面持续；
     *   离开工作站/刷新页面均不会中断，仅真正退出登录或异常断开才解绑） */
    public static function doctorHeartbeatStale($roomId) {
        return (int)self::val(
            "SELECT COUNT(*) FROM clinic_rooms WHERE id=? AND (doctor_heartbeat IS NULL
             OR (strftime('%s','now','localtime') - strftime('%s',doctor_heartbeat)) > 300)",
            array((int)$roomId)
        ) > 0;
    }

    /** 解除大屏与医生的绑定 */
    public static function unbindDoctor($roomId) {
        self::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, updated_at=? WHERE id=?',
            array(now_str(), (int)$roomId));
    }

    /** 更新大屏心跳 */
    public static function updateHeartbeat($roomId) {
        self::exec('UPDATE clinic_rooms SET screen_last_heartbeat=?, is_screen_online=1, updated_at=? WHERE id=?',
            array(now_str(), now_str(), (int)$roomId));
    }
}