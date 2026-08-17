<?php
/**
 * ============================================================
 * _init.php — AJAX 接口公共入口
 * ============================================================
 * 说明：所有接口文件开头 require 本文件：
 * 1. CSRF 令牌校验（POST 请求）
 * 2. 登录校验（未登录统一返回 JSON 错误）
 * 3. 角色权限校验：无关角色的用户无法调用其他科室的接口
 *    （admin 拥有全部权限）
 * ============================================================ */

// CSRF 防护（仅校验 POST）
CSRF::check();

// 登录校验
$__u = Auth::user();
if (!$__u) {
    json_fail('请先登录');
}

// 接口 → 允许角色 映射（不在映射中的接口任何登录用户均可调用）
$__roleMap = array(
    'admin'    => 'admin',
    'order'    => 'doctor',
    'record'   => 'doctor',
    'template' => 'doctor',
    'transfer' => 'doctor',
    'doctor'   => 'doctor',
    'cashier'  => 'cashier',
    'nurse'    => 'nurse',
    'lab'      => 'lab',
    'imaging'  => 'imaging',
    'pharmacy' => 'pharmacy',
);

if (isset($__roleMap[CURRENT_API]) && $__u['role'] !== 'admin' && $__u['role'] !== $__roleMap[CURRENT_API]) {
    json_fail('无权限访问该功能');
}

// 统一 action 参数
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
