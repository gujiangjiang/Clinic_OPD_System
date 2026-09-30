<?php
/**
 * ============================================================
 * services/fhir/FhirService.php — FHIR R4 资源互联引擎
 * ============================================================
 * 说明：FHIR R4 (v4.0.1) 双向能力：
 *  - 出向（Client Push）：将就诊数据组装为 FHIR Bundle（Patient /
 *    Encounter / Condition / MedicationRequest）异步 POST 到目标
 *    FHIR Server（integration.outbound.fhir.*）；
 *  - 入向（Provider Server）：按 FHIR 规范输出 CapabilityStatement /
 *    Patient / Encounter 资源（供 /api/fhir/r4/* 路由调用）。
 * 资源 id 统一以可读编码（patient-{patient_no} 等）保证跨系统稳定引用。
 * ============================================================ */
class FhirService {

    /** 支持声明的资源类型（CapabilityStatement 用） */
    public static function resourceTypes() {
        return array('Patient', 'Encounter', 'Condition', 'MedicationRequest', 'Bundle');
    }

    /* ============================================================
     * 入向：CapabilityStatement（/api/fhir/r4/metadata）
     * ============================================================ */
    public static function capabilityStatement() {
        $org = trim((string)setting('hospital_name', 'Clinic OPD System'));
        $base = integration_host_base() . '/api/fhir/r4/';
        $rest = array(
            'mode' => 'server',
            'security' => array(
                'cors' => false,
                'service' => array(
                    array('coding' => array(
                        array('system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service', 'code' => 'SMART-on-FHIR', 'display' => 'SMART-on-FHIR'),
                    )),
                ),
            ),
        );
        $res = array();
        foreach (self::resourceTypes() as $rt) {
            $op = array();
            if ($rt === 'Patient') {
                $op[] = array('name' => 'search-type', 'definition' => 'http://hl7.org/fhir/R4/OperationDefinition/Patient-search');
            }
            $res[] = array(
                'type' => $rt,
                'interaction' => array(
                    array('code' => 'read'),
                    array('code' => 'search-type'),
                ),
                'searchParam' => array(
                    array('name' => '_id', 'type' => 'token'),
                    array('name' => 'identifier', 'type' => 'token'),
                ),
                'operation' => $op,
            );
        }
        $rest['resource'] = $res;
        return array(
            'resourceType' => 'CapabilityStatement',
            'id' => 'clinic-opd-capability',
            'status' => 'active',
            'date' => now_str() . '+08:00',
            'publisher' => $org,
            'fhirVersion' => '4.0.1',
            'kind' => 'instance',
            'implementation' => array(
                'description' => $org . ' 门诊系统 FHIR R4 互操作服务',
                'url' => integration_host_base(),
            ),
            'rest' => array($rest),
        );
    }

