<?php
/**
 * ============================================================
 * imaging.php — 影像科接口
 * ============================================================
 * 说明：与检验科流程一致，结果为【影像所见 + 检查结论】：
 * 患者缴费后检查项目进入【待登记】→ 登记 → 【报告录入】→
 * 填写影像所见与结论 → 提交自动生成报告并打印 → 移入【已完成】
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/forms.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 影像科首页统计 ==================== */
    case 'home_stats':
        $today = date('Y-m-d');
        $todayItems = (int)DB::val('order', "SELECT COUNT(*) FROM order_items WHERE item_type='imaging' AND date(created_at)=?", array($today));
        $todayFee = (float)DB::val('order', "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_type='imaging' AND status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today));
        $pendingReg = (int)DB::val('order', "SELECT COUNT(*) FROM order_items WHERE item_type='imaging' AND status='paid'", array());
        $pendingRep = (int)DB::val('order', "SELECT COUNT(*) FROM order_items WHERE item_type='imaging' AND status='registered'", array());
        $itemTotal = (int)DB::val('lab', "SELECT COUNT(*) FROM exam_items WHERE status='approved'", array());
        $pendingAudit = (int)DB::val('lab', "SELECT COUNT(*) FROM exam_items WHERE status='pending'", array());
        $labels = array(); $series = array();
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = substr($day, 5);
            $series[] = (int)DB::val('order', "SELECT COUNT(*) FROM order_items WHERE item_type='imaging' AND date(created_at)=?", array($day));
        }
        json_ok(array(
            'kpi' => array('today_items' => $todayItems, 'today_fee' => round($todayFee, 2),
                'pending_reg' => $pendingReg, 'pending_rep' => $pendingRep, 'item_total' => $itemTotal, 'pending_audit' => $pendingAudit),
            'trend' => array('labels' => $labels, 'data' => $series),
        ));
        break;

    /* ==================== 队列列表（HTML） ==================== */
    case 'queue':
        $status = get('status', 'paid');
        $map = array('paid' => '待登记', 'registered' => '待出报告', 'done' => '已完成');
        // 说明：SQLite 分散式数据库不支持跨库 JOIN，患者信息按 patient_no 逐条补充
        $rows = DB::q('order', "SELECT * FROM order_items WHERE item_type='imaging' AND status=? ORDER BY id DESC LIMIT 200", array($status));
        $html = '<div class="fs-13 text-muted mb-8">' . $map[$status] . '：' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty"><div class="empty-ico">🩻</div>暂无' . $map[$status] . '项目</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>检查项目</th><th>流水号</th><th>开单医生</th><th>开单时间</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $p = DB::one('patient', 'SELECT name, gender, birth_date FROM patients WHERE patient_no=?', array($r['patient_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . ($p ? age_format($p['birth_date']) : '—') . '</span></td>' .
                    '<td>' . e($r['item_name']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                    '<td>';
                if ($status === 'paid') {
                    $html .= '<button class="btn btn-primary btn-sm" onclick="imgRegister(\'' . e(oid($r['id'])) . '\')">登记</button>';
                } elseif ($status === 'registered') {
                    $html .= '<button class="btn btn-success btn-sm" onclick="imgResultForm(\'' . e(oid($r['id'])) . '\')">录入报告</button>';
                } else {
                    $report = DB::one('lab', 'SELECT * FROM reports WHERE result_id=? AND status<>? ORDER BY id DESC', array((int)$r['result_id'], 'withdrawn'));
                    if ($report) {
                        $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' . e(oid($report['id'])) . '\',null)">查看报告</button> ' .
                            '<button class="btn btn-outline btn-sm" onclick="withdrawReport(\'' . e(oid($report['id'])) . '\')">申请撤回</button>';
                    } else {
                        $html .= '<span class="badge badge-gray">撤回审核中</span>';
                    }
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 新增检查项目（需求19：提交后需管理员审核） ==================== */
    // id > 0 时回填原提交内容（驳回后点击站内消息跳回，修改后重新提交）
    case 'item_form':
        json_ok(array('html' => form_item('imaging', (int)req('id', 0))));
        break;

    case 'item_save':
        $id = (int)post('id', 0);
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        if ($name === '') json_fail('请填写项目名称');
        if ($id > 0) {
            // 重新提交：更新原记录内容，回到待审核状态，并重建一条审核记录
            DB::exec('lab', 'UPDATE exam_items SET category=?, name=?, price=?, description=?, status=? WHERE id=?', array(
                $category, $name, $price, post('description'), 'pending', $id,
            ));
            DB::exec('core', "UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_exam' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
            submit_audit('item_exam', $id, '检查项目修改后重新提交：' . $name,
                '影像科 ' . $u['name'] . ' 修改后重新提交检查项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
            json_ok(array(), '检查项目已修改并重新提交，待管理员审核');
        }
        $newId = DB::insert('lab', 'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
            $category, $name, $price, post('description'), 'pending', now_str(),
        ));
        submit_audit('item_exam', $newId, '检查项目添加：' . $name,
            '影像科 ' . $u['name'] . ' 提交新增检查项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
        json_ok(array(), '检查项目已提交，待管理员审核通过后即可开单使用');
        break;

    /* ==================== 登记 ==================== */
    case 'register':
        $itemId = did(post('item_id'));
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging' || $it['status'] !== 'paid') {
            json_fail('项目不存在或状态异常');
        }
        DB::exec('order', "UPDATE order_items SET status='registered' WHERE id=?", array($itemId));
        json_ok(array(), '登记成功，请安排检查');
        break;

    /* ==================== 报告录入表单（HTML） ==================== */
    case 'result_form':
        // 表单弹窗通过 POST 提交 item_id，用 req() 兼容读取
        $itemId = did(req('item_id'));
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging') json_fail('项目不存在');
        $item = DB::one('lab', 'SELECT * FROM exam_items WHERE id=?', array($it['item_id']));
        $html = '<div class="form-group">
            <label class="form-label">检查项目</label>
            <input class="input" value="' . e($item ? $item['name'] : $it['item_name']) . '" readonly>
        </div>
        <div class="form-group">
            <label class="form-label">影像所见</label>
            <textarea class="textarea" id="resFindings" rows="4" placeholder="请填写影像所见描述"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">检查结论</label>
            <textarea class="textarea" id="resConclusion" rows="3" placeholder="请填写检查结论"></textarea>
        </div>';
        json_ok(array('html' => $html, 'item' => $it));
        break;

    /* ==================== 保存报告 → 自动生成报告并打印 ==================== */
    case 'save_result':
        $itemId = did(post('item_id'));
        $findings = post('findings');
        $conclusion = post('conclusion');
        if ($findings === '') json_fail('请填写影像所见');
        if ($conclusion === '') json_fail('请填写检查结论');
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging' || !in_array($it['status'], array('registered', 'done'), true)) {
            json_fail('项目不存在或状态异常');
        }
        $result = DB::one('lab', 'SELECT * FROM results WHERE order_item_id=?', array($itemId));
        if ($result) {
            DB::exec('lab', "UPDATE results SET findings=?, conclusion=?, status='done', executor=?, updated_at=? WHERE id=?", array(
                $findings, $conclusion, $u['name'], now_str(), $result['id'],
            ));
            $resultId = $result['id'];
        } else {
            $resultId = DB::insert('lab', "INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, findings, conclusion, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)", array(
                $it['item_id'], $itemId, $it['visit_id'], $it['patient_no'], $it['flow_no'], 'imaging',
                $findings, $conclusion, $u['name'], 'done', now_str(), now_str(),
            ));
        }
        // 回写 order_items.result_id：检验/影像「已完成」队列据此关联报告，支持查看/申请撤回
        DB::exec('order', 'UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $itemId));

        $reportNo = 'BG' . date('Ymd') . str_pad((string)DB::val('lab', 'SELECT COUNT(*) FROM reports WHERE substr(report_no,3,8)=?', array(date('Ymd'))) + 1, 4, '0', STR_PAD_LEFT);
        $reportId = DB::insert('lab', 'INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, doctor, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            $resultId, $reportNo, $it['visit_id'], $it['patient_no'], $it['flow_no'], 'imaging', $u['name'], 'done', now_str(),
        ));
        DB::exec('order', "UPDATE order_items SET status='done', executed_by=?, executed_at=? WHERE id=?", array($u['name'], now_str(), $itemId));
        if ($it['doctor_id'] > 0) {
            $pName = DB::val('patient', 'SELECT name FROM patients WHERE patient_no=?', array($it['patient_no']));
            send_msg('doctor', $it['doctor_id'],
                '检查报告已出：' . $it['item_name'],
                '患者「' . $pName . '」（' . $it['patient_no'] . '）的检查「' . $it['item_name'] . '」报告已出具，报告编号 ' . $reportNo,
                'report', '/api/print?action=report&report_id=' . oid($reportId),
                array('msg_type' => 'patient', 'patient_name' => $pName, 'visit_id' => (int)$it['visit_id']));
        }
        json_ok(array('report_id' => oid($reportId)), '报告已生成并提交');
        break;

    /* ==================== 申请撤回报告 ==================== */
    case 'withdraw':
        $reportId = did(post('report_id'));
        $reason = post('reason', '');
        if ($reason === '') json_fail('请填写撤回原因');
        $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($reportId));
        if (!$report || $report['status'] !== 'done') json_fail('报告不存在或已撤回');
        submit_audit('report_withdraw', $reportId,
            '检查报告撤回申请：' . $report['report_no'],
            '影像科 ' . $u['name'] . ' 申请撤回报告 ' . $report['report_no'] . '，原因：' . $reason,
            array('data' => json_encode(array('report_no' => $report['report_no'], 'reason' => $reason), JSON_UNESCAPED_UNICODE)));
        json_ok(array(), '撤回申请已提交，等待管理员审核');
        break;

    default:
        json_fail('未知操作');
}
