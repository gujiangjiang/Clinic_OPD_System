<?php
/**
 * ============================================================
 * tools/schema/migrate_integration_keys.php — 外部接口配置键迁移
 * ============================================================
 * 说明：将历史「接口管理」平铺键（his_/pacs_/hl7_/fhir_/yibao_ 前缀）迁移到
 * 双向两舱命名空间 integration.outbound.* / integration.inbound.* 下。
 * 仅迁移旧键存在且新键为空/不存在的条目，幂等可重复执行；
 * 历史键保留不删除（向后兼容旧读取代码），迁移完成后由监控面板核对。
 *
 * 用法：~/.local/bin/frankenphp php-cli tools/schema/migrate_integration_keys.php
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

if (!ConfigStore::isSystemInstalled()) {
    exit('系统未安装，跳过配置迁移');
}

/* ---------- 迁移映射：新键 => (旧键, 可选的旧值转换回调) ---------- */
$map = array(
    // FHIR：出向 Client Push
    'integration.outbound.fhir.remote_endpoint' => 'fhir_endpoint',
    'integration.outbound.fhir.auth_type'       => 'fhir_auth_type',
    'integration.outbound.fhir.client_token'    => 'fhir_token',
    // PACS：出向 DICOMweb / DIMSE
    'integration.outbound.pacs.wado_url'        => 'pacs_wado_url',
    'integration.outbound.pacs.viewer_url'      => 'pacs_viewer_url',
    'integration.outbound.pacs.remote_host'     => 'pacs_server_host',
    'integration.outbound.pacs.remote_port'     => 'pacs_server_port',
    // 旧 pacs_ae_title 语义为「本系统侧 AETitle」→ 迁移为入向 SCP 本地 AETitle
    'integration.inbound.pacs.local_ae_title'   => 'pacs_ae_title',
    // HL7 v2：传输通道（旧值 mllp/http → 新值 mllp_tcp/http_post）+ 消息头
    'integration.outbound.hl7.transport'        => array('hl7_transport', function ($v) {
        return $v === 'http' ? 'http_post' : 'mllp_tcp';
    }),
    'integration.outbound.hl7.sending_app'      => 'hl7_sending_app',
    'integration.outbound.hl7.receiving_app'    => 'hl7_receiving_app',
    // HL7 接收地址 host:port 拆分
    'integration.outbound.hl7.remote_host'      => array('hl7_receiver_url', function ($v) {
        $p = strrpos($v, ':');
        return $p === false ? $v : substr($v, 0, $p);
    }),
    'integration.outbound.hl7.remote_port'      => array('hl7_receiver_url', function ($v) {
        $p = strrpos($v, ':');
        return $p === false ? '' : substr($v, $p + 1);
    }),
    // HIS：出向适配器与网关
    'integration.outbound.his.hospital_code'    => 'his_system_code',
    // HIS：入向鉴权密钥（旧接口密钥迁移为入向 HIS 推送鉴权 Token）
    'integration.inbound.his.token'             => 'his_api_key',
    // 医保：出向前置机
    'integration.outbound.insurance.gateway_url'  => 'yibao_endpoint',
    'integration.outbound.insurance.fixmedins_code' => 'yibao_org_code',
    'integration.outbound.insurance.secret_key'   => 'yibao_operator_pwd',
);

$migrated = array();
$skipped = array();
foreach ($map as $newKey => $src) {
    $oldKey = is_array($src) ? $src[0] : $src;
    $conv = is_array($src) ? $src[1] : null;
    // 新键已有值：跳过（以新配置为准）
    $newVal = (string)setting($newKey, '');
    if ($newVal !== '') {
        $skipped[] = $newKey;
        continue;
    }
    $oldVal = (string)setting($oldKey, '');
    if ($oldVal === '') {
        continue;
    }
    if ($conv !== null) {
        $oldVal = $conv($oldVal);
    }
    if ($oldVal === '') {
        continue;
    }
    set_setting($newKey, $oldVal);
    $migrated[] = $newKey . '  ←  ' . $oldKey;
}

// PACS 协议模式默认推断：存在 WADO/DICOMweb 地址→dicomweb；否则有 TCP 参数→dimse
if ((string)setting('integration.pacs.protocol_mode', '') === '') {
    $hasWeb = (string)setting('integration.outbound.pacs.wado_url', '') !== '';
    $hasTcp = (string)setting('integration.outbound.pacs.remote_host', '') !== ''
        || (string)setting('integration.outbound.pacs.remote_port', '') !== '';
    if ($hasWeb || $hasTcp) {
        set_setting('integration.pacs.protocol_mode', $hasWeb ? 'dicomweb' : 'dimse');
        $migrated[] = 'integration.pacs.protocol_mode  ←  自动推断(' . ($hasWeb ? 'dicomweb' : 'dimse') . ')';
    }
}

echo "迁移完成：\n";
foreach ($migrated as $m) { echo '  · ' . $m . "\n"; }
if (!$migrated) { echo "  无待迁移旧键（新配置已就绪或无历史数据）\n"; }
echo '跳过（新键已有值，保留现状）：' . count($skipped) . ' 项' . "\n";