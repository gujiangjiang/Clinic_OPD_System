<?php
/**
 * ============================================================
 * parts/admin_settings.php v1.1.0 — 管理端：系统设置/统计/打印中心
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. stats      工作台统计
 *   2. settings   系统设置保存（医院名称/第二名称/页脚/时区/HIS密钥）
 *   3. upload_logo 上传医院 LOGO（同时作为 favicon）
 *   4. print_items 打印中心：某就诊可打印单据一览
 * ============================================================ */

/**
 * 处理系统设置相关动作
 * @param string $action 动作名
 */
function admin_part_settings($action) {
    $u = Auth::user();

    /* ==================== 工作台统计 ==================== */
    if ($action === 'stats') {
        $today = today_str();
        $regToday = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=? AND status IN ('paid','visiting','finished')", array($today));
        $waiting = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations WHERE date(register_time)=? AND status='paid'", array($today));
        $revenue = (float)DB::val('order', "SELECT COALESCE(SUM(total),0) FROM payments WHERE date(created_at)=?", array($today));
        $pendingAudits = (int)DB::val('core', "SELECT COUNT(*) FROM audits WHERE status='pending'");
        $lowStock = (int)DB::val('drug', "SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty<=10");
        $deptCount = (int)DB::val('dept', 'SELECT COUNT(*) FROM departments WHERE status=1');
        $userCount = (int)DB::val('user', 'SELECT COUNT(*) FROM users WHERE status=1');
        $msgCount = (int)DB::val('core', 'SELECT COUNT(*) FROM messages WHERE is_read=0 AND (to_role=? OR to_user_id=?)', array($u['role'], $u['id']));
        json_ok(array(
            'reg_today' => $regToday, 'waiting' => $waiting, 'revenue' => money($revenue),
            'pending_audits' => $pendingAudits, 'low_stock' => $lowStock,
            'dept_count' => $deptCount, 'user_count' => $userCount, 'msg_count' => $msgCount,
        ));
    }

    /* ==================== 系统设置保存 ==================== */
    if ($action === 'settings') {
        $hospital = post('hospital_name');
        if ($hospital === '') json_fail('医院名称不能为空');
        $tz = post('timezone', 'Asia/Shanghai');
        $tzList = DateTimeZone::listIdentifiers();
        if (!in_array($tz, $tzList, true)) $tz = 'Asia/Shanghai';
        set_setting('hospital_name', $hospital);
        set_setting('hospital_name2', post('hospital_name2'));
        // 页脚版权：固定格式自动生成【© 年份 医院名称 版权所有】，不再手动保存
        set_setting('timezone', $tz);
        // HIS 预留接口密钥（可为空=关闭外部接口）
        $hisKey = post('his_api_key', '');
        set_setting('his_api_key', $hisKey);
        date_default_timezone_set($tz);
        json_ok(array(), '系统设置已保存');
    }

    /* ==================== 上传医院 LOGO（同时作为 favicon） ==================== */
    if ($action === 'upload_logo') {
        $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
        if (isset($res['error'])) json_fail($res['error']);
        set_setting('logo', $res['path']);
        json_ok(array('path' => $res['path']), 'LOGO 已上传');
    }

    /* ==================== 打印中心：某就诊可打印单据一览 ==================== */
    if ($action === 'print_items') {
        $visitId = (int)get('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $hasRecord = (int)DB::val('medical', 'SELECT COUNT(*) FROM records WHERE visit_id=?', array($visitId)) > 0;
        $hasCert = (int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($visitId)) > 0;
        $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id', array($visitId));
        $typeNames = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置单', 'prescription' => '处方单');
        $html = '<div class="card" style="padding:14px">' .
            '<div class="fw-700 fs-15">' . e($row['patient']['name']) . '（' . e($visit['flow_no']) . '）</div>' .
            '<div class="fs-13 text-muted mt-4 mb-12">' . e($visit['first_dept_name']) . ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ ' . e(substr($visit['register_time'], 0, 16)) . '</div>';
        $html .= '<div class="flex gap-8" style="flex-wrap:wrap">' .
            '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=receipt&visit_id=' . (int)$visitId . '\',null)">挂号凭条</button>' .
            ($hasRecord ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' . (int)$visitId . '\',null,\'a5\')">电子病历</button>' : '') .
            ($hasCert ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=certificate&visit_id=' . (int)$visitId . '\',null)">诊断证明</button>' : '') .
            '</div>';
        if ($orders) {
            $html .= '<div class="fs-13 fw-600 mt-12 mb-4">开单单据</div><div class="flex gap-8" style="flex-wrap:wrap">';
            foreach ($orders as $o) {
                $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=order&order_id=' . (int)$o['id'] . '\',null)">' .
                    e(isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : $o['order_type']) . '（' . e($o['order_no']) . '）</button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        json_ok(array('html' => $html));
    }

    json_fail('未知操作');
}
