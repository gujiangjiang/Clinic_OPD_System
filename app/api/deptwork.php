<?php
/**
 * ============================================================
 * deptwork.php — 科室工作台共用接口（护士站/检验科/影像科/药房）
 * ============================================================
 * 说明：四个医技角色工作台遵循与医生工作站一致的「顶部患者横条 +
 * 候诊列表 + 主工作区」布局，公共数据统一由本接口提供，避免各角色
 * 重复实现：
 *   1. queue       患者级候诊列表（按角色/页签过滤，一行=一位患者）
 *   2. patient     患者工作台聚合数据（就诊/患者/病历摘要/生命体征/
 *                  护理记录/开单明细）
 *   3. call_panel  科室排队悬浮窗数据（当前处理中/下一位/候诊队列）
 * 角色个性化操作（检验值录入/影像报告保存/审方发药/护理执行）仍走
 * 各角色既有接口（lab.php / imaging.php / pharmacy.php / nurse.php）。
 * 数据访问统一委托各 Repository，本文件不含原生 SQL 拼装注入风险。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
require_once __DIR__ . '/parts/dept_common.php';

$u = Auth::user();
$role = $u['role'];

if (!in_array($role, array('nurse', 'lab', 'imaging', 'pharmacy'), true)) {
    json_fail('无权限访问科室工作台');
}

/** 角色工作台配置（页签文案 / 关联明细类型 / 大屏类型） */
function deptwork_role_cfg($role) {
    $map = array(
        'nurse' => array(
            'emoji' => '💉', 'room_type' => 'nurse',
            'item_types' => array('procedure', 'prescription'), 'nurse_rx' => true,
            'tabs' => array('doing' => '待处置', 'done' => '完成', 'today' => '当日'),
        ),
        'lab' => array(
            'emoji' => '🧪', 'room_type' => 'lab',
            'item_types' => array('lab'), 'nurse_rx' => false,
            'tabs' => array('doing' => '检验中', 'done' => '完成', 'today' => '当日'),
        ),
        'imaging' => array(
            'emoji' => '🩻', 'room_type' => 'imaging',
            'item_types' => array('imaging'), 'nurse_rx' => false,
            'tabs' => array('doing' => '检查中', 'done' => '完成', 'today' => '当日'),
        ),
        'pharmacy' => array(
            'emoji' => '💊', 'room_type' => 'pharmacy',
            'item_types' => array('prescription'), 'nurse_rx' => false,
            // 审方/发药拆分：待审方（doing）→ 待发药（reviewed）→ 已发药（done）+ 当日叠加
            'tabs' => array('doing' => '待审方', 'reviewed' => '待发药', 'done' => '已发药', 'today' => '当日'),
        ),
    );
    return $map[$role];
}

/** 明细类型过滤 SQL（护士站仅纳入「护士站执行」的处置/处方） */
function deptwork_type_where($cfg, $alias) {
    $types = $cfg['item_types'];
    $ph = implode(',', array_fill(0, count($types), '?'));
    $sql = "$alias.item_type IN ($ph)";
    $params = $types;
    if (!empty($cfg['nurse_rx'])) {
        $sql = "($alias.item_type IN ($ph) AND $alias.is_nurse=1)";
    }
    return array($sql, $params);
}

/**
 * 患者级候诊队列原始行（一行=一位患者，聚合其相关明细数量与状态）
 * @return array 原始行（未格式化 visit_id 混淆等）
 */
