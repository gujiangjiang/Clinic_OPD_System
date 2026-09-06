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
require_once APP_ROOT . '/app/includes/emr_formatter.php';
require_once __DIR__ . '/parts/dept_common.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 影像科首页统计 ==================== */
    case 'home_stats':
        dept_home_stats('imaging', 'exam_items');
        break;

    /* ==================== 队列列表（HTML） ==================== */
    case 'queue':
        dept_queue('imaging', '🩻', 'imgRegister', 'imgResultForm');
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
            OrderRepository::exec('UPDATE exam_items SET category=?, name=?, price=?, description=?, status=? WHERE id=?', array(
                $category, $name, $price, post('description'), 'pending', $id,
            ));
            OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_exam' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
            submit_audit('item_exam', $id, '检查项目修改后重新提交：' . $name,
                '影像科 ' . $u['name'] . ' 修改后重新提交检查项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
            json_ok(array(), '检查项目已修改并重新提交，待管理员审核');
        }
        $newId = OrderRepository::insert('INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
            $category, $name, $price, post('description'), 'pending', now_str(),
        ));
        submit_audit('item_exam', $newId, '检查项目添加：' . $name,
            '影像科 ' . $u['name'] . ' 提交新增检查项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
        json_ok(array(), '检查项目已提交，待管理员审核通过后即可开单使用');
        break;

    /* ==================== 登记 ==================== */
    case 'register':
        dept_register('imaging');
        break;

    /* ==================== 整张检查申请单登记 ====================
     * 登记以申请单为单位：该申请单全部待登记检查项目一次性置为已登记
     * （报告亦按申请单维度书写，避免逐子项目登记的繁琐） */
    case 'register_order':
        $orderId = did(post('order_id'));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'imaging') json_fail('检查申请单不存在');
        $rv = get_visit_row((int)$order['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if (!dept_visit_allowed($rv['visit'], $u)) json_fail('无权限登记该申请单');
        $n = (int)OrderRepository::exec("UPDATE order_items SET status='registered', registered_at=? WHERE order_id=? AND item_type='imaging' AND status='paid'", array(now_str(), $orderId));
        if ($n <= 0) json_fail('该申请单暂无待登记项目');
        json_ok(array(), '已登记该申请单 ' . $n . ' 个检查项目');
        break;

    /* ==================== 报告录入表单（HTML） ==================== */
    case 'result_form':
        $itemId = did(req('item_id'));
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging') json_fail('项目不存在');
        $item = OrderRepository::one('SELECT * FROM exam_items WHERE id=?', array($it['item_id']));
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
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging' || !in_array($it['status'], array('registered', 'done'), true)) {
            json_fail('项目不存在或状态异常');
        }
        // done 状态拦截：已生成非撤回报告的项目不可重复提交（防重复报告）；
        // 需重新录入须先走撤回流程（撤回后状态回到 registered）
        if ($it['status'] === 'done' && (int)$it['result_id'] > 0) {
            $hasActive = (int)OrderRepository::val("SELECT COUNT(*) FROM reports WHERE result_id=? AND status<>'withdrawn'", array((int)$it['result_id']));
            if ($hasActive > 0) json_fail('该检查项目已生成报告，如需修改请先申请撤回');
        }

        // 复合写操作（results + order_items 回写 + reports + 状态）整体包事务保证原子性
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            $result = OrderRepository::one('SELECT * FROM results WHERE order_item_id=?', array($itemId));
            if ($result) {
                OrderRepository::exec("UPDATE results SET findings=?, conclusion=?, status='done', executor=?, updated_at=? WHERE id=?", array(
                    $findings, $conclusion, $u['name'], now_str(), $result['id'],
                ));
                $resultId = $result['id'];
            } else {
                $resultId = OrderRepository::insert("INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, findings, conclusion, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)", array(
                    $it['item_id'], $itemId, $it['visit_id'], $it['patient_no'], $it['flow_no'], 'imaging',
                    $findings, $conclusion, $u['name'], 'done', now_str(), now_str(),
                ));
            }
            OrderRepository::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $itemId));

            // 报告（insert_report：MAX+1 生成 + 唯一索引并发撞号重试，杜绝重复报告号）
            $reportNo = next_report_no('imaging');
            // 快照固化：申请科室/申请医生/临床诊断（首诊断不含 ICD10）/申请时间/检查登记时间
            $snapOrder = OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$it['order_id']));
            $diag = '';
            $prDiag = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$it['visit_id']));
            if ($prDiag) {
                $emrD = emr_merge_defaults(emr_normalize(json_decode((string)$prDiag['emr_data'], true) ?: array()), emr_default_data(null));
                $diags = isset($emrD['diagnoses']) && is_array($emrD['diagnoses']) ? $emrD['diagnoses'] : array();
                if ($diags) $diag = emr_diag_text(array($diags[0]), false);
            }
            if ($diag === '') {
                $mirrorD = OrderRepository::one("SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!='' ORDER BY id ASC LIMIT 1", array((int)$it['visit_id']));
                if ($mirrorD) $diag = (string)$mirrorD['preliminary_diagnosis'];
            }
            $reportId = insert_report(array(
                'result_id' => $resultId, 'report_no' => $reportNo,
                'visit_id' => $it['visit_id'], 'patient_no' => $it['patient_no'], 'flow_no' => $it['flow_no'],
                'type' => 'imaging', 'doctor' => $u['name'], 'status' => 'done',
                'apply_dept' => $snapOrder ? (string)$snapOrder['dept_name'] : '',
                'apply_doctor' => $snapOrder ? (string)$snapOrder['doctor_name'] : '',
                'clinical_diag' => $diag,
                'apply_time' => $snapOrder ? (string)$snapOrder['created_at'] : '',
                'reg_time' => (string)$it['registered_at'],
            ));
            OrderRepository::exec("UPDATE order_items SET status='done', executed_by=?, executed_at=? WHERE id=?", array($u['name'], now_str(), $itemId));
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('保存失败：' . $ex->getMessage());
        }
        if ($it['doctor_id'] > 0) {
            $pName = OrderRepository::val('SELECT name FROM patients WHERE patient_no=?', array($it['patient_no']));
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
        dept_withdraw('影像', '检查');
        break;

    default:
        json_fail('未知操作');
}