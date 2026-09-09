<?php
/**
 * ============================================================
 * critical.php — 危急值接口
 * ============================================================
 * 说明：全院危急值全生命周期（发送 → 接收 → 处理 → 归档）：
 * 1. 检验科：save_result 检测到危急值后由前端调本接口 send；
 *    影像科：报告发布时把手动上报的危急值随 send 一并发出。
 * 2. 接收医生站内消息 → process 处理（符合/不符合病情 + 处理措施），
 *    处理同时自动在病历插入「危急值记录」续写文书（is_critical=1 只读）。
 * 3. 危急值记录一经创建不可删除/修改（本接口不提供任何删除/编辑动作），
 *    process 仅推进状态字段；报告快照独立固化在 snapshot_json，
 *    检验科后期撤回/修改数据不影响已归档危急值展示。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';
require_once __DIR__ . '/parts/dept_common.php';

$u = Auth::user();

/**
 * 构造检验报告危急值快照：
 * rows = 该报告全部检验结果行（含参考范围/危急值阈值/是否危急）
 * criticalIdx = 命中危急值的行下标
 */
function crit_lab_snapshot($result, $item) {
    $isGroup = (int)$item['is_group'] === 1;
    $values = json_decode((string)$result['values_json'], true);
    if (!is_array($values)) $values = array();
    $rows = array();
    if ($isGroup) {
        $members = DB::q("SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id", array((int)$item['id']));
        $map = (isset($values['values']) && is_array($values['values'])) ? $values['values'] : array();
        foreach ($members as $m) {
            $rows[] = array(
                'name' => (string)$m['name'],
                'value' => isset($map[(int)$m['id']]) ? (string)$map[(int)$m['id']] : '',
                'unit' => (string)$m['unit'],
                'normal_range' => (string)$m['normal_range'],
                'critical_low' => (string)$m['critical_low'],
                'critical_high' => (string)$m['critical_high'],
                'is_critical' => 0, 'flag' => '',
            );
        }
    } else {
        $rows[] = array(
            'name' => (string)$item['name'],
            'value' => isset($values['value']) ? (string)$values['value'] : '',
            'unit' => (string)$item['unit'],
            'normal_range' => (string)$item['normal_range'],
            'critical_low' => (string)$item['critical_low'],
            'critical_high' => (string)$item['critical_high'],
            'is_critical' => 0, 'flag' => '',
        );
    }
    $criticalIdx = array();
    foreach ($rows as $i => $r) {
        // 数值型：低于下限 / 高于上限命中；文本型（HIV 阳性等定性项目）：
        // 录入值等于危急值文本（不区分大小写）即命中
        if (crit_row_hit($r['value'], $r['critical_low'], $r['critical_high'])) {
            $rows[$i]['is_critical'] = 1;
            $rows[$i]['flag'] = 'text';
            $criticalIdx[] = $i;
        }
    }
    return array('rows' => $rows, 'criticalIdx' => $criticalIdx, 'item_name' => (string)$item['name']);
}

/** 处理时长文案（接收-发送：created_at 视为接收时间） */
function crit_duration_text($start, $end) {
    if ($start === '' || $end === '') return '';
    $s = strtotime($start); $e = strtotime($end);
    if (!$s || !$e || $e <= $s) return '';
    $secs = $e - $s;
    $h = intdiv($secs, 3600); $m = intdiv(($secs % 3600), 60);
    if ($h > 0) return $m > 0 ? $h . '小时' . $m . '分' : $h . '小时';
    if ($m > 0) return $m . '分';
    return $secs . '秒';
}

/** 写入危急值记录（报告快照固化） */
function crit_store_record($u, $source, $report, $display, $doc) {
    $rv = get_visit_row((int)$report['visit_id']);
    if (!$rv) json_fail('就诊记录不存在');
    $patient = $rv['patient'];
    $deptRow = DB::one('SELECT name FROM departments WHERE id=?', array(current_dept_id($u)));
    $fromDept = $deptRow ? (string)$deptRow['name'] : ($source === 'lab' ? '检验科' : '影像科');
    $snapshot = array(
        'item_name' => $display['item_name'],
        'rows' => isset($display['rows']) ? $display['rows'] : array(),
        'findings' => isset($display['findings']) ? $display['findings'] : '',
        'conclusion' => isset($display['conclusion']) ? $display['conclusion'] : '',
    );
    return (int)DB::insert(
        'INSERT INTO critical_values(source, report_id, result_id, visit_id, patient_no, flow_no, patient_name, patient_gender, patient_birth, patient_age, item_name, items_json, snapshot_json, from_dept, from_user_id, from_name, to_doctor_id, to_doctor_name, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array(
            $source, (int)$report['id'], (int)$report['result_id'], (int)$report['visit_id'],
            (string)$report['patient_no'], (string)$report['flow_no'],
            $patient ? (string)$patient['name'] : '',
            $patient ? (string)$patient['gender'] : '',
            $patient ? (string)$patient['birth_date'] : '',
            $patient ? age_format($patient['birth_date']) : '',
            (string)$display['item_name'],
            json_encode($display['critical_items'], JSON_UNESCAPED_UNICODE),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            $fromDept, (int)$u['id'], (string)$u['name'],
            (int)$doc['id'], (string)$doc['name'],
            'pending', now_str(),
        )
    );
}