function deptwork_queue_rows($u, $status, $today) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    // 双筛选：状态页签（药房含 reviewed 待发药，互斥单选）+ today（当日叠加可选）
    if (!in_array($status, array('doing', 'reviewed', 'done'), true)) $status = 'doing';
    $today = (int)$today === 1 ? 1 : 0;
    list($typeWhere, $typeParams) = deptwork_type_where($cfg, 'oi');

    $deptIds = user_dept_ids($u);
    $deptWhere = '';
    if ($deptIds) {
        $deptWhere = ' AND r.current_dept_id IN (' . in_placeholders($deptIds) . ')';
    }

    // 患者级归类（HAVING）：只要存在未办结项目 → 归入「待处置/待审方/检查中」；
    // 全部办结才算「完成」。药房按订单状态分三段：待审方（orders.status='paid'）/
    // 待发药（'reviewed'）/ 已发药（全部订单 dispensed/rejected 等）。
    $unDoneSet = ($role === 'pharmacy')
        ? "'paid'"
        : (($role === 'nurse') ? "'paid','dispensing'" : "'paid','registered'");
    $finDoneSet = ($role === 'pharmacy')
        ? "'done','dispensed','dispensing'"
        : "'done','dispensed'";
    if ($role === 'pharmacy') {
        // 药房：按订单状态（join orders o）判定，而非明细状态（审方通过后明细仍为 paid）
        if ($status === 'doing') {
            $having = "SUM(CASE WHEN o.status='paid' THEN 1 ELSE 0 END) > 0";
        } elseif ($status === 'reviewed') {
            $having = "SUM(CASE WHEN o.status='reviewed' THEN 1 ELSE 0 END) > 0";
        } else {
            $having = "COUNT(DISTINCT o.id) > 0
                AND SUM(CASE WHEN o.status='paid' THEN 1 ELSE 0 END) = 0
                AND SUM(CASE WHEN o.status='reviewed' THEN 1 ELSE 0 END) = 0";
        }
    } else {
        if ($status === 'doing') {
            $having = "SUM(CASE WHEN oi.status IN ($unDoneSet) THEN 1 ELSE 0 END) > 0";
        } else {
            $having = "SUM(CASE WHEN oi.status IN ($finDoneSet) THEN 1 ELSE 0 END) > 0
                AND SUM(CASE WHEN oi.status IN ($unDoneSet) THEN 1 ELSE 0 END) = 0";
        }
    }
    // 「当日」叠加筛选
    $todayWhere = $today ? " AND date(oi.created_at)=?" : '';

    // 可见天数过滤：每条明细按其开单医生的可见天数过滤；多医生开单以各自天数并集。
    // 双驱动方言：SQLite 用日期修饰符 + 标量 MAX/MIN；MySQL 用 DATE_SUB + GREATEST/LEAST。
    $queueDaysClause = (DB_DRIVER === 'mysql')
        ? "date(oi.created_at) >= DATE_SUB(CURDATE(), INTERVAL (GREATEST(2, LEAST(7, COALESCE(usr.queue_days,3))) - 1) DAY)"
        : "date(oi.created_at) >= date('now','localtime','-' || (MAX(2, MIN(7, COALESCE(usr.queue_days,3))) - 1) || ' days')";

    // 可见天数跟随开单医生权限（users.queue_days 2-7，默认 3）：
    // 每条明细按其开单医生的可见天数过滤；多医生开单以各自天数并集（取最长窗口）。
    $sql = "SELECT r.id AS visit_id, r.current_dept_name, r.current_dept_id, r.visit_seq, r.flow_no,
                r.status AS visit_status, r.registered_at, r.first_dept_name,
                p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth,
                COUNT(oi.id) AS item_cnt,
                SUM(CASE WHEN oi.status='paid' THEN 1 ELSE 0 END) AS st_paid,
                SUM(CASE WHEN oi.status='registered' THEN 1 ELSE 0 END) AS st_reg,
                SUM(CASE WHEN oi.status='dispensing' THEN 1 ELSE 0 END) AS st_dispensing,
                SUM(CASE WHEN oi.status='dispensed' THEN 1 ELSE 0 END) AS st_dispensed,
                SUM(CASE WHEN oi.status='done' THEN 1 ELSE 0 END) AS st_done,
                SUM(CASE WHEN oi.item_type='procedure' THEN 1 ELSE 0 END) AS proc_total,
                SUM(CASE WHEN oi.item_type='procedure' AND oi.status='done' THEN 1 ELSE 0 END) AS proc_done,
                SUM(CASE WHEN oi.item_type='prescription' THEN 1 ELSE 0 END) AS med_total,
                SUM(CASE WHEN oi.item_type='prescription' AND oi.status='dispensed' THEN 1 ELSE 0 END) AS med_done,
                COUNT(DISTINCT CASE WHEN o.status='paid' THEN o.id END) AS ord_paid,
                COUNT(DISTINCT CASE WHEN o.status='reviewed' THEN o.id END) AS ord_reviewed,
                COUNT(DISTINCT CASE WHEN o.status='dispensed' THEN o.id END) AS ord_dispensed,
                COUNT(DISTINCT o.id) AS ord_total,
                MAX(oi.created_at) AS last_order_at, MAX(oi.executed_at) AS max_executed
            FROM order_items oi
            LEFT JOIN users usr ON usr.id=oi.doctor_id
            JOIN orders o ON o.id=oi.order_id
            JOIN registrations r ON r.id=oi.visit_id
            JOIN patients p ON p.patient_no=oi.patient_no
            WHERE $typeWhere
              AND $queueDaysClause
              $deptWhere$todayWhere
            GROUP BY oi.visit_id
            HAVING $having";
    // 排序：待处置/待审方按最后一次开具到本科室的时间正序（最新在下面）；
    // 待发药同样按时间正序；完成按最近完成时间倒序（最新完成在上面）
    if ($status === 'done') {
        $sql .= ' ORDER BY max_executed DESC';
    } else {
        $sql .= ' ORDER BY last_order_at ASC';
    }
    $sql .= ' LIMIT 300';

    $params = array_merge($typeParams, $deptIds);
    if ($today) $params[] = today_str();
    return OrderRepository::q($sql, $params);
}

