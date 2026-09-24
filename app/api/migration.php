<?php
/**
 * ============================================================
 * api/migration.php — 数据库迁移状态/取消/切换（公开接口）
 * ============================================================
 * 说明：迁移期间全站锁定，本接口不校验登录（锁定页轮询进度、
 * 管理员取消、成功后切换主库均经此公开接口）：
 *   1. status   返回迁移状态与进度（锁定页轮询）
 *   2. cancel   请求取消迁移（校验管理员令牌，取消后回退原库）
 *   3. switch   迁移成功后确认切换主库到目标数据库
 *   4. done     迁移结果已处理（暂不切换，清除状态）
 * ============================================================ */

require_once APP_ROOT . '/app/core/MigrationRunner.php';

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

if ($action === 'status') {
    $s = MigrationRunner::state();
    json_ok(array(
        'status' => $s['status'],
        'from' => $s['from'],
        'to' => $s['to'],
        'total_tables' => (int)$s['total_tables'],
        'done_tables' => (int)$s['done_tables'],
        'total_rows' => (int)$s['total_rows'],
        'done_rows' => (int)$s['done_rows'],
        'current_table' => $s['current_table'],
        'error' => $s['error'],
        'started_at' => $s['started_at'],
        'finished_at' => $s['finished_at'],
    ));
}

if ($action === 'cancel') {
    $token = req('token', '');
    $r = MigrationRunner::cancel($token);
    if ($r['ok']) json_ok(array(), $r['msg']);
    json_fail($r['msg']);
}

if ($action === 'switch') {
    // 迁移成功后切换主库（config.db 指针更新到目标库），随后清除状态
    $r = MigrationRunner::switchMain();
    if ($r['ok']) json_ok(array(), $r['msg']);
    json_fail($r['msg']);
}

if ($action === 'done') {
    // 迁移成功但暂不切换：清除状态，继续使用原库
    MigrationRunner::confirm();
    json_ok(array(), '已保留原数据库，未切换');
}

json_fail('未知操作');