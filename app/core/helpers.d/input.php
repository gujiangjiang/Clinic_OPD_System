<?php
/**
 * ============================================================
 * helpers.d/input.php — 输入参数读取 / 时间字符串
 * ============================================================
 * 说明：POST / GET / REQUEST 参数读取、当前时间 / 日期字符串。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/** 读取 POST 参数（自动去首尾空格） */
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/**
 * 读取 POST 原始值（不去除首尾空格）
 * 说明：密码等敏感输入必须原样读取，禁止 trim：
 * 用户输入的密码可能含前导/尾随空格（复制粘贴或误输入），
 * 一旦 trim 会导致长度校验误判（如实际输入9位被判定少于6位），
 * 且入库/校验的密码与用户实际输入不一致，造成无法登录。
 */
function post_raw($key, $default = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

/** 读取 GET 参数（自动去首尾空格） */
function get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/**
 * 读取 GET 或 POST 参数（兼容两种请求方式，自动去首尾空格）
 * 说明：表单弹窗（loadModal / Clinic.modal.load）统一通过 POST 提交，
 * 而部分老接口用 get() 读参数导致编辑弹窗永远拿到 id=0（空白表单）。
 * form 类接口一律改用本函数读取，GET / POST 均兼容。
 */
function req($key, $default = '') {
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}

/** 当前时间字符串（站点时区） */
function now_str($fmt = 'Y-m-d H:i:s') {
    return date($fmt);
}

/** 当前日期字符串 */
function today_str() {
    return date('Y-m-d');
}