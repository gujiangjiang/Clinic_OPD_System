<?php
/**
 * ============================================================
 * parts/admin_audit.php v1.1.0 — 管理端：审核中心
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. audit_list  审核列表（待审核/已处理）
 *   2. audit       执行审核：模板/检验检查项目/药品/处置/报告撤回/密码重置
 * ============================================================ */

/**
 * 处理审核中心动作
 * @param string $action 动作名
 */
function admin_part_audit($action) {
    $u = Auth::user();

    /* ==================== 审核列表 ==================== */
    if ($action === 'audit_list') {
        $status = get('status', 'pending');
        if ($status === 'handled') {
            // 已处理页签：已通过 / 已驳回 / 已使用
            $rows = DB::q('core', "SELECT * FROM audits WHERE status IN ('approved','rejected','used') ORDER BY id DESC", array());
        } else {
            $status = 'pending';
            $rows = DB::q('core', 'SELECT * FROM audits WHERE status=? ORDER BY id DESC', array($status));
        }
        $html = '<div class="fs-13 text-muted mb-8">' . ($status === 'pending' ? '待审核' : '已处理') . '：' . count($rows) . ' 条</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">📋</div>暂无待审核事项</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>类型</th><th>事项</th><th>申请人</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            $typeNames = array(
                'template' => '病历模板', 'item_lab' => '检验项目添加', 'item_exam' => '检查项目添加',
                'item_drug' => '药品添加', 'item_disp' => '处置项目添加', 'report_withdraw' => '报告撤回',
                'pwd_reset' => '密码重置申请',
            );
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td><span class="badge badge-primary">' . e(isset($typeNames[$r['type']]) ? $typeNames[$r['type']] : $r['type']) . '</span></td>' .
                    '<td><div class="fw-600 fs-13">' . e($r['title']) . '</div><div class="fs-12 text-muted">' . e($r['content']) . '</div></td>' .
                    '<td>' . e($r['proposer']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 0, 16)) . '</td>' .
                    '<td>' . ($r['status'] === 'pending' ? '<span class="badge badge-warning">待审核</span>' : ($r['status'] === 'approved' ? '<span class="badge badge-success">已通过</span>' : ($r['status'] === 'used' ? '<span class="badge badge-gray">已使用</span>' : '<span class="badge badge-gray">已驳回</span>'))) . '</td>' .
                    '<td>';
                if ($r['status'] === 'pending') {
                    $html .= '<div class="flex gap-4">' .
                        '<button class="btn btn-success btn-sm" onclick="doAudit(' . (int)$r['id'] . ',1)">通过</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="doAudit(' . (int)$r['id'] . ',0)">驳回</button></div>';
                } else {
                    $html .= '<span class="fs-12 text-muted">' . e($r['handled_by']) . ' ' . e(substr($r['handled_at'], 5, 11)) . '</span>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 执行审核 ==================== */
    if ($action === 'audit') {
        $id = (int)post('id');
        $approve = (int)post('approve', 0);
        $audit = DB::one('core', 'SELECT * FROM audits WHERE id=? AND status=?', array($id, 'pending'));
        if (!$audit) json_fail('审核事项不存在或已处理');
        $newStatus = $approve ? 'approved' : 'rejected';
        DB::exec('core', "UPDATE audits SET status=?, handled_by=?, handled_at=?, note=? WHERE id=?", array($newStatus, $u['name'], now_str(), post('note'), $id));
        $refId = (int)$audit['ref_id'];
        $proposerId = (int)$audit['proposer_id'];
        switch ($audit['type']) {
            case 'template':
                DB::exec('medical', "UPDATE templates SET status=? WHERE id=?", array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg('doctor', $proposerId, '病历模板审核结果',
                        '您的病历模板审核' . ($approve ? '已通过' : '未通过') . ($approve ? '，现在可以使用' : '：' . post('note')), '', '');
                }
                break;
            case 'item_lab':
                DB::exec('lab', "UPDATE lab_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_exam':
                DB::exec('lab', "UPDATE exam_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_drug':
                DB::exec('drug', "UPDATE drugs SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'item_disp':
                DB::exec('disp', "UPDATE disposal_items SET status=? WHERE id=?", array($newStatus, $refId));
                break;
            case 'report_withdraw':
                if ($approve) {
                    // 批准撤回：报告作废，结果回到草稿，检验/检查项目回到已登记可重新录入
                    // 注意：分散式数据库下 results（lab 库）与 order_items（order 库）不可跨库子查询，
                    // 必须先从 results 取出 order_item_id，再更新 order 库
                    $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($refId));
                    if ($report) {
                        DB::exec('lab', "UPDATE reports SET status='withdrawn', withdraw_reason=?, withdraw_by=?, withdraw_at=? WHERE id=?", array($audit['content'], $u['name'], now_str(), $refId));
                        DB::exec('lab', "UPDATE results SET status='draft' WHERE id=?", array($report['result_id']));
                        $result = DB::one('lab', 'SELECT order_item_id FROM results WHERE id=?', array($report['result_id']));
                        if ($result && (int)$result['order_item_id'] > 0) {
                            DB::exec('order', "UPDATE order_items SET status='registered' WHERE id=?", array((int)$result['order_item_id']));
                        }
                    }
                }
                break;
            case 'pwd_reset':
                // 忘记密码：审核通过后重置为初始密码，并通知用户重新设置
                if ($approve) {
                    $target = DB::one('user', 'SELECT * FROM users WHERE id=?', array($refId));
                    if ($target) {
                        DB::exec('user', "UPDATE users SET password=?, pwd_changed=0 WHERE id=?", array(password_hash('123456', PASSWORD_DEFAULT), $refId));
                        send_msg($target['role'], $refId, '密码重置申请已通过',
                            '您申请的密码重置已通过管理员审核，密码已重置为初始密码，请点击下方【设置新密码】重新设置您的登录密码',
                            'pwd_reset', '');
                    }
                } else {
                    $target = DB::one('user', 'SELECT name FROM users WHERE id=?', array($refId));
                    if ($target) {
                        send_msg($target['role'], $refId, '密码重置申请未通过',
                            '您申请的密码重置未通过管理员审核，如有疑问请联系管理员。', '', '');
                    }
                }
                break;
        }
        json_ok(array(), $approve ? '已通过审核' : '已驳回');
    }

    json_fail('未知操作');
}
