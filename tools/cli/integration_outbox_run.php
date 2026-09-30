<?php
/**
 * ============================================================
 * tools/cli/integration_outbox_run.php — 出向同步任务后台执行 worker
 * ============================================================
 * 说明：消费 his_sync_tasks（Outbox）待办任务，按业务类型调用
 * HIS/FHIR/HL7/LIS 驱动投递：
 *  - 挂号/结算/发药 → HisDriverInterface（REST/JSON 或 SOAP/XML）
 *  - HL7 ADT/ORM    → HL7MessageBuilder + HL7Client（MLLP/HTTP + MSA 校验）
 *  - FHIR Bundle    → FhirService::pushVisitBundle
 *  - LIS 申请下发   → LisService::sendOrder
 * 成功置 success；500/超时等异常置 failed 并累加重试次数（供监控面板
 * 一键重试）。触发方式：业务入队后由 integration_spawn_worker 后台调用，
 * 也可由 cron 定时执行或监控面板手动触发。
 *
 * 用法：~/.local/bin/frankenphp php-cli tools/cli/integration_outbox_run.php
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

if (!ConfigStore::isSystemInstalled()) {
    exit('系统未安装，跳过');
}

$limit = isset($argv[1]) ? (int)$argv[1] : 20;
$res = HisOutbox::processPending($limit);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";