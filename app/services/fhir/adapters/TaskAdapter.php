<?php
/**
 * ============================================================
 * services/fhir/adapters/TaskAdapter.php — 影像检查工作项适配器（FHIR Task）
 * ============================================================
 * 映射「影像检查开单明细 + imaging_tasks 工作项」为 FHIR R4 Task，
 * 表达 IHE Scheduled Workflow / MPPS 的工作状态机（标准对应）：
 *   requested（已缴费待登记）→ accepted（已登记/已排程）
 *   → in-progress（摄片中）→ completed（已摄片）；cancelled（退费/取消）。
 *
 * 写回：PACS 侧登记 / 摄片后经 FHIR 写入本资源，落 imaging_tasks 表
 * （见 FhirService::write / applyWrite），供门诊退费门禁与报告入口判定。
 *
 * id 规则：task-{order_items.id}（与 servicerequest-{id} 1:1）
 * 搜索参数：_id、patient/subject、encounter、basedOn、focus、status、owner、business-status。
 * ============================================================ */
class TaskAdapter extends FhirAdapter {

    public static function resourceType() { return 'Task'; }

    /** 由开单明细状态（无工作项记录时）派生初始 Task 状态 */
    public static function deriveStatus($itemStatus) {
        $map = array(
            'open' => 'draft', 'paid' => 'requested', 'registered' => 'in-progress',
            'done' => 'completed', 'refunded' => 'cancelled', 'cancelled' => 'cancelled', 'rejected' => 'rejected',
        );
        $s = strtolower(trim((string)$itemStatus));
        return isset($map[$s]) ? $map[$s] : 'requested';
    }

    /** 工作项状态 → 中文业务状态（展示用） */
    public static function businessText($status) {
        $map = array(
            'draft' => '未缴费', 'requested' => '待登记', 'accepted' => '已登记待摄片',
            'in-progress' => '摄片中', 'completed' => '已摄片', 'cancelled' => '已取消', 'rejected' => '已拒绝',
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }

    private static function from() {
        return 'FROM order_items oi JOIN orders o ON o.id = oi.order_id'
            . ' LEFT JOIN imaging_tasks t ON t.order_item_id = oi.id';
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) return null;
        return PatientRepository::one(
            "SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doctor_name, o.created_at AS __order_created,"
            . " t.status AS __task_status, t.business_status AS __business_status, t.owner AS __owner,"
            . " t.status_reason AS __status_reason, t.registered_at AS __reg_at, t.performed_at AS __perf_at, t.updated_at AS __task_updated "
            . self::from() . " WHERE oi.id=? AND oi.item_type='imaging' AND o.order_type='imaging'",
            array((int)$bareId)
        );
    }

    /** 组合状态：优先工作项记录，否则按开单明细状态派生 */
    private static function statusOf($r) {
        $t = isset($r['__task_status']) ? trim((string)$r['__task_status']) : '';
        return $t !== '' ? $t : self::deriveStatus(isset($r['status']) ? $r['status'] : '');
    }