/** 患者级候诊队列（一行=一位患者，聚合其相关明细数量与状态） */
function deptwork_queue($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $status = get('status', 'doing');
    if (!in_array($status, array('doing', 'reviewed', 'done'), true)) $status = 'doing';
    $today = (int)get('today', 0) === 1 ? 1 : 0;
    $rows = deptwork_queue_rows($u, $status, $today);

    $list = array();
    foreach ($rows as $r) {
        $list[] = array(
            'code' => oid((int)$r['visit_id']),
            'name' => $r['pname'],
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'dept_name' => $r['current_dept_name'] ? $r['current_dept_name'] : $r['first_dept_name'],
            'visit_seq' => (int)$r['visit_seq'],
            'flow_no' => $r['flow_no'],
            // 时间 = 最后一次开具到本科室的处置/医嘱时间（非挂号时间）
            'date' => substr($r['last_order_at'], 0, 10),
            'time' => substr($r['last_order_at'], 11, 5),
            'visit_status' => $r['visit_status'],
            'registered_at' => $r['registered_at'],
            'item_cnt' => (int)$r['item_cnt'],
            'st_paid' => (int)$r['st_paid'],
            'st_reg' => (int)$r['st_reg'],
            'st_dispensing' => (int)$r['st_dispensing'],
            'st_dispensed' => (int)$r['st_dispensed'],
            'st_done' => (int)$r['st_done'],
            'proc_total' => (int)$r['proc_total'],
            'proc_done' => (int)$r['proc_done'],
            'med_total' => (int)$r['med_total'],
            'med_done' => (int)$r['med_done'],
            // 药房订单级计数：待审方 / 待发药 / 已发药（审方/发药拆分后按订单状态）
            'ord_paid' => (int)$r['ord_paid'],
            'ord_reviewed' => (int)$r['ord_reviewed'],
            'ord_dispensed' => (int)$r['ord_dispensed'],
            'ord_total' => (int)$r['ord_total'],
        );
    }
    $pref = isset($_SESSION['deptwork_tab'][$role]) ? $_SESSION['deptwork_tab'][$role] : array();
    json_ok(array(
        'role' => $role,
        'tabs' => $cfg['tabs'],
        'list' => $list,
        'pref' => array(
            'status' => isset($pref['status']) && in_array($pref['status'], array('doing', 'reviewed', 'done'), true) ? $pref['status'] : 'doing',
            'today' => !empty($pref['today']) ? 1 : 0,
        ),
    ));
}

