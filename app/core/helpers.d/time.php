<?php
/**
 * ============================================================
 * helpers.d/time.php — 时间与时区统一助手（站点时区墙钟存储契约）
 * ============================================================
 * 存储契约与迁移目标（分阶段，详见 AGENTS.md「时间与时区铁律」）：
 *  - 当前（生产态）：所有 DATETIME 列存储「站点时区（系统设置 → 全站时区）
 *    墙钟字符串」，写入唯一来源 now_str()/today_str()；相对时间用 is_recent()
 *    等本文件助手统一 PHP 口径；打印快照固化时区（print_snapshots.tz）。
 *  - 目标态（UTC 存储）：数据库统一存 UTC（now_str() 按模式感知写入），
 *    展示层经 fmt_time() 换算为站点时区；法律性单据按快照固化时区渲染，
 *    保证重打内容与开具时刻完全一致。
 *  - 迁移工具：tools/cli/tz_migrate.php（plan/apply/switch/rollback，含备份、
 *    清单与逐列校验），本文件提供其转换原语。
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
 * 解析「存储时间串」→ epoch 时间戳（模式感知）：
 *  - local 模式：按站点时区墙钟解析（与写入同口径）；
 *  - utc 模式：按 UTC 解析（存储即 UTC）。
 * @param string|null $ts 形如 '2026-10-26 13:46:00'；空/不可解析返回 null
 * @return int|null
 */
function ts_epoch($ts) {
    $s = trim((string)$ts);
    if ($s === '') return null;
    $t = time_storage_utc() ? strtotime($s . ' UTC') : strtotime($s);
    return $t === false ? null : $t;
}

/**
 * 展示换算（唯一展示入口）：存储值 → 用户可见的本地墙钟字符串。
 *  - local 模式：原样返回（存储即本地墙钟）；
 *  - utc 模式：按 $tz（法律性单据传快照固化时区）换算，缺省站点时区。
 * @param string|null $ts
 * @param string|null $tz 目标时区（缺省站点时区）
 * @return string
 */
function fmt_time($ts, $tz = null) {
    $s = (string)$ts;
    if ($s === '' || !time_storage_utc()) return $s;
    $out = utc_to_local($s, $tz);
    return $out !== '' ? $out : $s;
}

/**
 * 跨系统交换契约（外部接口铁律）：
 *  - **出站**：一律发送「站点时区墙钟」——存储值经 fmt_time() 换算；对外“现在”
 *    用 now_wall()；FHIR 时间由 FhirAdapter::isoDateTime() 单点输出「本地时间 +
 *    偏移」ISO 8601。外部系统（HIS/PACS/LIS/医保）从不接收 UTC 存储原值。
 *  - **入站**：外部系统一律按「站点时区墙钟」发送——经 wall_to_storage() 按当前
 *    存储口径落库（合同双方各自统一自己的时区设置；跨时区部署需另行约定）。
 * 以上与「本地墙钟写入」语境自洽：local↔utc 切换对外接口语义零变化。
 */

/**
 * 站点时区「现在」墙钟（对外交换的“现在”单一来源）：两模式输出一致。
 * @return string 'Y-m-d H:i:s'
 */
function now_wall() {
    return fmt_time(now_str());
}

/**
 * 跨系统入站墙钟 → 存储口径（出站反向为 fmt_time / now_wall）。
 *  - local 模式：恒等（老行为逐字节不变）；
 *  - utc 模式：按站点时区解释并换算为 UTC；
 *  - 空串返回 ''；非法串在 utc 模式返回 ''（调用方兜底 now_str()）。
 * @param string|null $s 外部系统发送的墙钟串
 * @return string
 */
function wall_to_storage($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (!time_storage_utc()) return $s;
    return local_to_utc($s, null);
}

/**
 * 业务日期标签（本地日的 Y-m-d）：存储值 → 站点时区的日期部分。
 * 用于分日展示/比较（local 模式直接截取；utc 模式先换算），
 * 与 reg_date 等「业务日期列」口径一致。
 * @param string|null $ts
 * @return string
 */
function local_date_of($ts) {
    $s = (string)$ts;
    if ($s === '') return '';
    if (!time_storage_utc()) return substr($s, 0, 10);
    return utc_to_local($s, null, 'Y-m-d');
}

/**
 * 本地日 [start, end) 的存储边界串（分日窗口查询统一用）：
 *  - local 模式：['2026-10-26 00:00:00', '2026-10-27 00:00:00']；
 *  - utc 模式：转换为对应 UTC 边界（[前一天 16:00, 当天 16:00) 之类）。
 * 查询写法：col >= ? AND col < ?（同时兼容两种模式，替代 date(col)=? 函数比较）。
 * @param string $ymd 本地日（Y-m-d）
 * @return array [start, end]
 */
function day_range($ymd) {
    $ymd = trim((string)$ymd);
    if ($ymd === '') return array('', '');
    $next = date('Y-m-d', strtotime($ymd . ' +1 day'));
    if (!time_storage_utc()) return array($ymd . ' 00:00:00', $next . ' 00:00:00');
    return array(local_to_utc($ymd . ' 00:00:00'), local_to_utc($next . ' 00:00:00'));
}

/**
 * 站点时区名（系统设置 → 全站时区，bootstrap 已 date_default_timezone_set）。
 * @return string 如 'Asia/Shanghai'
 */
function site_tz() {
    return date_default_timezone_get();
}

/**
 * 是否 UTC 存储模式。
 * 优先级：运行时覆盖（$GLOBALS['TIME_STORAGE_UTC_RUNTIME']，供安装向导 fresh、
 * 受控切换流程在同一请求内切换语义）＞ bootstrap 常量（config.db time.storage；
 * 未定义视为 local，CLI 工具/旧入口的兼容缺省）。
 * @return bool
 */
function time_storage_utc() {
    if (isset($GLOBALS['TIME_STORAGE_UTC_RUNTIME'])) return (bool)$GLOBALS['TIME_STORAGE_UTC_RUNTIME'];
    return defined('TIME_STORAGE_UTC') && TIME_STORAGE_UTC;
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

/**
 * 解析「指定时区墙钟」时间串 → epoch（**显式时区，绝不随存储模式变化**）。
 * 说明：这是 local_to_utc / utc_to_local 的纯转换底座——输入串一律按 $tz
 * （缺省站点时区）解释；与模式感知的 ts_epoch() 严格区分：
 * ts_epoch 解析「存储值」（utc 模式按 UTC），本函数解析「指定时区的墙钟」。
 * @param string|null $ts
 * @param string|null $tz 该时间串所属时区（缺省站点时区）
 * @return int|null 不可解析返回 null
 */
function ts_epoch_tz($ts, $tz = null) {
    $s = trim((string)$ts);
    if ($s === '') return null;
    $tz = ($tz !== null && $tz !== '') ? (string)$tz : date_default_timezone_get();
    try {
        return (new DateTime($s, new DateTimeZone($tz)))->getTimestamp();
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
