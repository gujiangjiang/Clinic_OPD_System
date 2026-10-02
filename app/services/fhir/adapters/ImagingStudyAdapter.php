<?php
/**
 * ============================================================
 * services/fhir/adapters/ImagingStudyAdapter.php — 影像检查适配器
 * ============================================================
 * 映射影像引用（imaging_refs）+ 检查报告（reports），供 PACS 浏览器联调。
 * 搜索参数：_id、patient/subject、identifier（含 DICOM StudyInstanceUID，
 * system=urn:dicom:uid）、modality、started。
 * id 规则：imagingstudy-{imaging_refs.id}（亦支持按 StudyInstanceUID 读取）。
 * 输出 status / subject / modality / numberOfSeries / numberOfInstances /
 * series[] / endpoint（可选）及影像所见与结论文本。
 * ============================================================ */
class ImagingStudyAdapter extends FhirAdapter {

    public static function resourceType() { return 'ImagingStudy'; }

    public static function resourceId($refId) {
        return 'imagingstudy-' . (int)$refId;
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (ctype_digit($bareId)) {
            $row = PatientRepository::one('SELECT ir.*, o.order_no, o.category_name FROM imaging_refs ir JOIN orders o ON o.id=ir.order_id WHERE ir.id=? AND o.order_type=\'imaging\'', array((int)$bareId));
            if ($row) return $row;
        }
        // 兼容按 DICOM StudyInstanceUID 直读（仅影像检查）
        return PatientRepository::one('SELECT ir.*, o.order_no, o.category_name FROM imaging_refs ir JOIN orders o ON o.id=ir.order_id WHERE ir.study_uid=? AND o.order_type=\'imaging\'', array($bareId));
    }

    /** 解析 series 明细：优先 meta_json.series（对象数组），回退 series_uids（字符串数组） */
    private static function seriesList($ref) {
        $meta = json_decode(isset($ref['meta_json']) ? (string)$ref['meta_json'] : '', true);
        if (is_array($meta) && !empty($meta['series']) && is_array($meta['series'])) {
            $out = array();
            foreach ($meta['series'] as $s) {
                if (!is_array($s)) continue;
                $out[] = array(
                    'uid' => isset($s['uid']) ? (string)$s['uid'] : '',
                    'modality' => isset($s['modality']) ? (string)$s['modality'] : '',
                    'description' => isset($s['description']) ? (string)$s['description'] : '',
                    'instances' => isset($s['instances']) ? (int)$s['instances'] : 0,
                );
            }
            if ($out) return $out;
        }
        $uids = json_decode(isset($ref['series_uids']) ? (string)$ref['series_uids'] : '', true);
        if (!is_array($uids)) $uids = array();
        $out = array();
        $defMod = isset($ref['modality']) ? (string)$ref['modality'] : '';
        foreach ($uids as $u) {
            if (is_array($u)) {
                $out[] = array(
                    'uid' => isset($u['uid']) ? (string)$u['uid'] : '',
                    'modality' => isset($u['modality']) ? (string)$u['modality'] : $defMod,
                    'description' => isset($u['description']) ? (string)$u['description'] : '',
                    'instances' => isset($u['instances']) ? (int)$u['instances'] : 0,
                );
            } else {
                $out[] = array('uid' => (string)$u, 'modality' => $defMod, 'description' => '', 'instances' => 0);
            }
        }
        return $out;
    }

    /** 检查项目名（StudyDescription）：开单明细 order_items.item_name */
    private static function orderItemName($ref) {
        if (empty($ref['order_item_id'])) return '';
        $it = PatientRepository::one('SELECT item_name FROM order_items WHERE id=?', array((int)$ref['order_item_id']));
        return ($it && isset($it['item_name'])) ? trim((string)$it['item_name']) : '';
    }

    /** 关联申请单（取 orders.order_no = 检查号/Accession Number） */
    private static function orderOf($ref) {
        if (empty($ref['order_id'])) return null;
        return PatientRepository::one('SELECT * FROM orders WHERE id=?', array((int)$ref['order_id']));
    }

