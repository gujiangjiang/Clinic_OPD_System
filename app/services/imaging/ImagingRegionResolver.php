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

    /** 区域 PACS 中是否存在该 StudyInstanceUID（轻量 QIDO 探针） */
    public static function studyExists($uid) {
        $uid = trim((string)$uid);
        if ($uid === '' || !self::configured()) return false;
        $arr = self::getJson('/studies?' . http_build_query(array('StudyInstanceUID' => $uid, 'includefield' => 'StudyInstanceUID')));
        if (!is_array($arr) || !count($arr)) return false;
        return self::tag($arr[0], '0020000D') !== '';
    }

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

    /** 取数值型标签（DS/IS/US），无值返回 0 */
    private static function num($obj, $t) {
        $s = self::tag($obj, $t);
        if ($s === '') return 0;
        $f = (float)$s;
        return is_finite($f) ? $f : 0;
    }

    /**
     * 取区域 PACS 某序列首个实例的像素 / 几何元数据（仅取 1 条，轻量）。
     * 用于把模拟器 / 区域 PACS 的窗宽窗位、像素间距、矩阵等透传给客户端，
     * 避免「只存引用」导致检查对象缺失这些字段（客户端不必再从 DICOM 文件推断）。
     */
    private static function firstInstanceMeta($studyUid, $seUid) {
        if ($seUid === '') return array();
        $res = self::getJson('/studies/' . rawurlencode($studyUid) . '/series/' . rawurlencode($seUid) . '/instances', array('limit' => 1));
        $o = (is_array($res) && isset($res[0]) && is_array($res[0])) ? $res[0] : array();
        if (!$o) return array();
        return array(
            'frames_per_instance' => max(1, (int)self::num($o, '00280008')),
            'rows' => (int)self::num($o, '00280010'),
            'columns' => (int)self::num($o, '00280011'),
            'bits_allocated' => (int)self::num($o, '00280100'),
            'bits_stored' => (int)self::num($o, '00280101'),
            'pixel_representation' => (int)self::num($o, '00280103'),
            'window_center' => self::num($o, '00281050'),
            'window_width' => self::num($o, '00281051'),
            'rescale_intercept' => self::num($o, '00281052'),
            'rescale_slope' => self::num($o, '00281053'),
            'pixel_spacing' => self::num($o, '00280030'),
            'slice_thickness' => self::num($o, '00180050'),
            'orientation' => self::tag($o, '00200037'),
            'sop_class_uid' => self::tag($o, '00080016'),
        );
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
        $station = self::tag($arr[0], '00081010');       // 设备名（StationName）
        $seriesRes = self::getJson('/studies/' . rawurlencode($uid) . '/series');
        $series = array(); $total = 0;
        foreach ((array)$seriesRes as $s) {
            if (!is_array($s)) continue;
            $seUid = self::tag($s, '0020000E');
            if ($seUid === '') continue;
            $n = (int)self::tag($s, '00201209');
            if ($n < 0) $n = 0;
            // 透传像素 / 几何元数据（窗宽窗位、像素间距、矩阵、层厚、方位等）
            $pix = ($n > 0) ? self::firstInstanceMeta($uid, $seUid) : array();
            $series[] = array_merge(array(
                'uid' => $seUid,
                'description' => self::tag($s, '0008103E'),
                'modality' => self::tag($s, '00080060'),
                'instances' => $n,
            ), $pix);
            $total += $n;
        }
        return array('uid' => $uid, 'series' => $series, 'instance_count' => $total,
            'institution' => $institution, 'station' => $station);
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
            // 保留区域透传的像素 / 几何元数据，仅规范化 UID 与实例数
            $series[] = array_merge($s, array('uid' => $seUid, 'instances' => $n));
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
            // region_name：区域影像存储 / PACS 的机构名（QIDO InstitutionName）；station_name：设备名（StationName）
            'meta' => array('series' => $series, 'source' => 'region-pacs',
                'region_name' => (string)(isset($r['institution']) ? $r['institution'] : ''),
                'station_name' => (string)(isset($r['station']) ? $r['station'] : '')),
            'created_by' => $u ? (string)$u['name'] : '',
        ));
        return ImagingRepository::refByItem((int)$itemId);
    }

    /** 以 order_item_id 为准 upsert 引用（可把占位 UID 升级为真实 UID，避免重复行） */
    public static function upsertRef($ref) {
        $existing = ImagingRepository::one('SELECT id, created_by FROM imaging_refs WHERE order_item_id=? ORDER BY id DESC LIMIT 1', array((int)$ref['order_item_id']));
        if ($existing) {
            // 登记人保留：无当前登录用户（如批处理）时沿用原值，避免覆盖为空
            $createdBy = (string)$ref['created_by'] !== '' ? (string)$ref['created_by'] : (string)$existing['created_by'];
            ImagingRepository::exec(
                'UPDATE imaging_refs SET study_uid=?, series_uids=?, instance_count=?, modality=?, region=?, meta_json=?, created_by=?, updated_at=? WHERE id=?',
                array(
                    (string)$ref['study_uid'],
                    json_encode($ref['series_uids'], JSON_UNESCAPED_UNICODE),
                    (int)$ref['instance_count'],
                    (string)$ref['modality'],
                    (string)$ref['region'],
                    json_encode($ref['meta'], JSON_UNESCAPED_UNICODE),
                    $createdBy,
                    now_str(),
                    (int)$existing['id'],
                )
            );
            return (int)$existing['id'];
        }
        return ImagingRepository::putRef($ref);
    }
}
