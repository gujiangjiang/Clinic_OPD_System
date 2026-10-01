<?php
/**
 * ============================================================
 * services/external/InboundGuard.php — 入向安全校验网关守护
 * ============================================================
 * 说明：所有入向开放端点（FHIR/HL7/LIS/HIS/支付回调）统一鉴权：
 *  1. 启用开关校验（模块未启用直接拒绝）
 *  2. IP 白名单校验（支持单 IP 与 CIDR 网段，每行一个）
 *  3. 凭证校验（Bearer 头 / X-*-Token 头 / ?token= 参数，hash_equals 防时序），
 *     并补齐 API Key / Secret 的有效生命周期（过期时间）与启用/禁用状态；
 *  4. 权限范围（Scope）粒度化隔离：凭证必须具备接口所需 Scope，
 *     不足时统一 403，杜绝单一密钥全接口越权访问。
 *
 * 凭证配置格式：
 *  - 列表（allowed_tokens）每行「名称,Token[,过期时间][,启用(1/0)][,Scope空格分隔]」；
 *  - 单 Token 模块附加元数据键：{tokenKey}_expires_at / _enabled / _scopes。
 *  - Scope 未配置时默认 '*'（向后兼容既有单一密钥；建议按需最小化授权）。
 * 校验结果统一经 authorize() 结构化返回，由调用方按模块语义渲染 JSON/FHIR。
 * ============================================================ */
class InboundGuard {

    /** 全量 Scope 通配 */
    const SCOPE_ALL = '*';

    /**
     * 通用入向鉴权（兼容旧调用：失败直接 json_fail）
     * @param string $module     模块标识（写日志用）
     * @param string $enabledKey 启用键（完整键名）
     * @param string $tokenKey   鉴权 Token 配置键名（为空跳过 Token 校验）
     * @param string $ipKey      IP 白名单配置键名（为空跳过 IP 校验）
     * @param string $legacyToken 旧版键回退（如 his_api_key，可为空）
     * @return bool
     */
    public static function check($module, $enabledKey = '', $tokenKey = '', $ipKey = '', $legacyToken = '') {
        $r = self::authorize($module, array(
            'enabledKey' => $enabledKey, 'tokenKey' => $tokenKey, 'ipKey' => $ipKey, 'legacyToken' => $legacyToken,
        ));
        if (!$r['ok']) {
            integration_log_inbound('inbound', $module, false, $r['msg'], '');
            json_fail($r['msg']);
        }
        return true;
    }

    /**
     * 统一授权评估（结构化返回，供控制器按模块语义渲染）
     * @param string $module 模块标识
     * @param array  $opts   {
     *   enabledKey, ipKey, tokenKey, legacyToken, listKey,
     *   requiredScope, skipApiGuard(bool 仅开关+IP，不校验 token)
     * }
     * @return array { ok:bool, http:int, code:string, msg:string, caller:string, scopes:string }
     */
    public static function authorize($module, $opts) {
        $opts = is_array($opts) ? $opts : array();
        $enabledKey = isset($opts['enabledKey']) ? (string)$opts['enabledKey'] : '';
        $ipKey = isset($opts['ipKey']) ? (string)$opts['ipKey'] : '';
        $tokenKey = isset($opts['tokenKey']) ? (string)$opts['tokenKey'] : '';
        $listKey = isset($opts['listKey']) ? (string)$opts['listKey'] : '';
        $legacy = isset($opts['legacyToken']) ? (string)$opts['legacyToken'] : '';
        $requiredScope = isset($opts['requiredScope']) ? (string)$opts['requiredScope'] : '';
        $skipApiGuard = !empty($opts['skipApiGuard']);

        // ① 启用开关
        if ($enabledKey !== '' && !integration_flag($enabledKey)) {
            return self::fail(403, 'forbidden', $module . ' 入向接口未启用');
        }
        // ② IP 白名单
        if ($ipKey !== '' && !self::ipAllowed(setting($ipKey, ''))) {
            integration_log_inbound('inbound', $module, false, 'IP 白名单拒绝', '');
            return self::fail(403, 'forbidden', '来源 IP 不在白名单内');
        }
        // ③ 凭证校验（skipApiGuard 时仅做开关+IP）
        if ($skipApiGuard) {
            return self::pass('', self::SCOPE_ALL);
        }
        if ($listKey === '' && $tokenKey === '') {
            return self::pass('', self::SCOPE_ALL);
        }

        $entry = null;
        if ($listKey !== '') {
            $entry = self::findListEntry($listKey, self::providedToken());
        } else {
            $entry = self::findSingleEntry($tokenKey, $legacy);
        }
        if (!$entry) {
            return self::fail(401, 'invalid_token', '鉴权失败：Token 无效或缺失');
        }
        $state = self::entryState($entry);
        if ($state === 'disabled') {
            return self::fail(403, 'forbidden', '鉴权失败：该凭证已禁用');
        }
        if ($state === 'expired') {
            return self::fail(401, 'invalid_token', '鉴权失败：该凭证已过期');
        }
        // ④ Scope 隔离
        $scopes = isset($entry['scopes']) && $entry['scopes'] !== '' ? $entry['scopes'] : self::SCOPE_ALL;
        if ($requiredScope !== '' && !self::scopeAllows($scopes, $requiredScope)) {
            return self::fail(403, 'forbidden', '无权限访问该接口（缺少 Scope：' . $requiredScope . '）');
        }
        return self::pass(isset($entry['name']) ? (string)$entry['name'] : '', $scopes);
    }

