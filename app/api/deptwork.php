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
            'tabs' => array('doing' => '待发药', 'done' => '完成', 'today' => '当日'),
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

/** 患者级候诊队列（一行=一位患者，聚合其相关明细数量与状态） */
function deptwork_queue($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $tab = get('tab', 'doing');
    if (!isset($cfg['tabs'][$tab])) $tab = 'doing';
    list($typeWhere, $typeParams) = deptwork_type_where($cfg, 'oi');

    $deptIds = user_dept_ids($u);
    $deptWhere = '';
    if ($deptIds) {
        $deptWhere = ' AND r.current_dept_id IN (' . in_placeholders($deptIds) . ')';
    }

    // 页签状态过滤（状态码为白名单常量，无注入风险）
    switch ($tab) {
        case 'doing':
            // 检验/影像「检查中」= 待登记(paid) + 待出报告(registered) 全流程在办项目；
            // 药房「待发药」= 已缴费待审方；护士「待处置」= 处置/医嘱在办
            $tabWhere = ($role === 'pharmacy')
                ? " AND oi.status='paid'"
                : (($role === 'nurse')
                    ? " AND oi.status IN ('paid','dispensing')"
                    : " AND oi.status IN ('paid','registered')");
            break;
        case 'done':
            $tabWhere = ($role === 'pharmacy')
                ? " AND oi.status IN ('dispensed','dispensing')"
                : " AND oi.status='done'";
            break;
        default: // today
            $tabWhere = " AND date(oi.created_at)=? AND oi.status<>'rejected'";
            break;
    }

    // 候诊可见天数与医生候诊一致（user_queue_days 2-7，默认 3）
    $since = date('Y-m-d', strtotime('-' . (user_queue_days($u) - 1) . ' days'));
    $sql = "SELECT r.id AS visit_id, r.current_dept_name, r.current_dept_id, r.visit_seq, r.flow_no,
                r.status AS visit_status, r.registered_at, r.first_dept_name,
                p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth,
                COUNT(oi.id) AS item_cnt,
                SUM(CASE WHEN oi.status='paid' THEN 1 ELSE 0 END) AS st_paid,
                SUM(CASE WHEN oi.status='registered' THEN 1 ELSE 0 END) AS st_reg,
                SUM(CASE WHEN oi.status='dispensing' THEN 1 ELSE 0 END) AS st_dispensing,
                SUM(CASE WHEN oi.status='done' THEN 1 ELSE 0 END) AS st_done,
                MIN(oi.created_at) AS min_created, MAX(oi.executed_at) AS max_executed
            FROM order_items oi
            JOIN registrations r ON r.id=oi.visit_id
            JOIN patients p ON p.patient_no=oi.patient_no
            WHERE $typeWhere AND date(oi.created_at)>=?$deptWhere$tabWhere
            GROUP BY oi.visit_id";
    switch ($tab) {
        case 'doing': $sql .= ' ORDER BY min_created ASC'; break;
        case 'done':  $sql .= ' ORDER BY max_executed DESC'; break;
        default:      $sql .= ' ORDER BY r.registered_at DESC'; break;
    }
    $sql .= ' LIMIT 300';

    $params = array_merge($typeParams, array($since), $deptIds);
    if ($tab === 'today') $params[] = today_str();
    $rows = OrderRepository::q($sql, $params);

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
            'date' => substr($r['registered_at'], 0, 10),
            'time' => substr($r['registered_at'], 11, 5),
            'visit_status' => $r['visit_status'],
            'registered_at' => $r['registered_at'],
            'item_cnt' => (int)$r['item_cnt'],
            'st_paid' => (int)$r['st_paid'],
            'st_reg' => (int)$r['st_reg'],
            'st_dispensing' => (int)$r['st_dispensing'],
            'st_done' => (int)$r['st_done'],
        );
    }
    json_ok(array(
        'role' => $role,
        'tabs' => $cfg['tabs'],
        'list' => $list,
        'pref' => array(
            'tab' => isset($_SESSION['deptwork_tab'][$role]) ? $_SESSION['deptwork_tab'][$role] : 'doing',
        ),
    ));
}

