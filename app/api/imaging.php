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

/**
 * 检查类型推导（DR/CT/US/MR，与前端 imgModality 口径一致）：
 * 供影像引用元数据（imaging_refs.modality）使用。
 */
function img_ref_modality($name) {
    if (preg_match('/CT/i', $name)) return 'CT';
    if (preg_match('/DR|X线|X光|摄片|胸片/i', $name)) return 'DR';
    if (preg_match('/超声|US|B超/i', $name)) return 'US';
    if (preg_match('/MR|磁共振|核磁/i', $name)) return 'MR';
    return 'OT';
}

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
        if (!$it || $it['item_type'] !== 'imaging') {
            json_fail('项目不存在或状态异常');
        }
        // 登记状态硬拦截（防未登记先写报告）：未缴费/已缴费未登记一律拒绝
        if (!in_array($it['status'], array('registered', 'done'), true)) {
            json_fail($it['status'] === 'paid' ? '该检查项目尚未登记，请先登记后再书写报告' : '项目不存在或状态异常');
        }
        // 医护角色归属校验（与 register_order 口径一致）
        $rv = get_visit_row((int)$it['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if (!dept_visit_allowed($rv['visit'], $u)) json_fail('无权限提交该就诊的检查报告');
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
            // 检查分类快照（标题前缀）：申请单 category_name，空则回退检查项目分类
            $catName = '';
            if ($snapOrder && !empty($snapOrder['category_name'])) {
                $catName = trim((string)$snapOrder['category_name']);
            }
            if ($catName === '' && (int)$it['item_id'] > 0) {
                $catItem = OrderRepository::one('SELECT category FROM exam_items WHERE id=?', array((int)$it['item_id']));
                if ($catItem) {
                    $c2 = trim((string)$catItem['category']);
                    if ($c2 !== '' && $c2 !== '检查') $catName = $c2;
                }
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
                'category_name' => $catName,
            ));
            OrderRepository::exec("UPDATE order_items SET status='done', executed_by=?, executed_at=? WHERE id=?", array($u['name'], now_str(), $itemId));
            // 影像引用登记（优化项1/2：三单匹配 + 只存引用）——报告出具即注册引用，
            // study_uid 以报告号占位（PACS 网关接入后替换为真实 DICOM UID）；
            // 三单匹配失败将抛异常回滚整个事务（硬拦截防张冠李戴）
            ImagingRepository::putRef(array(
                'order_item_id' => (int)$itemId,
                'order_id' => (int)$it['order_id'],
                'visit_id' => (int)$it['visit_id'],
                'patient_no' => (string)$it['patient_no'],
                'flow_no' => (string)$it['flow_no'],
                'study_uid' => $reportNo,
                'series_uids' => array(),
                'instance_count' => 0,
                'modality' => img_ref_modality($catName !== '' ? $catName : (string)$it['item_name']),
                'region' => 'region-pacs',
                'meta' => array(
                    'report_id' => $reportId,
                    'report_no' => $reportNo,
                    'clinical_diag' => $diag,
                ),
                'created_by' => $u['name'],
            ));
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

    /* ==================== 患者历史影像报告（按 patient_id 分页调阅） ====================
     * 检索规范（任务3）：
     *   1. 严格按患者唯一标识 patient_id（= patients.patient_no）检索，
     *      禁止姓名检索（防同名同姓混淆）；
     *   2. 分页策略：首屏最近 5 次，「加载更多」向下滚动分页；
     *   3. 历史内容一律只读：仅返回展示所需字段，不含任何可编辑入口；
     *      【复制到当前报告】由前端对 影像表现/影像诊断 两字段提供快捷复制。
     * 权限：影像科角色（含 admin），需能访问该就诊（科室归属校验口径与登记一致）。 */
    case 'history_reports':
        $patientNo = trim((string)get('patient_id', ''));
        if ($patientNo === '') json_fail('缺少患者唯一标识（patient_id）');
        $page = max(1, (int)get('page', 1));
        $pageSize = 5;   // 分段加载：首屏最近 5 次
        $patient = OrderRepository::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$patient) json_fail('患者不存在');

        // 总数（含已撤回——历史调阅需完整溯源，展示时标记状态）
        $total = (int)OrderRepository::val(
            "SELECT COUNT(*) FROM reports WHERE patient_no=? AND type='imaging'",
            array($patientNo)
        );
        $rows = OrderRepository::q(
            "SELECT rp.*, oi.id AS order_item_id, oi.item_name, oi.visit_id AS item_visit_id, oi.executed_at
             FROM reports rp
             LEFT JOIN results rs ON rs.id = rp.result_id
             LEFT JOIN order_items oi ON oi.id = rs.order_item_id
             WHERE rp.patient_no=? AND rp.type='imaging'
             ORDER BY rp.id DESC
             LIMIT ? OFFSET ?",
            array($patientNo, $pageSize, ($page - 1) * $pageSize)
        );
        $list = array();
        foreach ($rows as $r) {
            $findings = '';
            $conclusion = '';
            if ((int)$r['result_id'] > 0) {
                $res = OrderRepository::one('SELECT findings, conclusion FROM results WHERE id=?', array((int)$r['result_id']));
                if ($res) {
                    $findings = (string)$res['findings'];
                    $conclusion = (string)$res['conclusion'];
                }
            }
            // 检查项目：报告关联明细名 → 回退申请单分类名（旧数据兜底）
            $itemName = (string)$r['item_name'];
            if ($itemName === '' && (string)$r['category_name'] !== '') {
                $itemName = (string)$r['category_name'] . '检查';
            }
            $statusName = ((string)$r['status'] === 'withdrawn') ? '已撤回' : '已发布';
            // 影像调阅直链（第13项）：影像引用存在且配置了阅片器模板 → 历史详情可直达阅片
            $studyUid = '';
            if ((int)$r['order_item_id'] > 0) {
                $ref = ImagingRepository::refByItem((int)$r['order_item_id']);
                if ($ref) $studyUid = (string)$ref['study_uid'];
            }
            if ($studyUid === '') $studyUid = (string)$r['report_no'];   // 占位回退（报告号）
            $tpl = trim((string)setting('pacs_viewer_url', ''));
            $viewerUrl = $tpl !== '' ? str_replace('{study_uid}', rawurlencode($studyUid), $tpl) : '';
            $list[] = array(
                // 报告基本信息（只读）
                'report_id' => oid((int)$r['id']),
                'report_no' => (string)$r['report_no'],
                'item_name' => $itemName !== '' ? $itemName : '影像检查',
                'visit_code' => oid((int)$r['visit_id']),
                'check_time' => (string)$r['reg_time'],
                'report_time' => (string)$r['created_at'],
                'report_doctor' => (string)$r['doctor'],
                'audit_doctor' => '',   // 审核流未上线：预留字段，历史调阅展示为 —
                'status' => (string)$r['status'],
                'status_name' => $statusName,
                'apply_dept' => (string)$r['apply_dept'],
                // 影像调阅（第13项：只存引用 → 阅片器直链）
                'study_uid' => $studyUid,
                'viewer_url' => $viewerUrl,
                // 报告详情（只读；仅以下两字段允许前端提供复制到当前报告）
                'findings' => $findings,
                'conclusion' => $conclusion,
                'clinical_diag' => (string)$r['clinical_diag'],
            );
        }
        json_ok(array(
            'patient' => array(
                'patient_id' => $patient['patient_no'],
                'name' => $patient['name'],
                'gender' => $patient['gender'],
                'age_fmt' => age_format($patient['birth_date']),
            ),
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'has_more' => ($page * $pageSize) < $total,
        ));
        break;

    /* ==================== PACS Web 阅片器地址（study_uid 变量替换） ====================
     * 说明（优化项2/3）：影像只存引用——优先读 imaging_refs 影像引用表
     * （含 region 区域影像存储指向）；引用不存在时回退报告号/申请单号占位 UID。
     * Web 阅片器（无插件、窗宽窗位/缩放平移/多序列/MPR/测量标注能力由阅片器
     * 自身提供）通过 WADO-RS/DICOMweb 拉取影像，热数据调阅由 region 存储保障。 */
    case 'viewer_url':
        $itemId = did(get('item_id'));
        $tpl = trim((string)setting('pacs_viewer_url', ''));
        if ($tpl === '') json_fail('未配置 Web 阅片器 URL 模板，请管理员在【外部接口集成 → DICOM/PACS】中配置');
        $it = OrderRepository::one('SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['item_type'] !== 'imaging') json_fail('检查项目不存在');
        // 影像引用优先（只存引用架构：study_uid 唯一 + region 指向区域存储）
        $studyUid = '';
        $region = '';
        $seriesCount = 0;
        $ref = ImagingRepository::refByItem($itemId);
        if ($ref) {
            $studyUid = (string)$ref['study_uid'];
            $region = (string)$ref['region'];
            $series = json_decode((string)$ref['series_uids'], true);
            $seriesCount = is_array($series) ? count($series) : 0;
        } else {
            // 回退占位：优先报告号（报告即一次完整检查的出具单元），回退申请单号
            if ((int)$it['result_id'] > 0) {
                $rep = OrderRepository::one("SELECT report_no FROM reports WHERE result_id=? AND status<>'withdrawn' ORDER BY id DESC LIMIT 1", array((int)$it['result_id']));
                if ($rep) $studyUid = (string)$rep['report_no'];
            }
            if ($studyUid === '') {
                $o = OrderRepository::one('SELECT order_no FROM orders WHERE id=?', array((int)$it['order_id']));
                $studyUid = $o ? (string)$o['order_no'] : (string)$it['id'];
            }
        }
        json_ok(array(
            'url' => str_replace('{study_uid}', rawurlencode($studyUid), $tpl),
            'study_uid' => $studyUid,
            'region' => $region,
            'series_count' => $seriesCount,
            'from_ref' => (bool)$ref,
        ));
        break;

    /* ==================== 报告草稿（服务端同步，跨设备跨浏览器保留） ====================
     * 说明：localStorage 草稿仅本机有效（换设备/清缓存丢失），升级为服务端
     * 草稿：settings 键值对存储（img_draft_{uid}_{itemId}），随登录会话同步；
     * 仅本人可读写（键内含 uid），登记/提交状态由 save_result 主流程兜底校验。 */
    case 'draft_save':
        $itemId = did(post('item_id'));
        if ($itemId <= 0) json_fail('缺少检查项目标识');
        $findings = (string)post('findings', '');
        $conclusion = (string)post('conclusion', '');
        // 项目归属校验（防跨患者写草稿）：项目须属于影像类型且本人可访问该就诊
        $it = OrderRepository::one("SELECT * FROM order_items WHERE id=? AND item_type='imaging'", array($itemId));
        if (!$it) json_fail('检查项目不存在');
        $rv = get_visit_row((int)$it['visit_id']);
        if (!$rv || !dept_visit_allowed($rv['visit'], $u)) json_fail('无权限操作该就诊');
        set_setting('img_draft_' . (int)$u['id'] . '_' . $itemId, json_encode(array(
            'findings' => $findings, 'conclusion' => $conclusion, 'at' => now_str(),
        ), JSON_UNESCAPED_UNICODE));
        json_ok(array(), '草稿已同步到服务端（跨设备保留）');
        break;

    case 'draft_load':
        $itemId = did(get('item_id'));
        if ($itemId <= 0) json_ok(array('draft' => null));
        $it = OrderRepository::one("SELECT visit_id FROM order_items WHERE id=? AND item_type='imaging'", array($itemId));
        if (!$it) json_ok(array('draft' => null));
        $rv = get_visit_row((int)$it['visit_id']);
        if (!$rv || !dept_visit_allowed($rv['visit'], $u)) json_ok(array('draft' => null));
        $raw = setting('img_draft_' . (int)$u['id'] . '_' . $itemId, '');
        $draft = $raw !== '' ? json_decode($raw, true) : null;
        json_ok(array('draft' => is_array($draft) ? $draft : null));
        break;

    case 'draft_clear':
        $itemId = did(post('item_id'));
        if ($itemId > 0) {
            DB::exec('DELETE FROM settings WHERE skey=?', array('img_draft_' . (int)$u['id'] . '_' . $itemId));
        }
        json_ok(array(), '草稿已清除');
        break;

    /* ==================== 影像引用查询（管理端/影像科，只存引用架构视图） ==================== */
    case 'refs_list':
        if (!in_array($u['role'], array('admin', 'imaging'), true)) json_fail('无权限查看影像引用');
        $kw = trim((string)get('kw', ''));
        $page = max(1, (int)get('page', 1));
        $pageSize = 20;
        $where = '1=1';
        $params = array();
        if ($kw !== '') {
            // 检索口径：流水号 / 患者编号 / 报告号（引用元数据内）/ 申请单号——三单匹配键
            $where .= ' AND (ir.flow_no LIKE ? OR ir.patient_no LIKE ? OR o.order_no LIKE ?)';
            $like = '%' . $kw . '%';
            $params = array($like, $like, $like);
        }
        $total = (int)OrderRepository::val(
            "SELECT COUNT(*) FROM imaging_refs ir LEFT JOIN orders o ON o.id=ir.order_id WHERE $where",
            $params
        );
        $rows = OrderRepository::q(
            "SELECT ir.*, o.order_no, o.category_name, oi.item_name, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM imaging_refs ir
             LEFT JOIN orders o ON o.id=ir.order_id
             LEFT JOIN order_items oi ON oi.id=ir.order_item_id
             LEFT JOIN patients p ON p.patient_no=ir.patient_no
             WHERE $where
             ORDER BY ir.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, array($pageSize, ($page - 1) * $pageSize))
        );
        $list = array();
        foreach ($rows as $r) {
            $list[] = array(
                'id' => oid((int)$r['id']),
                'flow_no' => (string)$r['flow_no'],
                'patient_no' => (string)$r['patient_no'],
                'patient_name' => (string)$r['pname'],
                'gender' => (string)$r['pgender'],
                'age_fmt' => age_format($r['pbirth']),
                'order_no' => (string)$r['order_no'],
                'item_name' => (string)$r['item_name'],
                'study_uid' => (string)$r['study_uid'],
                'modality' => (string)$r['modality'],
                'region' => (string)$r['region'],
                'instance_count' => (int)$r['instance_count'],
                'created_by' => (string)$r['created_by'],
                'created_at' => (string)$r['created_at'],
            );
        }
        json_ok(array('list' => $list, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
        break;

    default:
        json_fail('未知操作');
}