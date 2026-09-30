<?php
/**
 * ============================================================
 * tools/cli/hl7_mllp_server.php — HL7 v2 MLLP 守护进程（入向）
 * ============================================================
 * 说明：本系统作为 HL7 消息接收方（Inbound），监听 TCP 端口接收外部
 * 设备/系统（HIS/LIS/区域平台）以 MLLP 帧（0x0B…0x1C0x0D）推送的消息：
 *  - ORU^R01：观察结果回传——按 OBR 申请单号自动回填检验结果并出报告；
 *  - 其他消息（ADT 等）：应答 AA 接收确认（业务处理预留扩展）；
 *  - 严格按请求消息 MSH-10 回传 MSA|AA|... 应答报文。
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

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) continue;
    $peer = stream_socket_get_name($conn, true);
    $peerIp = ($pos = strrpos($peer, ':')) !== false ? substr($peer, 0, $pos) : $peer;
    $tag = $peerIp;
    if (!$ipAllowed($peerIp)) {
        fclose($conn);
        continue;
    }
    // 读取 MLLP 帧：0x0B 起始 … 0x1C 0x0D 帧尾
    $frame = '';
    $inFrame = false;
    $started = null;
    while (!feof($conn)) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') break;
        for ($i = 0; $i < strlen($chunk); $i++) {
            $byte = $chunk[$i];
            if (!$inFrame && $byte === chr(0x0B)) {
                $inFrame = true;
                $frame = '';
                $started = microtime(true);
                continue;
            }
            if ($inFrame) {
                if ($byte === chr(0x1C) && isset($chunk[$i + 1]) && $chunk[$i + 1] === chr(0x0D)) {
                    // 帧尾：解析并应答
                    $i++;   // 跳过 0x0D
                    $ackCode = 'AA';
                    $ackText = '';
                    $msg = str_replace("\r\n", "\r", $frame);
                    try {
                        $parsed = HL7MessageParser::parse($msg);
                        $msgType = isset($parsed['msh']['type']) ? $parsed['msh']['type'] : '';
                        if (strpos($msgType, 'ORU') === 0) {
                            $oru = HL7MessageParser::oruExtract($parsed);
                            if ($oru['ok']) {
                                $obs = array();
                                foreach ($oru['observations'] as $o) {
                                    $obs[] = array(
                                        'name' => $o['item_name'] !== '' ? $o['item_name'] : $o['item_code'],
                                        'value' => $o['value'],
                                        'unit' => $o['unit'],
                                        'ref_range' => $o['ref_range'],
                                        'flag' => $o['flag'],
                                    );
                                }
                                $res = LisService::applyObservationReport($oru['order_no'], $obs, '', '', '');
                                $ackText = $res['msg'];
                                echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ORU^R01 ' . $res['msg'] . "\n";
                            } else {
                                throw new Exception('ORU 消息缺少 OBR 申请单号');
                            }
                        } else {
                            $ackText = 'received:' . ($msgType !== '' ? $msgType : 'unknown');
                            echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 接收确认 ' . ($msgType !== '' ? $msgType : 'unknown') . "\n";
                        }
                    } catch (Exception $ex) {
                        $ackCode = 'AE';
                        $ackText = $ex->getMessage();
                        echo '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' 处理失败：' . $ex->getMessage() . "\n";
                    }
                    $ack = HL7MessageBuilder::ack($msg, $ackCode, $ackText !== '' ? $ackText : 'received');
                    fwrite($conn, chr(0x0B) . $ack . chr(0x1C) . chr(0x0D));
                    fclose($conn);
                    $inFrame = false;
                    break 2;
                }
                $frame .= $byte;
            }
        }
    }
    if (isset($conn) && is_resource($conn) && !feof($conn) && $inFrame) {
        fclose($conn);
    }
}