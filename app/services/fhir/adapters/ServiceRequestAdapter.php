<?php
/**
 * ============================================================
 * services/fhir/adapters/ServiceRequestAdapter.php — 影像医嘱适配器
 * ============================================================
 * 映射「影像检查开单明细」为 FHIR R4 ServiceRequest，作为
 * 「摄片登记工作列表」的标准数据源（IHE Scheduled Workflow 的医嘱侧）。
 *
 * 说明（影像先后关系）：
 *   · 开单 / 缴费 → ServiceRequest（draft 未缴费 / active 已缴费可执行）；
 *   · 检查执行（登记 / 摄片）由 PACS 侧负责，状态经 Task 回写；
 *   · ImagingStudy 只在摄片完成后出现（见 ImagingStudyAdapter）。
 *
 * id 规则：servicerequest-{order_items.id}
 * 搜索参数：_id、patient/subject、encounter、status、identifier、authored、category。
 * ============================================================ */
class ServiceRequestAdapter extends FhirAdapter {

    public static function resourceType() { return 'ServiceRequest'; }

    /** 开单明细状态 → FHIR ServiceRequestStatus */
    public static function mapStatus($status) {
        $map = array(
            'open'      => 'draft',       // 已开单未缴费
            'paid'      => 'active',      // 已缴费，可执行
            'registered'=> 'active',      // 已登记/摄片中，仍在执行
            'done'      => 'completed',   // 已完成
            'refunded'  => 'revoked',     // 已退费
            'cancelled' => 'revoked',     // 已取消
            'rejected'  => 'revoked',     // 已拒绝
        );
        $s = strtolower(trim((string)$status));
        return isset($map[$s]) ? $map[$s] : 'draft';
    }

    private static function from() { return 'FROM order_items oi JOIN orders o ON o.id = oi.order_id'; }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) return null;
        return PatientRepository::one(
            "SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doctor_name, o.dept_name AS __dept_name, o.created_at AS __order_created "
            . self::from() . " WHERE oi.id=? AND oi.item_type='imaging' AND o.order_type='imaging'",
            array((int)$bareId)
        );
    }

    public static function toResource($row) {
        $r = is_array($row) ? $row : array();
        $itemId = (int)(isset($r['id']) ? $r['id'] : 0);
        $res = array(
            'resourceType' => 'ServiceRequest',
            'id' => 'servicerequest-' . $itemId,
            'status' => self::mapStatus(isset($r['status']) ? $r['status'] : ''),
            'intent' => 'order',
        );
        $orderNo = !empty($r['__order_no']) ? (string)$r['__order_no'] : (string)(isset($r['order_no']) ? $r['order_no'] : '');
        $ident = array();
        if ($orderNo !== '') $ident[] = self::identifier('urn:clinic:identifier:order', $orderNo, 'ACSN', 'Accession ID');
        if (!empty($r['flow_no'])) $ident[] = self::identifier('urn:clinic:identifier:visit', (string)$r['flow_no'], 'VN', 'Visit Number');
        if ($ident) $res['identifier'] = $ident;

        $res['category'] = array(self::codeable('http://snomed.info/sct', '363679005', 'Imaging'));
        $itemName = trim((string)(isset($r['item_name']) ? $r['item_name'] : ''));
        $res['code'] = array('text' => ($itemName !== '' ? $itemName : '影像检查'));

        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        if (!empty($r['visit_id'])) $res['encounter'] = array('reference' => 'Encounter/encounter-' . (int)$r['visit_id']);

        $authored = self::isoDateTime(!empty($r['__order_created']) ? $r['__order_created'] : (isset($r['created_at']) ? $r['created_at'] : ''));
        if ($authored !== null) $res['authoredOn'] = $authored;
        // 期望执行时间：登记时间（已登记）否则开单时间
        $occ = !empty($r['registered_at']) ? (string)$r['registered_at'] : (isset($r['created_at']) ? (string)$r['created_at'] : '');
        $occIso = self::isoDateTime($occ);
        if ($occIso !== null) $res['occurrenceDateTime'] = $occIso;

        $doc = trim((string)(isset($r['__doctor_name']) ? $r['__doctor_name'] : (isset($r['doctor_name']) ? $r['doctor_name'] : '')));
        if ($doc !== '') $res['requester'] = array('display' => $doc);
        $res['priority'] = 'routine';
        return $res;
    }

    public static function search($params) {
        $from = self::from();
        $where = array("oi.item_type='imaging'", "o.order_type='imaging'");
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if (strpos($v, 'servicerequest-') === 0) $v = substr($v, 15);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'oi.id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }

        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'oi.patient_no=?'; $args[] = $pno;
        }

        if (isset($params['encounter']) && trim((string)$params['encounter']) !== '') {
            $enc = trim((string)$params['encounter']);
            if (strpos($enc, 'Encounter/') === 0) $enc = substr($enc, 10);
            if (strpos($enc, 'encounter-') === 0) $enc = substr($enc, 10);
            if (ctype_digit($enc)) { $where[] = 'oi.visit_id=?'; $args[] = (int)$enc; }
        }

        if (isset($params['status']) && trim((string)$params['status']) !== '') {
            $want = array();
            $map = array(
                'draft' => array('open'), 'active' => array('paid', 'registered'),
                'completed' => array('done'), 'revoked' => array('refunded', 'cancelled', 'rejected'),
            );
            foreach (explode(',', (string)$params['status']) as $st) {
                $st = strtolower(trim($st));
                if (isset($map[$st])) $want = array_merge($want, $map[$st]);
            }
            if ($want) { $where[] = 'oi.status IN (' . in_placeholders($want) . ')'; $args = array_merge($args, $want); }
        }

        if (isset($params['identifier']) && trim((string)$params['identifier']) !== '') {
            $idv = trim((string)$params['identifier']);
            if (strpos($idv, '|') !== false) { list(, $idv) = explode('|', $idv, 2); }
            $where[] = 'o.order_no=?'; $args[] = trim($idv);
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) ' . $from . ' ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $rows = PatientRepository::q(
            'SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doctor_name, o.dept_name AS __dept_name, o.created_at AS __order_created '
            . $from . ' ' . $sqlWhere . ' ORDER BY oi.id DESC LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
            $args
        );
        $entries = array(); $patientRefs = array();
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
            array('name' => 'identifier', 'type' => 'token'),
            array('name' => 'authored', 'type' => 'date'),
            array('name' => 'category', 'type' => 'token'),
        );
    }
}