/** 页签偏好保存（登录会话，跨页面保持） */
function deptwork_queue_pref($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $status = get('status', 'doing');
    if (!in_array($status, array('doing', 'reviewed', 'done'), true)) $status = 'doing';
    $today = (int)get('today', 0) === 1 ? 1 : 0;
    $_SESSION['deptwork_tab'][$role] = array('status' => $status, 'today' => $today);
    json_ok(array('status' => $status, 'today' => $today));
}

/** 病历摘要（主诉/现病史/既往史/过敏史/查体/初步诊断）——多文书聚合取首个非空 */
function deptwork_record_summary($visitId) {
    $rows = EmrRepository::q('SELECT emr_data FROM patient_records WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    $secs = array('chief_complaint', 'history_present', 'past_history', 'allergies', 'physical_exam', 'diagnoses');
    $collect = array();
    foreach ($rows as $r) {
        $e = emr_merge_defaults(emr_normalize(json_decode((string)$r['emr_data'], true) ?: array()), emr_default_data(null));
        foreach ($secs as $k) {
            if (!isset($collect[$k]) && !empty($e[$k])) $collect[$k] = $e[$k];
        }
    }
    return array(
        'chief_complaint' => emr_cc_text(isset($collect['chief_complaint']) ? $collect['chief_complaint'] : array()),
        'present_illness' => emr_pi_text(isset($collect['history_present']) ? $collect['history_present'] : array()),
        'past_history' => emr_ph_text(isset($collect['past_history']) ? $collect['past_history'] : array()),
        'allergy_history' => emr_al_text(isset($collect['allergies']) ? $collect['allergies'] : array()),
        'physical_exam' => emr_pe_text(isset($collect['physical_exam']) ? $collect['physical_exam'] : array()),
        // 初步诊断仅名称（不含 ICD10 编码）——护理/医技工作台摘要展示精简
        'diagnosis' => emr_diag_names(isset($collect['diagnoses']) ? $collect['diagnoses'] : array()),
    );
}

/** 开单明细（含项目目录信息与结果数据，供各角色工作台复用）
 *  排序：按开单时间正序（最早开单在上、最新在下），药房/检验/影像按就诊先后顺序处理 */
function deptwork_orders($visitId) {
    $orders = OrderRepository::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id ASC', array((int)$visitId));
    $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
    $out = array();
    foreach ($orders as $o) {
        $items = array();
        foreach (OrderRepository::itemsByOrder((int)$o['id']) as $it) {
            $row = array(
                'id' => oid((int)$it['id']),
                'order_id' => oid((int)$o['id']),
                'item_id' => (int)$it['item_id'],
                'item_name' => $it['item_name'],
                'item_type' => $it['item_type'],
                'status' => $it['status'],
                'quantity' => (int)$it['quantity'],
                'price' => (float)$it['price'],
                'single_dose' => $it['single_dose'],
                'frequency' => $it['frequency'],
                'route' => $it['route'],
                'is_nurse' => (int)$it['is_nurse'],
                'sub_of' => (int)$it['sub_of'],
                'group_no' => (int)$it['group_no'],
                'is_parent' => (int)(isset($it['is_parent']) ? $it['is_parent'] : 0),
                'doctor_id' => (int)$o['doctor_id'],
                'doctor_name' => $o['doctor_name'],
                'executed_by' => $it['executed_by'],
                'executed_at' => $it['executed_at'],
                'created_at' => $it['created_at'],
                'unit' => '', 'normal_range' => '', 'critical_low' => '', 'critical_high' => '',
                'is_group' => 0, 'members' => array(),
                'values_json' => '', 'findings' => '', 'conclusion' => '',
                'report_no' => '', 'report_id' => 0, 'report_status' => '',
            );
            // 项目目录信息：检验/影像回填计量单位、正常范围与危急值
            if ($it['item_type'] === 'lab' && (int)$it['item_id'] > 0) {
                $lm = OrderRepository::one('SELECT * FROM lab_items WHERE id=?', array((int)$it['item_id']));
                if ($lm) {
                    $row['unit'] = $lm['unit'];
                    $row['normal_range'] = $lm['normal_range'];
                    $row['critical_low'] = $lm['critical_low'];
                    $row['critical_high'] = $lm['critical_high'];
                    $row['is_group'] = (int)$lm['is_group'];
                    if ((int)$lm['is_group'] === 1) {
                        $row['members'] = array_map(function ($m) {
                            return array(
                                'id' => (int)$m['id'], 'name' => $m['name'], 'unit' => $m['unit'],
                                'normal_range' => $m['normal_range'],
                                'critical_low' => $m['critical_low'], 'critical_high' => $m['critical_high'],
                            );
                        }, OrderRepository::q("SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id", array((int)$lm['id'])));
                    }
                }
            }
            // 结果数据：检验结果值 / 影像所见与结论
            $result = OrderRepository::one('SELECT * FROM results WHERE order_item_id=?', array((int)$it['id']));
            if ($result) {
                $row['values_json'] = (string)$result['values_json'];
                $row['findings'] = (string)$result['findings'];
                $row['conclusion'] = (string)$result['conclusion'];
            }
            // 报告：已完成项目关联报告号（可查看/撤回）
            if ((int)$it['result_id'] > 0) {
                $report = OrderRepository::one("SELECT id, report_no, status FROM reports WHERE result_id=? AND status<>'withdrawn' ORDER BY id DESC LIMIT 1", array((int)$it['result_id']));
                if ($report) {
                    $row['report_id'] = oid((int)$report['id']);
                    $row['report_no'] = $report['report_no'];
                    $row['report_status'] = $report['status'];
                }
            }
            $items[] = $row;
        }
        $out[] = array(
            'order_id' => oid((int)$o['id']),
            'order_no' => $o['order_no'],
            'order_type' => $o['order_type'],
            'type_name' => isset($typeNames[$o['order_type']]) ? $typeNames[$o['order_type']] : $o['order_type'],
            'doctor_name' => $o['doctor_name'],
            'dept_name' => $o['dept_name'],
            'created_at' => $o['created_at'],
            'total_amount' => (float)$o['total_amount'],
            'status' => $o['status'],
            'done_by' => isset($o['done_by']) ? $o['done_by'] : '',
            'dispensed_at' => isset($o['dispensed_at']) ? $o['dispensed_at'] : '',
            'review_by' => isset($o['review_by']) ? $o['review_by'] : '',
            'reviewed_at' => isset($o['reviewed_at']) ? $o['reviewed_at'] : '',
            'is_skin_test' => (int)(isset($o['is_skin_test']) ? $o['is_skin_test'] : 0),
            'source_order_id' => ((int)(isset($o['source_order_id']) ? $o['source_order_id'] : 0) > 0) ? oid((int)$o['source_order_id']) : 0,
            // 皮试处置单：附带皮试药品信息（供护士记录皮试结果）
            'skin_drug_id' => 0, 'skin_drug_name' => '',
            'items' => $items,
        );
        // 皮试处置单（is_skin_test=1 且关联皮试处方）→ 回填皮试药品（源处方主药）
        if (isset($o['is_skin_test']) && (int)$o['is_skin_test'] === 1 && $o['order_type'] === 'procedure' && (int)$o['source_order_id'] > 0) {
            $srcIt = OrderRepository::one("SELECT item_id, item_name FROM order_items WHERE order_id=? AND item_type='prescription' AND sub_of=0 ORDER BY id LIMIT 1", array((int)$o['source_order_id']));
            if ($srcIt) {
                $out[count($out) - 1]['skin_drug_id'] = (int)$srcIt['item_id'];
                $out[count($out) - 1]['skin_drug_name'] = preg_replace('/\(需要皮试\)/', '', (string)$srcIt['item_name']);
            }
        }
    }
    return $out;
}

/** 患者工作台聚合数据 */
function deptwork_patient($u) {
    $visitId = did(get('visit_id'));
    $row = get_visit_row($visitId);
    if (!$row) json_fail('就诊记录不存在');
    $visit = $row['visit'];
    $patient = $row['patient'];
    if (!dept_visit_allowed($visit, $u)) json_fail('无权限查看该患者');

    // 就诊科室类型（门诊/急诊）：横条徽章展示
    $dept = EmrRepository::one('SELECT type FROM departments WHERE id=?', array((int)$visit['current_dept_id']));

    $vitals = EmrRepository::vitalsByVisit($visitId);
    $nursing = EmrRepository::nursingByVisit($visitId);
    json_ok(array(
        'patient' => array(
            'patient_id' => $patient['patient_no'],
            'birth_date' => $patient['birth_date'],
            'gender' => $patient['gender'],
            'phone' => $patient['phone'],
            'id_card' => $patient['id_card'],
        ),
        'visit' => array(
            'id' => oid($visit['id']),
            'code' => oid($visit['id']),
            'name' => $patient['name'],
            'gender' => $patient['gender'],
            'age_fmt' => age_format($patient['birth_date'], $visit['registered_at']),
            'dept_type' => $dept ? $dept['type'] : 'clinic',
            'dept_name' => $visit['current_dept_name'],
            'first_dept_name' => $visit['first_dept_name'],
            'visit_no' => $visit['flow_no'],
            'visit_seq' => (int)$visit['visit_seq'],
            'fee_type' => isset($visit['fee_type']) ? $visit['fee_type'] : '',
            'fee' => (float)(isset($visit['fee']) ? $visit['fee'] : 0),
            'status' => $visit['status'],
            'created_at' => $visit['registered_at'],
        ),
        'summary' => deptwork_record_summary($visitId),
        'vitals' => $vitals,
        'nursing' => $nursing,
        'orders' => deptwork_orders($visitId),
    ));
}

/** 科室排队悬浮窗数据（当前处理中/下一位/候诊队列）
 * 数据源与候诊列表一致：患者级在办队列（按本角色未办结明细聚合），
 * 而非注册在当前科室的就诊——患者挂号在临床科室，护士/检验/影像/药房
 * 的排队看板须以其明细为准，否则恒为空。 */
function deptwork_call_panel($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $deptName = array('nurse' => '护士站', 'lab' => '检验科', 'imaging' => '影像科', 'pharmacy' => '药房');
    $title = isset($deptName[$role]) ? $deptName[$role] : '科室';

    // 在办队列（doing，不含当日过滤）
    $rows = deptwork_queue_rows($u, 'doing', 0);
    $list = array();
    foreach ($rows as $r) {
        $list[] = array(
            'name' => $r['pname'],
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'visit_seq' => (int)$r['visit_seq'],
            'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'],
            'visit_code' => oid((int)$r['visit_id']),
            'status' => $r['visit_status'],
        );
    }

    // 当前处理中：始终返回前端正在打开的患者（即使不在在办队列，如查看已完成单）
    $cur = null;
    $curIndex = -1;
    $curCode = get('current_visit', '');
    if ($curCode !== '') {
        foreach ($list as $i => $p) {
            if ($p['visit_code'] === $curCode) { $cur = $p; $curIndex = $i; break; }
        }
        if (!$cur) {
            // 不在在办队列：直接按就诊取回患者信息（当前打开的患者即为处理中）
            $vid = did($curCode);
            $row = get_visit_row($vid);
            if ($row && dept_visit_allowed($row['visit'], $u)) {
                $v = $row['visit'];
                $pt = $row['patient'];
                $cur = array(
                    'name' => $pt['name'],
                    'gender' => $pt['gender'],
                    'age_fmt' => age_format($pt['birth_date'], $v['registered_at']),
                    'visit_seq' => (int)$v['visit_seq'],
                    'flow_no' => $v['flow_no'],
                    'patient_no' => $v['patient_no'],
                    'visit_code' => $curCode,
                    'status' => $v['status'],
                );
            }
        }
    }
    // 下一位 = 当前患者之后的首位在办患者；无当前患者则取队首
    $next = null;
    if ($curIndex >= 0) {
        if (isset($list[$curIndex + 1])) $next = $list[$curIndex + 1];
    } elseif (isset($list[0])) {
        $next = $list[0];
    }
    json_ok(array(
        'dept_name' => $title,
        'bound' => true,
        'current' => $cur,
        'next' => $next,
        'waiting' => $list,
    ));
}

