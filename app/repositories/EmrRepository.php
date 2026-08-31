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
    public static function recordById($id) {
        return self::one('SELECT * FROM patient_records WHERE id=?', array((int)$id));
    }
    public static function recordsByVisit($visitId) {
        return self::q('SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    }
    public static function recordByVisitDoctor($visitId, $doctorId) {
        return self::one('SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }
    public static function latestRecordByVisitDoctor($visitId, $doctorId) {
        return self::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }
    public static function updateRecord($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE patient_records SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }
    public static function insertRecord($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO patient_records($cols) VALUES($phs)", array_values($data));
    }
    public static function deleteRecord($id) {
        return self::exec('DELETE FROM patient_records WHERE id=?', array((int)$id));
    }
    public static function countByVisitDoctor($visitId, $doctorId) {
        return (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
    }
    public static function countByVisit($visitId) {
        return (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=?', array((int)$visitId));
    }
    public static function maxIdByVisit($visitId) {
        return (int)self::val('SELECT MAX(id) FROM patient_records WHERE visit_id=?', array((int)$visitId));
    }
    public static function countByVisitDoctorOtherDept($visitId, $doctorId, $deptId) {
        return (int)self::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id<>? AND dept_id=?', array((int)$visitId, (int)$doctorId, (int)$deptId));
    }

    // ===== records 扁平镜像 =====
    public static function mirrorByVisitDoctor($visitId, $doctorId) {
        return self::one('SELECT * FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }
    public static function mirrorByPatientRecord($patientRecordId) {
        return self::one('SELECT id FROM records WHERE patient_record_id=?', array((int)$patientRecordId));
    }
    public static function mirrorByVisitDoctorFallback($visitId, $doctorId) {
        return self::one('SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }
    public static function insertMirror($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO records($cols) VALUES($phs)", array_values($data));
    }
    public static function updateMirror($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE records SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }
    public static function deleteMirrorByPatientRecord($patientRecordId) {
        self::exec('DELETE FROM records WHERE patient_record_id=?', array((int)$patientRecordId));
    }
    public static function countByVisitDoctorMirror($visitId, $doctorId) {
        return (int)self::val('SELECT COUNT(*) FROM records WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
    }
    public static function latestMirrorIdByVisitDoctor($visitId, $doctorId) {
        return (int)self::val('SELECT id FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }
    public static function mirrorByVisitDoctorWithPatientRecordZero($visitId, $doctorId) {
        return self::one('SELECT id FROM records WHERE visit_id=? AND doctor_id=? AND patient_record_id=0 ORDER BY id DESC LIMIT 1', array((int)$visitId, (int)$doctorId));
    }

    // ===== vitals 体征 =====
    public static function vitalsByVisit($visitId) {
        return self::q('SELECT * FROM vitals WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    }
    public static function vitalsByRecordId($recordId) {
        return self::one('SELECT * FROM vitals WHERE record_id=? ORDER BY id DESC LIMIT 1', array((int)$recordId));
    }
    public static function vitalsByVisitOperator($visitId, $operator) {
        return self::one('SELECT * FROM vitals WHERE visit_id=? AND operator=? ORDER BY id DESC LIMIT 1', array((int)$visitId, $operator));
    }
    public static function vitalsIdByVisitRecord($visitId, $recordId) {
        return self::one('SELECT id FROM vitals WHERE visit_id=? AND record_id=? LIMIT 1', array((int)$visitId, (int)$recordId));
    }
    public static function insertVitals($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO vitals($cols) VALUES($phs)", array_values($data));
    }
    public static function updateVitals($id, $data) {
        $set = array(); $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec('UPDATE vitals SET ' . implode(',', $set) . ' WHERE id=?', $params);
    }
    public static function updateVitalsRecordId($recordId, $visitId, $operator) {
        self::exec('UPDATE vitals SET record_id=? WHERE visit_id=? AND operator=? AND record_id=0', array((int)$recordId, (int)$visitId, $operator));
    }

    // ===== templates 病历模板 =====
    public static function templates() {
        return self::q('SELECT * FROM templates ORDER BY id');
    }

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
    public static function insertCertificate($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO certificates($cols) VALUES($phs)", array_values($data));
    }
    public static function certificatesByVisitOtherDoctors($visitId, $doctorId) {
        return self::q('SELECT * FROM patient_records WHERE visit_id=? AND doctor_id<>? ORDER BY id ASC', array((int)$visitId, (int)$doctorId));
    }

    // ===== referrals 转科 =====
    public static function insertReferral($data) {
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO referrals($cols) VALUES($phs)", array_values($data));
    }

    // ===== diag_orders 诊断排序 =====
    public static function diagOrderByVisitDoctor($visitId, $doctorId) {
        return self::one('SELECT id FROM diag_orders WHERE visit_id=? AND doctor_id=?', array((int)$visitId, (int)$doctorId));
    }
    public static function insertDiagOrder($visitId, $doctorId, $ordKeys) {
        return self::insert('INSERT INTO diag_orders(visit_id, doctor_id, ord_keys, updated_at) VALUES(?,?,?,?)', array((int)$visitId, (int)$doctorId, $ordKeys, now_str()));
    }
    public static function updateDiagOrder($id, $ordKeys) {
        self::exec('UPDATE diag_orders SET ord_keys=?, updated_at=? WHERE id=?', array($ordKeys, now_str(), (int)$id));
    }

    // ===== consents 知情同意书 =====
    public static function consentsByVisit($visitId) {
        return self::q('SELECT * FROM consents WHERE visit_id=? ORDER BY id', array((int)$visitId));
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