    /* ============================================================
     * 入向：资源构建
     * ============================================================ */
    /** Patient 资源 */
    public static function patientResource($patient) {
        $p = $patient ? $patient : array();
        $pno = (string)(isset($p['patient_no']) ? $p['patient_no'] : '');
        $name = (string)(isset($p['name']) ? $p['name'] : '');
        $res = array(
            'resourceType' => 'Patient',
            'id' => 'patient-' . $pno,
            'identifier' => array(
                array('use' => 'usual', 'system' => 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'local') . '/patient-no', 'value' => $pno),
            ),
        );
        if (!empty($p['id_card'])) {
            $res['identifier'][] = array('system' => 'urn:oid:2.16.840.1.113883.4.3.10', 'value' => (string)$p['id_card']);
        }
        if ($name !== '') {
            $res['name'] = array(array('use' => 'official', 'text' => $name, 'family' => $name));
        }
        if (!empty($p['gender'])) {
            $g = strtolower((string)$p['gender']);
            $genderMap = array('male' => 'male', 'm' => 'male', '男' => 'male',
                'female' => 'female', 'f' => 'female', '女' => 'female',
                'other' => 'other', 'unknown' => 'unknown');
            $res['gender'] = isset($genderMap[$g]) ? $genderMap[$g] : 'unknown';
        }
        if (!empty($p['birth_date'])) {
            $res['birthDate'] = (string)$p['birth_date'];
        }
        if (!empty($p['phone'])) {
            $res['telecom'] = array(array('system' => 'phone', 'value' => (string)$p['phone']));
        }
        if (!empty($p['address'])) {
            $res['address'] = array(array('text' => (string)$p['address']));
        }
        return $res;
    }

    /** Encounter 资源 */
    public static function encounterResource($visit, $patient = null) {
        $v = $visit ? $visit : array();
        $vid = isset($v['id']) ? (int)$v['id'] : 0;
        $pno = (string)(isset($v['patient_no']) ? $v['patient_no'] : '');
        $statusMap = array('pending' => 'planned', 'paid' => 'arrived', 'visiting' => 'in-progress', 'finished' => 'finished', 'refunded' => 'cancelled', 'cancelled' => 'cancelled');
        $status = isset($statusMap[$v['status']]) ? $statusMap[$v['status']] : 'unknown';
        $res = array(
            'resourceType' => 'Encounter',
            'id' => 'encounter-' . $vid,
            'status' => $status,
            'class' => array('system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB', 'display' => 'ambulatory'),
            'subject' => array('reference' => 'Patient/patient-' . $pno),
            'period' => array(
                'start' => (string)(isset($v['registered_at']) ? $v['registered_at'] : ''),
            ),
        );
        if (!empty($v['first_dept_name'])) {
            $res['serviceProvider'] = array('display' => (string)$v['first_dept_name']);
        }
        if (!empty($v['flow_no'])) {
            $res['identifier'] = array(array('system' => 'urn:clinic:flow_no', 'value' => (string)$v['flow_no']));
        }
        if (!empty($v['finished_at'])) {
            $res['period']['end'] = (string)$v['finished_at'];
        }
        return $res;
    }

    /** 按患者编号检索 Encounter（入向：Encounter?patient=） */
    public static function encountersOfPatient($patientNo) {
        $patient = DB::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$patient) {
            return self::bundle(array(), 0);
        }
        $visits = DB::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
        $entries = array(array('resource' => self::patientResource($patient)));
        foreach ($visits as $v) {
            $entries[] = array('resource' => self::encounterResource($v, $patient));
        }
        return self::bundle($entries, count($visits) + 1);
    }

    /** 通用 Bundle 组装 */
    public static function bundle($entries, $total = 0) {
        return array(
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (int)$total,
            'entry' => $entries,
        );
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
        $visit = DB::one('SELECT * FROM registrations WHERE id=?', array((int)$visitId));
        if (!$visit) throw new Exception('就诊记录不存在：' . (int)$visitId);
        $patient = DB::one('SELECT * FROM patients WHERE patient_no=?', array($visit['patient_no']));
        $bundle = self::buildVisitBundle($visit, $patient);
        $authType = (string)setting('integration.outbound.fhir.auth_type', 'none');
        $token = trim((string)setting('integration.outbound.fhir.client_token', ''));
        $opts = array(
            'json' => $bundle,
            'timeout' => 15,
        );
        if ($authType === 'basic' && $token !== '') {
            $opts['basic'] = array_map('trim', explode(':', $token, 2) + array('', ''));
        } elseif ($authType === 'bearer' && $token !== '') {
            $opts['bearer'] = $token;
        } elseif ($authType === 'oauth2' && $token !== '') {
            // OAuth2 客户端凭证：令牌缓存获取后按 Bearer 携带
            $opts['bearer'] = self::fetchOAuth2Token($token);
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
            $entries[] = array('resource' => self::patientResource($patient));
        }
        if ($visit) {
            $entries[] = array('resource' => self::encounterResource($visit, $patient));
        }
        $visitId = (int)(isset($visit['id']) ? $visit['id'] : 0);
        $patientNo = (string)(isset($visit['patient_no']) ? $visit['patient_no'] : '');

        // Condition：本次就诊诊断（病历 icd10_code/diagnosis_name）
        $diags = DB::q("SELECT icd10_code, diagnosis_name, created_at FROM patient_records
            WHERE visit_id=? AND icd10_code!='' ORDER BY id LIMIT 10", array($visitId));
        $ci = 0;
        foreach ($diags as $d) {
            $ci++;
            $entries[] = array('resource' => array(
                'resourceType' => 'Condition',
                'id' => 'condition-' . $visitId . '-' . $ci,
                'clinicalStatus' => array('coding' => array(array('system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active'))),
                'code' => array(
                    'coding' => array(array('system' => 'http://hl7.org/fhir/sid/icd-10-cn', 'code' => (string)$d['icd10_code'], 'display' => (string)$d['diagnosis_name'])),
                    'text' => (string)$d['diagnosis_name'],
                ),
                'subject' => array('reference' => 'Patient/patient-' . $patientNo),
                'encounter' => array('reference' => 'Encounter/encounter-' . $visitId),
                'recordedDate' => (string)$d['created_at'],
            ));
        }

        // MedicationRequest：处方明细
        $rxItems = DB::q("SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.visit_id=? AND o.order_type='prescription' AND oi.item_type='prescription'
            ORDER BY oi.id", array($visitId));
        foreach ($rxItems as $it) {
            $entries[] = array('resource' => array(
                'resourceType' => 'MedicationRequest',
                'id' => 'medrequest-' . (int)$it['id'],
                'status' => 'active',
                'intent' => 'order',
                'medicationCodeableConcept' => array('text' => (string)$it['item_name']),
                'subject' => array('reference' => 'Patient/patient-' . $patientNo),
                'encounter' => array('reference' => 'Encounter/encounter-' . $visitId),
                'dosageInstruction' => array(array(
                    'text' => trim((string)$it['single_dose'] . ' ' . $it['frequency'] . ' ' . $it['route']),
                )),
                'authoredOn' => (string)$it['created_at'],
            ));
        }

        return array(
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => $entries,
        );
    }

    /** OAuth2 客户端凭证令牌获取（简化实现：缓存至 settings，过期前复用） */
    private static function fetchOAuth2Token($credential) {
        $parts = explode(':', (string)$credential, 2);
        $clientId = isset($parts[0]) ? $parts[0] : '';
        $clientSecret = isset($parts[1]) ? $parts[1] : '';
        $endpoint = trim((string)setting('integration.outbound.fhir.remote_endpoint', ''));
        $tokenEndpoint = rtrim($endpoint, '/') . '/token';
        $cached = (string)setting('integration.outbound.fhir.oauth2_token', '');
        $cachedAt = (int)setting('integration.outbound.fhir.oauth2_token_at', '0');
        if ($cached !== '' && (time() - $cachedAt) < 3000) {
            return $cached;
        }
        $resp = HttpClient::request('POST', $tokenEndpoint, array(
            'body' => http_build_query(array(
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            )),
            'timeout' => 15,
            'headers' => array('Content-Type: application/x-www-form-urlencoded'),
        ));
        $json = json_decode($resp['body'], true);
        $token = is_array($json) && isset($json['access_token']) ? $json['access_token'] : '';
        if ($token === '') {
            throw new Exception('OAuth2 令牌获取失败：' . $resp['body']);
        }
        set_setting('integration.outbound.fhir.oauth2_token', $token);
        set_setting('integration.outbound.fhir.oauth2_token_at', (string)time());
        return $token;
    }
}