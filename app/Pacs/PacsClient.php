<?php
/**
 * ============================================================
 * app/Pacs/PacsClient.php — 外部 DICOM / PACS 接口客户端
 * ============================================================
 * 依据管理设置中的查询模式与接口地址，向远程 PACS / DICOMWeb 网关
 * 发起检索（search / study / ping），所有患者、医院等数据均来自该接口。
 * 未配置远程接口（Demo 模式）时由内置模拟服务 PvDemoPacs 提供数据。
 *
 * 远程接口约定（JSON）：
 *   GET {endpoint}?action=search&q=关键词&key=APIKEY
 *       → {code:200, msg, data:{list:[{study_uid,patient_id,name,gender,age,
 *            outpatient_no,accession_no,modality,description,study_date,
 *            institution,station_name,series_count}, ...]}}
 *   GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
 *       → {code:200, msg, data:{patient:{...}, study:{...}, series:[...]}}
 *   GET {endpoint}?action=ping&key=APIKEY
 *       → {code:200, data:{name,version}}
 * ============================================================ */
class PvPacsClient {

    public static function mode() {
        $m = PvSettings::get('pacs_query_mode', 'Demo');
        return $m === 'Remote' ? 'Remote' : 'Demo';
    }

    public static function isRemote() {
        return self::mode() === 'Remote' && trim((string)PvSettings::get('pacs_endpoint', '')) !== '';
    }

    /** 检索检查列表 */
    public static function search($keyword) {
        if (!self::isRemote()) return PvDemoPacs::search($keyword);
        $res = self::request('search', array('q' => (string)$keyword));
        $list = isset($res['data']['list']) && is_array($res['data']['list']) ? $res['data']['list'] : array();
        return $list;
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study($uid) {
        if (!self::isRemote()) return PvDemoPacs::study($uid);
        $res = self::request('study', array('uid' => (string)$uid));
        $d = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        if (!isset($d['patient']) || !isset($d['study'])) {
            throw new RuntimeException('PACS 接口返回的数据格式不正确');
        }
        if (!isset($d['series']) || !is_array($d['series'])) $d['series'] = array();
        return $d;
    }

    /** 接口连通性测试 */
    public static function ping() {
        if (!self::isRemote()) {
            $p = PvDemoPacs::ping();
            $p['endpoint'] = 'builtin://demo';
            return $p;
        }
        $res = self::request('ping', array());
        $p = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        $p['endpoint'] = PvSettings::get('pacs_endpoint', '');
        return $p;
    }

    /* ---------- HTTP 请求 ---------- */
    private static function request($action, array $params) {
        $endpoint = trim((string)PvSettings::get('pacs_endpoint', ''));
        if ($endpoint === '') throw new RuntimeException('未配置 PACS 接口地址');
        $params['action'] = $action;
        $key = trim((string)PvSettings::get('pacs_api_key', ''));
        if ($key !== '') $params['key'] = $key;
        $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($params);
        $timeout = max(1, (int)PvSettings::get('pacs_timeout', '5'));

        $raw = self::httpGet($url, $timeout);
        if ($raw === false || $raw === '') throw new RuntimeException('无法连接 PACS 接口：' . $url);
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new RuntimeException('PACS 接口返回非 JSON 数据');
        if (!isset($j['code']) || (int)$j['code'] !== 200) {
            throw new RuntimeException('PACS 接口返回错误：' . (isset($j['msg']) ? $j['msg'] : '未知错误'));
        }
        return $j;
    }

    private static function httpGet($url, $timeout) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => array('Accept: application/json'),
            ));
            $raw = curl_exec($ch);
            curl_close($ch);
            return $raw;
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        )));
        return @file_get_contents($url, false, $ctx);
    }
}
