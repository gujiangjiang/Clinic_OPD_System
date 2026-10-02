<?php
/**
 * ============================================================
 * services/hl7/HL7Client.php — HL7 v2.x 消息传输客户端
 * ============================================================
 * 说明：出向 HL7 消息投递，支持两种传输通道：
 *  - mllp_tcp：MLLP over TCP（0x0B 帧头 + 消息 + 0x1C 0x0D 帧尾），
 *    同步等待外部 ACK 并解析 MSA 应答码（AA/AE/AR）；
 *  - http_post：HTTP(S) POST 推送（远程地址含协议头时直连，否则
 *    按 http://host:port 拼接）。
 * 配置取自 integration.outbound.hl7.*（transport/remote_host/remote_port/
 * timeout_seconds），旧键 hl7_transport/hl7_receiver_url 自动回退兼容。
 * ============================================================ */
class HL7Client {

    /**
     * 发送 HL7 消息并等待应答
     * @param string $message HL7 消息文本
     * @return array { ok:bool, msa_code:string, ack_text:string, error:string }
     */
    public static function send($message) {
        $transport = integration_hl7_transport();
        if ($transport === 'http_post') {
            return self::sendHttp($message);
        }
        return self::sendMllp($message);
    }

    /** MLLP over TCP 发送（强依赖底层 Socket，双向闭环 ACK） */
    private static function sendMllp($message) {
        $host = integration_cfg('outbound.hl7.remote_host', '', 'hl7_receiver_url');
        $port = (int)integration_cfg('outbound.hl7.remote_port', '0', 'hl7_receiver_port');
        if ($host === '') {
            return array('ok' => false, 'msa_code' => '', 'ack_text' => '', 'error' => 'HL7 远端主机未配置');
        }
        // 旧键 hl7_receiver_url 形如 host:port 时自动拆分
        if (strpos($host, ':') !== false && strpos($host, '://') === false && $port <= 0) {
            list($host, $port) = explode(':', $host, 2);
        }
        if ($port <= 0) $port = 2575;
        $timeout = (int)integration_cfg('outbound.hl7.timeout_seconds', '10');
        if ($timeout <= 0) $timeout = 10;

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, min($timeout, 10));
        if (!$fp) {
            return array('ok' => false, 'msa_code' => '', 'ack_text' => '', 'error' => 'MLLP 连接失败：' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($fp, $timeout);
        try {
            $frame = chr(0x0B) . str_replace("\n", "\r", $message) . chr(0x1C) . chr(0x0D);
            fwrite($fp, $frame);
            $ack = '';
            while (!feof($fp)) {
                $chunk = fread($fp, 4096);
                if ($chunk === false || $chunk === '') break;
                $ack .= $chunk;
                if (strpos($ack, chr(0x1C) . chr(0x0D)) !== false) break;
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) break;
            }
            if ($ack === '') {
                return array('ok' => false, 'msa_code' => '', 'ack_text' => '', 'error' => 'MLLP 未收到 ACK（超时）');
            }
            // 剥离 MLLP 帧包络（帧头 0x0B、帧尾 0x1C 0x0D），保留消息内部 \r 段分隔符
            $ackClean = $ack;
            $frameEnd = strpos($ackClean, chr(0x1C) . chr(0x0D));
            if ($frameEnd !== false) $ackClean = substr($ackClean, 0, $frameEnd);
            $ackClean = ltrim($ackClean, chr(0x0B));
            $parsed = HL7MessageParser::parse($ackClean);
            $code = HL7MessageParser::msaCode($parsed);
            if ($code === '') {
                return array('ok' => false, 'msa_code' => '', 'ack_text' => $ackClean, 'error' => '应答缺少 MSA 段');
            }
            $mismatch = self::controlMismatch($message, $parsed);
            if ($code === 'AA' && $mismatch !== '') {
                return array('ok' => false, 'msa_code' => $code, 'ack_text' => $ackClean, 'error' => $mismatch);
            }
            $ok = ($code === 'AA');
            return array('ok' => $ok, 'msa_code' => $code, 'ack_text' => $ackClean, 'error' => $ok ? '' : 'HL7 应答 MSA-' . $code);
        } finally {
            fclose($fp);
        }
    }

    /** HTTP(S) POST 推送 */
    private static function sendHttp($message) {
        $host = integration_cfg('outbound.hl7.remote_host', '', 'hl7_receiver_url');
        $port = (int)integration_cfg('outbound.hl7.remote_port', '0', 'hl7_receiver_port');
        if ($host === '') {
            return array('ok' => false, 'msa_code' => '', 'ack_text' => '', 'error' => 'HL7 远端地址未配置');
        }
        $url = $host;
        if (strpos($url, '://') === false) {
            $url = 'http://' . $url . ($port > 0 ? ':' . $port : '');
        }
        $timeout = (int)integration_cfg('outbound.hl7.timeout_seconds', '10');
        if ($timeout <= 0) $timeout = 10;
        try {
            $resp = HttpClient::request('POST', $url, array(
                'body' => str_replace("\n", "\r", $message),
                'timeout' => $timeout,
                'headers' => array('Content-Type: text/plain; charset=utf-8'),
            ));
            $parsed = HL7MessageParser::parse($resp['body']);
            $code = HL7MessageParser::msaCode($parsed);
            if ($code === '') {
                $ok = $resp['status'] >= 200 && $resp['status'] < 300;
                return array('ok' => $ok, 'msa_code' => '', 'ack_text' => $resp['body'], 'error' => $ok ? '' : 'HTTP ' . $resp['status']);
            }
            $mismatch = self::controlMismatch($message, $parsed);
            if ($code === 'AA' && $mismatch !== '') {
                return array('ok' => false, 'msa_code' => $code, 'ack_text' => $resp['body'], 'error' => $mismatch);
            }
            $ok = ($code === 'AA');
            return array('ok' => $ok, 'msa_code' => $code, 'ack_text' => $resp['body'], 'error' => $ok ? '' : 'HL7 应答 MSA-' . $code);
        } catch (Exception $ex) {
            return array('ok' => false, 'msa_code' => '', 'ack_text' => '', 'error' => $ex->getMessage());
        }
    }

    /** 校验 ACK 的 MSA-2 是否回显我方 MSH-10；不一致返回错误文本，正常返回 '' */
    private static function controlMismatch($sentMessage, $ackParsed) {
        $sent = HL7MessageParser::parse((string)$sentMessage);
        $sentCtrl = isset($sent['msh']['control_id']) ? (string)$sent['msh']['control_id'] : '';
        $ackCtrl = HL7MessageParser::msaControl($ackParsed);
        if ($sentCtrl !== '' && $ackCtrl !== '' && $ackCtrl !== $sentCtrl) {
            return 'ACK MSA-2（' . $ackCtrl . '）与原消息控制 ID 不一致';
        }
        return '';
    }
}