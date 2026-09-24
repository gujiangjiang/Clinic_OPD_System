<?php
/**
 * ============================================================
 * helpers.d/settings.php — 系统设置读写
 * ============================================================
 * 说明：基于 core 库 settings 表（skey → svalue 键值对）的
 * 读取与写入。由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/* ============================================================
 * 系统设置读写（core 库 settings 表）
 * ------------------------------------------------------------
 * 请求级按 key 缓存：同一请求多次读取同一键（如 setting('org_code')）
 * 不重复查库（仿 work_schedule 缓存模式）；set_setting 写入后同步
 * 刷新缓存与作息缓存。
 * ============================================================ */
function setting($key, $default = '') {
    // 未安装（安装向导完成第 2 步之前）不触碰主库，避免自动创建 clinic_main.db
    if (!ConfigStore::isSystemInstalled()) return $default;
    if (!isset($GLOBALS['__setting_cache'])) $GLOBALS['__setting_cache'] = array();
    if (array_key_exists($key, $GLOBALS['__setting_cache'])) {
        $v = $GLOBALS['__setting_cache'][$key];
        return $v === null ? $default : $v;
    }
    $v = DB::val('SELECT svalue FROM settings WHERE skey=?', array($key));
    $GLOBALS['__setting_cache'][$key] = $v;
    return $v === null ? $default : $v;
}

function set_setting($key, $value) {
    DatabaseManager::upsertSetting(DatabaseManager::getMain(), $key, (string)$value);
    // 同步请求级缓存（后续读取新值）
    if (isset($GLOBALS['__setting_cache'])) $GLOBALS['__setting_cache'][$key] = (string)$value;
    // 修改作息设置时失效请求级缓存，保证同请求内后续读取新值
    if (strpos((string)$key, 'work_') === 0) {
        work_schedule_reset();
    }
}