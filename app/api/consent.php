<?php
/**
 * ============================================================
 * consent.php — 知情同意书接口
 * ============================================================
 * 说明：
 * 1. save   保存知情同意书（新建/编辑）
 * 2. list   获取某就诊的全部知情同意书列表
 * 3. get    获取单条知情同意书详情
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 保存知情同意书 ==================== */
    case 'save':
        $visitId = did(post('visit_id'));
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $patient = $row['patient'];
        $content = trim((string)post('content', ''));
        if ($content === '') json_fail('请填写知情同意内容');
        $id = (int)post('id', 0);
        $now = now_str();
        // 归档锁定：已诊毕不可修改
        if ($visit['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可修改');
        }
        // 跨科室只读锁定：医生当前科室 != 就诊当前科室（非会诊处理中）→ 绝对只读，
        // 不可保存/编辑知情同意书（与病历只读规则一致，杜绝跨科室修改）
        if (!get_editable_record($visit, $u)) {
            json_fail('跨科室病历仅只读，当前科室不可保存知情同意书');
        }
        // 病历可访问天数校验
        if (!visit_access_allowed($visit, $u)) {
            json_fail('该病历超出您的可查看历史天数，无法修改');
        }
        if ($id > 0) {
            // 编辑：仅更新内容，标题保持原值（不可更改）
            $old = DB::one('medical', 'SELECT * FROM consents WHERE id=? AND doctor_id=?', array($id, $u['id']));
            if (!$old) json_fail('知情同意书不存在或无权修改');
            DB::exec('medical', 'UPDATE consents SET content=?, updated_at=? WHERE id=?', array($content, $now, $id));
        } else {
            // 新建：标题由服务端从模板推导（模板的 name + 知情同意书），前端不可指定/篡改
            $tplId = (int)post('template_id', 0);
            $title = '';
            if ($tplId > 0) {
                $tpl = DB::one('emr_templates', 'SELECT content_json FROM emr_templates WHERE id=?', array($tplId));
                if ($tpl) {
                    $tc = json_decode((string)$tpl['content_json'], true);
                    $nm = is_array($tc) && !empty($tc['name']) ? trim((string)$tc['name']) : '';
                    if ($nm !== '') $title = $nm . '知情同意书';
                }
            }
            if ($title === '') json_fail('请从有效的知情同意书模板创建');
            $id = DB::insert('medical', 'INSERT INTO consents(visit_id, patient_no, flow_no, title, content, doctor_id, doctor_name, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
                $visitId, $patient['patient_no'], $visit['flow_no'], $title, $content, $u['id'], $u['name'], $now, $now,
            ));
        }
        json_ok(array('id' => $id), '知情同意书已保存');
        break;

    /* ==================== 就诊知情同意书列表 ==================== */
    case 'list':
        $visitId = did(get('visit_id'));
        if ($visitId <= 0) json_fail('参数错误');
        $rows = DB::q('medical', 'SELECT * FROM consents WHERE visit_id=? ORDER BY id ASC', array($visitId));
        $list = array();
        foreach ($rows as $r) {
            $list[] = array(
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'doctor_id' => (int)$r['doctor_id'],
                'doctor_name' => (string)$r['doctor_name'],
                'created_at' => (string)$r['created_at'],
            );
        }
        json_ok(array('list' => $list));
        break;

    /* ==================== 获取单条知情同意书 ==================== */
    case 'get':
        $id = (int)get('id', 0);
        $r = DB::one('medical', 'SELECT * FROM consents WHERE id=?', array($id));
        if (!$r) json_fail('知情同意书不存在');
        json_ok(array(
            'consent' => array(
                'id' => (int)$r['id'],
                'visit_id' => (int)$r['visit_id'],
                'title' => (string)$r['title'],
                'content' => (string)$r['content'],
                'doctor_id' => (int)$r['doctor_id'],
                'doctor_name' => (string)$r['doctor_name'],
                'created_at' => (string)$r['created_at'],
                'updated_at' => (string)$r['updated_at'],
            ),
        ));
        break;

    /* ==================== 删除知情同意书（仅本人创建） ==================== */
    case 'delete':
        $id = (int)post('id', 0);
        $c = DB::one('medical', 'SELECT * FROM consents WHERE id=?', array($id));
        if (!$c) json_fail('知情同意书不存在');
        if ((int)$c['doctor_id'] !== (int)$u['id']) json_fail('仅可删除本人创建的知情同意书');
        $row = get_visit_row($c['visit_id']);
        // 归档锁定：已诊毕不可删除
        if ($row && $row['visit']['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可删除');
        }
        // 跨科室只读锁定：医生当前科室 != 就诊当前科室（非会诊处理中）→ 绝对只读，
        // 不可删除知情同意书（与病历只读规则一致，杜绝跨科室删除）
        if ($row && !get_editable_record($row['visit'], $u)) {
            json_fail('跨科室病历仅只读，当前科室不可删除知情同意书');
        }
        DB::exec('medical', 'DELETE FROM consents WHERE id=?', array($id));
        json_ok(array(), '知情同意书已删除');
        break;

    default:
        json_fail('未知操作');
}