/** 页签偏好保存（登录会话，跨页面保持） */
function deptwork_queue_pref($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $tab = get('tab', 'doing');
    if (!isset($cfg['tabs'][$tab])) $tab = 'doing';
    $_SESSION['deptwork_tab'][$role] = $tab;
    json_ok(array('tab' => $tab));
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
        'diagnosis' => emr_diag_text(isset($collect['diagnoses']) ? $collect['diagnoses'] : array()),
    );
}

/** 开单明细（含项目目录信息与结果数据，供各角色工作台复用） */
function deptwork_orders($visitId) {
    $orders = OrderRepository::byVisit((int)$visitId);
    $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
    $out = array();
    foreach ($orders as $o) {
        $items = array();
        foreach (OrderRepository::itemsByOrder((int)$o['id']) as $it) {
            $row = array(
                'id' => oid((int)$it['id']),
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
            'items' => $items,
        );
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
            'status' => $visit['status'],
            'created_at' => $visit['registered_at'],
        ),
        'summary' => deptwork_record_summary($visitId),
        'vitals' => $vitals,
        'nursing' => $nursing,
        'orders' => deptwork_orders($visitId),
    ));
}

/** 角色工作台所属科室（排队悬浮窗数据源）：
 * 优先用户关联科室 dept_ids；未配置时按角色名匹配科室（检验科/影像科/药房/护士站） */
function deptwork_role_depts($u) {
    $ids = user_dept_ids($u);
    if ($ids) return $ids;
    $kw = array('lab' => '检验', 'imaging' => '影像', 'pharmacy' => '药房', 'nurse' => '护士');
    $name = isset($kw[$u['role']]) ? $kw[$u['role']] : '';
    if ($name !== '') {
        $rows = DB::q("SELECT id FROM departments WHERE status=1 AND name LIKE ? ORDER BY sort, id LIMIT 1", array('%' . $name . '%'));
        if ($rows) return array((int)$rows[0]['id']);
    }
    $any = DB::one("SELECT id FROM departments WHERE status=1 ORDER BY sort, id LIMIT 1");
    return $any ? array((int)$any['id']) : array();
}

/** 科室排队悬浮窗数据（当前处理中/下一位/候诊队列，复用医技大屏逻辑） */
function deptwork_call_panel($u) {
    $role = $u['role'];
    $cfg = deptwork_role_cfg($role);
    $deptIds = deptwork_role_depts($u);
    if (!$deptIds) {
        json_ok(array('depts' => array(), 'current' => null, 'next' => null, 'waiting' => array(), 'bound' => true));
        return;
    }
    // 未绑定科室=全院：取当前科室；多科室仅聚合当前科室队列
    $deptId = $deptIds[0];
    $dept = DeptRepository::one('SELECT * FROM departments WHERE id=?', array($deptId));
    $fmt = function ($r) {
        if (!$r) return null;
        return array(
            'name' => $r['pname'],
            'gender' => $r['pgender'],
            'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
            'visit_seq' => (int)$r['visit_seq'],
            'flow_no' => $r['flow_no'],
            'patient_no' => $r['patient_no'],
            'visit_code' => oid((int)$r['id']),
            'status' => $r['status'],
        );
    };
    json_ok(array(
        'depts' => $deptIds,
        'dept_name' => $dept ? $dept['name'] : '',
        'bound' => true,
        'current' => $fmt(QueueRepository::currentVisit($deptId)),
        'next' => $fmt(QueueRepository::nextWaiting($deptId)),
        'waiting' => array_map($fmt, QueueRepository::waitingList($deptId, 8)),
    ));
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
    default:
        json_fail('未知操作');
}
