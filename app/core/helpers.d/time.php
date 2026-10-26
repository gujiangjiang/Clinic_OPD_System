<?php
/**
 * ============================================================
 * helpers.d/time.php — 时间与时区统一助手（站点时区墙钟存储契约）
 * ============================================================
 * 数据契约：所有 DATETIME 列存储「站点时区（系统设置 → 全站时区）的墙钟
 * 字符串」，写入唯一来源 now_str()/today_str()（PHP date 函数，跟随 bootstrap
 * 设置的站点时区）。
 *
 * 为什么相对时间必须走 PHP 端（本文件）：
 *  - SQLite 的时间函数 `localtime` 取**操作系统时区**（容器常为 UTC）；
 *  - MySQL / PostgreSQL 的 NOW()/时间戳转换取**会话时区**（默认系统时区）；
 *  - 二者与站点时区（如 Asia/Shanghai）不一致时会产生**整小时级偏斜**
 *    （历史 bug：宝塔容器 UTC + 站点上海 → 8 小时内旧心跳被误判「在线」）。
 * 因此：相对时间/租约一律 `time() - strtotime()` 并经本文件助手统一口径；
 * 禁止在业务 SQL 中使用 SQL 端时间函数（详见 AGENTS.md「时间与时区铁律」）。
 * ============================================================ */

/**
 * 解析「站点时区墙钟」时间串 → epoch 时间戳。
 * @param string|null $ts 形如 '2026-10-26 13:46:00'；空/不可解析返回 null
 * @return int|null
 */
function ts_epoch($ts) {
    $s = trim((string)$ts);
    if ($s === '') return null;
    $t = strtotime($s);
    return $t === false ? null : $t;
}

/**
 * 距当前经过的秒数（未来时间按 0 计——用于展示 / 排序，绝不出现负数）。
 * @param string|null $ts
 * @return int 不可解析时返回 PHP_INT_MAX（视为极旧）
 */
function seconds_ago($ts) {
    $t = ts_epoch($ts);
    if ($t === null) return PHP_INT_MAX;
    return max(0, time() - $t);
}

/**
 * 租约判定（fail-closed）：$ts 在最近 $seconds 秒内活跃返回 true。
 * 语义约定：
 *  - 空 / 不可解析 → false（视为失效）；
 *  - 未来时间超过 $skew 秒 → false——正常场景写入方与读取方是同一台服务器，
 *    不出现"未来心跳"；一旦出现（修改全站时区后历史墙钟被按新时区重新解释、
 *    时钟回拨等），宁可判定失效（下线）也不把陈旧数据误判为在线/占用；
 *  - 时钟微秒级偏差由 $skew（默认 5 秒）容忍。
 * @param string|null $ts      时间串（站点时区墙钟）
 * @param int         $seconds 租约时长（如大屏心跳 30、医生绑定 300）
 * @param int         $skew    允许的未来偏差秒数
 * @return bool
 */
function is_recent($ts, $seconds, $skew = 5) {
    $t = ts_epoch($ts);
    if ($t === null) return false;
    $diff = time() - $t;
    return $diff >= -(int)$skew && $diff <= (int)$seconds;
}
