<?php
/**
 * ============================================================
 * parts/admin_audit.php v1.2.0 — 管理端：审核中心
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. audit_list  审核列表（待审核/已处理）
 *   2. audit       单条审核（通过 / 驳回，驳回可填写理由并通知提交者）
 *   3. audit_all   一键全部通过（待审核列表顶部按钮）
 * 审核结果通过站内消息通知提交者：驳回时附带理由，并携带跳转链接，
 * 提交者点击消息可回到添加页面回填之前提交的内容，修改后再次提交。
 * ============================================================ */

/**
 * 处理审核中心动作
 * @param string $action 动作名
 */
function admin_part_audit($action) {
    $u = Auth::user();

    /* ==================== 审核列表 ==================== */
    if ($action === 'audit_list') {
        $status = req('status', 'pending');
        if ($status === 'handled') {
            // 已处理页签：已通过 / 已驳回 / 已使用
            $rows = DB::q('core', "SELECT * FROM audits WHERE status IN ('approved','rejected','used') ORDER BY id DESC", array());
        } else {
            $status = 'pending';
            $rows = DB::q('core', 'SELECT * FROM audits WHERE status=? ORDER BY id DESC', array($status));
        }
        // 分组维度：'' 平铺 / user 按申请人 / type 按事项类型
        $group = req('group', '');
        $group = ($group === 'user' || $group === 'type') ? $group : '';
        // 可一键通过的常规待审核事项数（密码重置 / 报告撤回不纳入一键通过）
        $pendingCount = (int)DB::val('core', "SELECT COUNT(*) FROM audits WHERE status='pending' AND type NOT IN ('pwd_reset','report_withdraw')", array());
        $html = '<div class="fs-13 text-muted mb-8">' . ($status === 'pending' ? '待审核' : '已处理') . '：' . count($rows) . ' 条' .
            ($group ? '（按' . ($group === 'user' ? '申请人' : '类型') . '分组）' : '') . '</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">📋</div>暂无待审核事项</div>';
        } else {
            $typeNames = array(
                'template' => '病历模板', 'item_lab' => '检验项目添加', 'item_exam' => '检查项目添加',
                'item_drug' => '药品添加', 'item_disp' => '处置项目添加', 'report_withdraw' => '报告撤回',
                'pwd_reset' => '密码重置申请', 'profile_update' => '个人资料修改',
            );
            // 单条记录渲染（平铺与分组共用；分组视图可隐藏冗余列）
            $rowHtml = function ($r, $showType = true, $showProposer = true) use ($typeNames) {
                $h = '<tr>';
                if ($showType) {
                    $h .= '<td><span class="badge badge-primary">' . e(isset($typeNames[$r['type']]) ? $typeNames[$r['type']] : $r['type']) . '</span></td>';
                }
                $h .= '<td><div class="fw-600 fs-13">' . e($r['title']) . '</div><div class="fs-12 text-muted">' . e($r['content']) . '</div>' .
                    (!empty($r['creation_source']) ? '<div class="fs-12 mt-4"><span class="badge badge-warning">来源：' . e($r['creation_source']) . '</span></div>' : '') .
                    ($r['note'] ? '<div class="fs-12 mt-4" style="color:var(--danger)">驳回理由：' . e($r['note']) . '</div>' : '') . '</td>';
                if ($showProposer) {
                    $h .= '<td>' . e($r['proposer']) . '</td>';
                }
                $h .= '<td class="fs-12">' . e(substr($r['created_at'], 0, 16)) . '</td>' .
                    '<td>' . ($r['status'] === 'pending' ? '<span class="badge badge-warning">待审核</span>' : ($r['status'] === 'approved' ? '<span class="badge badge-success">已通过</span>' : ($r['status'] === 'used' ? '<span class="badge badge-gray">已使用</span>' : '<span class="badge badge-gray">已驳回</span>'))) . '</td>' .
                    '<td>';
                if ($r['status'] === 'pending') {
                    $h .= '<div class="flex gap-4">' .
                        '<button class="btn btn-success btn-sm" onclick="doAudit(' . (int)$r['id'] . ',1)">通过</button>' .
                        '<button class="btn btn-outline btn-sm" onclick="doAudit(' . (int)$r['id'] . ',0)">驳回</button></div>';
                } else {
                    $h .= '<span class="fs-12 text-muted">' . e($r['handled_by']) . ' ' . e(substr($r['handled_at'], 5, 11)) . '</span>';
                }
                $h .= '</td></tr>';
                return $h;
            };
            if ($group === '') {
                // —— 平铺列表 ——
                $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                    '<th>类型</th><th>事项</th><th>申请人</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= $rowHtml($r);
                }
                $html .= '</tbody></table></div>';
            } elseif ($group === 'user') {
                // —— 按申请人分组 ——
                $groups = array();
                foreach ($rows as $r) {
                    $groups[$r['proposer']][] = $r;
                }
                $html .= '<div class="flex-col gap-8">';
                foreach ($groups as $proposer => $list) {
                    $html .= '<div class="card"><div class="card-title" style="padding:10px 14px;border-bottom:1px solid var(--border)">' .
                        '👤 ' . e($proposer) . '<span class="fs-12 text-muted ml-8">' . count($list) . ' 条</span></div>' .
                        '<div class="table-wrap" style="border:none"><table class="table"><thead><tr>' .
                        '<th>类型</th><th>事项</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
                    foreach ($list as $r) {
                        $html .= $rowHtml($r, true, false);
                    }
                    $html .= '</tbody></table></div></div>';
                }
                $html .= '</div>';
            } else {
                // —— 按类型分组 ——
                $groups = array();
                foreach ($rows as $r) {
                    $key = isset($typeNames[$r['type']]) ? $typeNames[$r['type']] : $r['type'];
                    $groups[$key][] = $r;
                }
                $html .= '<div class="flex-col gap-8">';
                foreach ($groups as $typeName => $list) {
                    $html .= '<div class="card"><div class="card-title" style="padding:10px 14px;border-bottom:1px solid var(--border)">' .
                        '📂 ' . e($typeName) . '<span class="fs-12 text-muted ml-8">' . count($list) . ' 条</span></div>' .
                        '<div class="table-wrap" style="border:none"><table class="table"><thead><tr>' .
                        '<th>事项</th><th>申请人</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
                    foreach ($list as $r) {
                        $html .= $rowHtml($r, false, true);
                    }
                    $html .= '</tbody></table></div></div>';
                }
                $html .= '</div>';
            }
        }
        json_ok(array('html' => $html, 'pending_count' => $pendingCount));
    }

    /**
     * 执行单条审核（通过/驳回）
     * @param array  $audit   审核记录
     * @param int    $approve 1 通过 / 0 驳回
     * @param string $note    驳回理由
     */
    function audit_apply($audit, $approve, $note = '') {
        $u = Auth::user();
        $newStatus = $approve ? 'approved' : 'rejected';
        DB::exec('core', 'UPDATE audits SET status=?, handled_by=?, handled_at=?, note=? WHERE id=?', array($newStatus, $u['name'], now_str(), $note, (int)$audit['id']));
        $refId = (int)$audit['ref_id'];
        $proposerId = (int)$audit['proposer_id'];
        // 提交者角色（决定消息跳转链接指向哪个页面）
        $proposerRole = '';
        if ($proposerId > 0) {
            $pr = DB::one('user', 'SELECT role FROM users WHERE id=?', array($proposerId));
            $proposerRole = $pr ? $pr['role'] : '';
        }
        // 被驳回项目的回填页面链接（管理员在后台，检验/影像/药房在自己工作站）
        $backUrl = '';
        switch ($audit['type']) {
            case 'item_lab':
                $backUrl = '/admin/labitems?edit=' . $refId;
                break;
            case 'item_exam':
                $backUrl = '/admin/examitems?edit=' . $refId;
                break;
            case 'item_drug':
                $backUrl = '/admin/drugs?edit=' . $refId;
                break;
            case 'item_disp':
                $backUrl = '/admin/disposal?edit=' . $refId;
                break;
            case 'drugsetting':
                $backUrl = '/admin/drugsettings';
                break;
        }
        switch ($audit['type']) {
            case 'template':
                DB::exec('medical', 'UPDATE templates SET status=? WHERE id=?', array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg('doctor', $proposerId, '病历模板审核结果',
                        '您的病历模板「' . $audit['title'] . '」审核' . ($approve ? '已通过，现在可以使用' : '未通过：' . $note), '', '');
                }
                break;
            case 'item_lab':
                DB::exec('lab', 'UPDATE lab_items SET status=? WHERE id=?', array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg($proposerRole !== '' ? $proposerRole : 'doctor', $proposerId, '检验项目审核结果',
                        '您提交的检验项目「' . $audit['title'] . '」' . ($approve ? '已通过审核，可以开单使用' : '未通过审核，理由：' . $note . '（点击本消息回到添加页修改后重新提交）'),
                        '', '', array('msg_type' => 'system', 'link_url' => $backUrl));
                }
                break;
            case 'item_exam':
                DB::exec('lab', 'UPDATE exam_items SET status=? WHERE id=?', array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg($proposerRole !== '' ? $proposerRole : 'doctor', $proposerId, '检查项目审核结果',
                        '您提交的检查项目「' . $audit['title'] . '」' . ($approve ? '已通过审核，可以开单使用' : '未通过审核，理由：' . $note . '（点击本消息回到添加页修改后重新提交）'),
                        '', '', array('msg_type' => 'system', 'link_url' => $backUrl));
                }
                break;
            case 'item_drug':
                DB::exec('drug', 'UPDATE drugs SET status=? WHERE id=?', array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg($proposerRole !== '' ? $proposerRole : 'doctor', $proposerId, '药品审核结果',
                        '您提交的药品「' . $audit['title'] . '」' . ($approve ? '已通过审核，可以开方使用' : '未通过审核，理由：' . $note . '（点击本消息回到添加页修改后重新提交）'),
                        '', '', array('msg_type' => 'system', 'link_url' => $backUrl));
                }
                break;
            case 'item_disp':
                DB::exec('disp', 'UPDATE disposal_items SET status=? WHERE id=?', array($newStatus, $refId));
                if ($proposerId > 0) {
                    send_msg($proposerRole !== '' ? $proposerRole : 'doctor', $proposerId, '处置项目审核结果',
                        '您提交的处置项目「' . $audit['title'] . '」' . ($approve ? '已通过审核，可以开单使用' : '未通过审核，理由：' . $note . '（点击本消息回到添加页修改后重新提交）'),
                        '', '', array('msg_type' => 'system', 'link_url' => $backUrl));
                }
                break;
            case 'drugsetting':
                if ($approve) {
                    // 审核通过：解析提交数据并落库（新增/更新药品设置项）
                    $d = json_decode((string)$audit['data'], true);
                    if (is_array($d) && !empty($d['stype']) && !empty($d['name'])) {
                        $sId = (int)$d['id'];
                        $nn = (int)(isset($d['need_nurse']) ? $d['need_nurse'] : 0);
                        $bd = (int)(isset($d['bind_disposal_item_id']) ? $d['bind_disposal_item_id'] : 0);
                        if ($sId > 0) {
                            DB::exec('drug', 'UPDATE drug_settings SET name=?, need_nurse=?, bind_disposal_item_id=? WHERE id=?', array($d['name'], $nn, $bd, $sId));
                        } else {
                            DB::insert('drug', 'INSERT INTO drug_settings(stype, name, need_nurse, bind_disposal_item_id, sort) VALUES(?,?,?,?,0)', array($d['stype'], $d['name'], $nn, $bd));
                        }
                    }
                }
                if ($proposerId > 0) {
                    send_msg($proposerRole !== '' ? $proposerRole : 'pharmacy', $proposerId, '药品设置审核结果',
                        '您提交的药品设置「' . $audit['title'] . '」' . ($approve ? '已通过审核' : '未通过审核，理由：' . $note),
                        '', '', array('msg_type' => 'system'));
                }
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
                            '您申请的密码重置未通过管理员审核，理由：' . ($note !== '' ? $note : '未说明') . '，如有疑问请联系管理员。', '', '');
                    }
                }
                break;

            case 'profile_update':
                // 个人资料修改（学历/学位/介绍/头像）：通过则应用新值，拒绝/通过均站内消息通知
                $target = DB::one('user', 'SELECT * FROM users WHERE id=?', array($refId));
                if ($approve && $target) {
                    $upd = json_decode($audit['data'], true);
                    if (is_array($upd)) {
                        $set = array();
                        $params = array();
                        foreach ($upd as $k => $v) {
                            if (in_array($k, array('education', 'degree', 'intro', 'photo'), true)) {
                                $set[] = $k . '=?';
                                $params[] = $v;
                            }
                        }
                        if ($set) {
                            $params[] = $refId;
                            DB::exec('user', 'UPDATE users SET ' . implode(',', $set) . ' WHERE id=?', $params);
                        }
                    }
                    if ($proposerId > 0) {
                        send_msg($target['role'], $proposerId, '个人资料修改审核结果',
                            '您提交的个人资料修改申请已通过审核，学历/学位/个人介绍/头像已生效。', '', '');
                    }
                } elseif ($proposerId > 0) {
                    // 拒绝：若本次含新头像，删除已上传的待审文件（头像保持原样，自动还原）
                    $upd = json_decode($audit['data'], true);
                    if (is_array($upd) && !empty($upd['photo'])) {
                        $f = APP_ROOT . '/public/' . ltrim((string)$upd['photo'], '/');
                        if (strpos($f, APP_ROOT . '/public/uploads/') === 0 && is_file($f)) {
                            @unlink($f);
                        }
                    }
                    send_msg($target ? $target['role'] : 'user', $proposerId, '个人资料修改审核结果',
                        '您提交的个人资料修改申请未通过审核，理由：' . ($note !== '' ? $note : '未说明') . '。', '', '');
                }
                break;
        }
    }

    /* ==================== 执行单条审核 ==================== */
    if ($action === 'audit') {
        $id = (int)post('id');
        $approve = (int)post('approve', 0);
        $note = post('note', '');
        // 驳回必须填写理由
        if (!$approve && trim($note) === '') json_fail('请填写驳回理由，便于提交者修改后重新提交');
        $audit = DB::one('core', 'SELECT * FROM audits WHERE id=? AND status=?', array($id, 'pending'));
        if (!$audit) json_fail('审核事项不存在或已处理');
        audit_apply($audit, $approve, trim($note));
        json_ok(array(), $approve ? '已通过审核' : '已驳回（已通知提交者）');
    }

    /* ==================== 一键全部通过（待审核常规事项） ==================== */
    // 说明：逐条复用单条通过逻辑；密码重置（pwd_reset）与报告撤回（report_withdraw）
    // 涉及账号安全/报告作废，不纳入一键通过，需逐条人工审核。
    if ($action === 'audit_all') {
        $rows = DB::q('core', "SELECT * FROM audits WHERE status='pending' AND type NOT IN ('pwd_reset','report_withdraw') ORDER BY id DESC", array());
        if (!$rows) json_fail('当前没有可一键通过的事项');
        foreach ($rows as $a) {
            audit_apply($a, 1, '');
        }
        json_ok(array('count' => count($rows)), '已一键通过 ' . count($rows) . ' 条事项');
    }

    json_fail('未知操作');
}