    /** 关联报告（按 order_item.result_id → reports.result_id） */
    private static function reportOf($ref) {
        if (empty($ref['order_item_id'])) return null;
        $item = PatientRepository::one('SELECT result_id FROM order_items WHERE id=?', array((int)$ref['order_item_id']));
        if (!$item || (int)$item['result_id'] <= 0) return null;
        return PatientRepository::one("SELECT * FROM reports WHERE result_id=? AND type='imaging' ORDER BY id DESC LIMIT 1", array((int)$item['result_id']));
    }

    public static function toResource($row) {
        $ref = is_array($row) ? $row : array();
        $series = self::seriesList($ref);
        $instances = (int)(isset($ref['instance_count']) ? $ref['instance_count'] : 0);
        if ($instances <= 0) {
            foreach ($series as $s) $instances += (int)$s['instances'];
        }
        $studyUid = isset($ref['study_uid']) ? (string)$ref['study_uid'] : '';
        $modality = isset($ref['modality']) ? (string)$ref['modality'] : '';
        $started = self::isoDateTime(isset($ref['created_at']) ? $ref['created_at'] : '');

        $res = array(
            'resourceType' => 'ImagingStudy',
            'id' => self::resourceId(isset($ref['id']) ? $ref['id'] : 0),
            'status' => $instances > 0 ? 'available' : 'registered',
        );
        $orderNo = !empty($ref['order_no']) ? (string)$ref['order_no'] : '';
        if ($orderNo === '') {
            $order = self::orderOf($ref);
            $orderNo = ($order && !empty($order['order_no'])) ? (string)$order['order_no'] : '';
        }
        $ident = array();
        if ($studyUid !== '') {
            // DICOM StudyInstanceUID：FHIR 规范 system=urn:dicom:uid，value=urn:oid:{uid}
            $ident[] = array('system' => 'urn:dicom:uid', 'value' => 'urn:oid:' . $studyUid);
        }
        if ($orderNo !== '') {
            // 检查号（Accession Number）= 申请单号 orders.order_no
            $ident[] = self::identifier('urn:clinic:identifier:order', $orderNo, 'ACSN', 'Accession ID');
        }
        if (!empty($ref['flow_no'])) {
            // 门诊号（就诊流水号）
            $ident[] = self::identifier('urn:clinic:identifier:visit', (string)$ref['flow_no'], 'VN', 'Visit Number');
        }
        if ($ident) $res['identifier'] = $ident;

        $subj = self::patientRef(isset($ref['patient_no']) ? $ref['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        if (!empty($ref['visit_id'])) {
            $res['encounter'] = array('reference' => 'Encounter/encounter-' . (int)$ref['visit_id']);
        }
        if ($orderNo !== '') {
            $res['basedOn'] = array(array(
                'identifier' => self::identifier('urn:clinic:identifier:order', $orderNo, 'PLAC', 'Placer Order Number'),
            ));
        }
        if ($started !== null) $res['started'] = $started;
        if ($modality !== '') {
            // R4：modality 为 0..* Coding（非 CodeableConcept）
            $res['modality'] = array(self::coding('http://dicom.nema.org/resources/ontology/DCM', $modality, $modality));
        }
        $res['numberOfInstances'] = $instances;
        $seriesOut = array();
        $n = 0;
        foreach ($series as $s) {
            $n++;
            $serUid = $s['uid'] !== '' ? $s['uid'] : ($studyUid !== '' ? $studyUid . '.' . $n : '');
            if ($serUid === '') continue;   // 无有效 UID 时不输出该序列（series.uid 为 1..1）
            $item = array(
                'uid' => $serUid,
                'number' => $n,
                // series.modality 为 1..1 Coding：缺失时按 DICOM 惯例回退 OT
                'modality' => self::coding(
                    'http://dicom.nema.org/resources/ontology/DCM',
                    $s['modality'] !== '' ? $s['modality'] : 'OT',
                    $s['modality'] !== '' ? $s['modality'] : 'OT'
                ),
            );
            // numberOfInstances 为 0..1：未知（<=0）时不虚报 0，直接省略
            if ((int)$s['instances'] > 0) $item['numberOfInstances'] = (int)$s['instances'];
            if ($s['description'] !== '') $item['description'] = $s['description'];
            $seriesOut[] = $item;
        }
        if ($seriesOut) { $res['series'] = $seriesOut; }
        $res['numberOfSeries'] = count($seriesOut);

        // 检查项目（StudyDescription，R4 语义）：开单明细项目名，如「头颅MRI平扫」
        $itemName = self::orderItemName($ref);
        if ($itemName !== '') $res['description'] = $itemName;

        // 影像报告所见/结论（供 Viewer/报告联调）：放 note，不再占用 description
        $report = self::reportOf($ref);
        if ($report) {
            if (!empty($report['report_no'])) {
                // 报告号：独立 system，不授予 ACSN/PLAC/VN 类型，避免被误当作检查号
                $res['identifier'][] = self::identifier('urn:clinic:identifier:report', (string)$report['report_no']);
            }
            $res['note'] = array(array('text' => trim(
                (string)(isset($report['content']) ? $report['content'] : '')
                . (isset($report['clinical_diagnosis']) && $report['clinical_diagnosis'] !== '' ? '｜临床诊断：' . $report['clinical_diagnosis'] : '')
            )));
        }

        // endpoint（可选）：配置了 PACS/WADO/Viewer 地址时输出引用提示
        $ep = self::endpointRef();
        if ($ep) $res['endpoint'] = array($ep);

        return $res;
    }

    /** PACS 端点引用（integration.outbound.pacs.*） */
    private static function endpointRef() {
        $url = '';
        foreach (array('integration.outbound.pacs.wado_url', 'integration.outbound.pacs.qido_url', 'integration.outbound.pacs.viewer_url') as $k) {
            $v = trim((string)setting($k, ''));
            if ($v !== '') { $url = $v; break; }
        }
        if ($url === '') return null;
        return array(
            'type' => 'Endpoint',
            'display' => 'PACS',
            'identifier' => array(array('system' => 'urn:ietf:rfc:3986', 'value' => $url)),
        );
    }

    public static function search($params) {
        // 仅影像类检查属于 ImagingStudy（检验属于 Observation）——关联申请单强约束
        $from = "FROM imaging_refs ir JOIN orders o ON o.id = ir.order_id";
        $where = array("ir.study_uid<>''", "o.order_type='imaging'");
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            $uids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'imagingstudy-') === 0) $v = substr($v, 13);
                if (ctype_digit($v)) $ids[] = (int)$v;
                else $uids[] = $v;
            }
            $ors = array();
            if ($ids) { $ors[] = 'ir.id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
            if ($uids) { $ors[] = 'ir.study_uid IN (' . in_placeholders($uids) . ')'; $args = array_merge($args, $uids); }
            if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'ir.patient_no=?';
            $args[] = $pno;
        }

        if (isset($params['identifier']) && trim((string)$params['identifier']) !== '') {
            $idv = trim((string)$params['identifier']);
            // 支持 urn:dicom:uid|urn:oid:1.2.3 / StudyInstanceUID 裸值
            if (strpos($idv, '|') !== false) { list(, $idv) = explode('|', $idv, 2); }
            $idv = trim($idv);
            if (strpos($idv, 'urn:oid:') === 0) $idv = substr($idv, 8);
            $where[] = 'ir.study_uid=?';
            $args[] = $idv;
        }

        if (isset($params['modality']) && trim((string)$params['modality']) !== '') {
            $mods = array();
            foreach (explode(',', (string)$params['modality']) as $m) {
                $m = trim($m);
                if ($m !== '') $mods[] = $m;
            }
            if ($mods) { $where[] = 'ir.modality IN (' . in_placeholders($mods) . ')'; $args = array_merge($args, $mods); }
        }

        if (isset($params['started']) && trim((string)$params['started']) !== '') {
            $st = trim((string)$params['started']);
            if (strpos($st, ',') !== false) $st = explode(',', $st);
            else $st = array($st);
            foreach ($st as $one) {
                $one = trim($one);
                if ($one === '') continue;
                if (strlen($one) === 10) { $where[] = 'date(ir.created_at)=?'; $args[] = $one; }
                elseif (strlen($one) === 7) { $where[] = 'substr(ir.created_at,1,7)=?'; $args[] = $one; }
            }
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) ' . $from . ' ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'ir.id', 'started' => 'ir.created_at'), 'ir.id DESC');
        $rows = PatientRepository::q(
            'SELECT ir.*, o.order_no, o.category_name ' . $from . ' ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
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
            array('name' => 'identifier', 'type' => 'token'),
            array('name' => 'modality', 'type' => 'token'),
            array('name' => 'started', 'type' => 'date'),
        );
    }
}
