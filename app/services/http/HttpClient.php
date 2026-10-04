<?php
/**
 * ============================================================
 * services/http/HttpClient.php — 轻量 HTTP 客户端（cURL 封装）
 * ============================================================
 * 说明：供 HIS/HL7(HTTP)/LIS/FHIR 出向驱动与外部接口调用统一使用：
 *  1. JSON 自动序列化 / 解析，headers 自定义
 *  2. 基础认证（basic/bearer/自定义头）、超时、SSL 校验开关
 *  3. 统一返回 array(status, headers, body)；网络异常抛 Exception
 * 无第三方依赖，cURL 扩展缺失时回退 file_get_contents（http/https 流式）。
 * ============================================================ */
class HttpClient {

    /**
     * 发起请求
     * @param string $method GET/POST/PUT
     * @param string $url    完整地址
     * @param array  $opts   { headers:[], json:mixed, body:string, timeout:int,
     *                        basic:[user,pass], bearer:string, ssl_verify:bool, raw_headers:bool }
     * @return array { status:int, headers:string, body:string }
     * @throws Exception
     */
    public static function request($method, $url, $opts = array()) {
        $method = strtoupper((string)$method);
        $timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 10;
        $headers = isset($opts['headers']) ? (array)$opts['headers'] : array();
        $sslVerify = isset($opts['ssl_verify']) ? (bool)$opts['ssl_verify'] : false;
        $body = '';

        if (isset($opts['json'])) {
            $body = json_encode($opts['json'], JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        } elseif (isset($opts['body'])) {
            $body = (string)$opts['body'];
        }

        if (isset($opts['basic'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($opts['basic'][0] . ':' . $opts['basic'][1]);
        }
        if (isset($opts['bearer']) && $opts['bearer'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $opts['bearer'];
        }

        // 防 HTTP 头注入：头值可来自管理员配置（令牌/自定义头），剥离 CR/LF
        foreach ($headers as $i => $h) {
            $headers[$i] = str_replace(array("\r", "\n"), '', (string)$h);
        }

        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $headers, $body, $timeout, $sslVerify);
        }
        return self::viaStream($method, $url, $headers, $body, $timeout, $sslVerify);
    }

    /** 通过 cURL 发起 */
    private static function viaCurl($method, $url, $headers, $body, $timeout, $sslVerify) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ));
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            self::closeCurl($ch);
            throw new Exception('HTTP 请求失败：' . $err . ' (HTTP ' . $code . ')');
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        self::closeCurl($ch);
        return array(
            'status' => $code,
            'headers' => substr($resp, 0, $hdrLen),
            'body' => substr($resp, $hdrLen),
        );
    }

    /**
     * 关闭 cURL 句柄
     * 说明：PHP 8.0+ 中 curl_close 已无实际作用，PHP 8.5 起标记为 Deprecated
     * （会写入告警日志）。此处按版本判断——仅 PHP < 8.5 调用，兼容 PHP 7.x
     * 释放句柄的同时，避免 PHP 8.5+ 产生 Deprecated 警告。
     * @param resource|CurlHandle $ch
     */
    private static function closeCurl($ch) {
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    }

    /** cURL 缺失时回退流式读取（http/https 包装头） */
    private static function viaStream($method, $url, $headers, $body, $timeout, $sslVerify) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($body),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => $sslVerify, 'verify_peer_name' => $sslVerify),
        ));
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new Exception('HTTP 流式请求失败（cURL 扩展缺失）：' . $url);
        }
        $status = 200;
        $hdrs = array();
        if (function_exists('http_get_last_response_headers')) {
            $hdrs = http_get_last_response_headers();
        }
        if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) {
            $status = (int)$m[1];
        }
        return array(
            'status' => $status,
            'headers' => implode("\n", $hdrs),
            'body' => $resp,
        );
    }
}