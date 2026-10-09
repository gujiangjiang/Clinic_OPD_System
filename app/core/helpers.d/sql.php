<?php
/**
 * ============================================================
 * helpers.d/sql.php — 运行时 SQL 方言辅助
 * ============================================================
 * 说明：schema / 迁移的方言翻译由 `DatabaseManager::dialectSqlFor()` 统一负责；
 * 本文件仅提供运行时业务查询所需的少量方言构造函数，避免在接口 / 视图中
 * 散落 `strftime` / epoch / `date()` 的驱动判断，保证 SQLite/MySQL/PG 三库可用。
 * 所有函数返回可直接拼入 SQL 的表达式片段，参数列名由调用方以内联白名单常量传入。
 * ============================================================ */

/**
 * 按日 / 月 / 年分组的时间表达式（跨库）：
 * SQLite strftime / MySQL DATE_FORMAT / PG TO_CHAR。
 * @param string $col  日期时间列名（调用方内联常量）
 * @param string $unit day|month|year
 * @return string
 */
function sql_date_group($col, $unit = 'day') {
    $unit = in_array($unit, array('day', 'month', 'year'), true) ? $unit : 'day';
    $driver = DatabaseManager::driver();
    if ($driver === 'mysql') {
        $fmt = array('day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y');
        return "DATE_FORMAT($col, '" . $fmt[$unit] . "')";
    }
    if ($driver === 'pgsql') {
        $fmt = array('day' => 'YYYY-MM-DD', 'month' => 'YYYY-MM', 'year' => 'YYYY');
        return "TO_CHAR($col, '" . $fmt[$unit] . "')";
    }
    $fmt = array('day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y');
    return "strftime('" . $fmt[$unit] . "', $col)";
}

/**
 * 取日期部分（跨库，等价 SQLite date($col)）；
 * PG 的 TEXT 时间列需经 timestamp 转换（结果 IMMUTABLE，可参与表达式索引）。
 * @param string $col 日期时间列名
 * @return string
 */
function sql_date_part($col) {
    $driver = DatabaseManager::driver();
    if ($driver === 'mysql') return "DATE($col)";
    if ($driver === 'pgsql') return "(($col)::timestamp)::date";
    return "date($col)";
}

/**
 * 距当前本地时间的秒数（跨库，等价 SQLite `strftime('%s','now','localtime') - strftime('%s', $col)`）。
 * @param string $col 日期时间列名
 * @return string
 */
function sql_seconds_since($col) {
    $driver = DatabaseManager::driver();
    if ($driver === 'mysql') return "(UNIX_TIMESTAMP() - UNIX_TIMESTAMP($col))";
    if ($driver === 'pgsql') return "(EXTRACT(EPOCH FROM now()) - EXTRACT(EPOCH FROM $col))";
    return "(strftime('%s','now','localtime') - strftime('%s', $col))";
}
