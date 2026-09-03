<?php
/**
 * ============================================================
 * EmrRepository.php — 电子病历仓库
 * ============================================================
 * 覆盖：patient_records（结构化病历）、records（扁平镜像）、
 * vitals、templates、certificates、referrals、consents、diag_orders。
 * ============================================================ */
class EmrRepository extends BaseRepository {

    // ===== patient_records 结构化病历 =====
    public static function deleteRecord($id) {
        return self::exec('DELETE FROM patient_records WHERE id=?', array((int)$id));
    }
    public static function countByVisitDoctor($visitId, $doctorId) {
        return (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
    }

    // ===== records 扁平镜像 =====
    /** 删除镜像（按病历记录 id） */
    public static function deleteMirrorByPatientRecord($patientRecordId) {
        self::exec('DELETE FROM records WHERE patient_record_id=?', array((int)$patientRecordId));
    }

    // ===== vitals 体征 =====
    public static function vitalsByVisit($visitId) {
        return self::q('SELECT * FROM vitals WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    }
    public static function insertVitals($data) { return self::insertRow('vitals', $data); }

    // ===== certificates 诊断证明 =====
    public static function certificateByVisit($visitId) {
        return self::one('SELECT * FROM certificates WHERE visit_id=? ORDER BY id DESC', array((int)$visitId));
    }
    public static function countCertificatesByVisit($visitId) {
        return (int)self::val('SELECT COUNT(*) FROM certificates WHERE visit_id=?', array((int)$visitId));
    }
    public static function countCertificatesByNo($certNo) {
        return (int)self::val('SELECT COUNT(*) FROM certificates WHERE cert_no=?', array($certNo));
    }
    public static function insertCertificate($data) { return self::insertRow('certificates', $data); }

    // ===== diag_orders 诊断排序 =====

    /** 删除镜像（按 id） */
    public static function deleteMirrorById($id) {
        return self::exec('DELETE FROM records WHERE id=?', array((int)$id));
    }

    /** 会诊处理回退（删除会诊病历 = 放弃本次会诊处理） */
    public static function revertConsultation($consultId) {
        self::exec("UPDATE consultations SET status='pending', accepted_by='', accepted_at='', finished_by='', finished_at='' WHERE id=?", array((int)$consultId));
    }

    /** 就诊状态退回待就诊（当前科室已无文书时） */
    public static function revertVisitStatus($visitId, $status) {
        self::exec('UPDATE registrations SET status=? WHERE id=?', array($status, (int)$visitId));
    }

    // ===== nursing_records 护理记录 =====
    public static function nursingByVisit($visitId, $limit = 50) {
        return self::q('SELECT * FROM nursing_records WHERE visit_id=? ORDER BY id DESC LIMIT ' . (int)$limit, array((int)$visitId));
    }
    public static function insertNursing($data) {
        return self::insert('INSERT INTO nursing_records(visit_id, patient_no, flow_no, content, operator, created_at) VALUES(?,?,?,?,?,?)',
            array((int)$data['visit_id'], $data['patient_no'], $data['flow_no'], $data['content'], $data['operator'], now_str()));
    }
}