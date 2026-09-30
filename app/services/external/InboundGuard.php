<?php
/**
 * ============================================================
 * services/external/InboundGuard.php — 入向安全校验中间件
 * ============================================================
 * 说明：所有入向开放端点（FHIR/HL7/LIS/HIS/支付回调）统一鉴权：
 *  1. 启用开关校验（模块未启用直接拒绝）
 *  2. IP 白名单校验（支持单 IP 与 CIDR 网段，每行一个）
 *  3. Token 校验（Bearer 头 / X-*-Token 头 / ?token= 参数，hash_equals 防时序）
 * 校验失败按模块语义输出统一 JSON 错误或直接退出。
 * ============================================================ */
class InboundGuard {

    /**
     * 通用入向鉴权
     * @param string $module     模块标识（写日志用）
     * @param string $enabledKey integration.inbound.{module}.enabled 形式的启用键（完整键名）
     * @param string $tokenKey   鉴权 Token 配置键名（完整键名；为空跳过 Token 校验）
     * @param string $ipKey      IP 白名单配置键名（完整键名；为空跳过 IP 校验）
     * @param string $legacyToken 旧版键回退（如 his_api_key，可为空）
     * @return bool
     */
    public static function check($module, $enabledKey = '', $tokenKey = '', $ipKey = '', $legacyToken = '') {
        // ① 启用开关（未配置启用键的模块视为不强制开关）
        if ($enabledKey !== '' && !integration_flag($enabledKey)) {
            json_fail($module . ' 入向接口未启用');
        }
        // ② IP 白名单
        if ($ipKey !== '' && !self::ipAllowed(setting($ipKey, ''))) {
            integration_log_inbound('inbound', $module, false, 'IP 白名单拒绝', '');
            json_fail('来源 IP 不在白名单内');
        }
        // ③ Token 鉴权
        if ($tokenKey !== '' && !self::tokenOk($tokenKey, $legacyToken)) {
            integration_log_inbound('inbound', $module, false, 'Token 校验失败', '');
            json_fail('鉴权失败：Token 无效');
        }
        return true;
    }

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
     * @param string $tokenKey     配置键（integration.inbound.*.token / webhook_secret 等）
     * @param string $legacyToken  旧键回退（可为空）
     * @return bool
     */
    public static function tokenOk($tokenKey, $legacyToken = '') {
        $expected = trim((string)integration_cfg($tokenKey, '', $legacyToken));
        if ($expected === '') return false;   // 未配置密钥 → 一律拒绝
        $given = self::providedToken();
        return $given !== '' && hash_equals($expected, $given);
    }

    /** 从请求头 / Bearer / GET 参数提取调用方携带的 Token */
    public static function providedToken() {
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

    /**
     * 多组 Token 列表鉴权：每行「调用方名称,Token」（半角逗号分隔），
     * 请求携带的 Token 命中任一组即通过（hash_equals 防时序）。
     * @param string $listKey 配置键（如 integration.inbound.fhir.allowed_tokens）
     * @return bool
     */
    public static function tokenInList($listKey) {
        $text = trim((string)setting($listKey, ''));
        if ($text === '') return false;   // 未配置 → 拒绝全部
        $given = self::providedToken();
        if ($given === '') return false;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $pos = strpos($line, ',');
            $token = $pos === false ? trim($line) : trim(substr($line, $pos + 1));
            if ($token !== '' && hash_equals($token, $given)) return true;
        }
        return false;
    }
}