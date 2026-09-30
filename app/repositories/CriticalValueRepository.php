<?php
/**
 * ============================================================
 * CriticalValueRepository.php — 危急值仓库
 * ============================================================
 * 说明：封装危急值全生命周期（上报/列表/处理/关联病历/处理人检索）
 * 与生成危急值记录所需的联动查询（检验组合明细/患者/医生），
 * 统一经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class CriticalValueRepository extends BaseRepository {

    /** 检验组合的子项目明细（危急值展示用） */
    public static function groupItems($itemId) {
        return self::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$itemId));
    }

    /** 科室名（当前医生科室 / 发起科室展示用） */
    public static function deptName($deptId) {
        return self::one('SELECT name FROM departments WHERE id=?', array((int)$deptId));
    }

    /** 科室待处理/处理中患者数（上报时给医生看科室负荷，禁止夜间脱岗） */
    public static function deptPendingCount($deptId) {
        return (int)self::val("SELECT COUNT(*) FROM registrations WHERE first_dept_id=? AND status IN ('pending','visiting') AND date(registered_at)=date('now','localtime')", array((int)$deptId));
    }

    /** 就诊记录（快照生成时取患者/科室上下文） */
    public static function visitById($visitId) {
        return self::one('SELECT * FROM registrations WHERE id=?', array((int)$visitId));
    }

    /** 该医生最近病历记录 id（危急值处理自动续写挂接用） */
    public static function latestRecordByVisitDoctor($visitId, $doctorId) {
        return self::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }

    /** 该就诊最近病历记录 id（无本人文书时兜底挂接） */
    public static function latestRecordByVisit($visitId) {
        return self::one('SELECT id FROM patient_records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId));
    }

    /** 危急值记录 */
    public static function byId($id) {
        return self::one('SELECT * FROM critical_values WHERE id=?', array((int)$id));
    }

    /** 新危急值插入（记录一经创建不可删除/修改） */
    public static function create($data) {
        return self::insertRow('critical_values', $data);
    }

    /** 危急值处理完成（仅 pending 可处理，防并发重复处理） */
    public static function process($id, $matchStatus, $treatment, $processedBy) {
        return self::exec(
            "UPDATE critical_values SET status='done', match_status=?, treatment=?, processed_by=?, processed_at=? WHERE id=? AND status='pending'",
            array($matchStatus, $treatment, (int)$processedBy, now_str(), (int)$id)
        );
    }

    /** 关联自动续写的病历记录 */
    public static function attachRecord($id, $recordId) {
        return self::exec('UPDATE critical_values SET record_id=? WHERE id=?', array((int)$recordId, (int)$id));
    }

    /** 处理人姓名（处理记录展示用） */
    public static function userName($userId) {
        return self::one('SELECT name FROM users WHERE id=?', array((int)$userId));
    }

    /** 在岗处理医生检索（分诊处理人选择，仅在职医生） */
    public static function listDoctors($where, $params, $page, $pageSize) {
        return self::q("SELECT id, name, emp_no, title FROM users WHERE $where ORDER BY emp_no, id LIMIT ? OFFSET ?", paged_suffix($params, $page, $pageSize));
    }

    /** 在岗处理医生计数 */
    public static function countDoctors($where, $params) {
        return (int)self::val("SELECT COUNT(*) FROM users WHERE $where", $params);
    }

    /** 指定医生在职校验（转诊处理医生必须为在职医生） */
    public static function doctorActiveById($id) {
        return self::one("SELECT id, name, emp_no FROM users WHERE id=? AND role='doctor' AND status=1", array((int)$id));
    }

    /** 危急值分页列表 */
    public static function paginate($where, $params, $page, $pageSize) {
        return self::q("SELECT * FROM critical_values WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?", paged_suffix($params, $page, $pageSize));
    }

    /** 危急值计数 */
    public static function countRows($where, $params) {
        return (int)self::val("SELECT COUNT(*) FROM critical_values WHERE $where", $params);
    }

    /** 报告 / 结果快照（影像报告或检验结果） */
    public static function reportById($reportId, $source) {
        return self::one('SELECT * FROM reports WHERE id=? AND type=?', array((int)$reportId, $source));
    }

    /** 检验结果 */
    public static function resultById($id) {
        return self::one('SELECT * FROM results WHERE id=?', array((int)$id));
    }

    /** 检验项目 */
    public static function labItemById($id) {
        return self::one('SELECT * FROM lab_items WHERE id=?', array((int)$id));
    }
}