    /** 组装通过结果 */
    private static function pass($caller, $scopes) {
        return array('ok' => true, 'http' => 200, 'code' => '', 'msg' => '', 'caller' => (string)$caller, 'scopes' => (string)$scopes);
    }

    /** 组装失败结果 */
    private static function fail($http, $code, $msg) {
        return array('ok' => false, 'http' => (int)$http, 'code' => (string)$code, 'msg' => (string)$msg, 'caller' => '', 'scopes' => '');
    }

    /* ==================== 凭证解析与生命周期 ==================== */

    /**
     * 解析列表行「名称,Token[,过期][,启用][,Scope]」
     * @return array { name, token, expires, enabled, scopes }
     */
    public static function parseListEntry($line) {
        $parts = array_map('trim', explode(',', (string)$line));
        $name = isset($parts[0]) ? $parts[0] : '';
        $token = isset($parts[1]) ? $parts[1] : '';
        if ($token === '' && $name !== '') {
            // 兼容仅「Token」单列写法
            $token = $name;
            $name = '';
        }
        return array(
            'name' => $name,
            'token' => $token,
            'expires' => isset($parts[2]) ? $parts[2] : '',
            'enabled' => isset($parts[3]) ? $parts[3] : '',
            'scopes' => isset($parts[4]) ? $parts[4] : '',
        );
    }