    public static function toResource($row) {
        $r = is_array($row) ? $row : array();
        $itemId = (int)(isset($r['id']) ? $r['id'] : 0);
        $status = self::statusOf($r);
        $res = array(
            'resourceType' => 'Task',
            'id' => 'task-' . $itemId,
            'status' => $status,
            'intent' => 'order',
            'priority' => 'routine',
        );
        $res['basedOn'] = array(array('reference' => 'ServiceRequest/servicerequest-' . $itemId));
        $res['focus'] = array('reference' => 'ServiceRequest/servicerequest-' . $itemId);
        $orderNo = !empty($r['__order_no']) ? (string)$r['__order_no'] : (string)(isset($r['order_no']) ? $r['order_no'] : '');
        if ($orderNo !== '') {
            $res['identifier'] = array(self::identifier('urn:clinic:identifier:order', $orderNo, 'ACSN', 'Accession ID'));
        }
        $itemName = trim((string)(isset($r['item_name']) ? $r['item_name'] : ''));
        if ($itemName !== '') $res['description'] = $itemName;
        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['for'] = $subj;
        if (!empty($r['visit_id'])) $res['encounter'] = array('reference' => 'Encounter/encounter-' . (int)$r['visit_id']);

        $authored = self::isoDateTime(!empty($r['__order_created']) ? $r['__order_created'] : (isset($r['created_at']) ? $r['created_at'] : ''));
        if ($authored !== null) $res['authoredOn'] = $authored;

        $start = !empty($r['__reg_at']) ? (string)$r['__reg_at'] : '';
        $end = !empty($r['__perf_at']) ? (string)$r['__perf_at'] : '';
        $period = array();
        if ($start !== '') { $iso = self::isoDateTime($start); if ($iso !== null) $period['start'] = $iso; }
        if ($end !== '') { $iso = self::isoDateTime($end); if ($iso !== null) $period['end'] = $iso; }
        if ($period) $res['executionPeriod'] = $period;

        $biz = !empty($r['__business_status']) ? (string)$r['__business_status'] : self::businessText($status);
        if ($biz !== '') $res['businessStatus'] = array('text' => $biz);
        if (!empty($r['__status_reason'])) $res['statusReason'] = array('text' => (string)$r['__status_reason']);
        $owner = !empty($r['__owner']) ? (string)$r['__owner'] : 'PACS';
        $res['owner'] = array('display' => $owner);
        $doc = trim((string)(isset($r['__doctor_name']) ? $r['__doctor_name'] : (isset($r['doctor_name']) ? $r['doctor_name'] : '')));
        if ($doc !== '') $res['requester'] = array('display' => $doc);
        if (!empty($r['__task_updated'])) {
            $iso = self::isoDateTime($r['__task_updated']);
            if ($iso !== null) $res['lastModified'] = $iso;
        }
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
                if (strpos($v, 'task-') === 0) $v = substr($v, 5);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'oi.id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }
        $pno = trim((string)(isset($params['patient']) ? $params['patient'] : (isset($params['subject']) ? $params['subject'] : '')));
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
        if (isset($params['basedOn']) && trim((string)$params['basedOn']) !== '') {
            $b = trim((string)$params['basedOn']);
            if (strpos($b, 'ServiceRequest/') === 0) $b = substr($b, 15);
            if (strpos($b, 'servicerequest-') === 0) $b = substr($b, 14);
            if (ctype_digit($b)) { $where[] = 'oi.id=?'; $args[] = (int)$b; }
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        // 状态过滤：工作项记录状态或按开单明细状态派生。用 OR 覆盖两种来源。
        if (isset($params['status']) && trim((string)$params['status']) !== '') {
            $want = array_values(array_filter(array_map('trim', array_map('strtolower', explode(',', (string)$params['status'])))));
            if ($want) {
                $ph = in_placeholders($want);
                $map = array(
                    'draft' => array('open'), 'requested' => array('paid'), 'accepted' => array('registered'),
                    'in-progress' => array('registered'), 'completed' => array('done'),
                    'cancelled' => array('refunded', 'cancelled'), 'rejected' => array('rejected'),
                );
                $itemStates = array();
                foreach ($want as $w) { if (isset($map[$w])) $itemStates = array_merge($itemStates, $map[$w]); }
                $clause = '(COALESCE(NULLIF(t.status,\'\'), \'\') IN (' . $ph . ')';
                $args2 = $want;
                if ($itemStates) {
                    $clause .= ' OR (COALESCE(NULLIF(t.status,\'\'), \'\')=\'\' AND oi.status IN (' . in_placeholders($itemStates) . '))';
                    $args2 = array_merge($args2, $itemStates);
                }
                $clause .= ')';
                $sqlWhere .= ' AND ' . $clause;
                $args = array_merge($args, $args2);
            }
        }

        $total = (int)PatientRepository::val('SELECT COUNT(*) ' . $from . ' ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $rows = PatientRepository::q(
            'SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doctor_name, o.created_at AS __order_created,'
            . ' t.status AS __task_status, t.business_status AS __business_status, t.owner AS __owner,'
            . ' t.status_reason AS __status_reason, t.registered_at AS __reg_at, t.performed_at AS __perf_at, t.updated_at AS __task_updated '
            . $from . ' ' . $sqlWhere . ' ORDER BY oi.id DESC LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
            $args
        );
        $entries = array(); $patientRefs = array();
        foreach ($rows as $r) { $entries[] = self::toResource($r); $patientRefs[] = (string)$r['patient_no']; }
        return array('total' => $total, 'entries' => $entries, 'patientRefs' => $patientRefs);
    }

    public static function searchParams() {
        return array(
            array('name' => '_id', 'type' => 'token'),
            array('name' => 'patient', 'type' => 'reference'),
            array('name' => 'subject', 'type' => 'reference'),
            array('name' => 'encounter', 'type' => 'reference'),
            array('name' => 'basedOn', 'type' => 'reference'),
            array('name' => 'focus', 'type' => 'reference'),
            array('name' => 'status', 'type' => 'token'),
            array('name' => 'owner', 'type' => 'reference'),
            array('name' => 'business-status', 'type' => 'token'),
        );
    }

    /**
     * 写入 / 更新工作项（PACS 侧登记 / 摄片回写）。
     * @param string $bareId order_item_id
     * @param array  $resource FHIR Task 资源
     * @return array 更新后的资源
     * @throws FhirError 校验失败
     */
    public static function applyWrite($bareId, $resource) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) throw new FhirError(400, 'invalid', 'Task id 必须为 task-{编号}');
        $itemId = (int)$bareId;
        $it = PatientRepository::one("SELECT * FROM order_items WHERE id=? AND item_type='imaging'", array($itemId));
        if (!$it) throw new FhirError(404, 'not-found', '检查项目不存在：' . $itemId);
        $order = PatientRepository::one('SELECT * FROM orders WHERE id=?', array((int)$it['order_id']));

