<?php
/**
 * ============================================================
 * parts/dept_common.php — 检验/影像科共享接口逻辑
 * ============================================================
 * 说明：imaging.php 与 lab.php 中完全相同的逻辑抽取至此，
 * 差异部分（result_form/save_result/item_save）保留在原文件。
 * ============================================================ */

/**
 * 医技角色（护士/检验/影像/药房）就诊归属校验（宽松版）。
 * 说明：未绑定科室（dept_ids 为空）= 全院放行；已绑科室须匹配就诊当前科室。
 */
function dept_visit_allowed($visit, $u) {
    if ($u['role'] === 'admin') return true;
    $myDepts = user_dept_ids($u);
    if (!$myDepts) return true;
    $visitDept = (int)(isset($visit['current_dept_id']) ? $visit['current_dept_id'] : 0);
    return $visitDept <= 0 || in_array($visitDept, $myDepts, true);
}

/** 科室首页统计 */
function dept_home_stats($itemType, $itemTable) {
    $today = today_str();
    $todayItems = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type=? AND date(created_at)=?", array($itemType, $today));
    $todayFee = (float)OrderRepository::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_type=? AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($itemType, $today));
    $pendingReg = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type=? AND status='paid'", array($itemType));
    $pendingRep = (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type=? AND status='registered'", array($itemType));
    $itemTotal = (int)OrderRepository::val("SELECT COUNT(*) FROM $itemTable WHERE status='approved'", array());
    $pendingAudit = (int)OrderRepository::val("SELECT COUNT(*) FROM $itemTable WHERE status='pending'", array());
    $trend = trend_7_days(function ($day) use ($itemType) {
        return (int)OrderRepository::val("SELECT COUNT(*) FROM order_items WHERE item_type=? AND date(created_at)=?", array($itemType, $day));
    });
    json_ok(array(
        'kpi' => array('today_items' => $todayItems, 'today_fee' => round($todayFee, 2),
            'pending_reg' => $pendingReg, 'pending_rep' => $pendingRep, 'item_total' => $itemTotal, 'pending_audit' => $pendingAudit),
        'trend' => $trend,
    ));
}

/** 科室队列列表（HTML） */
function dept_queue($itemType, $emoji, $registerClick, $resultClick) {
    $status = get('status', 'paid');
    $map = array('paid' => '待登记', 'registered' => '待出报告', 'done' => '已完成');
    $rows = OrderRepository::q("SELECT * FROM order_items WHERE item_type=? AND status=? ORDER BY id DESC LIMIT 200", array($itemType, $status));
    $html = '<div class="fs-13 text-muted mb-8">' . $map[$status] . '：' . count($rows) . ' 项</div>';
    if (!$rows) {
        $html .= '<div class="empty"><div class="empty-ico">' . $emoji . '</div>暂无' . $map[$status] . '项目</div>';
    } else {
        $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
            '<th>患者</th><th>' . ($itemType === 'lab' ? '检验' : '检查') . '项目</th><th>流水号</th><th>开单医生</th><th>开单时间</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $p = OrderRepository::one('SELECT name, gender, birth_date FROM patients WHERE patient_no=?', array($r['patient_no']));
            $html .= '<tr>' .
                '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . ($p ? age_format($p['birth_date']) : '—') . '</span></td>' .
                '<td>' . e($r['item_name']) . '</td>' .
                '<td>' . e($r['flow_no']) . '</td>' .
                '<td>' . e($r['doctor_name']) . '</td>' .
                '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                '<td>';
            if ($status === 'paid') {
                if ($registerClick !== '') {
                    $html .= '<button class="btn btn-primary btn-sm" onclick="' . $registerClick . '(\'' . e(oid($r['id'])) . '\')">登记</button>';
                } else {
                    // 无门诊侧登记入口（如影像：登记 / 摄片由 PACS 侧负责）
                    $html .= '<span class="fs-12 text-muted">待执行（PACS）</span>';
                }
            } elseif ($status === 'registered') {
                $html .= '<button class="btn btn-success btn-sm" onclick="' . $resultClick . '(\'' . e(oid($r['id'])) . '\')">' . ($itemType === 'lab' ? '录入结果' : '录入报告') . '</button>';
            } else {
                $report = OrderRepository::one('SELECT * FROM reports WHERE result_id=? AND status<>? ORDER BY id DESC', array((int)$r['result_id'], 'withdrawn'));
                if ($report) {
                    $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' . e(oid($report['id'])) . '\',null)">查看报告</button> ' .
                        '<button class="btn btn-outline btn-sm" onclick="withdrawReport(\'' . e(oid($report['id'])) . '\')">申请撤回</button>';
                } else {
                    $html .= badge_html('gray', '撤回审核中');
                }
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    json_ok(array('html' => $html));
}

/** 登记 */
function dept_register($itemType) {
    $itemId = did(post('item_id'));
    $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
    if (!$it || $it['item_type'] !== $itemType || $it['status'] !== 'paid') {
        json_fail('项目不存在或状态异常');
    }
    // 归属校验（与 register_order 口径一致：未绑定科室=全院放行）
    $ord = OrderRepository::one('SELECT visit_id FROM orders WHERE id=?', array((int)$it['order_id']));
    $rv = $ord ? get_visit_row((int)$ord['visit_id']) : null;
    if (!$rv || !dept_visit_allowed($rv['visit'], Auth::user())) json_fail('该患者不属于当前科室，不可登记');
    OrderRepository::exec("UPDATE order_items SET status='registered', registered_at=? WHERE id=?", array(now_str(), $itemId));
    // 说明（流程解耦）：影像登记仅标记「已缴费→已登记」，不解析 / 不产生 StudyInstanceUID；
    // 摄片与影像由 PACS 侧负责，影像引用在调阅 / 书写报告时按检查号从区域 PACS 解析。
    json_ok(array(), '登记成功');
}

/** 申请撤回报告（$deptNoun 影像/检验 用于审计文案：影像科/检验科） */
function dept_withdraw($deptNoun, $titleType) {
    $reportId = did(post('report_id'));
    $reason = post('reason', '');
    if ($reason === '') json_fail('请填写撤回原因');
    $report = OrderRepository::one('SELECT * FROM reports WHERE id=?', array($reportId));
    if (!$report || $report['status'] !== 'done') json_fail('报告不存在或已撤回');
    // 归属校验：报告关联检验/检查项目 → 申请单 → 就诊科室
    $itm = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array((int)$report['result_id']));
    $ord = $itm ? OrderRepository::one('SELECT visit_id FROM orders WHERE id=?', array((int)$itm['order_id'])) : null;
    $rv = $ord ? get_visit_row((int)$ord['visit_id']) : null;
    if (!$rv || !dept_visit_allowed($rv['visit'], Auth::user())) json_fail('该患者不属于当前科室，不可撤回');
    submit_audit('report_withdraw', $reportId,
        $titleType . '报告撤回申请：' . $report['report_no'],
        $deptNoun . '科 ' . Auth::user()['name'] . ' 申请撤回报告 ' . $report['report_no'] . '，原因：' . $reason,
        array('data' => json_encode(array('report_no' => $report['report_no'], 'reason' => $reason), JSON_UNESCAPED_UNICODE)));
    json_ok(array(), '撤回申请已提交，等待管理员审核');
}