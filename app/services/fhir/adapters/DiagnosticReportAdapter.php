<?php
/**
 * ============================================================
 * services/fhir/adapters/DiagnosticReportAdapter.php — 影像报告适配器
 * ============================================================
 * 映射影像报告（reports，type=imaging）为 FHIR R4 DiagnosticReport，
 * 供 PACS 浏览器「查看影像报告」调阅。
 *
 * 核心元素：
 *   identifier   报告号（urn:clinic:identifier:report）/ 检查号（order，PLAC）
 *   status       final | preliminary | registered | cancelled | amended
 *   category     v2-0074 / RAD（Radiology）
 *   code         检查项目（order_items.item_name）
 *   subject      Patient/patient-{患者号}
 *   encounter    Encounter/encounter-{visit_id}
 *   imagingStudy ImagingStudy/imagingstudy-{imaging_refs.id}
 *   basedOn      申请单号（urn:clinic:identifier:order）
 *   issued       报告出具时间
 *   performer    书写报告医生
 *   conclusion   检查诊断/印象（results.conclusion，回退 reports.content）
 *   presentedForm 报告 PDF（reports.pdf_url 非空时）
 *   extension    检查所见 / 临床（初步）诊断 / 开单医生 / 开单科室
 * 搜索参数：_id、patient/subject、encounter、imagingStudy、category、date、
 *           identifier、status。
 * id 规则：diagnosticreport-{reports.id}。
 * ============================================================ */
class DiagnosticReportAdapter extends FhirAdapter {

    const EXT_FINDINGS   = 'urn:clinic:extension:imaging-findings';      // 检查所见
    const EXT_CLIN_DIAG  = 'urn:clinic:extension:clinical-diagnosis';     // 临床/初步诊断
    const EXT_ORDER_DOC  = 'urn:clinic:extension:ordering-physician';     // 开单医生
    const EXT_ORDER_DEPT = 'urn:clinic:extension:ordering-department';    // 开单科室

    public static function resourceType() { return 'DiagnosticReport'; }

    /** 查询基表（报告 ← 结果 ← 开单明细 ← 申请单） */
    private static function from() {
        return 'FROM reports rp'
            . ' LEFT JOIN results rs ON rs.id = rp.result_id'
            . ' LEFT JOIN order_items oi ON oi.id = rs.order_item_id'
            . ' LEFT JOIN orders o ON o.id = oi.order_id';
    }
    /** 影像引用 id 用子查询，避免 JOIN 造成行放大 */
    private static function refIdExpr() {
        return '(SELECT ir.id FROM imaging_refs ir WHERE ir.order_item_id=rs.order_item_id ORDER BY ir.id DESC LIMIT 1)';
    }
    private static function cols() {
        return 'rp.*, rs.findings AS __findings, rs.conclusion AS __conclusion, oi.item_name AS __item_name,'
            . ' o.order_no AS __order_no, o.dept_name AS __dept_name, ' . self::refIdExpr() . ' AS __ref_id';
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (!ctype_digit($bareId)) return null;
        return PatientRepository::one(
            'SELECT ' . self::cols() . ' ' . self::from() . " WHERE rp.id=? AND rp.type='imaging'",
            array((int)$bareId)
        );
    }

    /** 状态映射 → FHIR DiagnosticReportStatus */
    public static function mapStatus($status) {
        $map = array(
            'done' => 'final', 'final' => 'final',
            'pending' => 'registered', 'registered' => 'registered', 'ordered' => 'registered',
            'draft' => 'preliminary', 'preliminary' => 'preliminary',
            'amended' => 'amended', 'corrected' => 'corrected',
            'withdrawn' => 'cancelled', 'cancelled' => 'cancelled',
        );
        $s = strtolower(trim((string)$status));
        return isset($map[$s]) ? $map[$s] : 'preliminary';
    }

