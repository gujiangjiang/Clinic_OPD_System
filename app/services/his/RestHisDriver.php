<?php
/**
 * ============================================================
 * services/his/RestHisDriver.php — HIS 出向驱动：REST/JSON 适配器
 * ============================================================
 * 说明：将挂号/结算/发药业务以 JSON POST 上报 HIS 网关。
 * 请求头携带 AppID + 时间戳 + 随机串 + HMAC-SHA256 签名（防篡改/防重放）。
 * 网关地址 gateway_url 为 REST 基础地址，业务路径按类型拼接。
 * ============================================================ */
class RestHisDriver implements HisDriverInterface {

    public function name() {
        return 'REST/JSON';
    }

    /**
     * @param string $businessType
     * @param array  $payload
     * @return array
     */
    public function send($businessType, $payload) {
        $gateway = rtrim(trim((string)setting('integration.outbound.his.gateway_url', '')), '/');
        $appId = trim((string)setting('integration.outbound.his.app_id', ''));
        $secret = trim((string)setting('integration.outbound.his.app_secret', ''));
        $timeout = (int)setting('integration.outbound.his.timeout', '10');
        if ($timeout <= 0) $timeout = 10;
        try {
            if ($gateway === '') {
                throw new Exception('HIS 网关地址未配置（integration.outbound.his.gateway_url）');
            }
            $data = self::buildData($businessType, $payload);
            if ($data === null) {
                throw new Exception('业务数据不存在（' . $businessType . '）');
            }
            $body = json_encode(array(
                'business_type' => $businessType,
                'hospital_code' => (string)setting('integration.outbound.his.hospital_code', ''),
                'data' => $data,
            ), JSON_UNESCAPED_UNICODE);
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(8));
            $sign = hash_hmac('sha256', $appId . $ts . $nonce . $body, $secret);
            $resp = HttpClient::request('POST', $gateway . self::pathFor($businessType), array(
                'body' => $body,
                'timeout' => $timeout,
                'headers' => array(
                    'Content-Type: application/json; charset=utf-8',
                    'X-App-Id: ' . $appId,
                    'X-Timestamp: ' . $ts,
                    'X-Nonce: ' . $nonce,
                    'X-Sign: ' . $sign,
                ),
            ));
            // HTTP 2xx 仅代表传输层成功；还需解析网关业务码，避免业务失败被记为成功
            $httpOk = $resp['status'] >= 200 && $resp['status'] < 300;
            $biz = self::parseBusinessResult($resp['body']);
            $ok = $httpOk && $biz['ok'];
            $err = '';
            if (!$httpOk) $err = 'HIS 网关 HTTP ' . $resp['status'];
            elseif (!$biz['ok']) $err = $biz['msg'] !== '' ? $biz['msg'] : 'HIS 网关返回业务失败';
            return array('ok' => $ok, 'resp' => $resp['body'], 'error' => $err);
        } catch (Exception $ex) {
            return array('ok' => false, 'resp' => '', 'error' => $ex->getMessage());
        }
    }

    /**
     * 解析 HIS 网关业务返回：兼容 {code}/{resultCode}/{status}/{retCode} 与
     * {success}/{ok} 布尔约定；无法识别时按成功处理（回退 HTTP 状态）。
     * @return array { ok:bool, msg:string }
     */
    public static function parseBusinessResult($body) {
        $body = (string)$body;
        if ($body === '') return array('ok' => true, 'msg' => '');
        $j = json_decode($body, true);
        if (!is_array($j)) return array('ok' => true, 'msg' => '');
        $msg = '';
        foreach (array('msg', 'message', 'resultMsg', 'retMsg', 'error_description', 'error') as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') { $msg = $j[$k]; break; }
        }
        foreach (array('code', 'resultCode', 'status', 'retCode', 'errCode') as $k) {
            if (isset($j[$k])) {
                $cs = strtolower(trim((string)$j[$k]));
                $ok = in_array($cs, array('0', '200', 'success', 'ok', 'true', 's', '0000', '00000'), true);
                return array('ok' => $ok, 'msg' => $msg);
            }
        }
        if (isset($j['success'])) return array('ok' => (bool)$j['success'], 'msg' => $msg);
        if (isset($j['ok'])) return array('ok' => (bool)$j['ok'], 'msg' => $msg);
        return array('ok' => true, 'msg' => $msg);
    }

    /** 业务路径映射（支持按业务类型配置覆盖，未配置用默认） */
    private static function pathFor($businessType) {
        $p = trim((string)integration_cfg('outbound.his.path_' . $businessType, '', ''));
        if ($p !== '') return $p;
        switch ($businessType) {
            case 'his_registration': return '/registration';
            case 'his_settlement':   return '/settlement';
            case 'his_prescription': return '/prescription';
            default:                 return '/sync';
        }
    }

    /**
     * 按业务类型装载上报数据（实时查询主库，保证 worker 后台执行时数据最新）
     * @param string $businessType
     * @param array  $payload
     * @return array|null
     */
    public static function buildData($businessType, $payload) {
        switch ($businessType) {
            case 'his_registration':
                $vid = (int)self::pv($payload, 'visit_id');
                if ($vid <= 0) return null;
                $v = PatientRepository::one('SELECT * FROM registrations WHERE id=?', array($vid));
                if (!$v) return null;
                return array(
                    'visit_id' => (int)$v['id'],
                    'flow_no' => $v['flow_no'],
                    'patient_no' => $v['patient_no'],
                    'visit_seq' => (int)$v['visit_seq'],
                    'dept_id' => (int)$v['first_dept_id'],
                    'dept_name' => $v['first_dept_name'],
                    'session' => $v['session'],
                    'fee' => (float)$v['fee'],
                    'status' => $v['status'],
                    'registered_at' => $v['registered_at'],
                    'cashier_name' => $v['cashier_name'],
                );
            case 'his_settlement':
                $pid = (int)self::pv($payload, 'payment_id');
                if ($pid <= 0) return null;
                $p = CashierRepository::one('SELECT * FROM payments WHERE id=?', array($pid));
                if (!$p) return null;
                return array(
                    'payment_id' => (int)$p['id'],
                    'payment_no' => $p['payment_no'],
                    'visit_id' => (int)$p['visit_id'],
                    'order_id' => (int)$p['order_id'],
                    'patient_no' => $p['patient_no'],
                    'flow_no' => $p['flow_no'],
                    'kind' => $p['kind'],
                    'total' => (float)$p['total_amount'],
                    'method' => $p['method'],
                    'created_at' => $p['created_at'],
                );
            case 'his_prescription':
                $oid = (int)self::pv($payload, 'order_id');
                if ($oid <= 0) return null;
                $o = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($oid));
                if (!$o) return null;
                $items = OrderRepository::q("SELECT item_id, item_name, price, quantity, single_dose, frequency, route, status FROM order_items WHERE order_id=? ORDER BY id", array($oid));
                return array(
                    'order_id' => (int)$o['id'],
                    'order_no' => $o['order_no'],
                    'visit_id' => (int)$o['visit_id'],
                    'patient_no' => $o['patient_no'],
                    'flow_no' => $o['flow_no'],
                    'doctor_name' => $o['doctor_name'],
                    'total_amount' => (float)$o['total_amount'],
                    'status' => $o['status'],
                    'dispensed_at' => $o['dispensed_at'],
                    'items' => $items,
                );
            default:
                return null;
        }
    }

    /** 取 payload 值 */
    private static function pv($payload, $key) {
        return isset($payload[$key]) ? $payload[$key] : 0;
    }

    /** 工厂：按配置返回 REST 或 SOAP 驱动 */
    public static function make() {
        $protocol = (string)setting('integration.outbound.his.protocol', 'rest_json');
        if ($protocol === 'soap_xml') {
            return new SoapHisDriver();
        }
        return new RestHisDriver();
    }
}