        $status = strtolower(trim((string)(isset($resource['status']) ? $resource['status'] : '')));
        $allowed = array('requested', 'accepted', 'in-progress', 'completed', 'cancelled', 'rejected', 'draft');
        if (!in_array($status, $allowed, true)) throw new FhirError(400, 'invalid', 'Task.status 非法：' . $status);

        $biz = isset($resource['businessStatus']['text']) ? (string)$resource['businessStatus']['text'] : self::businessText($status);
        $reason = isset($resource['statusReason']['text']) ? (string)$resource['statusReason']['text'] : '';
        $owner = isset($resource['owner']['display']) ? (string)$resource['owner']['display'] : 'PACS';
        $regAt = isset($resource['executionPeriod']['start']) ? self::fromIso($resource['executionPeriod']['start']) : '';
        $perfAt = isset($resource['executionPeriod']['end']) ? self::fromIso($resource['executionPeriod']['end']) : '';
        if ($status === 'accepted' || $status === 'in-progress') {
            if ($regAt === '') $regAt = now_str();
        }
        if ($status === 'completed' && $perfAt === '') $perfAt = now_str();

        $existing = PatientRepository::one('SELECT id FROM imaging_tasks WHERE order_item_id=?', array($itemId));
        if ($existing) {
            PatientRepository::exec(
                'UPDATE imaging_tasks SET status=?, business_status=?, owner=?, status_reason=?, registered_at=?, performed_at=?, updated_at=? WHERE id=?',
                array($status, $biz, $owner, $reason, $regAt, $perfAt, now_str(), (int)$existing['id'])
            );
        } else {
            PatientRepository::insert(
                'INSERT INTO imaging_tasks(order_item_id, order_id, visit_id, patient_no, flow_no, status, business_status, owner, status_reason, registered_at, performed_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($itemId, (int)$it['order_id'], (int)$it['visit_id'], (string)$it['patient_no'], (string)$it['flow_no'],
                    $status, $biz, $owner, $reason, $regAt, $perfAt, now_str(), now_str())
            );
        }

        // 同步开单明细状态：登记 / 摄片推进 → registered（待出报告）；取消 → 保持/联动
        if (in_array($status, array('accepted', 'in-progress', 'completed'), true) && (string)$it['status'] === 'paid') {
            PatientRepository::exec("UPDATE order_items SET status='registered', registered_at=? WHERE id=?", array($regAt !== '' ? $regAt : now_str(), $itemId));
        }
        if ($status === 'cancelled' && in_array((string)$it['status'], array('paid', 'registered'), true)) {
            PatientRepository::exec("UPDATE order_items SET status='refunded' WHERE id=?", array($itemId));
        }

        $row = self::findRowByBareId($bareId);
        return self::toResource($row);
    }

    /** ISO 时间 → "Y-m-d H:i:s"（失败返回原串） */
    private static function fromIso($s) {
        $s = trim((string)$s);
        if ($s === '') return '';
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }
}
