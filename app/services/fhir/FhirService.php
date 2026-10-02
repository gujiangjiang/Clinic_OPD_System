<?php
/**
 * ============================================================
 * services/fhir/FhirService.php — FHIR R4 资源互联调度引擎
 * ============================================================
 * 说明：FHIR R4 (v4.0.1) 双向能力调度层（不自实现资源转换逻辑）：
 *  - 入向（Provider Server）：CapabilityStatement / 单资源 read /
 *    集合 search（Bundle + 分页 + _include），资源组装委托
 *    app/services/fhir/adapters/ 下各单资源适配器分治；
 *  - 鉴权辅助：OAuth2 client_credentials 令牌颁发与校验、Scope 判定；
 *  - 出向（Client Push）：就诊数据组装 Bundle 异步 POST 到目标 FHIR Server。
 * 资源 id 统一以可读编码（patient-{patient_no} / encounter-{id} …）稳定引用。
 * ============================================================ */
class FhirService {

    /** 入向支持的资源类型 → 适配器类（CapabilityStatement 与路由共用） */
    public static function adapterMap() {
        return array(
            'patient' => 'PatientAdapter',
            'encounter' => 'EncounterAdapter',
            'condition' => 'ConditionAdapter',
            'observation' => 'ObservationAdapter',
            'medicationrequest' => 'MedicationRequestAdapter',
            'imagingstudy' => 'ImagingStudyAdapter',
        );
    }

    /** 规范化资源类型（大小写不敏感）→ 适配器实例化类名；不支持返回 null */
    public static function adapterFor($type) {
        $key = strtolower(trim((string)$type));
        $map = self::adapterMap();
        if (!isset($map[$key])) return null;
        $class = $map[$key];
        return class_exists($class) ? $class : null;
    }

    /* ============================================================
     * CapabilityStatement（/api/fhir/r4/metadata）
     * ============================================================ */
    public static function capabilityStatement() {
        $org = trim((string)setting('hospital_name', 'Clinic OPD System'));
        $base = FhirAdapter::baseUrl();
        $rest = array(
            'mode' => 'server',
            'security' => array(
                'cors' => true,
                'service' => array(
                    array('coding' => array(array(
                        'system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service',
                        'code' => 'SMART-on-FHIR', 'display' => 'SMART-on-FHIR',
                    ))),
                ),
                'extension' => array(array(
                    'url' => 'http://fhir-registry.smarthealthit.org/StructureDefinition/oauth-uris',
                    // 仅实现 client_credentials 令牌端点，不提供 authorize（授权码）流程
                    'extension' => array(
                        array('url' => 'token', 'valueUri' => $base . '/oauth/token'),
                    ),
                )),
            ),
            // 系统级交互：仅实现了按资源检索/读取，未提供系统级 search/transaction
            'interaction' => array(),
        );
        // 支持 _include=:patient 的资源（其 search 会回传 patientRefs）
        $includeMap = array('Encounter', 'Condition', 'Observation', 'MedicationRequest', 'ImagingStudy');
        $res = array();
        foreach (self::adapterMap() as $key => $class) {
            if (!class_exists($class)) continue;
            $type = $class::resourceType();
            $params = array();
            foreach ($class::searchParams() as $p) {
                $params[] = array('name' => $p['name'], 'type' => $p['type']);
            }
            $item = array(
                'type' => $type,
                'interaction' => array(
                    array('code' => 'read'),
                    array('code' => 'search-type'),
                ),
                'versioning' => 'no-version',
                'readHistory' => false,
                'searchParam' => $params,
            );
            if (in_array($type, $includeMap, true)) {
                $item['searchInclude'] = array($type . ':patient');
            }
            $res[] = $item;
        }
        $rest['resource'] = $res;
        return array(
            'resourceType' => 'CapabilityStatement',
            'id' => 'clinic-opd-capability',
            'status' => 'active',
            'experimental' => false,
            'date' => date('Y-m-d\TH:i:sP'),
            'publisher' => $org,
            'kind' => 'instance',
            'fhirVersion' => '4.0.1',
            'format' => array('application/fhir+json'),
            'implementation' => array(
                'description' => $org . ' 门诊系统 FHIR R4 互操作服务',
                'url' => $base,
            ),
            'rest' => array($rest),
        );
    }

