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
 * ============================================================ */
function setting($key, $default = '') {
    $v = DB::val('SELECT svalue FROM settings WHERE skey=?', array($key));
    return $v === null ? $default : $v;
}

function set_setting($key, $value) {
    DB::exec('INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)', array($key, (string)$value));
}