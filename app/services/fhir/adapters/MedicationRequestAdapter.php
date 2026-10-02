<?php
/**
 * ============================================================
 * services/fhir/adapters/MedicationRequestAdapter.php — 处方医嘱适配器
 * ============================================================
 * 映射处方开单明细（order_items，item_type=prescription）。
 * 搜索参数：_id、patient/subject、encounter、status。
 * id 规则：medicationrequest-{order_items.id}。
 * ============================================================ */
class MedicationRequestAdapter extends FhirAdapter {

    public static function resourceType() { return 'MedicationRequest'; }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) return null;
        $row = PatientRepository::one("SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doc, o.order_type AS __order_type
            FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? AND oi.item_type='prescription'", array((int)$bareId));
        return $row;
    }

    /** 映射开单状态 → FHIR MedicationRequestStatus */
    public static function mapStatus($status) {
        $map = array(
            'open' => 'active', 'paid' => 'active', 'dispensing' => 'active', 'dispensed' => 'completed',
            'done' => 'completed', 'refunded' => 'cancelled', 'cancelled' => 'cancelled',
        );
        return isset($map[$status]) ? $map[$status] : 'active';
    }

    public static function toResource($row) {
        $r = is_array($row) ? $row : array();
        $res = array(
            'resourceType' => 'MedicationRequest',
            'id' => 'medicationrequest-' . (int)(isset($r['id']) ? $r['id'] : 0),
            'status' => self::mapStatus(isset($r['status']) ? $r['status'] : ''),
            'intent' => 'order',
        );
        if (!empty($r['order_no']) || !empty($r['__order_no'])) {
            $no = !empty($r['order_no']) ? $r['order_no'] : $r['__order_no'];
            $res['identifier'] = array(self::identifier('urn:clinic:identifier:prescription', (string)$no, 'PLAC', 'Placer Order Number'));
        }
        $res['medicationCodeableConcept'] = array('text' => isset($r['item_name']) ? (string)$r['item_name'] : '');
        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        if (!empty($r['visit_id'])) {
            $res['encounter'] = array('reference' => 'Encounter/encounter-' . (int)$r['visit_id']);
        }
        $dose = trim((string)(isset($r['single_dose']) ? $r['single_dose'] : ''));
        $freq = trim((string)(isset($r['frequency']) ? $r['frequency'] : ''));
        $route = trim((string)(isset($r['route']) ? $r['route'] : ''));
        $text = trim($dose . ' ' . $freq . ' ' . $route);
        if ($text !== '') {
            $res['dosageInstruction'] = array(array('text' => $text));
        }
        if (isset($r['quantity'])) {
            $res['dispenseRequest'] = array('quantity' => array('value' => (int)$r['quantity']));
        }
        $authored = self::isoDateTime(isset($r['created_at']) ? $r['created_at'] : '');
        if ($authored !== null) $res['authoredOn'] = $authored;
        if (!empty($r['doctor_name'])) $res['requester'] = array('display' => (string)$r['doctor_name']);
        return $res;
    }

    public static function search($params) {
        $where = array("oi.item_type='prescription'");
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'medicationrequest-') === 0) $v = substr($v, 18);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'oi.id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }
        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'oi.patient_no=?';
            $args[] = $pno;
        }
        if (isset($params['encounter']) && trim((string)$params['encounter']) !== '') {
            $enc = trim((string)$params['encounter']);
            if (strpos($enc, 'Encounter/') === 0) $enc = substr($enc, 10);
            if (strpos($enc, 'encounter-') === 0) $enc = substr($enc, 10);
            if (ctype_digit($enc)) { $where[] = 'oi.visit_id=?'; $args[] = (int)$enc; }
        }
        if (isset($params['status']) && trim((string)$params['status']) !== '') {
            $st = trim((string)$params['status']);
            $rev = array('active' => array('open', 'paid', 'dispensing'), 'completed' => array('dispensed', 'done'),
                'cancelled' => array('refunded', 'cancelled'));
            if (isset($rev[$st])) {
                $where[] = 'oi.status IN (' . in_placeholders($rev[$st]) . ')';
                $args = array_merge($args, $rev[$st]);
            }
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM order_items oi ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'oi.id', 'date' => 'oi.created_at', 'authoredon' => 'oi.created_at'), 'oi.id DESC');
        $rows = PatientRepository::q(
            'SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doc FROM order_items oi JOIN orders o ON o.id=oi.order_id '
            . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
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
            array('name' => 'status', 'type' => 'token'),
        );
    }
}
