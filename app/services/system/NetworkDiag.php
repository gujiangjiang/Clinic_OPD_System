<?php
/**
 * ============================================================
 * services/system/NetworkDiag.php — 出向网络栈诊断（DNS + IPv4/IPv6 双栈）
 * ============================================================
 * 说明：出向集成（HIS / FHIR / PACS / HL7 / LIS）调用失败时，定位问题出在
 * 「域名解析」还是「某一协议栈出口」：典型场景是目标域名仅有 AAAA 记录
 * （纯 IPv6），而本机运行环境（如 Docker bridge 容器）没有 IPv6 出口，
 * 于是浏览器能访问域名、系统间互调填域名必失败、填内网 IPv4 则正常。
 * 约定：全部探测静默容错（@ + 短超时），绝不抛异常影响主流程；
 * HttpClient 失败消息经 brief() 拼接紧凑诊断，CLI 用 describe() 输出全量矩阵。
 * ============================================================ */
class NetworkDiag {

    /**
     * 诊断一个地址（URL 或裸 host[:port]）的解析与双栈连通性
     * @param string $url     目标地址
     * @param int    $timeout 单次 TCP 探测超时（秒）
     * @return array { ok, summary, verdict, host, port, is_ip, a:[], aaaa:[], v4, v6 }
     *               v4/v6 = array('ok'=>bool,'detail'=>string) 或 null（该栈无解析）
     */
    public static function describe($url, $timeout = 2) {
        $res = array('ok' => false, 'summary' => '', 'verdict' => '', 'host' => '', 'port' => 0,
                     'is_ip' => '', 'a' => array(), 'aaaa' => array(), 'v4' => null, 'v6' => null);
        $raw = trim((string)$url);
        if ($raw === '') {
            $res['summary'] = '地址为空';
            return $res;
        }

        // 拆 host / port（兼容完整 URL 与裸 host[:port]）
        $auth = $raw;
        if (preg_match('#^([a-z][a-z0-9+.\-]*://)?([^/?\#]+)#i', $raw, $m)) $auth = $m[2];
        $host = $auth;
        $port = 0;
        if (preg_match('#^\[(.+)\](:([0-9]+))?$#', $auth, $m6)) {          // [v6]:port
            $host = $m6[1];
            $port = isset($m6[3]) ? (int)$m6[3] : 0;
        } elseif (strpos($host, ':') !== false) {                          // host:port
            $p = strrpos($host, ':');
            $maybe = substr($host, $p + 1);
            if (ctype_digit($maybe)) { $port = (int)$maybe; $host = substr($host, 0, $p); }
        }
        if (strpos($raw, 'https://') === 0 && $port === 0) $port = 443;
        if ($port === 0) $port = 80;
        $res['host'] = $host;
        $res['port'] = $port;

        // IP 字面量直连：跳过解析环节
        $family = self::ipFamily($host);
        if ($family !== '') {
            $res['is_ip'] = $family;
            if ($family === '4') $res['a'] = array($host); else $res['aaaa'] = array($host);
            list($ok, $detail) = self::tcp('tcp://' . ($family === '6' ? '[' . $host . ']' : $host) . ':' . $port, $timeout);
            $res[$family === '4' ? 'v4' : 'v6'] = array('ok' => $ok, 'detail' => $detail);
            $res['ok'] = $ok;
            $res['verdict'] = 'IP 直连（IPv' . $family . '）' . ($ok ? '可达' : '不可达：' . $detail);
            $res['summary'] = $res['verdict'];
            return $res;
        }

        // DNS 解析：dns_get_record（A/AAAA）+ gethostbyname 兜底（覆盖 /etc/hosts 与容器 extra_hosts 映射）
        if (function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_A) ?: array()) as $r) {
                if (!empty($r['ip']) && !in_array($r['ip'], $res['a'])) $res['a'][] = $r['ip'];
            }
            foreach ((@dns_get_record($host, DNS_AAAA) ?: array()) as $r) {
                if (!empty($r['ipv6']) && !in_array($r['ipv6'], $res['aaaa'])) $res['aaaa'][] = $r['ipv6'];
            }
        }
        $v4 = @gethostbyname($host);
        if ($v4 && $v4 !== $host && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !in_array($v4, $res['a'])) $res['a'][] = $v4;

        if (!$res['a'] && !$res['aaaa']) {
            $res['summary'] = $res['verdict'] = '域名解析失败（检查 DNS / hosts 配置）';
            return $res;
        }

        if ($res['a']) {
            list($ok, $detail) = self::tcp('tcp://' . $res['a'][0] . ':' . $port, $timeout);
            $res['v4'] = array('ok' => $ok, 'detail' => $detail);
        }
        if ($res['aaaa']) {
            list($ok, $detail) = self::tcp('tcp://[' . $res['aaaa'][0] . ']:' . $port, $timeout);
            $res['v6'] = array('ok' => $ok, 'detail' => $detail);
        }
        $v4ok = $res['v4'] ? $res['v4']['ok'] : null;
        $v6ok = $res['v6'] ? $res['v6']['ok'] : null;
        $res['ok'] = ($v4ok === true) || ($v6ok === true);

        $stack = 'A×' . count($res['a']) . ' AAAA×' . count($res['aaaa']);
        if ($v4ok === true && $v6ok === true) $res['verdict'] = 'IPv4/IPv6 双栈均可达';
        elseif ($v4ok === true && $v6ok === null) $res['verdict'] = 'IPv4 可达（目标无 IPv6 解析）';
        elseif ($v4ok === true && $v6ok === false) $res['verdict'] = 'IPv4 可达；IPv6 连接失败：' . $res['v6']['detail'];
        elseif ($v4ok === null && $v6ok === true) $res['verdict'] = '仅 IPv6 可达（目标无 IPv4 解析，本机 IPv6 出口正常）';
        elseif ($v4ok === false && $v6ok === true) $res['verdict'] = 'IPv6 可达；IPv4 连接失败：' . $res['v4']['detail'];
        elseif ($v4ok === null && $v6ok === false) {
            $res['verdict'] = '域名仅解析到 IPv6（无 A 记录），IPv6 连接失败：' . $res['v6']['detail']
                . ' —— 本机环境缺少 IPv6 出口时，系统间互调填域名不可达';
        } elseif ($v4ok === false && $v6ok === null) {
            $res['verdict'] = '仅解析到 IPv4，IPv4 连接失败：' . $res['v4']['detail'];
        } else {
            $res['verdict'] = 'IPv4 与 IPv6 均连接失败（' . $res['v4']['detail'] . ' / ' . $res['v6']['detail'] . '）';
        }
        $res['summary'] = $stack . '；' . $res['verdict'];
        return $res;
    }

    /** 紧凑诊断提示（拼接进出向失败消息；仅与双栈/解析相关的失败才返回非空） */
    public static function brief($url, $timeout = 2) {
        static $cache = array();
        $key = (string)$url;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $hint = '';
        $d = self::describe($url, $timeout);
        if ($d['is_ip'] === '' && $d['host'] !== '') {
            if (!$d['a'] && !$d['aaaa']) {
                $hint = '；诊断：域名 ' . $d['host'] . ' 解析失败（检查本机 DNS / hosts）';
            } elseif ($d['v4'] === null && $d['v6'] !== null && !$d['v6']['ok']) {
                $hint = '；诊断：域名 ' . $d['host'] . ' 仅解析到 IPv6（' . $d['aaaa'][0] . '），本机 IPv6 出口不可达（'
                    . $d['v6']['detail'] . '）——互调地址可改用内网 IPv4（如 http://10.0.0.91:端口），'
                    . '或按部署文档《IPv6 域名互调》修复（容器 hosts 映射 / 启用容器 IPv6 / 内网 DNS 分离）';
            } elseif ($d['v4'] !== null && $d['v6'] !== null && !$d['v4']['ok'] && !$d['v6']['ok']) {
                $hint = '；诊断：双栈均连接失败（检查网络与防火墙）';
            } elseif (($d['v4'] !== null && $d['v4']['ok']) || ($d['v6'] !== null && $d['v6']['ok'])) {
                $hint = '；诊断：复查双栈可达（可能为瞬时故障或超时过短）';
            }
        }
        $cache[$key] = $hint;
        return $hint;
    }

    /* ==================== 内部原语 ==================== */

    /** 判断主机名是否为 IP 字面量；返回 '4' / '6' / '' */
    private static function ipFamily($host) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '4';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return '6';
        return '';
    }

    /** TCP 连接探测（目标 tcp://host[:port]，IPv6 字面量自动加方括号）；返回 array(ok, detail) */
    private static function tcp($target, $timeout) {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($target, -1, $errno, $errstr, $timeout);
        if ($fp !== false) {
            fclose($fp);
            return array(true, '连接成功');
        }
        $errstr = trim((string)$errstr) !== '' ? $errstr : ('errno ' . $errno);
        if (strpos($errstr, 'refused') !== false || $errno === 111) return array(false, '连接被拒绝（对端端口未监听？）');
        if (strpos($errstr, 'Network is unreachable') !== false || $errno === 101
            || strpos($errstr, 'No route to host') !== false || $errno === 113) {
            return array(false, '网络不可达（本机无该协议栈出口/路由）');
        }
        if (strpos($errstr, 'timed out') !== false || strpos($errstr, 'timeout') !== false
            || $errno === 110 || $errstr === 'Connection timed out') {
            return array(false, '连接超时（被防火墙过滤？）');
        }
        return array(false, $errstr);
    }
}
