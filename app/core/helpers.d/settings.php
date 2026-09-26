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
 * 跨请求缓存：整表快照存于 Cache（键 cfg_settings，TTL 300s），首个读取
 * 键时从缓存取整表（缺则查库回填）；同请求后续读取全部命中请求级内存，
 * 不重复访问缓存/数据库。set_setting 写入后同步快照并失效缓存键。
 * ============================================================ */
function setting($key, $default = '') {
    // 未安装（安装向导完成第 2 步之前）不触碰主库，避免自动创建 clinic_main.db
    if (!ConfigStore::isSystemInstalled()) return $default;
    if (!isset($GLOBALS['__setting_table'])) {
        $all = Cache::get('cfg_settings', null);
        if (!is_array($all)) {
            $all = array();
            foreach (DB::q('SELECT skey, svalue FROM settings') as $r) {
                $all[$r['skey']] = $r['svalue'];
            }
            Cache::set('cfg_settings', $all, 300);
        }
        $GLOBALS['__setting_table'] = $all;
    }
    $t = $GLOBALS['__setting_table'];
    return array_key_exists($key, $t) ? $t[$key] : $default;
}

function set_setting($key, $value) {
    DatabaseManager::upsertSetting(DatabaseManager::getMain(), $key, (string)$value);
    // 同步请求级整表快照（同请求内后续读取新值）
    if (isset($GLOBALS['__setting_table'])) $GLOBALS['__setting_table'][$key] = (string)$value;
    // 失效跨请求缓存整表（下次读取自动重查回填）
    Cache::del('cfg_settings');
    // 修改作息设置时失效请求级缓存，保证同请求内后续读取新值
    if (strpos((string)$key, 'work_') === 0) {
        work_schedule_reset();
    }
}