/** 危急值病历续写一句话（EMR 危急值记录节点文案） */
function crit_sentence($cv, $matchText, $treatment) {
    $srcName = $cv['source'] === 'lab' ? '检验科' : '影像科';
    $kind = $cv['source'] === 'lab' ? '检验' : '检查';
    $itemName = (string)$cv['item_name'];
    $parts = array();
    $crits = json_decode((string)$cv['items_json'], true);
    if (is_array($crits)) {
        foreach ($crits as $c) {
            $name = isset($c['name']) ? (string)$c['name'] : '';
            if ($name === '') $name = $itemName;
            $val = isset($c['value']) ? (string)$c['value'] : '';
            $parts[] = $val !== '' ? ($name . ' ' . $val) : $name;
        }
    }
    if (!$parts) $parts[] = $itemName;
    $valText = implode('、', $parts);
    $s = '接收' . $srcName . '报告 ' . $kind . '：' . $valText . '，报危急值';
    if ($matchText !== '') $s .= '，' . $matchText;
    if ($treatment !== '') {
        // 处理意见若已以「处理」结尾则不再追加，避免「…处理处理」
        $s .= '，予' . $treatment . (preg_match('/处理$/u', $treatment) ? '' : '处理');
    }
    return $s;
}

/**
 * 处理危急值时自动插入病历续写记录（is_critical=1，全局只读）：
 * 记录医生/时间由服务端写入（同病历保存逻辑），文书文字 = 危急值记录一句话。
 */
function crit_insert_emr($cv, $u, $matchText, $treatment) {
    $visit = DB::one('SELECT * FROM registrations WHERE id=?', array((int)$cv['visit_id']));
    if (!$visit) json_fail('就诊记录不存在');
    $now = now_str();
    $emr = emr_default_data(null);
    $sentence = crit_sentence($cv, $matchText, $treatment);
    $emr['progress']['content'] = $sentence;
    // 续写父记录：处理医生本人最近一条文书，无则取本就诊最近一条
    $parent = DB::one('SELECT id FROM patient_records WHERE visit_id=? AND doctor_id=? ORDER BY id DESC LIMIT 1', array((int)$cv['visit_id'], (int)$u['id']));
    if (!$parent) {
        $parent = DB::one('SELECT id FROM patient_records WHERE visit_id=? ORDER BY id DESC LIMIT 1', array((int)$cv['visit_id']));
    }
    $parentId = $parent ? (int)$parent['id'] : 0;
    $recordId = (int)DB::insert(
        'INSERT INTO patient_records(visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, record_type, parent_record_id, chief_complaint, symptom_duration, symptom_unit, informant, arrival_way, has_past_history, allergy_history, is_leave_hospital, icd10_code, diagnosis_name, emr_data, emr_print_text, status, created_at, updated_at, consultation_id, is_critical) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array(
            (int)$cv['visit_id'], (string)$visit['patient_no'], (string)$visit['flow_no'],
            (int)$visit['current_dept_id'], (int)$u['id'], (string)$u['name'],
            'progress', $parentId, '', '', '', '', '', '否', '', '否', '', '危急值记录',
            json_encode($emr, JSON_UNESCAPED_UNICODE), $sentence,
            'done', $now, $now, 0, 1,
        )
    );
    // 旧 records 扁平镜像（兼容就诊历史列表等既有消费方）
    $mirror = array(
        'chief_complaint' => '',
        'present_illness' => $sentence,
        'past_history' => '',
        'allergy_history' => '',
        'physical_exam' => '',
        'consciousness' => '',
        'preliminary_diagnosis' => '危急值记录',
        'icd10_code' => '',
        'is_observation' => 0,
        'visit_type' => '',
        'doctor_advice' => '',
        'status' => 'done',
        'updated_at' => $now,
    );
    $cols = 'visit_id, patient_no, flow_no, dept_id, doctor_id, doctor_name, patient_record_id, ' . implode(',', array_keys($mirror)) . ', created_at';
    $marks = '?,?,?,?,?,?,?, ' . in_placeholders($mirror) . ',?';
    $params = array((int)$cv['visit_id'], (string)$visit['patient_no'], (string)$visit['flow_no'],
        (int)$visit['current_dept_id'], (int)$u['id'], (string)$u['name'], $recordId);
    foreach ($mirror as $v) $params[] = $v;
    $params[] = $now;
    DB::insert("INSERT INTO records($cols) VALUES($marks)", $params);
    return $recordId;
}