    /* ============================================================
     * 单资源读取 / 集合检索（路由调度）
     * ============================================================ */

    /**
     * 读取单资源
     * @param string $type   资源类型（Patient…）
     * @param string $id     资源 id（patient-xxx 或裸标识）
     * @return array FHIR 资源
     * @throws FhirError 资源不存在
     */
    public static function read($type, $id) {
        $class = self::adapterFor($type);
        if (!$class) {
            throw new FhirError(404, 'not-supported', '不支持的 FHIR 资源类型：' . $type);
        }
        $id = urldecode((string)$id);
        if ($id === '') {
            throw new FhirError(400, 'required', '缺少资源标识');
        }
        $bare = self::stripTypePrefix($type, $id);
        $resource = $class::readByBareId($bare);
        if (!$resource) {
            throw new FhirError(404, 'not-found', $class::resourceType() . ' 资源不存在：' . $id);
        }
        return $resource;
    }

    /**
     * 集合检索（返回 searchset Bundle）
     * @param string $type    资源类型
     * @param array  $params  查询参数（$_GET）
     * @param string $selfUrl 当前请求完整 URL（link[relation=self]）
     * @return array Bundle
     * @throws FhirError
     */
    public static function search($type, $params, $selfUrl = '') {
        $class = self::adapterFor($type);
        if (!$class) {
            throw new FhirError(404, 'not-supported', '不支持的 FHIR 资源类型：' . $type);
        }
        $result = $class::search(is_array($params) ? $params : array());
        $entry = array();
        foreach ($result['entries'] as $resource) {
            $rid = isset($resource['id']) ? (string)$resource['id'] : '';
            $entry[] = array(
                'fullUrl' => FhirAdapter::baseUrl() . '/' . $class::resourceType() . '/' . $rid,
                'resource' => $resource,
                'search' => array('mode' => 'match'),
            );
        }
        // _include：将关联患者合并进 Bundle（Encounter/ImagingStudy:patient）
        $refs = array();
        foreach ($result['patientRefs'] as $pno) {
            if ($pno !== '') $refs[$pno] = true;
        }
        if ($refs && FhirAdapter::wantsInclude($params, $class::resourceType())) {
            $pnos = array_keys($refs);
            $included = array();
            foreach ($pnos as $pno) {
                $row = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($pno));
                if ($row) $included[] = PatientAdapter::toResource($row);
            }
            foreach ($included as $resource) {
                $rid = isset($resource['id']) ? (string)$resource['id'] : '';
                $entry[] = array(
                    'fullUrl' => FhirAdapter::baseUrl() . '/Patient/' . $rid,
                    'resource' => $resource,
                    'search' => array('mode' => 'include'),
                );
            }
        }

