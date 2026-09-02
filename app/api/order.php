<?php
/**
 * ============================================================
 * order.php — 开单接口（检验/检查/处置/处方）— 分发入口
 * ============================================================
 * 说明：按功能拆分到 parts/（沿用 admin parts 模式）：
 *   parts/order_read.php  读取（catalog/prev_items/print/visit_orders）
 *   parts/order_write.php 写入（submit 提交开单 / delete 删除）
 * 本文件保留公共引导与动作分发。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';

$u = Auth::user();

require __DIR__ . '/parts/order_read.php';
require __DIR__ . '/parts/order_write.php';

switch ($action) {
    case 'catalog':
    case 'prev_items':
    case 'visit_orders':
        order_part_read($action);
        break;

    case 'submit':
    case 'delete':
        order_part_write($action);
        break;

    default:
        json_fail('未知操作');
}
