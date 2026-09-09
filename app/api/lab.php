<?php
/**
 * ============================================================
 * lab.php — 检验科接口
 * ============================================================
 * 说明：
 * 1. 患者缴费后检验项目进入【待登记】→ 登记 → 【检验录入】→
 *    填写化验数值（显示计量单位/正常范围/危急值上下限）→
 *    提交自动生成报告并打印 → 移入【已完成】
 * 2. 已完成报告可查看/申请撤回（管理员批准后可重新编辑）
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/forms.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
require_once __DIR__ . '/parts/dept_common.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 检验科首页统计 ==================== */
    case 'home_stats':
        dept_home_stats('lab', 'lab_items');
        break;

    /* ==================== 队列列表（HTML） ==================== */
    case 'queue':
        dept_queue('lab', '🧪', 'labRegister', 'labResultForm');
        break;

    /* ==================== 新增检验项目（需求19：提交后需管理员审核） ==================== */
    // id > 0 时回填原提交内容（驳回后点击站内消息跳回，修改后重新提交）
    case 'item_form':
        json_ok(array('html' => form_item('lab', (int)req('id', 0))));
        break;

    case 'item_save':
        $id = (int)post('id', 0);
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        if ($name === '') json_fail('请填写项目名称');
        if ($id > 0) {
            OrderRepository::exec('UPDATE lab_items SET category=?, name=?, unit=?, price=?, normal_range=?, critical_low=?, critical_high=?, description=?, status=? WHERE id=?', array(
                $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), 'pending', $id,
            ));
            OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='item_lab' AND ref_id=? AND status IN ('pending','rejected')", array($u['name'], now_str(), $id));
            submit_audit('item_lab', $id, '检验项目修改后重新提交：' . $name,
                '检验科 ' . $u['name'] . ' 修改后重新提交检验项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
            json_ok(array(), '检验项目已修改并重新提交，待管理员审核');
        }
        $newId = OrderRepository::insert('INSERT INTO lab_items(category, name, unit, price, normal_range, critical_low, critical_high, description, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
            $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), 'pending', now_str(),
        ));
        submit_audit('item_lab', $newId, '检验项目添加：' . $name,
            '检验科 ' . $u['name'] . ' 提交新增检验项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核');
        json_ok(array(), '检验项目已提交，待管理员审核通过后即可开单使用');
        break;

    /* ==================== 登记（采样） ==================== */
    case 'register':
        dept_register('lab');
        break;

    /* ==================== 整张检验申请单登记 ====================
     * 登记以申请单为单位：该申请单全部待登记检验项目一次性置为已登记
     * （出报告亦按申请单维度，避免逐子项目登记的繁琐与错乱） */
    case 'register_order':
        $orderId = did(post('order_id'));
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
        if (!$order || $order['order_type'] !== 'lab') json_fail('检验申请单不存在');
        // 医护角色归属校验（宽松：未绑定科室=全院放行；已绑科室须匹配就诊科室）
        $rv = get_visit_row((int)$order['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if (!dept_visit_allowed($rv['visit'], $u)) json_fail('无权限登记该申请单');
        $n = (int)OrderRepository::exec("UPDATE order_items SET status='registered', registered_at=? WHERE order_id=? AND item_type='lab' AND status='paid'", array(now_str(), $orderId));
        if ($n <= 0) json_fail('该申请单暂无待登记项目');
        json_ok(array(), '已登记该申请单 ' . $n . ' 个检验项目');
        break;

    /* ==================== 检验结果临时保存（失焦自动保存草稿） ====================
     * 一张检验单常需录入十几二十项，刷新丢失代价大：输入框失焦即写入
     * results.status='draft'，刷新后回填；仅「提交并打印报告」才生成正式报告。 */
    case 'save_draft':
        $itemId = did(post('item_id'));
        $value = (string)post('value', '');
        $isGroup = (int)post('is_group', 0) === 1 ? 1 : 0;
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'lab' || !in_array($it['status'], array('paid', 'registered'), true)) {
            json_fail('项目不存在或状态异常');
        }
        // 医护角色归属校验（与 register_order 口径一致：未绑定科室=全院放行；已绑科室须匹配就诊科室）
        $rv = get_visit_row((int)$it['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if (!dept_visit_allowed($rv['visit'], $u)) json_fail('无权限录入该就诊的检验结果');
        if ($isGroup) {
            $vals = json_decode($value, true);
            if (!is_array($vals)) json_fail('参数错误');
            $valuesJson = json_encode(array('group' => 1, 'values' => $vals), JSON_UNESCAPED_UNICODE);
        } else {
            $valuesJson = json_encode(array('value' => $value), JSON_UNESCAPED_UNICODE);
        }
        $result = OrderRepository::one('SELECT * FROM results WHERE order_item_id=?', array($itemId));
        if ($result) {
            OrderRepository::exec("UPDATE results SET values_json=?, status='draft', executor=?, updated_at=? WHERE id=?", array(
                $valuesJson, $u['name'], now_str(), $result['id'],
            ));
        } else {
            OrderRepository::insert("INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)", array(
                $it['item_id'], $itemId, $it['visit_id'], $it['patient_no'], $it['flow_no'], 'lab',
                $valuesJson, $u['name'], 'draft', now_str(), now_str(),
            ));
        }
        json_ok(array(), '已临时保存');
        break;

    /* ==================== 检验录入表单（HTML，含正常范围与危急值提示；检验组显示成员明细） ==================== */
    case 'result_form':
        // 表单弹窗通过 POST 提交 item_id，用 req() 兼容读取
        $itemId = did(req('item_id'));
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'lab') json_fail('项目不存在');
        $item = OrderRepository::one('SELECT * FROM lab_items WHERE id=?', array($it['item_id']));
        $itemName = $item ? $item['name'] : $it['item_name'];
        $html = '<div class="form-group">
            <label class="form-label">检验项目</label>
            <input class="input" value="' . e($itemName) . '" readonly>
        </div>';
        // 检验组：显示组内每个成员一行输入框（组价开单，成员结果分别录入）
        if ($item && (int)$item['is_group'] === 1) {
            $members = OrderRepository::q("SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id", array($item['id']));
            if (!$members) {
                $members = array();
            }
            foreach ($members as $i => $m) {
                $hint = '';
                if ($m['critical_low'] !== '' || $m['critical_high'] !== '') {
                    $hint = '<div class="fs-12 text-warning mt-4">危急值：低 ' . e($m['critical_low']) . ' / 高 ' . e($m['critical_high']) . '，超出时请立即复核并通知医生</div>';
                }
                $html .= '<div class="form-group" style="background:var(--bg-soft);border-radius:8px;padding:10px 12px;margin-bottom:10px">' .
                    '<label class="form-label">' . e($m['name']) . '（单位：' . e($m['unit']) . '）</label>' .
                    '<input type="text" class="input" id="resValue_' . (int)$m['id'] . '" placeholder="请输入检验结果数值">' .
                    '<div class="fs-12 text-muted mt-4">正常范围：' . e($m['normal_range']) . '</div>' . $hint . '</div>';
            }
            $html .= '<input type="hidden" id="resGroup" value="1">';
            $html .= '<div class="fs-12 text-muted">该检验为组合项目（' . e($itemName) . '），请逐一填写组内各项检验结果。</div>';
        } else {
            $hint = '';
            if ($item && ($item['critical_low'] !== '' || $item['critical_high'] !== '')) {
                $hint = '<div class="fs-12 text-warning mt-4">危急值：低 ' . e($item['critical_low']) . ' / 高 ' . e($item['critical_high']) .
                    '，超出时请立即复核并通知医生</div>';
            }
            $html .= '<div class="form-group">
                <label class="form-label">化验数值（单位：' . e($item ? $item['unit'] : '') . '）</label>
                <input type="text" class="input" id="resValue" placeholder="请输入检验结果数值">
                <div class="fs-12 text-muted mt-4">正常范围：' . e($item ? $item['normal_range'] : '') . '</div>' . $hint . '
            </div>';
        }
        json_ok(array('html' => $html, 'item' => $it));
        break;

    /* ==================== 保存检验结果 → 自动生成报告并打印 ==================== */
    case 'save_result':
        $itemId = did(post('item_id'));
        $value = post('value');
        $isGroup = (int)post('is_group', 0);
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'lab' || !in_array($it['status'], array('registered', 'done'), true)) {
            json_fail('项目不存在或状态异常');
        }
        // 医护角色归属校验（与 register_order 口径一致）
        $rv = get_visit_row((int)$it['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if (!dept_visit_allowed($rv['visit'], $u)) json_fail('无权限提交该就诊的检验结果');
        // done 状态拦截：已生成非撤回报告的项目不可重复提交（防重复报告）；
        // 需重新录入须先走撤回流程（撤回后状态回到 registered）
        if ($it['status'] === 'done' && (int)$it['result_id'] > 0) {
            $hasActive = (int)OrderRepository::val("SELECT COUNT(*) FROM reports WHERE result_id=? AND status<>'withdrawn'", array((int)$it['result_id']));
            if ($hasActive > 0) json_fail('该检验项目已生成报告，如需修改请先申请撤回');
        }
        $item = OrderRepository::one('SELECT * FROM lab_items WHERE id=?', array($it['item_id']));
        // 检验组：value 为 JSON（成员 id => 数值），校验组内每项均已填写
        if ($isGroup) {
            $vals = json_decode($value, true);
            if (!is_array($vals) || !$vals) json_fail('请输入组内各项检验结果');
            $members = OrderRepository::q("SELECT id FROM lab_items WHERE parent_id=? AND is_group=0", array($it['item_id']));
            $need = array();
            foreach ($members as $m) $need[(int)$m['id']] = true;
            $filled = array();
            foreach ($vals as $mid => $mv) {
                $filled[(int)$mid] = trim((string)$mv);
            }
            $missing = array();
            foreach ($need as $mid => $b) {
                if (!isset($filled[$mid]) || $filled[$mid] === '') {
                    $mName = OrderRepository::val('SELECT name FROM lab_items WHERE id=?', array($mid));
                    $missing[] = $mName ? $mName : ('#' . $mid);
                }
            }
            if ($missing) json_fail('请填写检验结果：' . implode('、', $missing));
            $valuesJson = json_encode(array('group' => 1, 'values' => $filled), JSON_UNESCAPED_UNICODE);
        } else {
            if ($value === '') json_fail('请输入检验结果数值');
            $valuesJson = json_encode(array('value' => $value), JSON_UNESCAPED_UNICODE);
        }

        // 复合写操作（results + order_items 回写 + reports + 状态）整体包事务保证原子性
        $failTx = function ($msg) {
            if (DatabaseManager::getMain()->inTransaction()) DatabaseManager::getMain()->rollBack();
            json_fail($msg);
        };
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            // 写入结果（撤回后重填时更新原结果）
            $result = OrderRepository::one('SELECT * FROM results WHERE order_item_id=?', array($itemId));
            if ($result) {
                OrderRepository::exec("UPDATE results SET values_json=?, status='done', executor=?, updated_at=? WHERE id=?", array(
                    $valuesJson, $u['name'], now_str(), $result['id'],
                ));
                $resultId = $result['id'];
            } else {
                $resultId = OrderRepository::insert("INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, executor, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)", array(
                    $it['item_id'], $itemId, $it['visit_id'], $it['patient_no'], $it['flow_no'], 'lab',
                    $valuesJson, $u['name'], 'done', now_str(), now_str(),
                ));
            }
            // 回写 order_items.result_id：检验/影像「已完成」队列据此关联报告，支持查看/申请撤回
            OrderRepository::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $itemId));

            // 报告（insert_report：MAX+1 生成 + 唯一索引并发撞号重试，杜绝重复报告号）
            $reportNo = next_report_no('lab');
            // 快照固化：生成即定格申请科室/申请医生/临床诊断（首诊断、不含 ICD10）/
            // 申请时间/检验登记时间——后期调阅不受病历、转科等后续变化影响
            $snapOrder = OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$it['order_id']));
            $diag = '';
            $prDiag = OrderRepository::one("SELECT emr_data FROM patient_records WHERE visit_id=? AND emr_data IS NOT NULL AND emr_data!='' ORDER BY id ASC LIMIT 1", array((int)$it['visit_id']));
            if ($prDiag) {
                $emrD = emr_merge_defaults(emr_normalize(json_decode((string)$prDiag['emr_data'], true) ?: array()), emr_default_data(null));
                $diags = isset($emrD['diagnoses']) && is_array($emrD['diagnoses']) ? $emrD['diagnoses'] : array();
                if ($diags) $diag = emr_diag_text(array($diags[0]), false);   // 首诊断，不含 ICD10 编码
            }
            if ($diag === '') {
                $mirrorD = OrderRepository::one("SELECT preliminary_diagnosis FROM records WHERE visit_id=? AND preliminary_diagnosis IS NOT NULL AND preliminary_diagnosis!='' ORDER BY id ASC LIMIT 1", array((int)$it['visit_id']));
                if ($mirrorD) $diag = (string)$mirrorD['preliminary_diagnosis'];
            }
            $reportId = insert_report(array(
                'result_id' => $resultId, 'report_no' => $reportNo,
                'visit_id' => $it['visit_id'], 'patient_no' => $it['patient_no'], 'flow_no' => $it['flow_no'],
                'type' => 'lab', 'doctor' => $u['name'], 'status' => 'done',
                // 检验备注：报告单页脚展示（提交时可选填写）
                'content' => trim((string)post('note', '')),
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
        // 通知医生 + 打印提醒
        if ($it['doctor_id'] > 0) {
            $pName = OrderRepository::val('SELECT name FROM patients WHERE patient_no=?', array($it['patient_no']));
            send_msg('doctor', $it['doctor_id'],
                '检验报告已出：' . $it['item_name'],
                '患者「' . $pName . '」（' . $it['patient_no'] . '）的检验「' . $it['item_name'] . '」结果已出具，报告编号 ' . $reportNo,
                'report', '/api/print?action=report&report_id=' . oid($reportId),
                array('msg_type' => 'patient', 'patient_name' => $pName, 'visit_id' => (int)$it['visit_id']));
        }
        // ===== 危急值检测：比对化验数值与项目危急值上下限，命中则交前端弹出通知流程 =====
        $critItems = array();
        if ($item) {
            if ($isGroup) {
                $members = OrderRepository::q("SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id", array($it['item_id']));
                foreach ($members as $m) {
                    $v = isset($filled[(int)$m['id']]) ? $filled[(int)$m['id']] : '';
                    $n = crit_parse_num($v);
                    if ($n === null) continue;
                    if ($m['critical_low'] !== '' && $n < (float)$m['critical_low']) {
                        $critItems[] = array('name' => (string)$m['name'], 'value' => $v, 'unit' => (string)$m['unit'], 'normal_range' => (string)$m['normal_range'], 'critical_low' => (string)$m['critical_low'], 'critical_high' => (string)$m['critical_high'], 'flag' => 'low');
                    } elseif ($m['critical_high'] !== '' && $n > (float)$m['critical_high']) {
                        $critItems[] = array('name' => (string)$m['name'], 'value' => $v, 'unit' => (string)$m['unit'], 'normal_range' => (string)$m['normal_range'], 'critical_low' => (string)$m['critical_low'], 'critical_high' => (string)$m['critical_high'], 'flag' => 'high');
                    }
                }
            } else {
                $n = crit_parse_num($value);
                if ($n !== null) {
                    if ($item['critical_low'] !== '' && $n < (float)$item['critical_low']) {
                        $critItems[] = array('name' => (string)$item['name'], 'value' => $value, 'unit' => (string)$item['unit'], 'normal_range' => (string)$item['normal_range'], 'critical_low' => (string)$item['critical_low'], 'critical_high' => (string)$item['critical_high'], 'flag' => 'low');
                    } elseif ($item['critical_high'] !== '' && $n > (float)$item['critical_high']) {
                        $critItems[] = array('name' => (string)$item['name'], 'value' => $value, 'unit' => (string)$item['unit'], 'normal_range' => (string)$item['normal_range'], 'critical_low' => (string)$item['critical_low'], 'critical_high' => (string)$item['critical_high'], 'flag' => 'high');
                    }
                }
            }
        }
        $data = array('report_id' => oid($reportId));
        if ($critItems) {
            // 命中危急值：随报告响应回传，前端据此弹出「检测到危急值 → 是否通知医生」
            // （默认通知开单医生，可改选其他医生）；report_id 供发送接口回读快照
            $data['critical'] = array(
                'items' => $critItems,
                'report_id' => oid($reportId),
                'item_name' => $item ? (string)$item['name'] : (string)$it['item_name'],
                'doctor_id' => (int)$it['doctor_id'],
                'doctor_name' => (string)$it['doctor_name'],
            );
        }
        json_ok($data, '结果已提交，报告已生成');
        break;

    /* ==================== 申请撤回报告（管理员审核） ==================== */
    case 'withdraw':
        dept_withdraw('检验', '检验');
        break;

    default:
        json_fail('未知操作');
}