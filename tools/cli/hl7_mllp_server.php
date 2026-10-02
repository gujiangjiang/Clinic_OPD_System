<?php
/**
 * ============================================================
 * tools/cli/hl7_mllp_server.php — HL7 v2 MLLP 守护进程（入向）
 * ============================================================
 * 说明：本系统作为 HL7 消息接收方（Inbound），监听 TCP 端口接收外部
 * 设备/系统（HIS/LIS/区域平台）以 MLLP 帧（0x0B…0x1C0x0D）推送的消息：
 *  - ORU^R01：观察结果回传——解析 MSH/PID/PV1/OBR/OBX，按 OBR 申请单号
 *    自动回填检验结果并出报告；检测 OBX-8 异常标志（HH/LL/CRIT/PANIC）
 *    或危急值阈值命中时，自动写入 critical_values 流水并通知接诊医生；
 *  - 其他消息（ADT 等）：应答 AA 接收确认（业务处理预留扩展）；
 *  - 严格按请求消息 MSH-10 回传标准 ACK（MSA|AA/AE/AR + ERR 段）。
 * 监听端口取 integration.inbound.hl7.local_port（默认 2575），
 * IP 白名单（integration.inbound.hl7.ip_whitelist）与 HTTP 代理共用。
 * 启动：~/.local/bin/frankenphp php-cli tools/cli/hl7_mllp_server.php
 * 建议常驻：systemd / supervisor 托管。
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
set_time_limit(0);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

if (!ConfigStore::isSystemInstalled()) {
    exit('系统未安装，退出');
}
$port = (int)integration_cfg('inbound.hl7.local_port', '2575', 'hl7_local_port');
if ($port <= 0 || $port > 65535) {
    exit('HL7 监听端口无效（integration.inbound.hl7.local_port）');
}
$host = '0.0.0.0';

$server = @stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);
if (!$server) {
    exit('MLLP 监听启动失败：' . $errstr . ' (' . $errno . ')' . "\n");
}
echo 'HL7 MLLP 守护进程已启动：' . $host . ':' . $port . ' (' . now_str() . ')' . "\n";
echo '按 Ctrl+C 退出。' . "\n";

/** IP 白名单校验（配置为空放行） */
$ipAllowed = function ($ip) {
    $list = trim((string)setting('integration.inbound.hl7.ip_whitelist', ''));
    if ($list === '') return true;
    $items = preg_split('/[\s,;]+/', $list) ?: array();
    foreach ($items as $item) {
        if (InboundGuard::ipInCidr($ip, trim($item))) return true;
    }
    return false;
};

/**
 * 处理单条 MLLP 帧载荷，返回应回传的 ACK 文本。
 * 全程捕获异常：解析/落库失败 → AE + ERR；非法消息 → AR。
 * @param string $frame 去除帧头帧尾后的 HL7 消息
 * @param string $tag   对端标识（日志用）
 * @return string ACK 报文
 */
function mllp_handle($frame, $tag) {
    $msg = str_replace(array("\r\n", "\n"), "\r", (string)$frame);
    if (trim($msg) === '') {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 空帧，拒绝' . "\n";
        return HL7MessageBuilder::ack('', 'AR', '空消息帧');
    }
    $parsed = array();
    try {
        $parsed = HL7MessageParser::parse($msg);
        $msgType = isset($parsed['msh']['type']) ? $parsed['msh']['type'] : '';
        if ($msgType === '') {
            echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 缺少 MSH 段，拒绝' . "\n";
            return HL7MessageBuilder::ack($msg, 'AR', '无法解析 HL7 消息（缺少 MSH 段）');
        }
        if (strpos($msgType, 'ORU') === 0) {
            $oru = HL7MessageParser::oruExtract($parsed);
            if (!$oru['ok']) {
                echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ORU 解析失败（缺 OBR 申请单号）' . "\n";
                return HL7MessageBuilder::ack($msg, 'AE', 'ORU 消息缺少 OBR 申请单号');
            }
            $res = HL7InboundService::applyOru($oru);
            echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ORU^R01 ' . $res['msg']
                . ($res['critical'] > 0 ? '【危急值 ' . $res['critical'] . ' 条】' : '') . "\n";
            return HL7MessageBuilder::ack($msg, 'AA', $res['msg']);
        }
        // ADT 及其他消息：接收确认（业务处理预留扩展）
        echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 接收确认 ' . $msgType . "\n";
        return HL7MessageBuilder::ack($msg, 'AA', 'received:' . $msgType);
    } catch (Exception $ex) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 处理失败：' . $ex->getMessage() . "\n";
        return HL7MessageBuilder::ack($msg, 'AE', $ex->getMessage());
    }
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) continue;
    // 单连接空闲超时，防止半开/静默连接阻塞整个监听
    @stream_set_timeout($conn, 30);
    $peer = stream_socket_get_name($conn, true);
    $peerIp = ($pos = strrpos($peer, ':')) !== false ? substr($peer, 0, $pos) : $peer;
    $tag = $peerIp;
    if (!$ipAllowed($peerIp)) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' IP 白名单拒绝，断开' . "\n";
        fclose($conn);
        continue;
    }
    // 读取 MLLP 帧：0x0B 起始 … 0x1C 0x0D 帧尾（单连接可连发多帧）
    $frame = '';
    $inFrame = false;
    while (!feof($conn)) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') break;
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $byte = $chunk[$i];
            if (!$inFrame && $byte === chr(0x0B)) {
                $inFrame = true;
                $frame = '';
                continue;
            }
            if ($inFrame) {
                if ($byte === chr(0x1C)) {
                    // 帧尾：可选 0x0D；解析并回传 ACK，随后继续等待下一帧
                    if (isset($chunk[$i + 1]) && $chunk[$i + 1] === chr(0x0D)) $i++;
                    $ack = mllp_handle($frame, $tag);
                    fwrite($conn, chr(0x0B) . $ack . chr(0x1C) . chr(0x0D));
                    $frame = '';
                    $inFrame = false;
                    continue;
                }
                $frame .= $byte;
            }
        }
    }
    if (is_resource($conn)) fclose($conn);
}