/* ==================== 医技诊室绑定 / 叫号（参考医生工作站大屏） ====================
 * 说明：护士站/检验/影像/药房工作台「叫号」同样先绑定大屏诊室（悬浮窗），
 * 绑定后才打开叫号面板；绑定/解绑/心跳/叫号与医生诊室共用 clinic_rooms 的
 * current_doctor_id 关联（一人一块屏），room_type 限定本角色诊室类型。 */

/** 可用诊室列表（本角色类型；绑定归属随 current_doctor_id） */
function deptwork_get_available_rooms($u) {
    $cfg = deptwork_role_cfg($u['role']);
    $roomType = $cfg['room_type'];
    $myDepts = user_dept_ids($u);
    $where = 'room_type=?';
    $params = array($roomType);
    if ($myDepts) {
        $where .= ' AND dept_id IN (' . in_placeholders($myDepts) . ')';
        $params = array_merge($params, $myDepts);
    }
    $rows = DB::q("SELECT * FROM clinic_rooms WHERE $where ORDER BY id", $params);
    $list = array();
    foreach ($rows as $room) {
        $isOnline = (!empty($room['screen_last_heartbeat']) && (time() - strtotime($room['screen_last_heartbeat'])) <= 30);
        if (!$isOnline) {
            $status = 'offline'; $text = '大屏离线，请联系管理员'; $sel = false;
        } elseif ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
            $status = 'occupied'; $text = $room['current_doctor_name'] . ' 正在使用'; $sel = false;
        } else {
            $status = ($room['current_doctor_id'] == $u['id']) ? 'bound' : 'available';
            $text = $status === 'bound' ? '已绑定' : '在线空闲'; $sel = true;
        }
        $list[] = array('id' => (int)$room['id'], 'name' => $room['room_name'], 'status' => $status, 'status_text' => $text, 'selectable' => $sel);
    }
    $myBound = DB::one('SELECT * FROM clinic_rooms WHERE current_doctor_id=? ORDER BY id DESC LIMIT 1', array($u['id']));
    json_ok(array('list' => $list, 'bound' => $myBound ? array('id' => (int)$myBound['id'], 'name' => $myBound['room_name']) : null));
}

