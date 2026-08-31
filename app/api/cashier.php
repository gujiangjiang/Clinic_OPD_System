<?php
/**
 * ============================================================
 * cashier.php — 挂号收费处接口 — 分发入口
 * ============================================================
 * 说明：按功能拆分到 parts/（沿用 admin parts 模式）：
 *   parts/cashier_read.php  读取（home_stats/depts/reg_list/
 *                           visit_search/visit_detail/pay_orders）
 *   parts/cashier_write.php 写入（quick_name/register/pay_visit/
 *                           cancel_visit/refund_order）
 * 本文件保留公共引导、编号规则共享函数与动作分发。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/* ==================== 编号规则函数 ==================== */

/** 患者唯一ID：年月日 + 当日序号2位（25031101） */
function next_patient_no() {
    $ymd = date('ymd');
    $n = CashierRepository::countPatientsByPrefix($ymd);
    return $ymd . str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT);
}

/** 门诊流水号：年月日 + 当日序号4位（2503110001） */
function next_flow_no() {
    $ymd = date('ymd');
    $n = CashierRepository::countRegistrationsByPrefix($ymd);
    return $ymd . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** 门诊就诊序号：每科室每日3位独立递增（含退费/取消记录，序号不回收） */
function next_visit_seq($deptId) {
    return CashierRepository::countVisitSeq($deptId, today_str()) + 1;
}

/** 当日某科室某时段已用号源数 */
function dept_used_count($deptId, $session) {
    return CashierRepository::deptUsed($deptId, $session);
}

require __DIR__ . '/parts/cashier_read.php';
require __DIR__ . '/parts/cashier_write.php';

switch ($action) {
    case 'home_stats':
    case 'depts':
    case 'reg_list':
    case 'visit_search':
    case 'visit_detail':
    case 'pay_orders':
        cashier_part_read($action);
        break;

    case 'quick_name':
    case 'register':
    case 'pay_visit':
    case 'cancel_visit':
    case 'refund_order':
        cashier_part_write($action);
        break;

    default:
        json_fail('未知操作');
}
