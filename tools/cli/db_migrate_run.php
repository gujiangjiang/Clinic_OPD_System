<?php
/**
 * ============================================================
 * tools/cli/db_migrate_run.php — 数据库迁移后台执行任务
 * ============================================================
 * 说明：由 MigrationRunner::start() 以 nohup 后台启动，独立进程执行
 * 实际迁移（复用 DatabaseMigrator），逐表更新进度到 config.db，
 * 前端轮询 migration_status 展示进度条；支持取消（cancel_requested
 * 检查）与失败/成功状态落库。刷新页面不影响迁移进度。
 *
 * 用法：<php-runner> php-cli tools/cli/db_migrate_run.php <token>
 * ============================================================ */

error_reporting(E_ERROR | E_PARSE);   // 后台任务静默运行，错误写入状态
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/config/bootstrap.php';

require_once APP_ROOT . '/app/core/MigrationRunner.php';
require_once APP_ROOT . '/app/core/DatabaseMigrator.php';

$token = isset($argv[1]) ? trim((string)$argv[1]) : '';
$state = MigrationRunner::state();
if ($state['status'] !== 'running') {
    exit('no running migration');
}

try {
    // 源库：当前主库（config.db 指针仍为源驱动；迁移成功后才更新）
    $srcDriver = DatabaseManager::driver();
    // 表总数（进度分母）
    $totalTables = 0;
    $pdo = DatabaseManager::getMain();
    if ($srcDriver === 'sqlite') {
        $totalTables = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
    } else {
        $totalTables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    }
    // 迁移（每表完成回调：更新进度 + 取消检查）
    $result = DatabaseMigrator::migrate($state['to'], $state['to_params'], function ($table, $tableTotal, $doneRows) use (&$state, $totalTables) {
        // 每次进度更新后重新读取状态（并发取消请求实时生效）
        $state = MigrationRunner::state();
        if ($state['status'] === 'running' && $state['cancel_requested']) {
            // 取消：中止迁移，主库指针未改（回退原库）
            throw new Exception('__CANCEL__');
        }
        $state['done_tables']++;
        $state['done_rows'] = $doneRows;
        $state['total_tables'] = $totalTables;
        $state['current_table'] = $table;
        MigrationRunner::save($state);
    });
    // 成功：状态置 done（主库指针保持原库，由管理员在锁定页确认后再切换）
    $state = MigrationRunner::state();
    if ($state['status'] === 'running' && $state['cancel_requested']) {
        $state = MigrationRunner::state();
        MigrationRunner::cancelled($state);
        exit('cancelled');
    }
    MigrationRunner::success($state);
    exit('done:' . $result['rows']);
} catch (Exception $ex) {
    $state = MigrationRunner::state();
    if ($state['status'] === 'running' && $state['cancel_requested']) {
        MigrationRunner::cancelled($state);
        exit('cancelled');
    }
    $err = $ex->getMessage();
    // 取消请求经异常中止（__CANCEL__）→ 置 cancelled
    if ($err === '__CANCEL__') {
        MigrationRunner::cancelled($state);
        exit('cancelled');
    }
    MigrationRunner::fail($state, $err);
    exit('failed:' . $err);
}