/** 绑定大屏诊室 */
function deptwork_bind_room($u) {
    $roomId = (int)post('room_id');
    $room = DB::one('SELECT * FROM clinic_rooms WHERE id=?', array($roomId));
    if (!$room) json_fail('诊室不存在');
    // 类型限定：仅可绑定本角色类型诊室（护士→nurse / 检验→lab / 影像→imaging / 药房→pharmacy）
    $cfg = deptwork_role_cfg($u['role']);
    if ($room['room_type'] !== $cfg['room_type']) json_fail('无权绑定该类型诊室');
    // 后端强拦截：大屏必须在线
    if (empty($room['screen_last_heartbeat']) || (time() - strtotime($room['screen_last_heartbeat'])) > 30) {
        json_fail('该大屏当前处于离线状态，无法绑定，请确保大屏已开启并在运行！');
    }
    // 已被他人占用 → 拒绝
    if ($room['current_doctor_id'] > 0 && (int)$room['current_doctor_id'] !== (int)$u['id']) {
        json_fail('该大屏已被 ' . $room['current_doctor_name'] . ' 使用，无法绑定');
    }
    // 释放本人此前绑定的其他诊室（一人一块屏）
    DB::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL WHERE current_doctor_id=?', array($u['id']));
    DB::exec('UPDATE clinic_rooms SET current_doctor_id=?, current_doctor_name=?, doctor_heartbeat=?, call_session_date=?, updated_at=? WHERE id=?',
        array($u['id'], $u['name'], now_str(), today_str(), now_str(), $roomId));
    json_ok(array('room_id' => $roomId, 'room_name' => $room['room_name']), '已绑定大屏「' . $room['room_name'] . '」');
}

