<?php
/**
 * ============================================================
 * helpers.d/time.php — 时间与时区统一助手（站点时区墙钟存储契约）
 * ============================================================
 * 存储契约与迁移目标（分阶段，详见 AGENTS.md「时间与时区铁律」）：
 *  - 当前（生产态）：所有 DATETIME 列存储「站点时区（系统设置 → 全站时区）
 *    墙钟字符串」，写入唯一来源 now_str()/today_str()；相对时间用 is_recent()
 *    等本文件助手统一 PHP 口径；打印快照固化时区（print_snapshots.tz）。
 *  - 目标态（UTC 存储）：数据库统一存 UTC（now_utc()），展示层经
 *    utc_to_local() 换算为站点时区；法律性单据按快照固化时区渲染，
 *    保证重打内容与开具时刻完全一致。
 *  - 已备好迁移工具：tools/cli/tz_migrate.php（plan/apply/rollback，
 *    含备份、清单与逐列校验），本文件提供其转换原语。
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
 * 站点时区名（系统设置 → 全站时区，bootstrap 已 date_default_timezone_set）。
 * @return string 如 'Asia/Shanghai'
 */
function site_tz() {
    return date_default_timezone_get();
}

/**
 * 指定时区在当前（或给定）时刻的 UTC 偏移串。
 * @param string|null $tz 时区名（缺省站点时区）
 * @return string 如 '+08:00'（非法时区回退 '+00:00'）
 */
function tz_offset_str($tz = null) {
    $tz = ($tz !== null && $tz !== '') ? (string)$tz : date_default_timezone_get();
    try {
        return (new DateTime('now', new DateTimeZone($tz)))->format('P');
    } catch (Exception $e) {
        return '+00:00';
    }
}

/** 当前 UTC 时刻串（UTC 目标态的写入来源；当前与 now_str() 并存备迁移） */
function now_utc() {
    return gmdate('Y-m-d H:i:s');
}

/**
 * 解析「指定时区墙钟」时间串 → epoch。
 * @param string|null $ts
 * @param string|null $tz 该时间串所属时区（缺省按当前站点时区解析，同 ts_epoch）
 * @return int|null 不可解析返回 null
 */
function ts_epoch_tz($ts, $tz = null) {
    $s = trim((string)$ts);
    if ($s === '') return null;
    if ($tz === null || $tz === '') return ts_epoch($s);
    try {
        return (new DateTime($s, new DateTimeZone((string)$tz)))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 「时区 A 墙钟」→ UTC 存储串：迁移工具与 UTC 目标态写入的核心原语。
 * @param string|null $ts 墙钟时间串
 * @param string|null $tz 该串所属时区（缺省站点时区；迁移存量时传「当时时区」，
 *                        法律性单据可传快照固化时区 print_snapshots.tz）
 * @return string 'Y-m-d H:i:s'（UTC）；不可解析返回 ''
 */
function local_to_utc($ts, $tz = null) {
    $t = ts_epoch_tz($ts, $tz);
    return $t === null ? '' : gmdate('Y-m-d H:i:s', $t);
}

/**
 * UTC 存储串 → 指定时区墙钟（展示层换算核心原语）。
 * @param string|null $ts UTC 时间串
 * @param string|null $tz 目标时区（缺省站点时区；法律性单据传快照固化时区）
 * @param string|null $fmt 输出格式（缺省 'Y-m-d H:i:s'）
 * @return string 不可解析返回原串
 */
function utc_to_local($ts, $tz = null, $fmt = null) {
    $t = ts_epoch_tz($ts, 'UTC');
    if ($t === null) return (string)$ts;
    $tz = ($tz !== null && $tz !== '') ? (string)$tz : date_default_timezone_get();
    try {
        $dt = new DateTime('@' . $t);
        $dt->setTimezone(new DateTimeZone($tz));
    } catch (Exception $e) {
        return (string)$ts;
    }
    return $dt->format($fmt !== null && $fmt !== '' ? $fmt : 'Y-m-d H:i:s');
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
