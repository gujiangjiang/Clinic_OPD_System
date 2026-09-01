<?php
/**
 * ============================================================
 * helpers.d/work.php — 医院作息时间 / 号源时段
 * ============================================================
 * 说明：医院作息四要素（settings 表）、夏令时切换、当前挂号时段
 * 状态判定、号源显示文本映射。由 helpers.php 统一加载，拆分后
 * 引用方式不变。
 * ============================================================ */

/* ============================================================
 * 医院作息时间（影响门诊号源开放时段；急诊 24 小时不受限）
 * 说明：
 * 1. 常规作息四要素存于 settings（work_am_start 等，HH:MM）
 * 2. 夏令时（此处指医院夏/冬季作息切换，非系统时区夏令时）：
 *    开启后可设置生效日期范围（MM-DD，支持跨年如 11-01~03-31）
 *    与夏令时作息四要素；命中日期范围时系统自动改用夏令时作息
 * ============================================================ */

/** 读取当前生效的作息（含夏令时判断），返回 HH:MM 四要素与 is_dst 标记 */
function work_schedule() {
    $w = array(
        'am_start' => setting('work_am_start', '08:00'),
        'am_end'   => setting('work_am_end', '12:00'),
        'pm_start' => setting('work_pm_start', '14:00'),
        'pm_end'   => setting('work_pm_end', '17:30'),
        'dst_enabled' => setting('dst_enabled', '0'),
        'dst_start'   => setting('dst_start', ''),
        'dst_end'     => setting('dst_end', ''),
        'dst_am_start' => setting('dst_am_start', ''),
        'dst_am_end'   => setting('dst_am_end', ''),
        'dst_pm_start' => setting('dst_pm_start', ''),
        'dst_pm_end'   => setting('dst_pm_end', ''),
    );
    $w['is_dst'] = '0';
    if ($w['dst_enabled'] === '1' && $w['dst_start'] !== '' && $w['dst_end'] !== '') {
        // 取 MM-DD 部分（兼容误存 YYYY-MM-DD）；跨年区间（起始>结束）任一命中即视为在范围内
        $now = date('m-d');
        $a = substr($w['dst_start'], -5);
        $b = substr($w['dst_end'], -5);
        $in = ($a <= $b) ? ($now >= $a && $now <= $b) : ($now >= $a || $now <= $b);
        if ($in) {
            $w['is_dst'] = '1';
            foreach (array('am_start', 'am_end', 'pm_start', 'pm_end') as $k) {
                if ($w['dst_' . $k] !== '') $w[$k] = $w['dst_' . $k];
            }
        }
    }
    return $w;
}

/**
 * 当前挂号时段状态（按生效作息判定）：
 *   before 未上班 / am 上午可挂 / noon 午休 / pm 下午可挂 / after 已下班
 */
function work_session_now() {
    $w = work_schedule();
    $t = date('H:i');
    if ($t < $w['am_start']) return 'before';
    if ($t <= $w['am_end']) return 'am';
    if ($t < $w['pm_start']) return 'noon';
    if ($t <= $w['pm_end']) return 'pm';
    return 'after';
}

/** 当前时段的提示文案（供接口与页面复用） */
function work_status_msg($state = null) {
    $w = work_schedule();
    if ($state === null) $state = work_session_now();
    switch ($state) {
        case 'before': return '今日挂号尚未开始，上午 ' . $w['am_start'] . ' 开始放号';
        case 'noon':   return '午休中：上午号源已截止，下午 ' . $w['pm_start'] . ' 开始放号';
        case 'after':  return '今日已下班，门诊挂号已结束（急诊 24 小时可挂）';
        default:       return '';
    }
}

/**
 * 号源显示文本：将存储的 session 值映射为前端显示文本
 * 存储值：'am' 上午 / 'pm' 下午 / 'all' 昼夜
 * 兼容旧数据中已存储的 '上午'/'下午'/'昼夜' 直接透传
 */
function session_display_text($session) {
    if ($session === 'am') return '上午';
    if ($session === 'pm') return '下午';
    if ($session === 'all') return '昼夜';
    return (string)$session;
}