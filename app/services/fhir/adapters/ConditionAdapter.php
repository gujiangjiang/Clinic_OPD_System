<?php
/**
 * ============================================================
 * services/fhir/adapters/ConditionAdapter.php — Condition 资源适配器
 * ============================================================
 * 映射门诊诊断病历（patient_records.icd10_code）；ICD-10 代码关联。
 * 搜索参数：_id、patient/subject、encounter、code。
 * Condition id 规则：condition-{patient_records.id}。
 * ============================================================ */
class ConditionAdapter extends FhirAdapter {

    public static function resourceType() { return 'Condition'; }

    public static function resourceId($recordId) {
        return 'condition-' . (int)$recordId;
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) return null;
        return PatientRepository::one('SELECT * FROM patient_records WHERE id=?', array((int)$bareId));
    }

    public static function toResource($row) {
        $r = is_array($row) ? $row : array();
        $res = array(
            'resourceType' => 'Condition',
            'id' => self::resourceId(isset($r['id']) ? $r['id'] : 0),
            'clinicalStatus' => self::codeable('http://terminology.hl7.org/CodeSystem/condition-clinical', 'active', 'Active'),
            'verificationStatus' => self::codeable('http://terminology.hl7.org/CodeSystem/condition-ver-status', 'confirmed', 'Confirmed'),
        );
        $code = isset($r['icd10_code']) ? trim((string)$r['icd10_code']) : '';
        $name = isset($r['diagnosis_name']) ? trim((string)$r['diagnosis_name']) : '';
        if ($code !== '' || $name !== '') {
            $res['code'] = array();
            if ($code !== '') {   // 仅在编码非空时提供 coding（FHIR code 不可为空）
                $res['code']['coding'] = array(array(
                    'system' => 'http://hl7.org/fhir/sid/icd-10-cn',
                    'code' => $code,
                    'display' => $name,
                ));
            }
            $res['code']['text'] = $name !== '' ? $name : $code;
        }
        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        if (!empty($r['visit_id'])) {
            $res['encounter'] = array('reference' => self::ref('Encounter', self::refId('encounter', (int)$r['visit_id'])));
        }
        $rec = self::isoDateTime(isset($r['created_at']) ? $r['created_at'] : '');
        if ($rec !== null) $res['recordedDate'] = $rec;
        return $res;
    }

    /** 构造嵌套引用 id（encounter-1） */
    protected static function refId($prefix, $id) {
        return $prefix . '-' . (int)$id;
    }

    public static function search($params) {
        $where = array("icd10_code IS NOT NULL", "icd10_code<>''");
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'condition-') === 0) $v = substr($v, 10);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }
        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'patient_no=?';
            $args[] = $pno;
        }
        if (isset($params['encounter']) && trim((string)$params['encounter']) !== '') {
            $enc = trim((string)$params['encounter']);
            if (strpos($enc, 'Encounter/') === 0) $enc = substr($enc, 10);
            if (strpos($enc, 'encounter-') === 0) $enc = substr($enc, 10);
            if (ctype_digit($enc)) { $where[] = 'visit_id=?'; $args[] = (int)$enc; }
        }
        if (isset($params['code']) && trim((string)$params['code']) !== '') {
            $code = trim((string)$params['code']);
            if (strpos($code, '|') !== false) { list(, $code) = explode('|', $code, 2); }
            $where[] = 'icd10_code=?';
            $args[] = $code;
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM patient_records ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'id', 'date' => 'created_at', 'recorded-date' => 'created_at'), 'id DESC');
        $rows = PatientRepository::q(
            'SELECT * FROM patient_records ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
            $args
        );
        $entries = array();
        $patientRefs = array();
        foreach ($rows as $r) {
            $entries[] = self::toResource($r);
            $patientRefs[] = (string)$r['patient_no'];
        }
        return array('total' => $total, 'entries' => $entries, 'patientRefs' => $patientRefs);
    }

    public static function searchParams() {
        return array(
            array('name' => '_id', 'type' => 'token'),
            array('name' => 'patient', 'type' => 'reference'),
            array('name' => 'subject', 'type' => 'reference'),
            array('name' => 'encounter', 'type' => 'reference'),
            array('name' => 'code', 'type' => 'token'),
        );
    }
}