    public static function toResource($row) {
        $r = is_array($row) ? $row : array();
        $res = array(
            'resourceType' => 'DiagnosticReport',
            'id' => 'diagnosticreport-' . (int)(isset($r['id']) ? $r['id'] : 0),
            'status' => self::mapStatus(isset($r['status']) ? $r['status'] : ''),
        );

        $ident = array();
        if (!empty($r['report_no'])) {
            $ident[] = self::identifier('urn:clinic:identifier:report', (string)$r['report_no']);   // 报告号
        }
        $orderNo = !empty($r['__order_no']) ? (string)$r['__order_no'] : '';
        if ($orderNo !== '') {
            $ident[] = self::identifier('urn:clinic:identifier:order', $orderNo, 'PLAC', 'Placer Order Number');  // 检查号
        }
        if ($ident) $res['identifier'] = $ident;

        $res['category'] = array(self::codeable('http://terminology.hl7.org/CodeSystem/v2-0074', 'RAD', 'Radiology'));
        $itemName = !empty($r['__item_name']) ? (string)$r['__item_name'] : (string)(isset($r['category_name']) ? $r['category_name'] : '');
        // code 为 1..1：始终提供（无项目名时给通用文本）
        $res['code'] = array('text' => ($itemName !== '' ? $itemName : '影像报告'));

        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        if (!empty($r['visit_id'])) {
            $res['encounter'] = array('reference' => 'Encounter/encounter-' . (int)$r['visit_id']);
        }
        if (!empty($r['__ref_id'])) {
            $res['imagingStudy'] = array(array('reference' => 'ImagingStudy/imagingstudy-' . (int)$r['__ref_id']));
        }
        if ($orderNo !== '') {
            $res['basedOn'] = array(array('identifier' => self::identifier('urn:clinic:identifier:order', $orderNo, 'PLAC', 'Placer Order Number')));
        }

        $eff = self::isoDateTime(!empty($r['applied_at']) ? $r['applied_at'] : (isset($r['registered_at']) ? $r['registered_at'] : ''));
        if ($eff !== null) $res['effectiveDateTime'] = $eff;
        $issued = self::isoDateTime(!empty($r['registered_at']) ? $r['registered_at'] : (isset($r['created_at']) ? $r['created_at'] : ''));
        if ($issued !== null) $res['issued'] = $issued;

        $doctor = trim((string)(isset($r['doctor_name']) ? $r['doctor_name'] : ''));
        if ($doctor !== '') {
            $res['performer'] = array(array('display' => $doctor));            // 书写报告医生
            $res['resultsInterpreter'] = array(array('display' => $doctor));
        }

        // 检查诊断（结论）：results.conclusion 优先，回退报告正文
        $conc = trim((string)(!empty($r['__conclusion']) ? $r['__conclusion'] : (isset($r['content']) ? $r['content'] : '')));
        if ($conc !== '') $res['conclusion'] = $conc;

        if (!empty($r['pdf_url'])) {
            $res['presentedForm'] = array(array(
                'contentType' => 'application/pdf',
                'url' => (string)$r['pdf_url'],
                'title' => '影像报告',
            ));
        }

        // 标准扩展：检查所见 / 临床诊断 / 开单医生 / 开单科室（核心元素无对应字段）
        $ext = array();
        $findings = trim((string)(isset($r['__findings']) ? $r['__findings'] : ''));
        if ($findings !== '') $ext[] = array('url' => self::EXT_FINDINGS, 'valueString' => $findings);
        $clin = trim((string)(isset($r['clinical_diagnosis']) ? $r['clinical_diagnosis'] : ''));
        if ($clin !== '') $ext[] = array('url' => self::EXT_CLIN_DIAG, 'valueString' => $clin);
        $applyDoc = trim((string)(isset($r['apply_doctor_name']) ? $r['apply_doctor_name'] : ''));
        if ($applyDoc !== '') $ext[] = array('url' => self::EXT_ORDER_DOC, 'valueString' => $applyDoc);
        $applyDept = trim((string)(!empty($r['apply_dept_name']) ? $r['apply_dept_name'] : (isset($r['__dept_name']) ? $r['__dept_name'] : '')));
        if ($applyDept !== '') $ext[] = array('url' => self::EXT_ORDER_DEPT, 'valueString' => $applyDept);
        if ($ext) $res['extension'] = $ext;

        return $res;
    }

    public static function search($params) {
        $where = array("rp.type='imaging'");
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'diagnosticreport-') === 0) $v = substr($v, 17);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'rp.id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }

        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'rp.patient_no=?';
            $args[] = $pno;
        }

        if (isset($params['encounter']) && trim((string)$params['encounter']) !== '') {
            $enc = trim((string)$params['encounter']);
            if (strpos($enc, 'Encounter/') === 0) $enc = substr($enc, 10);
            if (strpos($enc, 'encounter-') === 0) $enc = substr($enc, 10);
            if (ctype_digit($enc)) { $where[] = 'rp.visit_id=?'; $args[] = (int)$enc; }
        }

        if (isset($params['imagingStudy']) && trim((string)$params['imagingStudy']) !== '') {
            $is = trim((string)$params['imagingStudy']);
            if (strpos($is, 'ImagingStudy/') === 0) $is = substr($is, 13);
            if (strpos($is, 'imagingstudy-') === 0) $is = substr($is, 13);
            if (ctype_digit($is)) { $where[] = self::refIdExpr() . '=?'; $args[] = (int)$is; }
        }

        if (isset($params['identifier']) && trim((string)$params['identifier']) !== '') {
            $idv = trim((string)$params['identifier']);
            if (strpos($idv, '|') !== false) { list(, $idv) = explode('|', $idv, 2); }
            $where[] = '(rp.report_no=? OR o.order_no=?)';
            $args[] = trim($idv);
            $args[] = trim($idv);
        }

        if (isset($params['category']) && trim((string)$params['category']) !== '') {
            // 仅支持 RAD（Radiology）；其它分类返回空集
            $cat = strtolower(trim((string)$params['category']));
            if ($cat !== 'rad' && $cat !== 'radiology') $where[] = '1=0';
        }

        if (isset($params['date']) && trim((string)$params['date']) !== '') {
            $d = trim((string)$params['date']);
            if (strlen($d) === 10) { $where[] = 'date(rp.registered_at)=?'; $args[] = $d; }
            elseif (strlen($d) === 7) { $where[] = 'substr(rp.registered_at,1,7)=?'; $args[] = $d; }
        }

        if (isset($params['status']) && trim((string)$params['status']) !== '') {
            $st = strtolower(trim((string)$params['status']));
            $rev = array(
                'final' => array('done', 'final'),
                'registered' => array('pending', 'registered', 'ordered'),
                'preliminary' => array('draft', 'preliminary'),
                'cancelled' => array('withdrawn', 'cancelled'),
            );
            if (isset($rev[$st])) {
                $where[] = 'rp.status IN (' . in_placeholders($rev[$st]) . ')';
                $args = array_merge($args, $rev[$st]);
            }
        }

        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) ' . self::from() . ' ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'rp.id', 'date' => 'rp.registered_at', 'issued' => 'rp.registered_at'), 'rp.id DESC');
        $rows = PatientRepository::q(
            'SELECT ' . self::cols() . ' ' . self::from() . ' ' . $sqlWhere
            . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
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
            array('name' => 'imagingStudy', 'type' => 'reference'),
            array('name' => 'identifier', 'type' => 'token'),
            array('name' => 'category', 'type' => 'token'),
            array('name' => 'date', 'type' => 'date'),
            array('name' => 'status', 'type' => 'token'),
        );
    }
}
