<?php
/**
 * ============================================================
 * helpers.d/pinyin.php — mbstring 兼容
 * ============================================================
 * 说明：mbstring 扩展缺失时的兼容函数（mb_strlen / mb_substr）。
 * 旧版拼音首字母生成（pinyin_initial，90 行映射表）已删除——
 * 种子 ICD 数据自带 pinyin 字段，全项目无任何调用点。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/* ============================================================
 * mbstring 扩展缺失时的兼容函数（仅在未加载时生效）
 * 说明：部分 PHP 7.x 环境（如精简镜像）未启用 mbstring 扩展，
 * 会导致 mb_strlen / mb_substr 报致命错误。这里提供基于
 * preg 的 UTF-8 兼容实现，保证系统在无 mbstring 时也能运行。
 * ============================================================ */
if (!function_exists('mb_strlen')) {
    /** UTF-8 安全的字符串长度（mbstring 缺失时使用） */
    function mb_strlen($str, $encoding = null) {
        if (preg_match_all('/./us', (string)$str, $m) > 0) {
            return count($m[0]);
        }
        return 0;
    }
}

if (!function_exists('mb_substr')) {
    /** UTF-8 安全的子串截取（mbstring 缺失时使用） */
    function mb_substr($str, $start, $length = null, $encoding = null) {
        $str = (string)$str;
        if (!preg_match_all('/./us', $str, $m)) {
            return '';
        }
        $chars = $m[0];
        $total = count($chars);
        // 负数 start 从末尾计算
        if ($start < 0) {
            $start = max(0, $total + $start);
        }
        if ($length === null || $length < 0) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, $length));
    }
}
