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

    /**
     * 叫号会话日期（跨天规则）——在每次取号源/叫号前调用，返回更新后的诊室行（已持久化）
     *  · 首次使用（call_session_date 为空）：记录为当天日期
     *  · 跨天后（call_session_date != 今天）：
     *      - 不允许跨天（allow_cross_day=0）：清空本诊室前一天所有叫号记录 + 重置当前就诊，
     *        会话日期改为今天（新的一天从当天号源重新叫起，非当天号一律不叫）
     *      - 允许跨天（allow_cross_day=1）：保持会话日期，号源从会话日期 0 点起延续，
     *        一次登录（绑定）内支持跨 0 点继续叫号；重新登录绑定后从当天号源开始
     */
    public static function roomQueueRefresh($room) {
        $today = today_str();
        $sessDate = !empty($room['call_session_date']) ? (string)$room['call_session_date'] : '';
        if ($sessDate === '') {
            self::exec("UPDATE clinic_rooms SET call_session_date=?, updated_at=? WHERE id=?",
                array($today, now_str(), (int)$room['id']));
            $room['call_session_date'] = $today;
            return $room;
        }
        if ($sessDate !== $today) {
            if ((int)$room['allow_cross_day'] !== 1) {
                $now = now_str();
                // 跨天且不允许：清空本诊室前一天所有叫号记录（含过号/认领），重置当前就诊
                self::exec("DELETE FROM call_events WHERE room_id=? OR (dept_id=? AND date(created_at) < ?)",
                    array((int)$room['id'], (int)$room['dept_id'], $today));
                self::exec("UPDATE clinic_rooms SET current_visit_id=0, current_flow_no='', current_called_at='',
                    last_call_action='', last_call_at='', call_session_date=?, updated_at=? WHERE id=?",
                    array($today, $now, (int)$room['id']));
                $room['current_visit_id'] = 0;
                $room['current_flow_no'] = '';
                $room['call_session_date'] = $today;
            }
            // 允许跨天：保持会话日期，继续沿用（跨 0 点后号源从会话日期延续）
        }
        return $room;
    }

    /** 号源生效日期过滤子句（跨天规则）：返回 SQL 片段 + 追加参数 */
    private static function poolDateCond($room, &$params) {
        $sessDate = !empty($room['call_session_date']) ? (string)$room['call_session_date'] : today_str();
        if ((int)$room['allow_cross_day'] === 1) {
            // 允许跨天：号源从会话日期 0 点起延续（一次登录内跨 0 点不中断）
            $params[] = $sessDate . ' 00:00:00';
            return 'COALESCE(tr.transfer_at, r.registered_at) >= ?';
        }
        // 仅当天：只叫「当天号源」，非当天不叫
        $params[] = $sessDate;
        return 'date(COALESCE(tr.transfer_at, r.registered_at)) = ?';
    }

    /**
     * 科室动态号源池（未认领患者，按跨天规则过滤当天/会话日期号源）
     * 规则：
     *  · 仅统计当前在该科室、状态 paid（已缴费候诊）且号源日期符合跨天设置的就诊记录
     *  · 已被任何医生「叫号认领」（call_events 存在 action='call'）的患者不再入池，
     *    保证多医生并发时号源不重复；过号（miss）患者同样不再入池
     *  · 排序按「到本科室的生效时间」：转入患者按转入时间、普通挂号按挂号时间
     */
    public static function deptPoolForRoom($room, $limit = 0) {
        $deptId = (int)$room['dept_id'];
        $params = array($deptId, $deptId);
        $dateCond = self::poolDateCond($room, $params);
        $sql = "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth,
                COALESCE(tr.transfer_at, r.registered_at) AS eff_time
             FROM registrations r
             LEFT JOIN patients p ON p.patient_no=r.patient_no
             LEFT JOIN (SELECT visit_id, MAX(created_at) AS transfer_at FROM referrals WHERE to_dept_id=? GROUP BY visit_id) tr ON tr.visit_id=r.id
             WHERE r.current_dept_id=? AND r.status='paid' AND $dateCond
               AND NOT EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='call')
             ORDER BY eff_time, r.registered_at, r.id";
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
        return self::q($sql, $params);
    }

    /** 科室号源池首条（下一位，按跨天规则） */
    public static function deptPoolNextForRoom($room) {
        $rows = self::deptPoolForRoom($room, 1);
        return $rows ? $rows[0] : null;
    }

    /** 科室号源池总数（按跨天规则） */
    public static function deptPoolCountForRoom($room) {
        $deptId = (int)$room['dept_id'];
        $params = array($deptId, $deptId);
        $dateCond = self::poolDateCond($room, $params);
        return (int)self::val(
            "SELECT COUNT(*) FROM registrations r
             LEFT JOIN (SELECT visit_id, MAX(created_at) AS transfer_at FROM referrals WHERE to_dept_id=? GROUP BY visit_id) tr ON tr.visit_id=r.id
             WHERE r.current_dept_id=? AND r.status='paid' AND $dateCond
               AND NOT EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='call')",
            $params
        );
    }

    /** 过号患者列表（最近 N 位，按跨天规则，供大屏显示（过号）标记） */
    public static function deptMissedForRoom($room, $limit = 8) {
        $deptId = (int)$room['dept_id'];
        $params = array($deptId, $deptId);
        $dateCond = self::poolDateCond($room, $params);
        return self::q(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r
             LEFT JOIN patients p ON p.patient_no=r.patient_no
             LEFT JOIN (SELECT visit_id, MAX(created_at) AS transfer_at FROM referrals WHERE to_dept_id=? GROUP BY visit_id) tr ON tr.visit_id=r.id
             WHERE r.current_dept_id=? AND r.status='paid' AND $dateCond
               AND EXISTS (SELECT 1 FROM call_events ce WHERE ce.visit_id=r.id AND ce.action='miss')
             ORDER BY (SELECT MAX(created_at) FROM call_events ce2 WHERE ce2.visit_id=r.id AND ce2.action='miss') DESC
             LIMIT " . (int)$limit,
            $params
        );
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