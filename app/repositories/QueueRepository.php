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
             WHERE r.current_dept_id=? AND r.status='paid' ORDER BY r.visit_seq, r.registered_at LIMIT $limit",
            array((int)$deptId)
        );
    }

    /** 按科室查询候诊队列（护士站使用，含多种状态） */
    public static function deptQueue($deptId, $date, $statusWhere = "r.status IN ('paid','visiting','finished')") {
        return self::q(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE r.current_dept_id=? AND date(r.registered_at)=? AND $statusWhere
             ORDER BY r.visit_seq",
            array((int)$deptId, $date)
        );
    }

    /** 医生信息（大屏展示用） */
    public static function doctorInfo($doctorId) {
        return self::one('SELECT name, emp_no, title, intro, photo FROM users WHERE id=?', array((int)$doctorId));
    }

    /** 大屏医生心跳保活检测：超过 90 秒未更新视为异常断开 */
    public static function doctorHeartbeatStale($roomId) {
        return (int)self::val(
            "SELECT COUNT(*) FROM clinic_rooms WHERE id=? AND (doctor_heartbeat IS NULL
             OR (strftime('%s','now','localtime') - strftime('%s',doctor_heartbeat)) > 90)",
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

    /** 更新大屏绑定医生 */
    public static function bindDoctor($roomId, $doctorId, $doctorName) {
        self::exec('UPDATE clinic_rooms SET current_doctor_id=?, current_doctor_name=?, doctor_heartbeat=?, updated_at=? WHERE id=?',
            array((int)$doctorId, $doctorName, now_str(), now_str(), (int)$roomId));
    }
}