        return self::bundle($entry, (int)$result['total'], $params, $selfUrl);
    }

    /**
     * 组装 searchset Bundle（含 link self / next）
     * @param array  $entry   已组装的 entry 列表
     * @param int    $total   匹配总数
     * @param array  $params  查询参数
     * @param string $selfUrl 当前请求 URL
     */
    public static function bundle($entry, $total, $params = array(), $selfUrl = '') {
        $bundle = array(
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (int)$total,
        );
        if ($selfUrl === '') {
            $selfUrl = FhirAdapter::baseUrl();
        }
        $bundle['link'] = array(array('relation' => 'self', 'url' => $selfUrl));
        // next 链接：按 _page（优先）或 _offset 推进
        $count = isset($params['_count']) ? (int)$params['_count'] : 20;
        if ($count <= 0) $count = 20;
        if ($count > 200) $count = 200;
        $page = isset($params['_page']) ? (int)$params['_page'] : 0;
        $offset = isset($params['_offset']) ? (int)$params['_offset'] : 0;
        $nextOffset = $page > 0 ? $page * $count : ($offset > 0 ? $offset + $count : $count);
        if ($nextOffset < $total) {
            $nextParams = $params;
            unset($nextParams['_offset']);
            if ($page > 0) $nextParams['_page'] = $page + 1;
            else $nextParams['_offset'] = $nextOffset;
            $bundle['link'][] = array('relation' => 'next', 'url' => self::urlWith($selfUrl, $nextParams));
        }
        $bundle['entry'] = $entry;
        return $bundle;
    }

    /** 在 URL 上覆盖查询参数 */
    public static function urlWith($url, $params) {
        $base = $url;
        $qs = '';
        $pos = strpos($url, '?');
        if ($pos !== false) { $base = substr($url, 0, $pos); $qs = substr($url, $pos + 1); }
        $merged = array();
        if ($qs !== '') {
            foreach (explode('&', $qs) as $pair) {
                if ($pair === '') continue;
                $kv = explode('=', $pair, 2);
                $merged[urldecode($kv[0])] = isset($kv[1]) ? urldecode($kv[1]) : '';
            }
        }
        foreach ($params as $k => $v) {
            if ($v === null) { unset($merged[$k]); continue; }
            $merged[$k] = $v;
        }
        $parts = array();
        foreach ($merged as $k => $v) {
            $parts[] = urlencode($k) . '=' . urlencode((string)$v);
        }
        return $base . ($parts ? ('?' . implode('&', $parts)) : '');
    }

    /** 去掉资源类型前缀（patient- / encounter- / imagingstudy- …） */
    public static function stripTypePrefix($type, $id) {
        $id = (string)$id;
        $t = strtolower(trim((string)$type));
        if ($t !== '' && stripos($id, $t . '-') === 0) {
            return substr($id, strlen($t) + 1);
        }
        return $id;
    }

    /* ============================================================
     * OperationOutcome 标准错误资源
     * ============================================================ */
    public static function operationOutcome($code, $diagnostics, $severity = 'error') {
        return array(
            'resourceType' => 'OperationOutcome',
            'issue' => array(array(
                'severity' => (string)$severity,
                'code' => (string)$code,
                'diagnostics' => (string)$diagnostics,
            )),
        );
    }

    /* ============================================================
     * OAuth2 / SMART-on-FHIR 令牌颁发与校验
     * ------------------------------------------------------------
     * 支持 grant_type=client_credentials：
     *   客户端配置 integration.inbound.fhir.oauth_clients
     *   （每行「client_id,client_secret,scopes」，scopes 可空默认 system/*.read）。
     *   签发自包含 HMAC-SHA256 令牌（base64url(payload).base64url(sig)），
     *   有效期 7200 秒；亦兼容系统设置的长期静态 Token/API Key
     *   （integration.inbound.fhir.allowed_tokens，由 InboundGuard 校验）。
     * ============================================================ */

    /** 令牌签名密钥（未配置时自动生成并持久化） */
    public static function tokenSecret() {
        $s = trim((string)setting('integration.inbound.fhir.oauth_secret', ''));
        if ($s === '') {
            $s = bin2hex(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
            try { set_setting('integration.inbound.fhir.oauth_secret', $s); } catch (Exception $ex) {}
        }
        return $s;
    }

    /** 列出 OAuth 客户端 [client_id => [secret, scopes]] */
    public static function oauthClients() {
        $text = trim((string)setting('integration.inbound.fhir.oauth_clients', ''));
        $out = array();
        if ($text === '') return $out;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2 || $parts[0] === '') continue;
            $out[$parts[0]] = array(
                'secret' => $parts[1],
                'scopes' => isset($parts[2]) && $parts[2] !== '' ? $parts[2] : 'system/*.read',
            );
        }
        return $out;
    }

    /**
     * 颁发令牌（client_credentials）
     * @return array 令牌响应
     * @throws FhirError 400 invalid_client / 400 unsupported_grant_type
     */
    public static function issueToken($grantType, $clientId, $clientSecret, $requestedScope = '') {
        if ($grantType !== 'client_credentials') {
            throw new FhirError(400, 'invalid', '不支持的 grant_type（仅支持 client_credentials）');
        }
        $clients = self::oauthClients();
        if (!isset($clients[$clientId]) || !hash_equals((string)$clients[$clientId]['secret'], (string)$clientSecret)) {
            throw new FhirError(401, 'security', 'client_id 或 client_secret 无效');
        }
        $scope = $requestedScope !== '' ? $requestedScope : $clients[$clientId]['scopes'];
        $issuedAt = time();
        $expiresIn = 7200;
        $payload = array(
            'iss' => FhirAdapter::baseUrl(),
            'client_id' => $clientId,
            'scope' => $scope,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $expiresIn,
        );
        return array(
            'access_token' => self::signToken($payload),
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => $scope,
        );
    }

    /** 签发自包含令牌 */
    public static function signToken($payload) {
        $body = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $body, self::tokenSecret(), true);
        return $body . '.' . self::b64url($sig);
    }

    /**
     * 校验自包含令牌
     * @return array|null 有效返回 payload（含 scope），否则 null
     */
    public static function verifyToken($token) {
        $token = trim((string)$token);
        if ($token === '' || strpos($token, '.') === false) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        $body = $parts[0];
        $expect = self::b64url(hash_hmac('sha256', $body, self::tokenSecret(), true));
        if (!hash_equals($expect, $parts[1])) return null;
        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && (int)$payload['exp'] < time()) return null;
        return $payload;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode($data) {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data);
    }

    /**
     * Scope 判定（支持通配）：granted 逗号/空格分隔，required 形如 system/Patient.read。
     * 规则：'*' 放行；'system/*.read' 放行任意 *.read；完整匹配放行。
     */
    public static function scopeAllows($granted, $required) {
        $granted = trim((string)$granted);
        if ($granted === '') return false;
        if ($required === '') return true;
        $list = preg_split('/[\s,]+/', $granted, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($list as $g) {
            if ($g === '*' || $g === '*.*') return true;
            if (strcasecmp($g, $required) === 0) return true;
            // system/*.read 之类通配
            if (strpos($g, '*') !== false) {
                $regex = '/^' . str_replace('\\*', '.*', preg_quote($g, '/')) . '$/i';
                if (preg_match($regex, $required)) return true;
            }
        }
        return false;
    }

    /* ============================================================
     * 出向：就诊数据组装 Bundle 并推送（Outbox worker 调用）
     * ============================================================ */
    /**
     * 推送某次就诊的 FHIR Bundle 到外部 FHIR Server
     * @param int $visitId
     * @throws Exception 推送失败
     */
    public static function pushVisitBundle($visitId) {
        $endpoint = trim((string)setting('integration.outbound.fhir.remote_endpoint', ''));
        if ($endpoint === '') {
            throw new Exception('FHIR 目标地址未配置（integration.outbound.fhir.remote_endpoint）');
        }
        $visit = PatientRepository::one('SELECT * FROM registrations WHERE id=?', array((int)$visitId));
        if (!$visit) throw new Exception('就诊记录不存在：' . (int)$visitId);
        $patient = PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($visit['patient_no']));
        $bundle = self::buildVisitBundle($visit, $patient);
        $authType = (string)setting('integration.outbound.fhir.auth_type', 'none');
        $token = trim((string)setting('integration.outbound.fhir.client_token', ''));
        $opts = array('json' => $bundle, 'timeout' => 15);
        if ($authType === 'basic' && $token !== '') {
            $opts['basic'] = array_map('trim', explode(':', $token, 2) + array('', ''));
        } elseif (($authType === 'bearer' || $authType === 'oauth2') && $token !== '') {
            $opts['bearer'] = $token;
        }
        $resp = HttpClient::request('POST', rtrim($endpoint, '/') . '/Bundle', $opts);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new Exception('FHIR 推送失败（HTTP ' . $resp['status'] . '）：' . $resp['body']);
        }
    }

    /** 组装就诊事务 Bundle（Patient + Encounter + Condition + MedicationRequest） */
    public static function buildVisitBundle($visit, $patient) {
        $entries = array();
        if ($patient) {
            $entries[] = array('resource' => PatientAdapter::toResource($patient));
        }
        if ($visit) {
            $entries[] = array('resource' => EncounterAdapter::toResource($visit));
        }
        $visitId = (int)(isset($visit['id']) ? $visit['id'] : 0);
        $diags = EmrRepository::q("SELECT * FROM patient_records WHERE visit_id=? AND icd10_code!='' ORDER BY id LIMIT 10", array($visitId));
        foreach ($diags as $d) {
            $entries[] = array('resource' => ConditionAdapter::toResource($d));
        }
        $rxItems = OrderRepository::q("SELECT oi.*, o.order_no AS __order_no, o.doctor_name AS __doc
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.visit_id=? AND o.order_type='prescription' AND oi.item_type='prescription'
            ORDER BY oi.id", array($visitId));
        foreach ($rxItems as $it) {
            $entries[] = array('resource' => MedicationRequestAdapter::toResource($it));
        }
        return array(
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => $entries,
        );
    }
}
