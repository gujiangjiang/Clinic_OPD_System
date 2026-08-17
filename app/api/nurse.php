<?php
/**
 * ============================================================
 * nurse.php — 护士站接口
 * ============================================================
 * 说明：
 * 1. 今日患者列表（按护士关联科室过滤）
 * 2. 待处置：开单时勾选【护士站处置】的处置项目
 *    （缴费后进入待处置，护士完成后显示执行护士姓名并通知医生）
 * 3. 生命体征：与医生工作站共用接口双向同步（血压/心率/脉搏/血氧/呼吸）
 * 4. 护理记录：按患者记录护理信息
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 护士可见科室ID数组（未设置则全部科室） */
function nurse_dept_ids() {
    global $u;
    $ids = array();
    foreach (explode(',', $u['dept_ids']) as $id) {
        if ((int)$id > 0) $ids[] = (int)$id;
    }
    return $ids;
}

switch ($action) {

    /* ==================== 今日患者列表 ==================== */
    case 'patients':
        $deptIds = nurse_dept_ids();
        $where = "date(r.register_time)=? AND r.status IN ('paid','visiting','finished')";
        $params = array(today_str());
        if ($deptIds) {
            $where .= ' AND r.current_dept_id IN (' . implode(',', array_fill(0, count($deptIds), '?')) . ')';
            $params = array_merge($params, $deptIds);
        }
        $rows = DB::q('patient', "SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page, p.id_card AS pid_card
            FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
            WHERE $where ORDER BY r.visit_seq", $params);
        json_ok(array('list' => array_map(function ($r) {
            return array(
                'visit_id' => (int)$r['id'],
                'name' => $r['pname'],
                'gender' => $r['pgender'],
                'age' => (int)$r['page'],
                'id_card' => $r['pid_card'],
                'patient_no' => $r['patient_no'],
                'flow_no' => $r['flow_no'],
                'dept_name' => $r['current_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'status' => $r['status'],
                'register_time' => $r['register_time'],
            );
        }, $rows)));
        break;

    /* ==================== 待处置列表（护士站执行） ==================== */
    case 'treatments':
        // 说明：跨库数据（科室名/患者信息）按 visit_id 逐条补充
        $rows = DB::q('order', "SELECT * FROM order_items WHERE item_type='procedure' AND need_nurse=1 AND status='paid' ORDER BY id DESC LIMIT 100");
        $html = '';
        if (!$rows) {
            $html = '<div class="empty"><div class="empty-ico">✅</div>暂无待处置项目</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>患者</th><th>处置项目</th><th>流水号</th><th>科室</th><th>开单医生</th><th>开单时间</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $v = DB::one('patient', 'SELECT current_dept_name FROM registrations WHERE id=?', array($r['visit_id']));
                $p = DB::one('patient', 'SELECT name, gender, age FROM patients WHERE patient_no=?', array($r['patient_no']));
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($p ? $p['name'] : '') . ' <span class="fs-12 text-muted fw-400">' . e($p ? $p['gender'] : '') . '/' . (int)($p ? $p['age'] : 0) . '岁</span></td>' .
                    '<td>' . e($r['item_name']) . ' ×' . (int)$r['quantity'] . '</td>' .
                    '<td>' . e($r['flow_no']) . '</td>' .
                    '<td>' . e($v ? $v['current_dept_name'] : '') . '</td>' .
                    '<td>' . e($r['doctor_name']) . '</td>' .
                    '<td class="fs-12">' . e(substr($r['created_at'], 5, 11)) . '</td>' .
                    '<td><button class="btn btn-success btn-sm" onclick="completeTreatment(' . (int)$r['id'] . ')">完成处置</button></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 完成处置（显示执行护士姓名并通知医生） ==================== */
    case 'complete':
        $itemId = (int)post('item_id');
        $it = DB::one('order', 'SELECT * FROM order_items WHERE id=?', array($itemId));
        if (!$it || $it['status'] !== 'paid') json_fail('该处置不存在或状态异常');
        DB::exec('order', "UPDATE order_items SET status='done', executed_by=?, executed_at=? WHERE id=?", array($u['name'], now_str(), $itemId));
        if ($it['doctor_id'] > 0) {
            send_msg('doctor', $it['doctor_id'],
                '处置已完成：' . $it['item_name'],
                '护士 ' . $u['name'] . ' 已完成患者（' . $it['patient_no'] . '）的处置「' . $it['item_name'] . '」',
                '', '');
        }
        json_ok(array(), '处置已完成（执行护士：' . $u['name'] . '）');
        break;

    /* ==================== 生命体征：读取（最新一条） ==================== */
    case 'vitals':
        $visitId = (int)get('visit_id');
        $v = DB::one('nurse', 'SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC', array($visitId));
        json_ok(array('vitals' => $v ? $v : null));
        break;

    /* ==================== 生命体征：保存（与医生站同接口双向同步） ==================== */
    case 'save_vitals':
        $visitId = (int)post('visit_id');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        DB::insert('nurse', 'INSERT INTO vitals(visit_id, patient_no, flow_no, bp_systolic, bp_diastolic, heart_rate, pulse, spo2, respiration, operator, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'],
            (int)post('bp_systolic', 0), (int)post('bp_diastolic', 0),
            post('heart_rate'), post('pulse'), post('spo2'), post('respiration'),
            $u['name'], now_str(),
        ));
        json_ok(array(), '生命体征已保存（医生工作站将同步显示）');
        break;

    /* ==================== 护理记录列表 ==================== */
    case 'nursing_list':
        $visitId = (int)get('visit_id');
        $rows = DB::q('nurse', 'SELECT * FROM nursing_records WHERE visit_id=? ORDER BY id DESC LIMIT 50', array($visitId));
        $html = '';
        if (!$rows) {
            $html = '<div class="text-muted fs-13">暂无护理记录</div>';
        }
        foreach ($rows as $r) {
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px">' .
                '<div class="fs-13">' . nl2br(e($r['content'])) . '</div>' .
                '<div class="fs-12 text-muted mt-4">' . e($r['operator']) . ' ｜ ' . e($r['created_at']) . '</div></div>';
        }
        json_ok(array('html' => $html));
        break;

    /* ==================== 新增护理记录 ==================== */
    case 'nursing_add':
        $visitId = (int)post('visit_id');
        $content = post('content');
        if ($content === '') json_fail('请输入护理记录内容');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        DB::insert('nurse', 'INSERT INTO nursing_records(visit_id, patient_no, flow_no, content, operator, created_at) VALUES(?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'], $content, $u['name'], now_str(),
        ));
        json_ok(array(), '护理记录已添加');
        break;

    default:
        json_fail('未知操作');
}