    /**
     * 在 Token 列表中查找携带的 Token 对应条目
     * @return array|null
     */
    public static function findListEntry($listKey, $given) {
        $given = (string)$given;
        if ($given === '') return null;
        $text = trim((string)setting($listKey, ''));
        if ($text === '') return null;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $entry = self::parseListEntry($line);
            if ($entry['token'] !== '' && hash_equals($entry['token'], $given)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * 单 Token 模块条目（含可选生命周期元数据键）
     * @return array|null
     */
    public static function findSingleEntry($tokenKey, $legacyToken = '') {
        $expected = trim((string)integration_cfg($tokenKey, '', $legacyToken));
        if ($expected === '') return null;
        $given = self::providedToken();
        if ($given === '' || !hash_equals($expected, $given)) return null;
        $prefix = $tokenKey;
        return array(
            'name' => $prefix,
            'token' => $expected,
            'expires' => (string)setting($prefix . '_expires_at', ''),
            'enabled' => (string)setting($prefix . '_enabled', '1'),
            'scopes' => (string)setting($prefix . '_scopes', self::SCOPE_ALL),
        );
    }

    /**
     * 生命周期状态：ok / disabled / expired
     * @param array $entry
     * @return string
     */
    public static function entryState($entry) {
        $enabled = isset($entry['enabled']) ? trim((string)$entry['enabled']) : '';
        if ($enabled !== '' && in_array(strtolower($enabled), array('0', 'false', 'off', 'no', 'disabled'), true)) {
            return 'disabled';
        }
        $expires = isset($entry['expires']) ? trim((string)$entry['expires']) : '';
        if ($expires !== '' && $expires !== '0') {
            $ts = ctype_digit($expires) ? (int)$expires : (strtotime($expires) ?: 0);
            if ($ts > 0 && $ts < time()) return 'expired';
        }
        return 'ok';
    }

    /**
     * Scope 判定（支持通配）：与 FhirService::scopeAllows 单一实现，避免双份维护。
     * @param string $granted  已授权 Scope（逗号/空格分隔）
     * @param string $required 所需 Scope（如 system/Patient.read、patient:sync）
     * @return bool
     */
    public static function scopeAllows($granted, $required) {
        return FhirService::scopeAllows($granted, $required);
    }

    /* ==================== 以下为原基础能力（向后兼容） ==================== */

    /** 当前请求来源 IP */
    public static function currentIp() {
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * IP 白名单判定（支持单 IP / CIDR，多行/逗号/空格分隔，空串放行）
     * @param string $whitelist 配置文本
     * @return bool
     */
    public static function ipAllowed($whitelist) {
        $ip = self::currentIp();
        if ($ip === '') return false;
        $whitelist = trim((string)$whitelist);
        if ($whitelist === '') return true;   // 留空 = 不限制
        $items = preg_split('/[\s,;]+/', $whitelist) ?: array();
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') continue;
            if (self::ipInCidr($ip, $item)) return true;
        }
        return false;
    }

    /** IP 是否命中单 IP 或 CIDR 网段 */
    public static function ipInCidr($ip, $cidr) {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        list($net, $bits) = explode('/', $cidr, 2);
        $bits = (int)$bits;
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false) return false;
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return ($ipLong & $mask) === ($netLong & $mask);
    }

    /**
     * Token 校验：支持 X-{name}-Token 头 / Authorization Bearer / ?token= 参数
     * @param string $tokenKey     配置键
     * @param string $legacyToken  旧键回退（可为空）
     * @return bool
     */
    public static function tokenOk($tokenKey, $legacyToken = '') {
        $entry = self::findSingleEntry($tokenKey, $legacyToken);
        return $entry !== null && self::entryState($entry) === 'ok';
    }

    /** 从请求头 / Bearer / X-API-Key / GET 参数提取调用方携带的 Token */
    public static function providedToken() {
        // X-API-Key（FHIR/DICOMweb 兼容；不在 X-*-Token 规则内，需单独识别）
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $ak = trim((string)$_SERVER['HTTP_X_API_KEY']);
            if ($ak !== '') return $ak;
        }
        foreach ($_SERVER as $k => $v) {
            if (preg_match('/^HTTP_X_([A-Z0-9_]+)_TOKEN$/', $k)) {
                $tv = trim((string)$v);
                if ($tv !== '') return $tv;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                return trim($m[1]);
            }
        }
        // 兼容 form-urlencoded 下的 Authorization 参数（部分网关代理会剥离头）
        $t = trim((string)get('token', ''));
        if ($t !== '') return $t;
        $t = trim((string)get('api_key', ''));
        if ($t !== '') return $t;
        return '';
    }

    /** 调用方携带的 X-API-Key 头（FHIR 兼容） */
    public static function providedApiKey() {
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string)$_SERVER['HTTP_X_API_KEY']);
        }
        return '';
    }

    /**
     * 多组 Token 列表鉴权：请求携带的 Token 命中任一组且生命周期有效即通过。
     * @param string $listKey 配置键（如 integration.inbound.fhir.allowed_tokens）
     * @return bool
     */
    public static function tokenInList($listKey) {
        $entry = self::findListEntry($listKey, self::providedToken());
        return $entry !== null && self::entryState($entry) === 'ok';
    }
}
