<?php
/**
 * ============================================================
 * services/his/SoapHisDriver.php — HIS 出向驱动：SOAP WebService/XML 适配器
 * ============================================================
 * 说明：将挂号/结算/发药业务以 SOAP 1.1 Envelope（text/xml）POST 到
 * WebService 网关（WSDL 地址或接口地址）。业务数据复用 RestHisDriver
 * 的统一装载逻辑（buildData），仅封装协议层差异。
 * 携带 AppID + 时间戳 + HMAC-SHA256 签名头，兼容院内 SOAP 网关惯例。
 * ============================================================ */
class SoapHisDriver implements HisDriverInterface {

    public function name() {
        return 'SOAP/XML';
    }

    /**
     * @param string $businessType
     * @param array  $payload
     * @return array
     */
    public function send($businessType, $payload) {
        $gateway = trim((string)setting('integration.outbound.his.gateway_url', ''));
        $appId = trim((string)setting('integration.outbound.his.app_id', ''));
        $secret = trim((string)setting('integration.outbound.his.app_secret', ''));
        $timeout = (int)setting('integration.outbound.his.timeout', '10');
        if ($timeout <= 0) $timeout = 10;
        try {
            if ($gateway === '') {
                throw new Exception('HIS 网关地址未配置（integration.outbound.his.gateway_url）');
            }
            $data = RestHisDriver::buildData($businessType, $payload);
            if ($data === null) {
                throw new Exception('业务数据不存在（' . $businessType . '）');
            }
            $hospitalCode = (string)setting('integration.outbound.his.hospital_code', '');
            $body = $this->soapEnvelope($businessType, $hospitalCode, $data);
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(8));
            $sign = hash_hmac('sha256', $appId . $ts . $nonce . $body, $secret);
            $resp = HttpClient::request('POST', $gateway, array(
                'body' => $body,
                'timeout' => $timeout,
                'headers' => array(
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "urn:his#' . $businessType . '"',
                    'X-App-Id: ' . $appId,
                    'X-Timestamp: ' . $ts,
                    'X-Nonce: ' . $nonce,
                    'X-Sign: ' . $sign,
                ),
            ));
            $httpOk = $resp['status'] >= 200 && $resp['status'] < 300;
            $biz = self::parseSoapResult($resp['body']);
            $ok = $httpOk && $biz['ok'];
            $err = '';
            if (!$httpOk) $err = 'HIS 网关 HTTP ' . $resp['status'];
            elseif (!$biz['ok']) $err = $biz['msg'] !== '' ? $biz['msg'] : 'SOAP 返回故障';
            return array('ok' => $ok, 'resp' => $resp['body'], 'error' => $err);
        } catch (Exception $ex) {
            return array('ok' => false, 'resp' => '', 'error' => $ex->getMessage());
        }
    }

    /**
     * 解析 SOAP 返回：命名空间无关地识别 Fault（soap:Fault / SOAP-ENV:Fault），
     * 并尝试读取常见业务码节点（resultCode/code/status）。非 XML 时按成功回退。
     * @return array { ok:bool, msg:string }
     */
    public static function parseSoapResult($xml) {
        $xml = trim((string)$xml);
        if ($xml === '') return array('ok' => true, 'msg' => '');
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) return array('ok' => true, 'msg' => '');
        $xp = new DOMXPath($doc);
        $faults = $xp->query('//*[local-name()="Fault"]');
        if ($faults->length > 0) {
            $msg = '';
            foreach (array('faultstring', 'Reason', 'faultcode', 'detail') as $n) {
                $nodes = $xp->query('.//*[local-name()="' . $n . '"]', $faults->item(0));
                if ($nodes->length > 0) { $msg = trim($nodes->item(0)->textContent); if ($msg !== '') break; }
            }
            return array('ok' => false, 'msg' => $msg !== '' ? $msg : 'SOAP Fault');
        }
        foreach (array('resultCode', 'code', 'status', 'retCode') as $n) {
            $nodes = $xp->query('//*[local-name()="' . $n . '"]');
            if ($nodes->length > 0) {
                $cs = strtolower(trim($nodes->item(0)->textContent));
                $ok = in_array($cs, array('0', '200', 'success', 'ok', 'true', 's', '0000', '00000'), true);
                return array('ok' => $ok, 'msg' => $ok ? '' : ('SOAP 业务失败（code=' . $cs . '）'));
            }
        }
        return array('ok' => true, 'msg' => '');
    }

    /** SOAP 1.1 Envelope 组装（data 数组递归转 XML） */
    private function soapEnvelope($businessType, $hospitalCode, $data) {
        $appId = trim((string)setting('integration.outbound.his.app_id', ''));
        $xmlData = self::toXml($data);
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Header>' .
            '<HisAuth xmlns="urn:his"><AppId>' . self::esc($appId) . '</AppId><HospitalCode>' . self::esc($hospitalCode) . '</HospitalCode></HisAuth>' .
            '</soap:Header>' .
            '<soap:Body><' . $businessType . ' xmlns="urn:his">' . $xmlData . '</' . $businessType . '></soap:Body>' .
            '</soap:Envelope>';
    }

    /** 递归数组 → XML 节点 */
    private static function toXml($data, $name = 'data') {
        $xml = '';
        foreach ((array)$data as $k => $v) {
            $tag = is_numeric($k) ? $name . '_' . ((int)$k + 1) : (string)$k;
            $tag = preg_replace('/[^a-zA-Z0-9_]/', '_', $tag) ?: 'item';
            if (is_array($v)) {
                $xml .= '<' . $tag . '>' . self::toXml($v, $tag) . '</' . $tag . '>';
            } else {
                $xml .= '<' . $tag . '>' . self::esc($v) . '</' . $tag . '>';
            }
        }
        return $xml;
    }

    /** XML 转义 */
    private static function esc($v) {
        return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
    }

    /** 工厂：SOAP 驱动 */
    public static function make() {
        return new SoapHisDriver();
    }
}