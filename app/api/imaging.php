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

$u = Auth::user();

switch ($action) {

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
                $p = DB::one('patient', 'SELECT name, gender, age FROM patients WHERE patient_no=?', array($r['patient_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . (int)($p ? $p['age'] : 0) . '岁</span></td>' .
                    '<td>' . e($r['item_name']) . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                    '<td>';
                if ($status === 'paid') {
                    $html .= '<button class="btn btn-primary btn-sm" onclick="imgRegister(' . (int)$r['id'] . ')">登记</button>';
                } elseif ($status === 'registered') {
                    $html .= '<button class="btn btn-success btn-sm" onclick="imgResultForm(' . (int)$r['id'] . ')">录入报告</button>';
                } else {
                    $report = DB::one('lab', 'SELECT * FROM reports WHERE result_id=? AND status<>? ORDER BY id DESC', array((int)$r['result_id'], 'withdrawn'));
                    if ($report) {
                        $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' . (int)$report['id'] . '\',null)">查看报告</button> ' .
                            '<button class="btn btn-outline btn-sm" onclick="withdrawReport(' . (int)$report['id'] . ')">申请撤回</button>';
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

    /* ==================== 登记 ==================== */
    case 'register':
        $itemId = (int)post('item_id');
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging' || $it['status'] !== 'paid') {
            json_fail('项目不存在或状态异常');
        }
        DB::exec('order', "UPDATE order_items SET status='registered' WHERE id=?", array($itemId));
        json_ok(array(), '登记成功，请安排检查');
        break;

    /* ==================== 报告录入表单（HTML） ==================== */
    case 'result_form':
        $itemId = (int)get('item_id');
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
        $itemId = (int)post('item_id');
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
            send_msg('doctor', $it['doctor_id'],
                '检查报告已出：' . $it['item_name'],
                '患者（' . $it['patient_no'] . '）的检查「' . $it['item_name'] . '」报告已出具，报告编号 ' . $reportNo,
                'report', '/api/print?action=report&report_id=' . $reportId);
        }
        json_ok(array('report_id' => $reportId), '报告已生成并提交');
        break;

    /* ==================== 申请撤回报告 ==================== */
    case 'withdraw':
        $reportId = (int)post('report_id');
        $reason = post('reason', '');
        if ($reason === '') json_fail('请填写撤回原因');
        $report = DB::one('lab', 'SELECT * FROM reports WHERE id=?', array($reportId));
        if (!$report || $report['status'] !== 'done') json_fail('报告不存在或已撤回');
        DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, data, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            'report_withdraw', $reportId,
            '检查报告撤回申请：' . $report['report_no'],
            '影像科 ' . $u['name'] . ' 申请撤回报告 ' . $report['report_no'] . '，原因：' . $reason,
            json_encode(array('report_no' => $report['report_no'], 'reason' => $reason), JSON_UNESCAPED_UNICODE),
            'pending', $u['name'], $u['id'], now_str(),
        ));
        json_ok(array(), '撤回申请已提交，等待管理员审核');
        break;

    default:
        json_fail('未知操作');
}
