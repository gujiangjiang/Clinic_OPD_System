<?php
/**
 * ============================================================
 * services/ConnectivityTester.php — 接口连通性测试通用组件
 * ============================================================
 * 说明：接口管理各子模块（FHIR/PACS/HL7/LIS/HIS/医保/存证）共用同一套
 * 连通性测试：
 *  - 入向：验证本地服务是否完整启用（模块开关 + 凭证配置 + 本地端点自检，
 *    携带凭证请求本地端点，能返回预期结果即视为通过）；
 *  - 出向：验证对方服务器是否可达且返回数据正确（按配置的网关/地址发起
 *    HTTP 请求并校验响应）。
 * 入参配置值由调用方传入（保存前测试用表单当前值，保存时用待入库值），
 * 本组件不落库、不修改任何数据，只做只读探测并返回逐条日志。
 * 每条日志含 blocking 标记：仅「已启用但探测失败」为阻断项（保存拦截用），
 * 「未启用 / 未配置」为非阻断提示项（可保存，但界面明确提示补齐）。
 * ============================================================ */
class ConnectivityTester {

    /** 本地 HTTP 探测超时（秒） */
    const TIMEOUT = 8;

    /**
     * 测试指定模块的连通性（入向 + 出向逐条探测，收集日志）
     * @param string $groupId 模块 id（fhir/pacs/hl7/lis/his/insurance/evid）
     * @param array  $vals    配置值表（key => value，可含未保存的表单值）
     * @return array { ok:bool, items:[{name, ok, blocking, detail}] }
     *   ok=false 表示存在阻断项（已启用但探测失败）；
     *   items[].blocking=true 的项为阻断项，其余为提示/跳过项。
     */
    public static function test($groupId, $vals) {
        $get = function ($k) use ($vals) { return isset($vals[$k]) ? trim((string)$vals[$k]) : ''; };
        $on = function ($k) use ($get) { return $get($k) === '1'; };
        $items = array();

        switch ($groupId) {
            case 'fhir':
                if ($on('integration.outbound.fhir.enabled')) {
                    $items[] = self::httpCheck('出向 FHIR Server', $get('integration.outbound.fhir.remote_endpoint'), 'GET', true);
                } else {
                    $items[] = self::skip('出向 FHIR 上报', '未启用（启用出向上报后自动测试）');
                }
                if ($on('integration.inbound.fhir.enabled')) {
                    $tok = self::firstToken($get('integration.inbound.fhir.allowed_tokens'));
                    if ($tok === '') {
                        $items[] = array('name' => '入向 FHIR 授权 Token', 'ok' => false, 'blocking' => true, 'detail' => '已启用但未配置授权 Token 列表');
                    } else {
                        $items[] = self::localGetCheck('入向 FHIR 元数据端点', '/api/fhir/r4/metadata', $tok, function ($json) {
                            return isset($json['resourceType']) && $json['resourceType'] === 'CapabilityStatement';
                        });
                    }
                } else {
                    $items[] = self::skip('入向 FHIR 开放', '未启用（启用入向开放后自动测试）');
                }
                break;

            case 'pacs':
                $mode = $get('integration.pacs.protocol_mode');
                if ($mode === 'dicomweb') {
                    // PACS 无启用开关：未配置地址视为未使用（提示不阻断），已配置不可达也仅提示
                    $items[] = self::httpCheck('QIDO-RS 检索端点', $get('integration.outbound.pacs.qido_url'), 'GET', false);
                    $items[] = self::httpCheck('WADO-RS 调阅端点', $get('integration.outbound.pacs.wado_url'), 'GET', false);
                    $items[] = self::httpCheck('STOW-RS 上传端点', $get('integration.outbound.pacs.stow_url'), 'GET', false);
                } else {
                    $host = $get('integration.outbound.pacs.remote_host');
                    $port = $get('integration.outbound.pacs.remote_port');
                    $items[] = ($host !== '' && $port !== '')
                        ? self::tcpCheck('DIMSE 远端主机', $host, $port)
                        : self::skip('DIMSE 远端主机', '未配置主机或端口');
                }
                $ae = $get('integration.inbound.pacs.local_ae_title');
                $lp = $get('integration.inbound.pacs.local_port');
                $items[] = ($ae !== '' && $lp !== '')
                    ? array('name' => '入向接收（SCP）', 'ok' => true, 'blocking' => false, 'detail' => 'AE ' . $ae . ' / 端口 ' . $lp . '（由 PACS 前置网关承载监听）')
                    : self::skip('入向接收（SCP）', '未配置本地 AE Title 或监听端口');
                break;

            case 'hl7':
                if ($on('integration.outbound.hl7.enabled')) {
                    $host = $get('integration.outbound.hl7.remote_host');
                    $port = $get('integration.outbound.hl7.remote_port');
                    if ($host === '' || $port === '') {
                        $items[] = array('name' => '出向 HL7 远端', 'ok' => false, 'blocking' => true, 'detail' => '已启用但未配置主机或端口');
                    } elseif (integration_hl7_transport() === 'mllp_tcp') {
                        $items[] = self::tcpCheck('MLLP 远端端口', $host, $port);
                    } else {
                        $items[] = self::httpCheck('HTTP 推送端点', $host . ':' . $port, 'POST', true);
                    }
                } else {
                    $items[] = self::skip('出向 HL7 发送', '未启用（启用出向发送后自动测试）');
                }
                $lp = $get('integration.inbound.hl7.local_port');
                $items[] = $lp !== '' ? self::localPortCheck('入向 MLLP 监听端口', $lp) : self::skip('入向 MLLP 监听端口', '未配置监听端口');
                break;

            case 'lis':
                if ($on('integration.outbound.lis.enabled')) {
                    $items[] = self::httpCheck('出向检验申请接口', $get('integration.outbound.lis.order_url'), 'POST', true);
                } else {
                    $items[] = self::skip('出向 LIS 申请下发', '未启用（启用申请下发后自动测试）');
                }
                $secret = $get('integration.inbound.lis.webhook_secret');
                if ($secret === '') {
                    $items[] = self::skip('入向 Webhook 验签密钥', '未配置（回调会被拒绝，如需接收请配置）');
                    $items[] = self::skip('入向 LIS 回调端点', '跳过（未配置验签密钥）');
                } else {
                    $items[] = self::localGetCheck('入向 LIS 回调端点', '/api/external/lis/callback', $secret, null, 'POST');
                }
                break;

            case 'his':
                if ($on('integration.outbound.his.enabled')) {
                    $items[] = self::httpCheck('出向 HIS 网关', $get('integration.outbound.his.gateway_url'), 'POST', true);
                } else {
                    $items[] = self::skip('出向 HIS 同步', '未启用（启用出向同步后自动测试）');
                }
                $tok = $get('integration.inbound.his.token');
                if ($tok === '') {
                    $items[] = self::skip('入向鉴权 Token', '未配置（只读查询与推送将被拒绝，如需入向请生成并保存 Token）');
                    $items[] = self::skip('入向只读端点自检', '跳过（未配置 Token）');
                } else {
                    $items[] = self::localGetCheck('入向只读端点自检', '/api/external/his/read?action=ping', $tok, function ($json) {
                        return !empty($json['data']['pong']);
                    });
                }
                break;

            case 'insurance':
                $url = $get('integration.outbound.insurance.gateway_url');
                $items[] = $url !== '' ? self::httpCheck('医保前置机', $url, 'POST', false) : self::skip('医保前置机', '未配置网关地址');
                $mode = $get('pay_aggregate_mode');
                if (in_array($mode, array('wechat', 'alipay', 'both'), true)) {
                    $items[] = array('name' => '聚合支付模式', 'ok' => true, 'blocking' => false, 'detail' => '已启用（支付回调端点对外可用）');
                    $items[] = self::localGetCheck('入向支付回调端点', '/api/cashier/pay-notify/' . ($mode === 'wechat' ? 'wechat' : ($mode === 'alipay' ? 'alipay' : 'wechat')), '', null, 'POST');
                } else {
                    $items[] = self::skip('聚合支付模式', '未启用（仅现金/线下收费，无需支付回调）');
                }
                break;

            case 'evid':
                $mode = $get('evid_mode');
                if ($mode === 'hash') {
                    $items[] = array('name' => '存证模式', 'ok' => true, 'blocking' => false, 'detail' => '本地哈希指纹（SHA-256），无需外部服务');
                } elseif ($mode === 'http') {
                    $items[] = self::httpCheck('外部存证服务', $get('evid_endpoint'), 'POST', false);
                } else {
                    $items[] = self::skip('存证模式', '关闭（不进行存证，无需连通性测试）');
                }
                break;

            default:
                $items[] = array('name' => '模块', 'ok' => false, 'blocking' => true, 'detail' => '未知接口模块');
        }

        // 汇总：存在阻断项（已启用但探测失败）即整体不通过（保存拦截）
        $allOk = true;
        foreach ($items as $it) {
            if (!empty($it['blocking'])) { $allOk = false; break; }
        }
        return array('ok' => $allOk, 'items' => $items);
    }