/** 解绑大屏诊室 */
function deptwork_unbind_room($u) {
    $roomId = (int)post('room_id');
    DB::exec('UPDATE clinic_rooms SET current_doctor_id=0, current_doctor_name="", doctor_heartbeat=NULL, current_visit_id=0, current_flow_no="", current_called_at="", last_call_action="", last_call_at="", updated_at=? WHERE id=? AND current_doctor_id=?',
        array(now_str(), $roomId, $u['id']));
    json_ok(array(), '已释放诊室');
}

/** 绑定心跳保活 */
function deptwork_room_heartbeat($u) {
    $roomId = (int)post('room_id');
    DB::exec('UPDATE clinic_rooms SET doctor_heartbeat=?, updated_at=? WHERE id=? AND current_doctor_id=?',
        array(now_str(), now_str(), $roomId, $u['id']));
    json_ok(array());
}

/** 当前绑定诊室（无则 null） */
function deptwork_bound_room($u) {
    return DB::one('SELECT * FROM clinic_rooms WHERE current_doctor_id=? ORDER BY id DESC LIMIT 1', array($u['id']));
}

/** 医技叫号下一位：把在办队列中的下一位患者推送到大屏（当前处理中之后或队首） */
function deptwork_call_next($u) {
    $room = deptwork_bound_room($u);
    if (!$room) json_fail('请先绑定大屏诊室后再叫号');
    $rows = deptwork_queue_rows($u, 'doing', 0);
    if (!$rows) json_fail('当前暂无待叫号患者');
    // 下一位：优先「当前打开患者」之后的首位，否则取队首
    $curCode = post('current_visit', '');
    $next = null;
    if ($curCode !== '') {
        $found = false;
        foreach ($rows as $r) {
            if ($found) { $next = $r; break; }
            if (oid((int)$r['visit_id']) === $curCode) $found = true;
        }
    }
    if (!$next) $next = $rows[0];
    $visitId = (int)$next['visit_id'];
    $now = now_str();
    DB::exec('UPDATE clinic_rooms SET current_visit_id=?, current_flow_no=?, current_called_at=?, last_call_action=?, last_call_at=?, updated_at=? WHERE id=?',
        array($visitId, $next['flow_no'], $now, 'call', $now, $now, (int)$room['id']));
    DB::insert('INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at) VALUES(?,?,?,?,?,?,?,?,?)',
        array($visitId, $next['flow_no'], $next['patient_no'], (int)$room['dept_id'], (int)$room['id'], (int)$u['id'], $u['name'], 'call', $now));
    json_ok(array('visit_id' => oid($visitId), 'name' => $next['pname'], 'flow_no' => $next['flow_no']), '已呼叫 ' . $next['pname']);
}

