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
/**
 * 敏感设置键判定（密钥/令牌/口令类不进入跨请求快照缓存）：
 * 命中即按「不缓存」处理——宁可多一次数据库查询，也避免密钥扩散到
 * file/redis/memcached 等共享缓存载体。
 * @param string $key 设置键名
 * @return bool
 */
function setting_is_sensitive_key($key) {
    $k = strtolower((string)$key);
    foreach (array('secret', 'private_key', 'password', 'passwd', 'pass', 'token', 'api_key', 'apikey', 'app_key', 'appkey', 'sign_key', 'hmac') as $needle) {
        if (strpos($k, $needle) !== false) return true;
    }
    return false;
}

function setting($key, $default = '') {
    // 未安装（安装向导完成第 2 步之前）不触碰主库，避免自动创建 clinic_main.db
    if (!ConfigStore::isSystemInstalled()) return $default;
    if (!isset($GLOBALS['__setting_table'])) {
        $all = Cache::get('cfg_settings', null);
        if (!is_array($all)) {
            $all = array();
            foreach (CoreRepository::q('SELECT skey, svalue FROM settings') as $r) {
                // 敏感键不入快照缓存（后续按需直查数据库）
                if (setting_is_sensitive_key($r['skey'])) continue;
                $all[$r['skey']] = $r['svalue'];
            }
            Cache::set('cfg_settings', $all, 300);
        }
        $GLOBALS['__setting_table'] = $all;
    }
    $t = $GLOBALS['__setting_table'];
    if (array_key_exists($key, $t)) return $t[$key];
    // 敏感键绕过快照：直接查库（低频调用），避免密钥写入共享缓存
    if (setting_is_sensitive_key($key)) {
        $v = CoreRepository::val('SELECT svalue FROM settings WHERE skey=?', array($key));
        return $v === null ? $default : $v;
    }
    return $default;
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