    /* ==================== 探测原语 ==================== */

    /** 非阻断提示项（未启用/未配置/跳过） */
    private static function skip($name, $detail) {
        return array('name' => $name, 'ok' => false, 'blocking' => false, 'detail' => $detail);
    }

    /** 出向 HTTP 探测（GET/POST 到目标地址，2xx/3xx 视为可达） */
    private static function httpCheck($name, $url, $method = 'GET', $blocking = true) {
        if ($url === '') {
            // 已启用但未配置地址 = 阻断项（保存拦截）；未启用场景由调用方先走 skip
            return array('name' => $name, 'ok' => false, 'blocking' => $blocking, 'detail' => '未配置地址');
        }
        try {
            $resp = HttpClient::request($method, $url, array('timeout' => self::TIMEOUT, 'headers' => array('X-Connectivity-Probe: 1')));
            $status = (int)$resp['status'];
            $ok = ($status >= 200 && $status < 400);
            return array('name' => $name, 'ok' => $ok, 'blocking' => $blocking && !$ok,
                'detail' => ($ok ? 'HTTP ' . $status . ' 可达' : 'HTTP ' . $status . ' 响应异常') . '（' . substr((string)$resp['body'], 0, 200) . '）');
        } catch (Exception $ex) {
            return array('name' => $name, 'ok' => false, 'blocking' => $blocking, 'detail' => '连接失败：' . $ex->getMessage());
        }
    }