/** 医技再次叫号（重复播报当前） */
function deptwork_call_repeat($u) {
    $room = deptwork_bound_room($u);
    if (!$room) json_fail('请先绑定大屏诊室');
    if ((int)$room['current_visit_id'] <= 0) json_fail('当前无正在呼叫的患者');
    $now = now_str();
    DB::exec('UPDATE clinic_rooms SET current_called_at=?, last_call_action=?, last_call_at=?, updated_at=? WHERE id=?',
        array($now, 'repeat_call', $now, $now, (int)$room['id']));
    DB::insert('INSERT INTO call_events(visit_id, flow_no, patient_no, dept_id, room_id, doctor_id, doctor_name, action, created_at) VALUES(?,?,?,?,?,?,?,?,?)',
        array((int)$room['current_visit_id'], $room['current_flow_no'], '', (int)$room['dept_id'], (int)$room['id'], (int)$u['id'], $u['name'], 'repeat_call', $now));
    json_ok(array(), '已再次呼叫');
}

switch ($action) {
    case 'queue':
        deptwork_queue($u);
        break;
    case 'queue_pref':
        deptwork_queue_pref($u);
        break;
    case 'patient':
        deptwork_patient($u);
        break;
    case 'call_panel':
        deptwork_call_panel($u);
        break;
    case 'get_available_rooms':
        deptwork_get_available_rooms($u);
        break;
    case 'bind_room':
        deptwork_bind_room($u);
        break;
    case 'unbind_room':
        deptwork_unbind_room($u);
        break;
    case 'room_heartbeat':
        deptwork_room_heartbeat($u);
        break;
    case 'call_next':
        deptwork_call_next($u);
        break;
    case 'call_repeat':
        deptwork_call_repeat($u);
        break;
    default:
        json_fail('未知操作');
}