switch ($action) {

    /* ==================== 医生分页搜索（发送危急值时选接收医生，无限滚动） ==================== */
    case 'doctor_search':
        $q = get('q', '');
        $page = max(1, (int)get('page', 1));
        $pageSize = 20;
        $where = "role='doctor' AND status=1";
        $params = array();
        if ($q !== '') {
            $where .= ' AND (name LIKE ? OR emp_no LIKE ? OR username LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $total = (int)DB::val("SELECT COUNT(*) FROM users WHERE $where", $params);
        $rows = DB::q("SELECT id, name, emp_no, title FROM users WHERE $where ORDER BY emp_no, id LIMIT ? OFFSET ?", array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
        json_ok(array('list' => $rows, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
        break;

    /* ==================== 发送危急值（检验科自动检测 / 影像科手动上报） ==================== */
    case 'send':
        $source = post('source');
        if (!in_array($source, array('lab', 'imaging'), true)) json_fail('参数错误');
        $reportId = did(post('report_id'));
        $toDoctorId = (int)post('to_doctor_id');
        if ($toDoctorId <= 0) json_fail('请选择接收医生');
        $doc = DB::one("SELECT id, name, emp_no FROM users WHERE id=? AND role='doctor' AND status=1", array($toDoctorId));
        if (!$doc) json_fail('接收医生不存在或已停用');
        $report = DB::one('SELECT * FROM reports WHERE id=? AND type=?', array($reportId, $source));
        if (!$report) json_fail('报告不存在');
        $result = DB::one('SELECT * FROM results WHERE id=?', array((int)$report['result_id']));
        $rv = get_visit_row((int)$report['visit_id']);
        if (!$rv) json_fail('就诊记录不存在');
        if ($u['role'] !== 'admin' && !dept_visit_allowed($rv['visit'], $u)) {
            json_fail('无权限发送该就诊的危急值');
        }
        if ($source === 'lab') {
            $item = DB::one('SELECT * FROM lab_items WHERE id=?', array($result ? (int)$result['item_id'] : 0));
            if (!$item) json_fail('检验项目不存在');
            $snap = crit_lab_snapshot($result, $item);
            $critItems = array();
            foreach ($snap['criticalIdx'] as $i) $critItems[] = $snap['rows'][$i];
            if (!$critItems) json_fail('未检测到危急值');
            $display = array('item_name' => $snap['item_name'], 'rows' => $snap['rows'], 'critical_items' => $critItems);
        } else {
            $itemName = post('item');
            if ($itemName === '') json_fail('请填写危急值项目');
            $display = array(
                'item_name' => $itemName,
                'rows' => array(),
                'critical_items' => array(array('name' => $itemName, 'value' => '', 'unit' => '', 'normal_range' => '', 'critical_low' => '', 'critical_high' => '')),
                'findings' => $result ? (string)$result['findings'] : '',
                'conclusion' => $result ? (string)$result['conclusion'] : '',
            );
        }
        $cvId = crit_store_record($u, $source, $report, $display, $doc);
        $pName = $rv['patient'] ? (string)$rv['patient']['name'] : '';
        $kind = $source === 'lab' ? '检验' : '检查';
        send_msg('doctor', $toDoctorId,
            '⚠️ 危急值通知：' . $display['item_name'],
            '患者「' . $pName . '」（' . $report['patient_no'] . '）的' . $kind . '结果报危急值，请及时处理',
            'report', '/api/print?action=report&report_id=' . oid($reportId),
            array('msg_type' => 'critical', 'patient_name' => $pName, 'visit_id' => (int)$report['visit_id'], 'link_url' => '/critical_value/' . oid($cvId)));
        json_ok(array('id' => oid($cvId)), '危急值已发送并通知医生');
        break;

    /* ==================== 危急值详情（处理弹窗 / 只读查看） ==================== */
    case 'detail':
        $id = did(get('id'));
        $cv = DB::one('SELECT * FROM critical_values WHERE id=?', array($id));
        if (!$cv) json_fail('危急值记录不存在');
        $isDoctor = $u['role'] === 'doctor' && (int)$cv['to_doctor_id'] === (int)$u['id'];
        $isSender = (int)$cv['from_user_id'] === (int)$u['id'];
        $isDept = ($u['role'] === 'lab' && $cv['source'] === 'lab') || ($u['role'] === 'imaging' && $cv['source'] === 'imaging');
        if ($u['role'] !== 'admin' && !$isDoctor && !$isSender && !$isDept) {
            json_fail('无权限查看该危急值');
        }
        $cv['id'] = oid((int)$cv['id']);
        $cv['report_id'] = $cv['report_id'] ? oid((int)$cv['report_id']) : 0;
        $cv['result_id'] = $cv['result_id'] ? oid((int)$cv['result_id']) : 0;
        $cv['visit_id'] = $cv['visit_id'] ? oid((int)$cv['visit_id']) : 0;
        $cv['items'] = json_decode((string)$cv['items_json'], true) ?: array();
        $cv['snapshot'] = json_decode((string)$cv['snapshot_json'], true) ?: array();
        $cv['duration'] = crit_duration_text((string)$cv['created_at'], (string)$cv['processed_at']);
        $cv['processed_by_name'] = '';
        if ((int)$cv['processed_by'] > 0) {
            $pb = DB::one('SELECT name FROM users WHERE id=?', array((int)$cv['processed_by']));
            $cv['processed_by_name'] = $pb ? (string)$pb['name'] : '';
        }
        unset($cv['items_json'], $cv['snapshot_json']);
        json_ok(array('cv' => $cv));
        break;

    /* ==================== 危急值列表（医生=收到的；检验/影像=本部门发出；管理员=全院） ==================== */
    case 'list':
        $from = get('from');
        $to = get('to');
        $status = get('status', '');
        $page = max(1, (int)get('page', 1));
        $pageSize = 20;
        $where = '1=1';
        $params = array();
        if ($u['role'] === 'doctor') {
            $where .= ' AND to_doctor_id=?';
            $params[] = (int)$u['id'];
        } elseif ($u['role'] === 'lab') {
            $where .= " AND source='lab'";
        } elseif ($u['role'] === 'imaging') {
            $where .= " AND source='imaging'";
        } elseif ($u['role'] !== 'admin') {
            json_fail('无权限查看危急值');
        }
        if ($status === 'pending' || $status === 'done') {
            $where .= ' AND status=?';
            $params[] = $status;
        }
        if ($from !== '') { $where .= ' AND date(created_at)>=?'; $params[] = $from; }
        if ($to !== '') { $where .= ' AND date(created_at)<=?'; $params[] = $to; }
        $total = (int)DB::val("SELECT COUNT(*) FROM critical_values WHERE $where", $params);
        $rows = DB::q("SELECT * FROM critical_values WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
        $out = array();
        foreach ($rows as $cv) {
            $out[] = array(
                'id' => oid((int)$cv['id']),
                'source' => (string)$cv['source'],
                'item_name' => (string)$cv['item_name'],
                'patient_name' => (string)$cv['patient_name'],
                'patient_no' => (string)$cv['patient_no'],
                'from_dept' => (string)$cv['from_dept'],
                'from_name' => (string)$cv['from_name'],
                'to_doctor_name' => (string)$cv['to_doctor_name'],
                'status' => (string)$cv['status'],
                'match_status' => (string)$cv['match_status'],
                'treatment' => (string)$cv['treatment'],
                'created_at' => (string)$cv['created_at'],
                'processed_at' => (string)$cv['processed_at'],
                'duration' => crit_duration_text((string)$cv['created_at'], (string)$cv['processed_at']),
                'items' => json_decode((string)$cv['items_json'], true) ?: array(),
            );
        }
        json_ok(array('list' => $out, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
        break;

    /* ==================== 医生处理危急值（符合/不符合病情 + 处理措施） ==================== */
    case 'process':
        $id = did(post('id'));
        $match = post('match');
        $treatment = post('treatment');
        if (!in_array($match, array('match', 'mismatch'), true)) json_fail('请选择是否符合病情');
        if ($treatment === '') json_fail('请填写处理措施');
        $cv = DB::one('SELECT * FROM critical_values WHERE id=?', array($id));
        if (!$cv) json_fail('危急值记录不存在');
        if ($u['role'] !== 'admin' && (int)$cv['to_doctor_id'] !== (int)$u['id']) {
            json_fail('您不是该危急值的接收医生');
        }
        if ($cv['status'] === 'done') json_fail('该危急值已处理，不可重复处理');
        $matchText = $match === 'match' ? '符合病情' : '不符合病情';
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            // 条件更新防并发重复处理：仅待处理状态可推进
            $n = DB::exec("UPDATE critical_values SET status='done', match_status=?, treatment=?, processed_by=?, processed_at=? WHERE id=? AND status='pending'",
                array($matchText, $treatment, (int)$u['id'], now_str(), $id));
            if ($n <= 0) {
                $pdo->rollBack();
                json_fail('该危急值已处理，不可重复处理');
            }
            $recordId = crit_insert_emr($cv, $u, $matchText, $treatment);
            DB::exec('UPDATE critical_values SET record_id=? WHERE id=?', array($recordId, $id));
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_fail('处理失败：' . $ex->getMessage());
        }
        json_ok(array('id' => oid($id), 'record_id' => oid($recordId)), '危急值已处理，已写入病历「危急值记录」');
        break;

    default:
        json_fail('未知操作');
}
