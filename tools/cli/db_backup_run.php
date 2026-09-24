<?php
/**
 * ============================================================
 * tools/cli/db_backup_run.php — 定时/立即备份后台执行任务
 * ============================================================
 * 说明：读取 config.db 备份库配置（backup.driver + 参数），调用
 * DatabaseMigrator::backupTo 同步当前主库全部数据到备份库（不动主库
 * 指针），完成后写入 backup.last_date（今日已备份标记，供调度去重）。
 * 由 public/index.php 的定时调度检查触发（到点且当日未备份时启动）。
 * ============================================================ */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

require_once APP_ROOT . '/app/core/DatabaseMigrator.php';

$driver = ConfigStore::get('backup.driver', '');
if ($driver === '' || !ConfigStore::dbDriverValid($driver)) {
    exit('no backup config');
}
$params = array(
    'path' => ConfigStore::get('backup.' . $driver . '.path', ''),
    'host' => ConfigStore::get('backup.' . $driver . '.host', '127.0.0.1'),
    'port' => ConfigStore::get('backup.' . $driver . '.port', $driver === 'pgsql' ? '5432' : '3306'),
    'dbname' => ConfigStore::get('backup.' . $driver . '.dbname', ''),
    'user' => ConfigStore::get('backup.' . $driver . '.user', ''),
    'pass' => ConfigStore::get('backup.' . $driver . '.pass', ''),
);
try {
    $r = DatabaseMigrator::backupTo($driver, $params);
    // 写入备份时间戳（定时调度去重：当日已备份不再重复触发）
    ConfigStore::set('backup.last_at', now_str());
    ConfigStore::set('backup.last_date', date('Y-m-d'));
    ConfigStore::set('backup.last_result', 'ok');
    ConfigStore::set('backup.running', '');
    ConfigStore::resetCache();
    exit('backup done:' . $r['rows']);
} catch (Exception $ex) {
    ConfigStore::set('backup.last_result', 'fail');
    ConfigStore::set('backup.running', '');
    ConfigStore::resetCache();
    exit('backup fail:' . $ex->getMessage());
}