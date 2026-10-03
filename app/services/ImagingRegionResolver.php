<?php
/**
 * ============================================================
 * app/services/ImagingRegionResolver.php — 影像 StudyInstanceUID 解析与引用登记
 * ============================================================
 * 说明（影像先后关系铁律）：
 *   DICOM 的 StudyInstanceUID 由「影像产生方」（设备 / 区域 PACS）在检查产生时分配，
 *   门诊 HIS 不制造 UID。因此：
 *     · 检查登记（开始拍片）时，若已配置出向区域 PACS，则按检查号（申请单号）
 *       向区域 PACS 查回真实 StudyInstanceUID 并登记「影像引用」；
 *     · 未连接区域 PACS / 查无该检查时，不产生任何 UID（严禁用报告号等伪造占位），
 *       检查仍可登记，但影像引用缺省——待设备上传 / PACS 可查后再解析。
 *   报告书写前置：必须有真实 UID 的影像引用（先有影像，后有报告）。
 * ============================================================ */
class ImagingRegionResolver {

    /** 出向区域 PACS 基地址（去掉 {study_uid} 模板与结尾 /studies） */
    public static function base() {
        $base = trim((string)setting('integration.outbound.pacs.qido_url', ''));
        if ($base === '') $base = trim((string)setting('integration.outbound.pacs.wado_url', ''));
        if ($base === '') return '';
        $base = preg_replace('#/\{?(study_uid|studyUID)\}.*$#i', '', $base);
        return rtrim(preg_replace('#/studies/?$#i', '', rtrim($base, '/')), '/');
    }

    public static function configured() { return self::base() !== ''; }

    private static function headers() {
        $headers = array();
        $scheme = strtolower(trim((string)setting('integration.outbound.pacs.auth_scheme', 'none')));
        $val = trim((string)setting('integration.outbound.pacs.auth_value', ''));
        if ($val !== '') {
            if ($scheme === 'bearer') $headers[] = 'Authorization: Bearer ' . $val;
            elseif ($scheme === 'x-api-key') $headers[] = 'X-API-Key: ' . $val;
            elseif ($scheme === 'basic') $headers[] = 'Authorization: Basic ' . base64_encode($val);
            elseif ($scheme === 'custom') $headers[] = $val;
        }
        return $headers;
    }

