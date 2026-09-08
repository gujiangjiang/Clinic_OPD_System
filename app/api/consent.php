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
// 病历内容快照投影（emr_*_text / consent_emr_snapshot / 默认话术）由 emr_formatter 提供
require_once APP_ROOT . '/app/includes/emr_formatter.php';

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
        // 告知内容：空则回落默认话术（与打印签名区上方文案一致）
        $notice = trim((string)post('notice', ''));
        if ($notice === '') $notice = consent_default_notice();
        // 勾选的病历内容节（白名单过滤）
        $sectionsIn = json_decode((string)post('sections', '[]'), true);
        $sections = consent_section_filter(is_array($sectionsIn) ? $sectionsIn : array());
        $id = (int)post('id', 0);
        $now = now_str();
        // 归档锁定：已诊毕不可修改
        if ($visit['status'] === 'finished') {
            json_fail('该患者已诊毕，病历已归档，不可修改');
        }
        // 新建前置：无已保存首诊病历 → 禁止创建知情同意书
        // （同意书需有首诊病历支撑；与前端 syncNavAdds 隐藏「＋」同规则）
        if ($id <= 0) {
            $hasInitial = EmrRepository::one("SELECT id FROM patient_records WHERE visit_id=? AND record_type='initial' LIMIT 1", array($visitId));
            if (!$hasInitial) json_fail('请先书写并保存首诊病历后再创建知情同意书');
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
        // 病历内容快照：以首诊文书为锚点固化（编辑重存时随当前病历重新快照）
        $snapshotJson = json_encode(consent_emr_snapshot($visitId, $sections), JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            // 编辑：更新 内容/告知内容/勾选节 + 重新快照（随当前病历更新），标题保持原值
            $old = EmrRepository::one('SELECT * FROM consents WHERE id=? AND doctor_id=?', array($id, $u['id']));
            if (!$old) json_fail('知情同意书不存在或无权修改');
            EmrRepository::exec('UPDATE consents SET content=?, notice=?, emr_snapshot=?, updated_at=? WHERE id=?',
                array($content, $notice, $snapshotJson, $now, $id));
        } else {
            // 标题推导（完全自定义抬头）：
            // · 新模板（content 无 name 字段）→ 模板 title 原文（门诊告知书/病重通知书等任意标题）
            // · 旧模板（content.name 非空）→ 兼容旧逻辑 name + 知情同意书
            $tplId = (int)post('template_id', 0);
            $title = '';
            if ($tplId > 0) {
                $tpl = EmrRepository::one('SELECT title, content_json FROM emr_templates WHERE id=?', array($tplId));
                if ($tpl) {
                    $tc = json_decode((string)$tpl['content_json'], true);
                    $nm = is_array($tc) && isset($tc['name']) && trim((string)$tc['name']) !== '' ? trim((string)$tc['name']) : '';
                    if ($nm !== '') {
                        $title = $nm . '知情同意书';
                    } else {
                        $title = trim((string)$tpl['title']);
                    }
                }
            }
            if ($title === '') json_fail('请从有效的知情同意书模板创建');
            // 开具科室固化：就诊当前科室（创建时确定，转科/会诊后不再变化）
            $deptId = (int)$visit['current_dept_id'];
            $deptName = (string)$visit['current_dept_name'];
            $id = EmrRepository::insert('INSERT INTO consents(visit_id, patient_no, flow_no, title, content, notice, emr_snapshot, doctor_id, doctor_name, dept_id, dept_name, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $visitId, $patient['patient_no'], $visit['flow_no'], $title, $content, $notice, $snapshotJson, $u['id'], $u['name'], $deptId, $deptName, $now, $now,
            ));
        }
        json_ok(array('id' => $id), '知情同意书已保存');
        break;

    /* ==================== 就诊知情同意书列表 ==================== */
    case 'list':
        $visitId = did(get('visit_id'));
        if ($visitId <= 0) json_fail('参数错误');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        if (!visit_dept_authorized($row['visit'], $u)) json_fail('无权限查看该就诊的知情同意书');
        $rows = EmrRepository::q('SELECT * FROM consents WHERE visit_id=? ORDER BY id ASC', array($visitId));
        $list = array();
        foreach ($rows as $r) {
            $list[] = array(
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'doctor_id' => (int)$r['doctor_id'],
                'doctor_name' => (string)$r['doctor_name'],
                'dept_name' => (string)(isset($r['dept_name']) ? $r['dept_name'] : ''),
                'created_at' => (string)$r['created_at'],
            );
        }
        json_ok(array('list' => $list));
        break;

    /* ==================== 获取单条知情同意书 ==================== */
    case 'get':
        $id = (int)get('id', 0);
        $r = EmrRepository::one('SELECT * FROM consents WHERE id=?', array($id));
        if (!$r) json_fail('知情同意书不存在');
        $vRow = get_visit_row((int)$r['visit_id']);
        if ($vRow && !visit_dept_authorized($vRow['visit'], $u)) json_fail('无权限查看');
        // 勾选节：优先取快照记录的 sections（编辑回填），旧数据回退旧行为（主诉+初步诊断）
        $snap = json_decode((string)$r['emr_snapshot'], true);
        $sections = is_array($snap) && !empty($snap['sections'])
            ? consent_section_filter($snap['sections'])
            : array('chief_complaint', 'preliminary_diagnosis');
        json_ok(array(
            'consent' => array(
                'id' => (int)$r['id'],
                'visit_id' => (int)$r['visit_id'],
                'title' => (string)$r['title'],
                'content' => (string)$r['content'],
                'notice' => (string)(isset($r['notice']) ? $r['notice'] : ''),
                'sections' => $sections,
                'doctor_id' => (int)$r['doctor_id'],
                'doctor_name' => (string)$r['doctor_name'],
                'dept_name' => (string)(isset($r['dept_name']) ? $r['dept_name'] : ''),
                'created_at' => (string)$r['created_at'],
                'updated_at' => (string)$r['updated_at'],
            ),
        ));
        break;

    /* ==================== 删除知情同意书（仅本人创建） ==================== */
    case 'delete':
        $id = (int)post('id', 0);
        $c = EmrRepository::one('SELECT * FROM consents WHERE id=?', array($id));
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
        EmrRepository::exec('DELETE FROM consents WHERE id=?', array($id));
        json_ok(array(), '知情同意书已删除');
        break;

    default:
        json_fail('未知操作');
}