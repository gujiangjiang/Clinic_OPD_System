<?php
/**
 * ============================================================
 * helpers.d/evidence.php — 存证 / 电子签名扩展接口
 * ============================================================
 * 说明：为正式医疗机构提供电子病历与诊断证明的存证能力：
 *  - hash 模式：对文书内容计算 SHA-256 指纹入库（自证完整、防篡改比对）
 *  - http 模式：调用外部 CA/时间戳/存证服务 HTTP 接口，获取存证凭据
 * 配置入口：接口管理 → 存证 / 电子签名（evid_mode / evid_endpoint / evid_token / evid_signer）
 * 调用方：病历保存（record_save.php）与诊断证明开具（record_cert.php）
 * ============================================================ */

/**
 * 计算文书内容指纹（规范内容：记录类型 + 编号 + 正文 + 医生 + 时间，换行折叠为空格）
 * @param string $recordType  记录类型（initial/progress/certificate）
 * @param string $recordNo    记录编号（病历记录ID 或 诊断证明号）
 * @param string $content     文书正文（打印文本/诊断证明内容）
 * @param string $signer      签名人（医生/印章）
 * @param string $time        存证时间
 * @return string  SHA-256 十六进制摘要
 */
function evid_fingerprint($recordType, $recordNo, $content, $signer = '', $time = '') {
    $norm = preg_replace('/\s+/u', ' ', trim((string)$content));
    return hash('sha256', $recordType . '|' . $recordNo . '|' . $signer . '|' . $time . '|' . $norm);
}

/**
 * 执行存证：按配置模式计算指纹并（可选）调用外部存证服务
 * @param string $recordType  记录类型（initial/progress/certificate）
 * @param string $recordNo    记录编号
 * @param string $content     文书正文
 * @param string $extraMeta   附加元数据（JSON 字符串，随 hash 一并提交外部服务）
 * @return array  array('mode','hash','algo','token','signer','time')
 *                 mode 为 off 时返回 null（调用方跳过存证）
 */
function evid_sign($recordType, $recordNo, $content, $extraMeta = '') {
    $mode = (string)setting('evid_mode', 'off');
    if ($mode === 'off' || $mode === '') return null;
    $algo = 'SHA-256';
    $signer = (string)setting('evid_signer', '');
    $time = now_str();
    $hash = evid_fingerprint($recordType, $recordNo, $content, $signer, $time);
    $token = '';
    if ($mode === 'http') {
        $endpoint = trim((string)setting('evid_endpoint', ''));
        $apiToken = trim((string)setting('evid_token', ''));
        if ($endpoint !== '') {
            $token = evid_http_call($endpoint, $apiToken, array(
                'record_type' => $recordType,
                'record_no' => $recordNo,
                'hash' => $hash,
                'algo' => $algo,
                'signer' => $signer,
                'time' => $time,
                'meta' => $extraMeta,
            ));
        }
    }
    return array('mode' => $mode, 'hash' => $hash, 'algo' => $algo, 'token' => $token, 'signer' => $signer, 'time' => $time);
}

/**
 * 调用外部存证/签名服务（POST JSON，Bearer 令牌；失败返回空串不阻断主流程）
 * @param string $endpoint 服务地址
 * @param string $apiToken 服务令牌
 * @param array  $payload  提交数据
 * @return string 服务返回的存证凭据 token（解析响应 JSON 的 token 字段）
 */
function evid_http_call($endpoint, $apiToken, $payload) {
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'timeout' => 10,
        'header' => "Content-Type: application/json\r\n"
            . ($apiToken !== '' ? 'Authorization: Bearer ' . $apiToken . "\r\n" : '')
            . 'X-Evid-Key: ' . $apiToken . "\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true,
    )));
    $body = @file_get_contents($endpoint, false, $ctx);
    if ($body === false || $body === '') return '';
    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['token'])) return (string)$decoded['token'];
    return mb_substr($body, 0, 200);
}