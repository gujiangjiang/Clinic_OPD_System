<?php
/**
 * ============================================================
 * print.php — 统一打印中心数据接口
 * ============================================================
 * 说明：所有单据打印（挂号凭条/缴费凭条/申请单/处方单/处置单/
 * 检验检查报告/诊断证明/电子病历）统一由本接口提供 HTML，
 * 前端 print.js 渲染后打印。管理员【打印中心】及各科室补打均调用本接口。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';

$u = Auth::user();

/**
 * 打印访问守卫（越权防护）：管理员放行；其余按角色白名单 + 就诊归属校验。
 * 说明：不做可查看天数限制——历史只读面板（打印）不受 queue_days 限制。
 * @param array  $visit 就诊记录（registrations 行）
 * @param array  $allowedRoles 允许打印该单据的角色
 */
function print_guard($visit, $allowedRoles) {
    global $u;
    if ($u['role'] === 'admin') return;
    if (!in_array($u['role'], $allowedRoles, true)) {
        json_fail('无权限打印该单据');
    }
    // 收费员打印凭条不受科室限制（全院收费）
    if ($u['role'] === 'cashier') return;
    if (!visit_dept_authorized($visit, $u)) {
        json_fail('无权打印该就诊的单据');
    }
}

switch ($action) {

    /* ---------------- 挂号凭条 ---------------- */
    case 'receipt':
        $vid = did(get('visit_id'));
        if ($vid <= 0) json_fail('链接无效或已过期，请重新获取打印凭据');
        $row = get_visit_row($vid);
        if (!$row) json_fail('就诊记录不存在');
        print_guard($row['visit'], array('cashier'));
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['first_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        $visit['status_name'] = visit_status_name($visit['status']);
        json_ok(array('html' => pt_receipt($visit, $row['patient'])));
        break;

    /* ---------------- 缴费凭条 ---------------- */
    case 'payment':
        $payId = did(get('payment_id'));
        $pay = EmrRepository::one('SELECT * FROM payments WHERE id=?', array($payId));
        if (!$pay) json_fail('缴费记录不存在');
        $payVisit = EmrRepository::one('SELECT * FROM registrations WHERE id=?', array($pay['visit_id']));
        if (!$payVisit) json_fail('就诊记录不存在');
        print_guard($payVisit, array('cashier'));
        $items = array();
        if ($pay['kind'] === 'visit') {
            // 挂号费缴费：项目为挂号费
            $visit = EmrRepository::one('SELECT * FROM registrations WHERE id=?', array($pay['visit_id']));
            $dept = $visit ? EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['first_dept_id'])) : null;
            $items[] = array('name' => '挂号费（' . ($visit ? $visit['first_dept_name'] : '') . '）', 'quantity' => 1, 'price' => $pay['total']);
        } else {
            $rows = EmrRepository::q('SELECT * FROM order_items WHERE order_id=?', array($pay['order_id']));
            foreach ($rows as $r) {
                $items[] = array('name' => $r['item_name'], 'quantity' => (int)$r['quantity'], 'price' => $r['price']);
            }
        }
        json_ok(array('html' => pt_payment($pay, $items)));
        break;

    /* ---------------- 申请单 / 处方单 / 处置单 ---------------- */
    case 'order':
        // 支持批量：检查申请单按分类拆分后，一次性打印多张（order_ids=1,2,3）
        $idsRaw = trim((string)get('order_ids', ''));
        if ($idsRaw !== '') {
            $orderIds = array_values(array_unique(array_filter(did_list($idsRaw), function ($v) { return $v > 0; })));
        } else {
            $orderIds = array_filter(array(did(get('order_id'))), function ($v) { return $v > 0; });
        }
        if (!$orderIds) json_fail('开单记录不存在');
        $titles = array('lab' => '检验申请单', 'imaging' => '检查申请单', 'procedure' => '处置申请单', 'prescription' => '门诊处方笺');
        $html = '';
        foreach ($orderIds as $orderId) {
            $order = EmrRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
            if (!$order) json_fail('开单记录不存在');
            // 按就诊归属校验
            $orderVisit = EmrRepository::one('SELECT * FROM registrations WHERE id=?', array($order['visit_id']));
            if (!$orderVisit) json_fail('就诊记录不存在');
            print_guard($orderVisit, array('doctor'));
            $items = EmrRepository::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($orderId));
            // 检查申请单标题动态化：显示「{检查分类}申请单」（如 CT申请单 / DR（数字化X线）申请单）
            $title = isset($titles[$order['order_type']]) ? $titles[$order['order_type']] : '申请单';
            if ($order['order_type'] === 'imaging' && isset($order['category_name']) && trim((string)$order['category_name']) !== '' && trim((string)$order['category_name']) !== '检查') {
                $title = trim((string)$order['category_name']) . '申请单';
            }
            // 处方：按主医嘱 is_nurse 分组（子医嘱跟随主药），生成三张单据：
            // ① 非护士药品 → 门诊处方笺（药房取药，原单号）
            // ② 护士药品   → 门诊处方笺（药房取药，单号+N）——给药房取药
            // ③ 护士药品   → 门诊输液（注射）笺副本（护士站，单号+Z）——给护士
            if ($order['order_type'] === 'prescription') {
                $mainSeq = 0;
                $groups = array();
                foreach ($items as $it) {
                    if ((int)$it['sub_of'] > 0) continue;
                    $mainSeq++;
                    $subs = array();
                    foreach ($items as $subIt) {
                        if ((int)$subIt['sub_of'] === $mainSeq) $subs[] = $subIt;
                    }
                    $groups[] = array('main' => $it, 'subs' => $subs);
                }
                $pharmItems = array();
                $nurseItems = array();
                $pharmSeq = 0;
                $nurseSeq = 0;
                foreach ($groups as $g) {
                    if (!empty($g['main']['is_nurse'])) {
                        $nurseSeq++;
                        $g['main']['sub_of'] = 0;
                        $nurseItems[] = $g['main'];
                        foreach ($g['subs'] as $s) { $s['sub_of'] = $nurseSeq; $nurseItems[] = $s; }
                    } else {
                        $pharmSeq++;
                        $g['main']['sub_of'] = 0;
                        $pharmItems[] = $g['main'];
                        foreach ($g['subs'] as $s) { $s['sub_of'] = $pharmSeq; $pharmItems[] = $s; }
                    }
                }
                // ① 非护士药品处方笺
                if ($pharmItems) {
                    $html .= pt_order($order, $pharmItems, '门诊处方笺', array('note_type' => 'pharm', 'display_no' => $order['order_no']));
                }
                // ②③ 护士药品：处方笺 + 输液注射笺副本
                if ($nurseItems) {
                    $html .= pt_order($order, $nurseItems, '门诊处方笺', array('note_type' => 'pharm', 'display_no' => $order['order_no'] . 'N'));
                    $html .= pt_order($order, $nurseItems, '门诊输液（注射）笺', array('note_type' => 'nurse', 'display_no' => $order['order_no'] . 'Z'));
                }
            } else {
                $order['is_nurse_any'] = 0;
                foreach ($items as $it) {
                    if (!empty($it['is_nurse'])) $order['is_nurse_any'] = 1;
                }
                $html .= pt_order($order, $items, $title);
            }
        }
        json_ok(array('html' => $html));
        break;

    /* ---------------- 电子病历（补打） ---------------- */
    case 'record':
        $row = get_visit_row(did(get('visit_id')));
        if (!$row) json_fail('就诊记录不存在');
        print_guard($row['visit'], array('doctor'));
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        $vitals = EmrRepository::one('SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visit['id']));

        // ===== 多医生接诊（1:N）：该流水下全部文书输出为【一份连续文档】 =====
        // 首段带完整页眉（页眉归首诊文书），续写段以分割线 + 「病历续写 /
        // 续写时间」承接头开始，各段签名紧跟正文右下角，页脚仅最后一段；
        // 生命体征属就诊级数据，仅在首段展示。
        $prs = EmrRepository::q('SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC', array($visit['id']));
        if ($prs) {
            $last = count($prs) - 1;
            // 打印页脚「记录时间」统一为首诊医师首次保存病历的时间（不随续写改变）
            $firstCreatedAt = isset($prs[0]['created_at']) ? $prs[0]['created_at'] : null;
            $body = '';
            foreach ($prs as $i => $pr) {
                // 意识状态/初复诊存于旧 records 镜像表，按各文书医生本人回读
                $mirror = EmrRepository::one('SELECT consciousness, visit_type FROM records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC', array($visit['id'], $pr['doctor_id']));
                $pr['consciousness'] = $mirror ? (string)$mirror['consciousness'] : '';
                $pr['visit_type'] = ($mirror && $mirror['visit_type'] !== '') ? (string)$mirror['visit_type'] : '初诊';
                // 生命体征归属：按文书记录精确关联（record_id 优先）。
                // 续写/会诊病历各自独立体征——只取本记录关联的体征，绝不复用首诊体征；
                // 首诊记录才按 operator 回退就诊体征（护士站录入共用）。
                $segVitals = get_record_vitals($pr['id'], $visit['id'], $pr['doctor_name'], $pr['record_type']);
                $body .= pt_record($visit, $row['patient'], $pr, $segVitals ? $segVitals : array(), $i === 0 ? 'full' : 'continue', $i === $last, $firstCreatedAt);
            }
            json_ok(array('html' => '<div class="print-record-doc">' . $body . '</div>'));
        }
        // 回退：无结构化病历时按旧 records 扁平数据渲染单文档（兼容历史就诊）
        $record = EmrRepository::one('SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visit['id']));
        if (!$record) json_fail('该就诊暂无已保存的病历，请先在病历中完善主诉、现病史与初步诊断并保存后再打印');
        json_ok(array('html' => '<div class="print-record-doc">' . pt_record($visit, $row['patient'], $record, $vitals) . '</div>'));
        break;

    /* ---------------- 诊断证明（补打） ---------------- */
    case 'certificate':
        $visitId = did(get('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        print_guard($row['visit'], array('doctor'));
        $cert = EmrRepository::one('SELECT * FROM certificates WHERE visit_id=?', array($visitId));
        if (!$cert) json_fail('该就诊未开具诊断证明');
        $record = EmrRepository::one('SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($visitId));
        // 固化快照：证书存有开具时的病历摘要则原样使用（与 certificate_print
        // 同规则）——补打内容与开具时完全一致，不随后续续写漂移
        $record = cert_fallback_snapshot($record, $cert);
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        json_ok(array('html' => pt_certificate($visit, $row['patient'], $record, $cert, $cert['doctor_name'])));
        break;

    /* ==================== 知情同意书打印 ==================== */
    case 'consent':
        $id = (int)get('id', 0);
        $c = EmrRepository::one('SELECT * FROM consents WHERE id=?', array($id));
        if (!$c) json_fail('知情同意书不存在');
        $row = get_visit_row($c['visit_id']);
        if (!$row) json_fail('就诊记录不存在');
        print_guard($row['visit'], array('doctor'));
        $c['flow_no'] = $row['visit']['flow_no'];
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        // 病情介绍：取该就诊首诊文书（结构化 emr 投影），无则回退旧 records 镜像
        $record = array();
        $pr = EmrRepository::one("SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC LIMIT 1", array($c['visit_id']));
        if ($pr) {
            $emr = json_decode((string)$pr['emr_data'], true);
            if (is_array($emr)) {
                $record['chief_complaint'] = emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array());
                $record['present_illness'] = emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array());
                $record['preliminary_diagnosis'] = emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array());
            }
        }
        if (!$record) {
            $mirror = EmrRepository::one('SELECT chief_complaint, present_illness, preliminary_diagnosis FROM records WHERE visit_id=? ORDER BY id ASC LIMIT 1', array($c['visit_id']));
            if ($mirror) $record = $mirror;
        }
        json_ok(array('html' => pt_consent($visit, $row['patient'], $c, $c['doctor_name'], $record)));
        break;

    /* ---------------- 会诊申请单打印 ---------------- */
    case 'consultation':
        // 会诊科室「确认会诊」入口（withAccept）不提供打印，前端已隐藏按钮；
        // 后端同样拦截：仅允许发起科室医生（或会诊已接受的接收科室医生）打印申请单。
        $u = Auth::user();
        $cid = did(get('id'));
        $cons = EmrRepository::one('SELECT * FROM consultations WHERE id=?', array($cid));
        if (!$cons) json_fail('会诊记录不存在');
        $row = get_visit_row($cons['visit_id']);
        if (!$row) json_fail('就诊记录不存在');
        // 权限：发起医生本人 / 会诊目标科室医生（已接受处理的接收方）可打印；
        // 其他科室医生或确认会诊前的接收医生一律拒绝（后端硬拦截）。
        $curDeptRow = EmrRepository::one('SELECT current_dept_id FROM users WHERE id=?', array($u['id']));
        $curDeptId = $curDeptRow ? (int)$curDeptRow['current_dept_id'] : 0;
        $isFrom = (int)$cons['from_doctor_id'] === (int)$u['id'];
        $isTarget = (int)$cons['target_dept_id'] === $curDeptId;
        if (!$isFrom && !$isTarget) json_fail('无权打印该会诊申请单');
        if ($isTarget && $cons['status'] === 'pending' && (string)$cons['accepted_by'] === '') {
            json_fail('请先确认会诊后再打印该会诊申请单');
        }
        $visit = $row['visit'];
        $visit['name'] = $row['patient']['name'];
        $visit['gender'] = $row['patient']['gender'];
        $visit['age'] = $row['patient']['age'];
        $visit['birth_date'] = $row['patient']['birth_date'];
        $dept = EmrRepository::one('SELECT * FROM departments WHERE id=?', array($visit['current_dept_id']));
        $visit['dept_type'] = $dept ? $dept['type'] : 'clinic';
        // 病历快照：取发起科室医生的首诊文书（主诉/现病史/体格检查）
        $snap = array('chief_complaint' => '', 'present_illness' => '', 'physical_exam' => '');
        $pr = EmrRepository::one("SELECT * FROM patient_records WHERE visit_id=? AND dept_id=? AND record_type='initial' ORDER BY id ASC LIMIT 1",
            array((int)$cons['visit_id'], (int)$cons['from_dept_id']));
        if ($pr) {
            $emr = emr_merge_defaults(emr_normalize(json_decode($pr['emr_data'], true)), emr_default_data(null));
            $snap['chief_complaint'] = emr_cc_text($emr['chief_complaint']);
            $snap['present_illness'] = emr_pi_text($emr['history_present']);
            $snap['physical_exam'] = emr_pe_text($emr['physical_exam']);
        }
        $cons = consult_ensure_no($cons);
        json_ok(array('html' => pt_consult($visit, $row['patient'], $cons, $snap)));
        break;

    /* ---------------- 检验/检查报告 ---------------- */
    case 'report':
        $reportId = did(get('report_id'));
        $report = EmrRepository::one('SELECT * FROM reports WHERE id=?', array($reportId));
        if (!$report) json_fail('报告不存在');
        $result = EmrRepository::one('SELECT * FROM results WHERE id=?', array($report['result_id']));
        $item = null;
        if ($result) {
            $item = EmrRepository::one('SELECT * FROM ' . ($result['type'] === 'lab' ? 'lab_items' : 'exam_items') . ' WHERE id=?', array($result['item_id']));
        }
        $row = get_visit_row($report['visit_id']);
        // 报告打印角色白名单（检验/影像/医生/管理员）；不做科室归属限制——
        // 报告由对应科室统一登记出具，跨就诊打印属正常工作流
        if (!in_array($u['role'], array('doctor', 'lab', 'imaging', 'admin'), true)) {
            json_fail('无权限打印该报告');
        }
        json_ok(array('html' => pt_report($report, $result, $item)));
        break;

    default:
        json_fail('未知操作');
}
