<?php
/**
 * ============================================================
 * services/fhir/adapters/FhirAdapter.php — FHIR 单资源适配器基类
 * ============================================================
 * 说明：FHIR R4 资源构建分治架构的抽象基类。每个资源一个适配器
 * （PatientAdapter / EncounterAdapter / ...），由 FhirService 统一调度：
 *  - readByBareId($bareId)：按资源裸标识读取单资源（找不到返回 null）
 *  - search($params)：集合检索，返回 array('total'=>int, 'entries'=>[])
 *  - toResource($row)：单行数据 → FHIR 资源数组
 *  - searchParams()：CapabilityStatement 声明的搜索参数（不漏报不虚报）
 * 基类提供空值容错、Identifier/Coding/时间格式/分页排序等公共工具，
 * 严禁在业务适配器中重复实现。
 * ============================================================ */

/** FHIR 错误（携带 HTTP 状态与 OperationOutcome issue.code） */
class FhirError extends Exception {
    public $httpStatus;
    public $issueCode;
    public function __construct($httpStatus, $issueCode, $message) {
        parent::__construct((string)$message);
        $this->httpStatus = (int)$httpStatus;
        $this->issueCode = (string)$issueCode;
    }
}

abstract class FhirAdapter {

    /** 官方 FHIR 端点基路径 */
    const BASE_PATH = '/api/fhir/r4';

    /* ==================== 子类契约 ==================== */

    /** 资源类型名（Patient / Encounter / ...） */
    abstract public static function resourceType();

    /** 单行 → FHIR 资源数组 */
    abstract public static function toResource($row);

    /**
     * 集合检索
     * @param array $params 查询参数（$_GET 原样）
     * @return array { total:int, entries:array<resource>, patientRefs:array<string> }
     */
    abstract public static function search($params);

    /** CapabilityStatement 声明的搜索参数 [ [name, type, (optional)definition] ] */
    abstract public static function searchParams();

    /* ==================== 读取 ==================== */

    /**
     * 按裸标识读取单资源（默认实现：委托子类的 findRowByBareId）
     * @param string $bareId 去掉「资源类型-」前缀后的裸标识
     * @return array|null FHIR 资源
     */
    public static function readByBareId($bareId) {
        $row = static::findRowByBareId($bareId);
        return $row ? static::toResource($row) : null;
    }

    /** 子类实现：按裸标识查库；默认按原生主键 id 查询 */
    protected static function findRowByBareId($bareId) {
        return null;
    }

    /* ==================== 公共工具（空值容错） ==================== */

    /** 资源基地址（含尾部无斜杠） */
    public static function baseUrl() {
        return integration_host_base() . self::BASE_PATH;
    }

    /** 资源引用字符串：Patient/patient-xxx */
    protected static function ref($type, $id) {
        return $type . '/' . $id;
    }

    /** 患者引用（统一 id 规则 patient-{patient_no}） */
    protected static function patientRef($patientNo) {
        $patientNo = (string)$patientNo;
        return $patientNo === '' ? null : array('reference' => 'Patient/patient-' . $patientNo);
    }

    /** Identifier 规范组装（system + type.coding + value） */
    protected static function identifier($system, $value, $typeCode = '', $typeText = '') {
        $value = (string)$value;
        $id = array('system' => (string)$system, 'value' => $value);
        if ($typeCode !== '') {
            $id['type'] = array('coding' => array(array(
                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                'code' => (string)$typeCode,
                'display' => $typeText !== '' ? (string)$typeText : (string)$typeCode,
            )));
            if ($typeText !== '') $id['type']['text'] = (string)$typeText;
        }
        return $id;
    }

    /** CodeableConcept 组装 */
    protected static function codeable($system, $code, $display = '') {
        $c = array('coding' => array(array('system' => (string)$system, 'code' => (string)$code)));
        if ($display !== '') {
            $c['coding'][0]['display'] = (string)$display;
            $c['text'] = (string)$display;
        }
        return $c;
    }

    /** 性别标准化（中文/英文/单字母 → FHIR gender） */
    protected static function gender($g) {
        $g = strtolower(trim((string)$g));
        $map = array(
            'male' => 'male', 'm' => 'male', '男' => 'male',
            'female' => 'female', 'f' => 'female', '女' => 'female',
            'other' => 'other', 'unknown' => 'unknown', '未知' => 'unknown',
        );
        return isset($map[$g]) ? $map[$g] : 'unknown';
    }

    /**
     * 日期时间 → ISO 8601 带时区偏移（严禁空格）。
     * 输入容忍 'Y-m-d H:i:s' / 'Y-m-d'；空或非法返回 null。
     */
    protected static function isoDateTime($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d\TH:i:sP', $ts);
    }

    /** 日期 → YYYY-MM-DD（空或非法返回 null） */
    protected static function isoDate($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    /** 分页参数：返回 [count, offset, page]（_count 默认 20 上限 200） */
    protected static function paging($params) {
        $count = isset($params['_count']) ? (int)$params['_count'] : 20;
        if ($count <= 0) $count = 20;
        if ($count > 200) $count = 200;
        $page = isset($params['_page']) ? (int)$params['_page'] : 0;
        $offset = isset($params['_offset']) ? (int)$params['_offset'] : 0;
        if ($page > 0) {
            $offset = ($page - 1) * $count;
        }
        if ($offset < 0) $offset = 0;
        return array($count, $offset, $page > 0 ? $page : ($offset > 0 ? intdiv($offset, $count) + 1 : 1));
    }

    /**
     * _sort 解析为 SQL 片段（白名单字段映射，防注入）
     * @param array  $params 查询参数
     * @param array  $map    允许排序字段 [参数名 => SQL 列]
     * @param string $default 默认 ORDER BY（无 _sort 时）
     * @return string ORDER BY 片段（不含 "ORDER BY"）
     */
    protected static function sortClause($params, $map, $default) {
        if (!isset($params['_sort']) || trim((string)$params['_sort']) === '') return $default;
        $parts = array();
        foreach (explode(',', (string)$params['_sort']) as $token) {
            $token = trim($token);
            if ($token === '') continue;
            $dir = 'ASC';
            if ($token[0] === '-') { $dir = 'DESC'; $token = substr($token, 1); }
            elseif ($token[0] === '+') { $token = substr($token, 1); }
            if (!isset($map[$token])) continue;
            $parts[] = $map[$token] . ' ' . $dir;
        }
        return $parts ? implode(', ', $parts) : $default;
    }

    /** 是否请求包含关联患者（_include=Encounter:patient / ImagingStudy:patient / *） */
    public static function wantsInclude($params, $resourceType) {
        if (!isset($params['_include'])) return false;
        $inc = trim((string)$params['_include']);
        if ($inc === '' || $inc === '*') return $inc !== '';
        foreach (explode(',', $inc) as $token) {
            $token = trim($token);
            if ($token === $resourceType . ':patient') return true;
            if (stripos($token, ':patient') !== false) return true;
        }
        return false;
    }

    /** 从资源数组提取患者编号（subject.reference = Patient/patient-xxx） */
    protected static function patientNoFromResource($resource) {
        if (!is_array($resource) || !isset($resource['subject']['reference'])) return '';
        $ref = (string)$resource['subject']['reference'];
        if (strpos($ref, 'Patient/patient-') === 0) return substr($ref, 16);
        if (strpos($ref, 'patient-') === 0) return substr($ref, 8);
        return '';
    }
}
