<?php
/**
 * ============================================================
 * services/fhir/adapters/EncounterAdapter.php — Encounter 资源适配器
 * ============================================================
 * 映射 registrations（门诊挂号/接诊）；搜索参数：_id、patient/subject、
 * date、status。Encounter id 规则：encounter-{registration.id}。
 * ============================================================ */
class EncounterAdapter extends FhirAdapter {

    public static function resourceType() { return 'Encounter'; }

    public static function resourceId($visitId) {
        return 'encounter-' . (int)$visitId;
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (ctype_digit($bareId)) {
            $row = PatientRepository::one('SELECT * FROM registrations WHERE id=?', array((int)$bareId));
            if ($row) return $row;
        }
        return PatientRepository::one('SELECT * FROM registrations WHERE flow_no=?', array($bareId));
    }

    /** 状态映射：门诊业务状态 → FHIR EncounterStatus */
    public static function mapStatus($status) {
        $map = array(
            'pending' => 'planned', 'paid' => 'arrived', 'visiting' => 'in-progress',
            'finished' => 'finished', 'refunded' => 'cancelled', 'cancelled' => 'cancelled',
        );
        return isset($map[$status]) ? $map[$status] : 'unknown';
    }

    public static function toResource($row) {
        $v = is_array($row) ? $row : array();
        $vid = isset($v['id']) ? (int)$v['id'] : 0;
        $pno = isset($v['patient_no']) ? (string)$v['patient_no'] : '';
        $res = array(
            'resourceType' => 'Encounter',
            'id' => self::resourceId($vid),
            'status' => self::mapStatus(isset($v['status']) ? $v['status'] : ''),
            'class' => array(
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB', 'display' => 'ambulatory',
            ),
        );
        if (!empty($v['flow_no'])) {
            $res['identifier'] = array(
                self::identifier('urn:clinic:identifier:visit', (string)$v['flow_no'], 'VN', 'Visit Number'),
            );
        }
        $subj = self::patientRef($pno);
        if ($subj) $res['subject'] = $subj;
        $start = self::isoDateTime(isset($v['registered_at']) ? $v['registered_at'] : '');
        $period = array();
        if ($start !== null) $period['start'] = $start;
        $end = self::isoDateTime(isset($v['finished_at']) ? $v['finished_at'] : '');
        if ($end !== null) $period['end'] = $end;
        if ($period) $res['period'] = $period;
        if (!empty($v['current_dept_name']) || !empty($v['first_dept_name'])) {
            $dept = !empty($v['current_dept_name']) ? $v['current_dept_name'] : $v['first_dept_name'];
            $res['serviceProvider'] = array('display' => (string)$dept);
        }
        return $res;
    }

    public static function search($params) {
        $where = array();
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            $flows = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'encounter-') === 0) $v = substr($v, 10);
                if (ctype_digit($v)) $ids[] = (int)$v;
                else $flows[] = $v;
            }
            $ors = array();
            if ($ids) { $ors[] = 'id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
            if ($flows) { $ors[] = 'flow_no IN (' . in_placeholders($flows) . ')'; $args = array_merge($args, $flows); }
            if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        $pno = '';
        if (isset($params['patient'])) $pno = (string)$params['patient'];
        elseif (isset($params['subject'])) $pno = (string)$params['subject'];
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'patient_no=?';
            $args[] = $pno;
        }

        if (isset($params['status']) && trim((string)$params['status']) !== '') {
            $rev = array('planned' => 'pending', 'arrived' => 'paid', 'in-progress' => 'visiting',
                'finished' => 'finished', 'cancelled' => array('refunded', 'cancelled'));
            $st = trim((string)$params['status']);
            if (isset($rev[$st])) {
                if (is_array($rev[$st])) {
                    $where[] = 'status IN (' . in_placeholders($rev[$st]) . ')';
                    $args = array_merge($args, $rev[$st]);
                } else {
                    $where[] = 'status=?';
                    $args[] = $rev[$st];
                }
            }
        }

        if (isset($params['date']) && trim((string)$params['date']) !== '') {
            $dates = array();
            foreach (explode(',', (string)$params['date']) as $d) {
                $d = trim($d);
                if ($d === '') continue;
                if (strlen($d) === 10) $dates[] = $d;
                elseif (strlen($d) === 7) $dates[] = $d . '-%';
                elseif (strlen($d) === 4) $dates[] = $d . '-%';
            }
            foreach ($dates as $d) {
                if (strpos($d, '%') !== false) { $where[] = "substr(registered_at,1,7) LIKE ?"; $args[] = $d; }
                else { $where[] = 'date(registered_at)=?'; $args[] = $d; }
            }
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM registrations ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'id', 'date' => 'registered_at'), 'id DESC');
        $rows = PatientRepository::q(
            'SELECT * FROM registrations ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
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
            array('name' => 'date', 'type' => 'date'),
            array('name' => 'status', 'type' => 'token'),
        );
    }
}