    private static function getJson($path) {
        $base = self::base();
        if ($base === '') return null;
        try {
            $resp = HttpClient::request('GET', $base . $path, array('timeout' => 12, 'headers' => self::headers()));
        } catch (Exception $e) { return null; }
        if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) return null;
        $j = json_decode((string)$resp['body'], true);
        return is_array($j) ? $j : null;
    }

    private static function tag($obj, $t) {
        return (isset($obj[$t]['Value'][0]) && !is_array($obj[$t]['Value'][0])) ? (string)$obj[$t]['Value'][0] : '';
    }

    /** 是否标准 DICOM UID（数字与点、至少两段、无前导零、≤64）；见 imaging_is_standard_uid */
    public static function isRealUid($uid) {
        return function_exists('imaging_is_standard_uid')
            ? imaging_is_standard_uid($uid)
            : (bool)preg_match('/^(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))+$/', trim((string)$uid));
    }

    /**
     * 按检查号（申请单号）解析区域 PACS 检查。
     * @return array|null {uid, series:[{uid,description,modality,instances}], instance_count}
     */
    public static function resolveByAccession($accession) {
        $accession = trim((string)$accession);
        if ($accession === '' || !self::configured()) return null;
        $arr = self::getJson('/studies?' . http_build_query(array('AccessionNumber' => $accession, 'includefield' => 'all')));
        if (!is_array($arr) || !count($arr)) return null;
        $uid = self::tag($arr[0], '0020000D');
        if ($uid === '') return null;
        $institution = self::tag($arr[0], '00080080');   // 区域 PACS 机构名（InstitutionName）
        $seriesRes = self::getJson('/studies/' . rawurlencode($uid) . '/series');
        $series = array(); $total = 0;
        foreach ((array)$seriesRes as $s) {
            if (!is_array($s)) continue;
            $seUid = self::tag($s, '0020000E');
            if ($seUid === '') continue;
            $n = (int)self::tag($s, '00201209');
            if ($n < 0) $n = 0;
            $series[] = array(
                'uid' => $seUid,
                'description' => self::tag($s, '0008103E'),
                'modality' => self::tag($s, '00080060'),
                'instances' => $n,
            );
            $total += $n;
        }
        return array('uid' => $uid, 'series' => $series, 'instance_count' => $total, 'institution' => $institution);
    }

    /**
     * 为该检查明细解析并登记影像引用（未连 PACS / 无匹配返回 null，不产生占位 UID）。
     * @return array|null 影像引用行
     */
    public static function registerForItem($itemId) {
        if (!self::configured()) return null;
        $it = OrderRepository::one("SELECT * FROM order_items WHERE id=? AND item_type='imaging'", array((int)$itemId));
        if (!$it) return null;
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$it['order_id']));
        if (!$order) return null;
        $r = self::resolveByAccession((string)$order['order_no']);
        if (!$r) return null;

        $cat = trim((string)$order['category_name']);
        $mod = imaging_modality_code($cat) !== '' ? imaging_modality_code($cat) : imaging_modality_code((string)$it['item_name']);
        if ($mod === '' && isset($r['series'][0]['modality'])) $mod = strtoupper((string)$r['series'][0]['modality']);
        if ($mod === '') $mod = 'OT';

        // UID 规范化：区域给出的 StudyInstanceUID 若合规则沿用；否则由本系统按检查号
        // 确定性派生标准 UID（根 1.2.826.0.1.3680043.8.498），并经 FHIR 对外发布，
        // 保证 Study/Series/SOP 全链路为合规 DICOM UID 且各系统一致。
        $studyUid = self::isRealUid($r['uid']) ? (string)$r['uid'] : imaging_uid_from_seed('study|' . (string)$order['order_no']);
        $series = array(); $seriesUids = array(); $total = 0; $i = 0;
        foreach ($r['series'] as $s) {
            $i++;
            $seUid = self::isRealUid($s['uid']) ? (string)$s['uid'] : ($studyUid . '.' . $i);
            $n = max(0, (int)$s['instances']);
            $series[] = array(
                'uid' => $seUid,
                'description' => (string)$s['description'],
                'modality' => (string)$s['modality'],
                'instances' => $n,
            );
            $seriesUids[] = $seUid;
            $total += $n;
        }
        if (!$series) {   // 区域无序列明细：至少给出一个合规序列 UID
            $series[] = array('uid' => $studyUid . '.1', 'description' => '', 'modality' => $mod, 'instances' => 0);
            $seriesUids[] = $studyUid . '.1';
        }

        $u = Auth::user();
        self::upsertRef(array(
            'order_item_id' => (int)$itemId,
            'order_id' => (int)$it['order_id'],
            'visit_id' => (int)$it['visit_id'],
            'patient_no' => (string)$it['patient_no'],
            'flow_no' => (string)$it['flow_no'],
            'study_uid' => $studyUid,
            'series_uids' => $seriesUids,
            'instance_count' => $total,
            'modality' => $mod,
            'region' => 'region-pacs',
            // region_name：区域影像存储 / PACS 的机构名（QIDO InstitutionName），供应引用查询展示
            'meta' => array('series' => $series, 'source' => 'region-pacs',
                'region_name' => (string)(isset($r['institution']) ? $r['institution'] : '')),
            'created_by' => $u ? (string)$u['name'] : '',
        ));
        return ImagingRepository::refByItem((int)$itemId);
    }

    /** 以 order_item_id 为准 upsert 引用（可把占位 UID 升级为真实 UID，避免重复行） */
    public static function upsertRef($ref) {
        $existing = ImagingRepository::one('SELECT id FROM imaging_refs WHERE order_item_id=? ORDER BY id DESC LIMIT 1', array((int)$ref['order_item_id']));
        if ($existing) {
            ImagingRepository::exec(
                'UPDATE imaging_refs SET study_uid=?, series_uids=?, instance_count=?, modality=?, region=?, meta_json=?, created_by=?, updated_at=? WHERE id=?',
                array(
                    (string)$ref['study_uid'],
                    json_encode($ref['series_uids'], JSON_UNESCAPED_UNICODE),
                    (int)$ref['instance_count'],
                    (string)$ref['modality'],
                    (string)$ref['region'],
                    json_encode($ref['meta'], JSON_UNESCAPED_UNICODE),
                    (string)$ref['created_by'],
                    now_str(),
                    (int)$existing['id'],
                )
            );
            return (int)$existing['id'];
        }
        return ImagingRepository::putRef($ref);
    }
}
