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

/** 取当前医生所在科室 ID 列表（与 doctor.php 同源） */
function tpl_dept_ids($u) {
    $ids = array();
    foreach (explode(',', isset($u['dept_ids']) ? (string)$u['dept_ids'] : '') as $id) {
        if ((int)$id > 0) $ids[] = (int)$id;
    }
    return $ids;
}

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
        $rows = DB::q('dept', "SELECT id, name FROM departments WHERE status=1 AND type IN ('clinic','emergency') ORDER BY sort, id");
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
            $myDepts = tpl_dept_ids($u);
            // 可见性：本人个人模板（任意状态）+ 已发布全院 + 已发布本人科室模板
            $conds = array("(scope='personal' AND creator_id=?)");
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
        $sql .= " ORDER BY is_system DESC, scope ASC, id DESC";
        $rows = DB::q('emr_templates', $sql, $params);
        $out = array();
        foreach ($rows as $t) {
            // 关联科室名
            $deptNames = array();
            $links = DB::q('emr_templates', 'SELECT dept_id FROM emr_template_depts WHERE template_id=?', array((int)$t['id']));
            if ($links) {
                $dids = array();
                foreach ($links as $l) $dids[] = (int)$l['dept_id'];
                $ph2 = implode(',', array_fill(0, count($dids), '?'));
                foreach (DB::q('dept', "SELECT id, name FROM departments WHERE id IN ($ph2)", $dids) as $dn) {
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
        $t = DB::one('emr_templates', 'SELECT * FROM emr_templates WHERE id=?', array($id));
        if (!$t) json_fail('模板不存在');
        // 越权防护：系统模板/他人模板不可编辑（管理员可查看）
        if ($t['is_system'] == 1) json_fail('通用模板不可编辑');
        if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') {
            json_fail('无权编辑该模板');
        }
        $links = DB::q('emr_templates', 'SELECT dept_id FROM emr_template_depts WHERE template_id=?', array($id));
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
        // 后端强制剥离禁止字段（前端可能被篡改）
        $contentArr = tpl_filter_content($contentArr);

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
            $old = DB::one('emr_templates', 'SELECT * FROM emr_templates WHERE id=?', array($id));
            if (!$old) json_fail('模板不存在');
            if ((int)$old['is_system'] === 1) json_fail('通用模板不可修改');
            if ((int)$old['creator_id'] !== (int)$u['id'] && !$isAdmin) json_fail('无权修改该模板');
            // 管理员编辑他人模板不改变归属；医生编辑保持原 scope/状态语义
            DB::exec('emr_templates', 'UPDATE emr_templates SET title=?, type=?, scope=?, content_json=?, updated_at=? WHERE id=?',
                array($title, $type, $scope, json_encode($contentArr, JSON_UNESCAPED_UNICODE), now_str(), $id));
            $tplId = $id;
        } else {
            $tplId = DB::insert('emr_templates', 'INSERT INTO emr_templates(title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(?,?,?,?,?,?,0,?,?,?)', array(
                $title, $type, $scope, $u['id'], $u['name'], $status,
                json_encode($contentArr, JSON_UNESCAPED_UNICODE), now_str(), now_str(),
            ));
        }

        // 科室关联（仅 dept 范围）
        DB::exec('emr_templates', 'DELETE FROM emr_template_depts WHERE template_id=?', array($tplId));
        if ($scope === 'dept') {
            $deptIds = array();
            foreach (explode(',', (string)post('dept_ids', '')) as $d) {
                $d = (int)$d;
                if ($d > 0) $deptIds[$d] = true;
            }
            // 校验科室存在且为临床科室
            if ($deptIds) {
                $ph = implode(',', array_fill(0, count($deptIds), '?'));
                foreach (DB::q('dept', "SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph)", array_keys($deptIds)) as $dd) {
                    DB::insert('emr_templates', 'INSERT OR IGNORE INTO emr_template_depts(template_id, dept_id) VALUES(?,?)', array($tplId, (int)$dd['id']));
                }
            }
        }

        json_ok(array('id' => $tplId, 'status' => $status),
            $status === 'pending_review' ? '模板已提交，科室/全院模板需管理员审核后生效' : '模板已保存');
        break;

    /* ==================== 审核（管理员专属） ==================== */
    case 'review':
        if ($u['role'] !== 'admin') json_fail('仅管理员可审核模板');
        $id = (int)post('id');
        $verdict = post('verdict');   // approve / reject
        $t = DB::one('emr_templates', 'SELECT * FROM emr_templates WHERE id=?', array($id));
        if (!$t) json_fail('模板不存在');
        if ((int)$t['is_system'] === 1) json_fail('通用模板不可审核');
        if ($verdict === 'approve') {
            DB::exec('emr_templates', 'UPDATE emr_templates SET status=?, updated_at=? WHERE id=?', array('published', now_str(), $id));
            json_ok(array(), '模板审核通过，已发布');
        } elseif ($verdict === 'reject') {
            // 驳回：状态 rejected + 范围强制降级为个人 + 系统通知创建者
            DB::exec('emr_templates', 'UPDATE emr_templates SET status=?, scope=?, updated_at=? WHERE id=?', array('rejected', 'personal', now_str(), $id));
            if ((int)$t['creator_id'] > 0) {
                send_msg('doctor', (int)$t['creator_id'],
                    '病历模板被驳回',
                    '您的模板「' . $t['title'] . '」未通过管理员审核，已降级为个人模板（仅自己可见可用）。请修改后重新提交。',
                    '', '', array('msg_type' => 'system'));
            }
            json_ok(array(), '模板已驳回，已通知创建人并降级为个人模板');
        } else {
            json_fail('审核指令无效');
        }
        break;

    /* ==================== 删除模板（越权防护） ==================== */
    case 'delete':
        $id = (int)post('id');
        $t = DB::one('emr_templates', 'SELECT * FROM emr_templates WHERE id=?', array($id));
        if (!$t) json_fail('模板不存在');
        if ((int)$t['is_system'] === 1) json_fail('通用模板不可删除');
        if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') json_fail('无权删除该模板');
        DB::exec('emr_templates', 'DELETE FROM emr_templates WHERE id=?', array($id));
        DB::exec('emr_templates', 'DELETE FROM emr_template_depts WHERE template_id=?', array($id));
        json_ok(array(), '模板已删除');
        break;

    default:
        json_fail('未知操作');
}