    /** TCP 连通探测（DIMSE/MLLP 等非 HTTP 端口） */
    private static function tcpCheck($name, $host, $port) {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, (int)$port, $errno, $errstr, self::TIMEOUT);
        if ($fp) {
            fclose($fp);
            return array('name' => $name, 'ok' => true, 'blocking' => false, 'detail' => $host . ':' . $port . ' TCP 可连通');
        }
        return array('name' => $name, 'ok' => false, 'blocking' => true, 'detail' => $host . ':' . $port . ' 连接失败：' . $errstr);
    }

    /** 入向本地端口探测（本机监听检测） */
    private static function localPortCheck($name, $port) {
        $fp = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 2);
        if ($fp) {
            fclose($fp);
            return array('name' => $name, 'ok' => true, 'blocking' => false, 'detail' => '127.0.0.1:' . $port . ' 本机端口有监听');
        }
        // 本系统不直接承载 TCP 监听（由前置网关承载）：无监听不代表未启用，仅提示
        return array('name' => $name, 'ok' => true, 'blocking' => false, 'detail' => '端口 ' . $port . ' 未探测到本机监听（由外部前置承载时属正常）');
    }

    /** 入向本地 HTTP 端点自检（携带凭证请求本地端点，校验响应/预期结果） */
    private static function localGetCheck($name, $path, $token = '', $verify = null, $method = 'GET') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '127.0.0.1';
        $url = $scheme . '://' . $host . $path;
        $headers = array('X-Requested-With: XMLHttpRequest');
        if ($token !== '') $headers[] = 'X-HIS-Token: ' . $token;
        try {
            $resp = HttpClient::request($method, $url, array('timeout' => self::TIMEOUT, 'headers' => $headers));
            $status = (int)$resp['status'];
            $json = json_decode((string)$resp['body'], true);
            $ok = ($status === 200);
            if ($ok && is_callable($verify)) {
                $ok = (bool)$verify($json);
            }
            return array('name' => $name, 'ok' => $ok, 'blocking' => !$ok,
                'detail' => 'HTTP ' . $status . ' ' . ($ok ? '通过' : '响应异常') . '（' . substr((string)$resp['body'], 0, 120) . '）');
        } catch (Exception $ex) {
            return array('name' => $name, 'ok' => false, 'blocking' => true, 'detail' => '本地端点请求失败：' . $ex->getMessage());
        }
    }

    /** 多组 Token 列表首行 Token（每行「调用方名,Token」） */
    private static function firstToken($text) {
        $text = trim((string)$text);
        if ($text === '') return '';
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $pos = strpos($line, ',');
            $tok = $pos === false ? trim($line) : trim(substr($line, $pos + 1));
            if ($tok !== '') return $tok;
        }
        return '';
    }
}