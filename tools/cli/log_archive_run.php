<?php
/**
 * ============================================================
 * tools/cli/log_archive_run.php — 日志滑动窗口归档清理（三库自适应）
 * ============================================================
 * 说明：按保留天数淘汰 system_logs / inbound_events 等追加写日志表的超期数据，
 * 各驱动采用最低成本策略（MySQL 分区裁剪优先、PG 分批删除、SQLite 事务分批 +
 * incremental_vacuum），规避大事务锁死与存储膨胀。严格遵守【三库异构互迁零破坏】。
 *
 * 用法：
 *   <php-runner> php-cli tools/cli/log_archive_run.php
 *   <php-runner> php-cli tools/cli/log_archive_run.php --days=30 --batch=1000
 *   <php-runner> php-cli tools/cli/log_archive_run.php --table=system_logs --dry-run
 *
 * 参数：
 *   --days=N      保留天数（默认取设置 log.retention_days，再默认 7；0=不按时间清理）
 *   --batch=N     单批删除行数（默认 1000）
 *   --table=a,b   指定表（仅允许 system_logs,inbound_events；缺省全部）
 *   --dry-run     仅统计待清理行数，不删除
 *
 * 可由 cron 定时执行；亦可由后台调度按设置触发。
 * ============================================================ */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

if (!ConfigStore::isSystemInstalled()) {
    exit(json_encode(array('ok' => false, 'msg' => '系统未安装，跳过'), JSON_UNESCAPED_UNICODE) . "\n");
}

/* ---------- 解析参数 ---------- */
$opts = array('days' => null, 'batch' => 1000, 'table' => null, 'dry_run' => false);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
    if (strpos($arg, '--days=') === 0)  { $opts['days']  = (int)substr($arg, 7); continue; }
    if (strpos($arg, '--batch=') === 0) { $opts['batch'] = (int)substr($arg, 8); continue; }
    if (strpos($arg, '--table=') === 0) { $opts['table'] = substr($arg, 8); continue; }
}
// 未指定天数：取日志保留设置（默认 30）
if ($opts['days'] === null) {
    $opts['days'] = (int)LogService::cfg('log.retention_days', '7');
}

$res = LogArchiver::archive($opts['days'], $opts['batch'], $opts['dry_run'], $opts['table']);
$res['ok'] = true;
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
