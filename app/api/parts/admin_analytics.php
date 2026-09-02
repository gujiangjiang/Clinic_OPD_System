<?php
/**
 * ============================================================
 * parts/admin_analytics.php v1.0.0 — 管理端：医院运营分析（分发器）
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分。口径统一为【已缴费】：
 *   · 项目收入：orders（status NOT IN refunded/cancelled），按 paid_at 落账日归属；
 *     order_type 映射——prescription 药费 / lab 检验费 / imaging 检查费 / procedure 处置费
 *   · 挂号费：payments(kind='visit')，按 created_at 落账日归属；
 *   · 门诊人次：registrations（status IN paid/visiting/finished）按 paid_at 归属；
 *   · 医生接诊人次：medical.patient_records 按 created_at（接诊时间）归属，谁接诊算谁。
 * 按动作拆分为 parts/admin_analytics/*.php，本文件仅保留函数分发，
 * 逻辑与拆分前完全一致。
 * ============================================================ */

require __DIR__ . '/admin_analytics/ana_helpers.php';
require __DIR__ . '/admin_analytics/ana_overview.php';
require __DIR__ . '/admin_analytics/ana_trend.php';
require __DIR__ . '/admin_analytics/ana_dept.php';
require __DIR__ . '/admin_analytics/ana_doctor.php';
require __DIR__ . '/admin_analytics/ana_custom.php';
require __DIR__ . '/admin_analytics/ana_disposition.php';

/**
 * 处理运营分析动作
 * @param string $action 动作名
 */
function admin_part_analytics($action) {
    if ($action === 'ana_overview') { admin_ana_overview(); return; }
    if ($action === 'ana_trend') { admin_ana_trend(); return; }
    if ($action === 'ana_dept') { admin_ana_dept(); return; }
    if ($action === 'ana_doctor') { admin_ana_doctor(); return; }
    if ($action === 'ana_custom') { admin_ana_custom(); return; }
    if ($action === 'ana_disposition') { admin_ana_disposition(); return; }
    json_fail('未知操作');
}