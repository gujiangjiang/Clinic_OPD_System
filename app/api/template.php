<?php
/**
 * ============================================================
 * template.php — 病历模板接口（v2 重构）
 * ============================================================
 * 说明：
 * 1. 模板范围：personal 个人 / dept 科室 / hospital 全院
 *    （管理员创建仅限 hospital/dept 免审；医生 personal 免审，
 *     dept/hospital 进审核流）
 * 2. 内容过滤：模板仅保留 主诉/现病史/主要症状/体格检查/门诊处置/
 *    嘱托 等结构化文本项，强制剥离生命体征、意识状态、既往史、
 *    过敏史、辅助检查、留观标记及任何诊断（ICD-10）数据。
 * 3. 鉴权：is_system=1 模板严禁修改/删除；非本人且非管理员
 *    严禁修改/删除。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/**
 * 模板内容过滤：仅保留模板允许的结构化文本项。
 * 强制剥离：生命体征/意识状态（外部节，本就不在 emr_data）、
 * 既往史、过敏史、辅助检查（含自动段）、留观标记、全部诊断。
 * 保留：主诉、现病史、主要症状、体格检查、门诊处置自定义、
 * 嘱托。
 */
function tpl_filter_content($emr) {
    if (!is_array($emr)) $emr = array();
    $keep = array();
    // 主诉
    foreach (array('chief_complaint', 'history_present', 'main_symptoms', 'physical_exam', 'advice') as $k) {
        if (isset($emr[$k])) $keep[$k] = $emr[$k];
    }
    // 门诊处置：仅保留自定义处置（处理意见），剥离处方行/处置自动段
    if (isset($emr['disposition_custom'])) $keep['disposition_custom'] = $emr['disposition_custom'];
    // 显式剥离项（防御：即便前端误传也强制删除）
    foreach (array('diagnoses', 'past_history', 'allergies', 'aux_result', 'aux_external',
        'aux_orders', 'rx_lines', 'disp_items', 'is_leave_hospital', 'progress', 'consciousness') as $k) {
        unset($keep[$k]);
    }
    return $keep;
}

