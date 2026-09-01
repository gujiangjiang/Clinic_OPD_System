<?php
/**
 * ============================================================
 * parts/record_write.php — 电子病历：写入（分发器）
 * ============================================================
 * 按动作拆分为 parts/record/*.php（新建续写/保存/体征/诊断排序/诊断），
 * 本文件仅保留函数分发，逻辑与拆分前完全一致。
 * ============================================================ */

require __DIR__ . '/record/record_create_progress.php';
require __DIR__ . '/record/record_save.php';
require __DIR__ . '/record/record_save_vitals.php';
require __DIR__ . '/record/record_save_diag_order.php';
require __DIR__ . '/record/record_save_diags.php';

function record_part_write($action) {
    $u = Auth::user();

    if ($action === 'create_progress') {
        record_part_create_progress($u);
        return;
    }
    if ($action === 'save') {
        record_part_save($u);
        return;
    }
    if ($action === 'save_vitals') {
        record_part_save_vitals($u);
        return;
    }
    if ($action === 'save_diag_order') {
        record_part_save_diag_order($u);
        return;
    }
    if ($action === 'save_diags') {
        record_part_save_diags($u);
        return;
    }
}