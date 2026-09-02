<?php
/**
 * ============================================================
 * parts/order_write.php — 开单：写入（分发器）
 * ============================================================
 * 按动作拆分为 parts/order/*.php（提交/删除），本文件仅保留函数分发，
 * 逻辑与拆分前完全一致。
 * ============================================================ */

require __DIR__ . '/order/order_submit.php';
require __DIR__ . '/order/order_delete.php';

function order_part_write($action) {
    $u = Auth::user();

    if ($action === 'submit') {
        order_part_submit($u);
        return;
    }
    if ($action === 'delete') {
        order_part_delete($u);
        return;
    }
}