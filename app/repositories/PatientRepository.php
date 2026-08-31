<?php
/**
 * ============================================================
 * PatientRepository.php — 患者档案仓库
 * ============================================================
 * 说明：封装患者档案（建档/修改/检索）与就诊历史相关 SQL，
 * 全部经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class PatientRepository extends BaseRepository {

    /** 按身份证查询患者（挂号自动填充用） */
    public static function byIdCard($idCard) {
        return self::one('SELECT * FROM patients WHERE id_card=?', array($idCard));
    }

    /** 按患者编号或身份证查询 */
    public static function byPatientNo($patientNo) {
        return self::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
    }

    /** 按身份证或患者编号查询（二选一） */
    public static function byCardOrNo($kw) {
        return self::one('SELECT * FROM patients WHERE id_card=? OR patient_no=?', array($kw, $kw));
    }

    /** 模糊检索（患者编号/身份证/姓名），倒序限 20 条 */
    public static function search($kw) {
        $like = '%' . $kw . '%';
        return self::q(
            'SELECT * FROM patients WHERE patient_no LIKE ? OR id_card LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT 20',
            array($like, $like, $like)
        );
    }

    /** 更新患者可修改信息（姓名/性别/身份证/出生年月不可改） */
    public static function updateProfile($patientNo, $fields) {
        $set = array();
        $params = array();
        $allowed = array('phone', 'ethnicity', 'marital', 'occupation', 'work_unit', 'address');
        foreach ($allowed as $f) {
            $set[] = $f . '=?';
            $params[] = isset($fields[$f]) ? $fields[$f] : '';
        }
        $params[] = $patientNo;
        return self::exec('UPDATE patients SET ' . implode(',', $set) . ' WHERE patient_no=?', $params);
    }

    /** 该患者全部就诊记录（倒序） */
    public static function visitsOf($patientNo) {
        return self::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY registered_at DESC, id DESC', array($patientNo));
    }

    /** 就诊是否有已保存病历（结构化表优先，旧镜像表兜底） */
    public static function visitHasRecord($visitId) {
        $c1 = (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=?', array((int)$visitId));
        $c2 = (int)self::val('SELECT COUNT(*) FROM records WHERE visit_id=?', array((int)$visitId));
        return ($c1 > 0 || $c2 > 0);
    }

    /** 就诊是否有诊断证明 */
    public static function visitHasCertificate($visitId) {
        return (int)self::val('SELECT COUNT(*) FROM certificates WHERE visit_id=?', array((int)$visitId)) > 0;
    }

    /** 当前医生是否接诊过该就诊（结构化表或镜像表任一有本人文书） */
    public static function visitTreatedBy($visitId, $doctorId) {
        $c1 = (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
        $c2 = (int)self::val('SELECT COUNT(*) FROM records WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
        return ($c1 > 0 || $c2 > 0);
    }
}