switch ($action) {

    /* ==================== 临床科室列表（模板编辑弹窗用，医生可访问） ==================== */
    case 'depts':
        $rows = EmrRepository::q("SELECT id, name FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY sort, id");
        json_ok(array('list' => $rows));
        break;

    /* ==================== 可用模板列表 ==================== */
    // 医生可见：全院 published + 本人科室 dept published + 本人个人模板
    // 管理员可见全部
    case 'list':
        $kw = trim((string)get('kw', ''));
        $type = get('type', 'medical_record');
        if (!in_array($type, array('medical_record', 'consent', 'order_note'), true)) $type = 'medical_record';
        $isAdmin = ($u['role'] === 'admin');
        $sql = "SELECT * FROM emr_templates WHERE type=?";
        $params = array($type);
        if ($isAdmin) {
            // 管理员：全部（含草稿/驳回）
        } else {
            $myDepts = user_dept_ids($u);
            // 可见性：本人个人模板（任意状态）+ 本人待审核模板（未通过前仅自己可见可用）+
            // 已发布全院 + 已发布本人科室模板
            $conds = array("(scope='personal' AND creator_id=?)");
            $params[] = $u['id'];
            $conds[] = "(status='pending_review' AND creator_id=?)";
            $params[] = $u['id'];
            $conds[] = "(scope='hospital' AND status='published')";
            if ($myDepts) {
                // 科室模板：已发布且包含本人科室的（关联表多对多）
                $ph = implode(',', array_fill(0, count($myDepts), '?'));
                $conds[] = "(scope='dept' AND status='published' AND id IN (SELECT template_id FROM emr_template_depts WHERE dept_id IN ($ph)))";
                foreach ($myDepts as $d) $params[] = $d;
            }
            $sql .= " AND (" . implode(' OR ', $conds) . ")";
        }
        if ($kw !== '') {
            $sql .= " AND title LIKE ?";
            $params[] = '%' . $kw . '%';
        }
        // 排序：系统模板置顶，其余按创建时间倒序（新创建的显示在上）
        $sql .= " ORDER BY is_system DESC, id DESC";
        $rows = EmrRepository::q($sql, $params);
        $out = array();
        foreach ($rows as $t) {
            // 关联科室名
            $deptNames = array();
            $links = EmrRepository::q('SELECT dept_id FROM emr_template_depts WHERE template_id=?', array((int)$t['id']));
            if ($links) {
                $dids = array();
                foreach ($links as $l) $dids[] = (int)$l['dept_id'];
                $ph2 = implode(',', array_fill(0, count($dids), '?'));
                foreach (EmrRepository::q("SELECT id, name FROM departments WHERE id IN ($ph2)", $dids) as $dn) {
                    $deptNames[] = $dn['name'];
                }
            }
            $out[] = array(
                'id' => (int)$t['id'],
                'title' => (string)$t['title'],
                'type' => (string)$t['type'],
                'scope' => (string)$t['scope'],
                'creator_id' => (int)$t['creator_id'],
                'creator_name' => (string)$t['creator_name'],
                'status' => (string)$t['status'],
                'is_system' => (int)$t['is_system'],
                'dept_names' => $deptNames,
                'created_at' => (string)$t['created_at'],
                'updated_at' => (string)$t['updated_at'],
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ==================== 单条模板详情（编辑回填） ==================== */
    case 'get':
        $id = (int)get('id');
        $t = EmrRepository::one('SELECT * FROM emr_templates WHERE id=?', array($id));
        if (!$t) json_fail('模板不存在');
        // for_apply=1：应用模板/创建病历场景——允许读取当前医生可见模板（含系统模板）
        // 供医生套用到病历；编辑模板（默认）才做系统/归属越权拦截。
        $forApply = (int)get('for_apply', 0);
        if ($forApply === 1) {
            // 可见性过滤须与 list 一致：本人个人模板（任意状态）+ 本人待审核模板
            // + 已发布全院 + 已发布本人科室模板（防越权读取他人私有模板）
            $isVisible = false;
            $myDepts = user_dept_ids($u);
            if ((int)$t['creator_id'] === (int)$u['id'] && $t['scope'] === 'personal') {
                $isVisible = true;
            } elseif ((int)$t['creator_id'] === (int)$u['id'] && $t['status'] === 'pending_review') {
                $isVisible = true;
            } elseif ($t['scope'] === 'hospital' && $t['status'] === 'published') {
                $isVisible = true;
            } elseif ($t['scope'] === 'dept' && $t['status'] === 'published' && $myDepts) {
                $ph = implode(',', array_fill(0, count($myDepts), '?'));
                $cnt = (int)EmrRepository::val("SELECT COUNT(*) FROM emr_template_depts WHERE template_id=? AND dept_id IN ($ph)", array_merge(array($id), $myDepts));
                if ($cnt > 0) $isVisible = true;
            }
            if (!$isVisible) json_fail('无权查看该模板');
        } else {
            if ($t['is_system'] == 1) json_fail('通用模板不可编辑');
            if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') {
                json_fail('无权编辑该模板');
            }
        }
        $links = EmrRepository::q('SELECT dept_id FROM emr_template_depts WHERE template_id=?', array($id));
        $deptIds = array();
        foreach ($links as $l) $deptIds[] = (int)$l['dept_id'];
        json_ok(array(
            'template' => array(
                'id' => (int)$t['id'],
                'title' => (string)$t['title'],
                'type' => (string)$t['type'],
                'scope' => (string)$t['scope'],
                'status' => (string)$t['status'],
                'content' => json_decode((string)$t['content_json'], true) ?: array(),
                'dept_ids' => $deptIds,
            ),
        ));
        break;

    /* ==================== 保存模板（新建/编辑） ==================== */
    case 'save':
        $id = (int)post('id', 0);
        $title = trim((string)post('title', ''));
        $type = post('type', 'medical_record');
        $scope = post('scope', 'personal');
        $content = post('content', '{}');
        if (!in_array($type, array('medical_record', 'consent', 'order_note'), true)) $type = 'medical_record';
        if (!in_array($scope, array('personal', 'dept', 'hospital'), true)) $scope = 'personal';
        if ($title === '') json_fail('请填写模板名称');
        $contentArr = json_decode((string)$content, true);
        if (!is_array($contentArr)) $contentArr = array();
        // 内容按模板类型区分：
        // · consent 知情同意书模板：{ name: XX（标题中的 XX）, content: 正文 }
        // · medical_record 病历模板：结构化 EMR（后端剥离禁止字段）
        $typeLabel = $type === 'consent' ? '知情同意书模板' : '病历模板';
        if ($type === 'consent') {
            if (empty($contentArr['name'])) $contentArr['name'] = '通用';
            if (!isset($contentArr['content'])) $contentArr['content'] = '';
            $contentArr['content'] = trim((string)$contentArr['content']);
        } else {
            $contentArr = tpl_filter_content($contentArr);
        }

        $isAdmin = ($u['role'] === 'admin');
        // 管理员创建：仅限 hospital/dept
        if ($isAdmin && $scope === 'personal') json_fail('管理员模板适用范围仅限全院或科室');
        if (!$isAdmin && $scope === 'personal') {
            $status = 'published';   // 个人模板免审
        } else {
            $status = $isAdmin ? 'published' : 'pending_review';   // 管理员免审；医生科室/全院进审核
        }

        // 编辑：越权防护
        if ($id > 0) {
            $old = EmrRepository::one('SELECT * FROM emr_templates WHERE id=?', array($id));
            if (!$old) json_fail('模板不存在');
            if ((int)$old['is_system'] === 1) json_fail('通用模板不可修改');
            if ((int)$old['creator_id'] !== (int)$u['id'] && !$isAdmin) json_fail('无权修改该模板');
            // 待审核锁定：提交审核后的模板不允许编辑（审核通过/驳回后恢复），防止审核与修改竞态
            if ($old['status'] === 'pending_review') json_fail('模板正在审核中，审核通过或驳回后方可修改');
            // 管理员编辑他人模板不改变归属；医生编辑保持原 scope/状态语义
            EmrRepository::exec('UPDATE emr_templates SET title=?, type=?, scope=?, content_json=?, updated_at=? WHERE id=?',
                array($title, $type, $scope, json_encode($contentArr, JSON_UNESCAPED_UNICODE), now_str(), $id));
            $tplId = $id;
        } else {
            $tplId = EmrRepository::insert('INSERT INTO emr_templates(title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(?,?,?,?,?,?,0,?,?,?)', array(
                $title, $type, $scope, $u['id'], $u['name'], $status,
                json_encode($contentArr, JSON_UNESCAPED_UNICODE), now_str(), now_str(),
            ));
        }

        // 科室关联（仅 dept 范围）
        $deptIds = array();
        EmrRepository::exec('DELETE FROM emr_template_depts WHERE template_id=?', array($tplId));
        if ($scope === 'dept') {
            $deptIds = array();
            foreach (explode(',', (string)post('dept_ids', '')) as $d) {
                $d = (int)$d;
                if ($d > 0) $deptIds[$d] = true;
            }
            // 校验科室存在且为临床科室
            if ($deptIds) {
                $ph = implode(',', array_fill(0, count($deptIds), '?'));
                foreach (EmrRepository::q("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph)", array_keys($deptIds)) as $dd) {
                    EmrRepository::insert('INSERT OR IGNORE INTO emr_template_depts(template_id, dept_id) VALUES(?,?)', array($tplId, (int)$dd['id']));
                }
            }
        }

        // 非管理员提交的 dept/hospital 模板进入审核中心（audits 表）：
        // 创建/更新一条待审核记录，管理员在【审核中心】统一处理
        if ($status === 'pending_review') {
            $scopeName = $scope === 'hospital' ? '全院' : '科室';
            $existing = EmrRepository::one("SELECT id FROM audits WHERE type='template' AND ref_id=? AND status='pending'", array($tplId));
            $auditData = json_encode(array(
                'title' => $title, 'scope' => $scope, 'dept_ids' => $deptIds ? array_keys($deptIds) : array(),
            ), JSON_UNESCAPED_UNICODE);
            if ($existing) {
                EmrRepository::exec('UPDATE audits SET title=?, content=?, data=?, proposer=?, proposer_id=?, created_at=? WHERE id=?', array(
                    $typeLabel . '待审核：' . $title, '提交' . $scopeName . $typeLabel . '「' . $title . '」，请在审核中心查看详情并审核', $auditData, $u['name'], $u['id'], now_str(), (int)$existing['id'],
                ));
            } else {
                submit_audit('template', $tplId, $typeLabel . '待审核：' . $title,
                    '提交' . $scopeName . $typeLabel . '「' . $title . '」，请在审核中心查看详情并审核',
                    array('data' => $auditData));
            }
            // 站内消息提醒管理员前往审核中心处理
            send_msg('admin', 0, '待审核提醒',
                '医生 ' . $u['name'] . ' 提交了' . $scopeName . $typeLabel . '「' . $title . '」待审核，请前往审核中心处理',
                '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
        } else {
            // 免审（个人/管理员）或已过审：清理该模板残留的待审核记录
            EmrRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type='template' AND ref_id=? AND status='pending'", array($u['name'], now_str(), $tplId));
        }

        json_ok(array('id' => $tplId, 'status' => $status),
            $status === 'pending_review' ? '模板已提交，科室/全院模板需管理员在【审核中心】审核后生效' : '模板已保存');
        break;

    /* ==================== 删除模板（越权防护） ==================== */
    case 'delete':
        $id = (int)post('id');
        $t = EmrRepository::one('SELECT * FROM emr_templates WHERE id=?', array($id));
        if (!$t) json_fail('模板不存在');
        if ((int)$t['is_system'] === 1) json_fail('通用模板不可删除');
        if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') json_fail('无权删除该模板');
        // 待审核锁定：提交审核后的模板不允许删除（审核通过/驳回后恢复）
        if ($t['status'] === 'pending_review') json_fail('模板正在审核中，审核通过或驳回后方可删除');
        EmrRepository::exec('DELETE FROM emr_templates WHERE id=?', array($id));
        EmrRepository::exec('DELETE FROM emr_template_depts WHERE template_id=?', array($id));
        json_ok(array(), '模板已删除');
        break;

    default:
        json_fail('未知操作');
}
