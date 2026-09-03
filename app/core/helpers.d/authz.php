<?php
/**
 * ============================================================
 * helpers.d/authz.php — 用户科室 / 病历授权 / 生命体征
 * ============================================================
 * 说明：用户关联科室 ID 列表解析、病历段生命体征归属查询、科室
 * 数据隔离与病历可访问天数校验。由 helpers.php 统一加载，拆分后
 * 引用方式不变。
 * ============================================================ */

/**
 * 用户关联科室 ID 列表（统一解析 dept_ids 逗号分隔串）
 * 说明：会话快照中的 dept_ids 可能为 NULL（如管理员等无科室用户），
 * 先判空再拆分，避免 PHP 8 的 Undefined key / Deprecated 告警污染 JSON。
 * @param array $u 用户数据（含 dept_ids 字段；缺省取当前登录用户）
 * @return int[]
 */
function user_dept_ids($u = null) {
    if ($u === null) {
        $u = Auth::user();
    }
    $ids = array();
    foreach (explode(',', isset($u['dept_ids']) ? (string)$u['dept_ids'] : '') as $id) {
        if ((int)$id > 0) $ids[] = (int)$id;
    }
    return $ids;
}

/**
 * 护士操作科室归属校验（宽松版）。
 * 说明：护士默认不绑定科室（dept_ids 为空 = 全院），此时一律放行——
 * 强制执行 visit_dept_authorized 会拦停所有护士操作；仅当护士在
 * 用户管理中确实配置了 dept_ids 时，才校验就诊当前科室是否在其
 * 关联科室范围内。
 * @param array $visit 就诊行（需含 current_dept_id）
 * @param array $u     当前用户
 * @return bool
 */
function nurse_visit_allowed($visit, $u) {
    $myDepts = user_dept_ids($u);
    if (!$myDepts) return true;   // 未绑定科室 = 全院护士
    $visitDept = (int)(isset($visit['current_dept_id']) ? $visit['current_dept_id'] : 0);
    return $visitDept <= 0 || in_array($visitDept, $myDepts, true);
}

/**
 * 用户当前所在科室 ID（统一读取 users.current_dept_id）
 * 说明：会话快照（auth_user）不含 current_dept_id，各接口此前重复内联
 * SELECT current_dept_id FROM users，统一收敛到本函数（含会话快照
 * 优先的快速路径）。
 * @param array $u 用户数据（含可选 current_dept_id / id）
 * @return int 科室 ID，0 表示未选择
 */
function current_dept_id($u) {
    if (isset($u['current_dept_id']) && (int)$u['current_dept_id'] > 0) {
        return (int)$u['current_dept_id'];
    }
    $row = UserRepository::currentDept((int)$u['id']);
    return $row ? (int)$row['current_dept_id'] : 0;
}

/**
 * 病历段生命体征归属查询（统一规则）：
 * 按文书记录精确关联（record_id 优先）；续写/会诊病历各自独立体征——
 * 只取本记录关联的体征，绝不复用首诊体征；首诊记录（非续写）才按
 * operator 回退就诊体征（护士站录入共用，仅回退未归属任何病历的体征）。
 * @return array|null vitals 行
 */
function get_record_vitals($recordId, $visitId, $operator, $recordType) {
    if ((int)$recordId > 0) {
        $v = EmrRepository::one('SELECT * FROM vitals WHERE record_id=? ORDER BY id DESC LIMIT 1', array((int)$recordId));
        if ($v) return $v;
    }
    if ($recordType !== 'progress') {
        // 仅回退「未归属任何病历」的体征（record_id=0），且 operator 一致——
        // 绝不引用其他病历/会诊已归属的体征，保证病历间体征完全隔离。
        return EmrRepository::one('SELECT * FROM vitals WHERE visit_id=? AND operator=? AND record_id=0 ORDER BY id DESC LIMIT 1', array((int)$visitId, (string)$operator));
    }
    return null;
}

/**
 * 科室数据隔离：非挂号科室的医生不能查看/接诊当前就诊。
 * 放行条件：管理员；已诊毕归档（历史查看）；当前就诊科室在医生科室范围内；
 * 或医生已在本就诊写过病历（临床连续性）。患者历史就诊（既往病历）不受限。
 */
function visit_dept_authorized($visit, $u) {
    if ($u['role'] === 'admin') return true;
    if (isset($visit['status']) && $visit['status'] === 'finished') return true;
    $curDept = (int)(isset($visit['current_dept_id']) ? $visit['current_dept_id'] : 0);
    if ($curDept <= 0) return true;
    $myDepts = user_dept_ids($u);
    if (in_array($curDept, $myDepts, true)) return true;
    $visitId = (int)(isset($visit['id']) ? $visit['id'] : 0);
    if ($visitId > 0) {
        $n = (int)DB::val('SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=?', array($visitId, (int)$u['id']));
        if ($n > 0) return true;
    }
    return false;
}

/**
 * 病历可访问天数校验（防越权访问超期历史病历）：
 * 管理员放行；所有就诊（含待就诊/就诊中）均须在医生 queue_days
 * （2-7，默认 3）可查看天数内——门诊挂号一次管 N 天，过期即不可见。
 * 历史只读面板（print.php）不受此限制。
 */
function visit_access_allowed($visit, $u) {
    if ($u['role'] === 'admin') return true;
    $queueDays = 3;
    if (isset($u['queue_days']) && (int)$u['queue_days'] >= 2 && (int)$u['queue_days'] <= 7) {
        $queueDays = (int)$u['queue_days'];
    } else {
        $ud = DB::one('SELECT queue_days FROM users WHERE id=?', array((int)$u['id']));
        if ($ud && (int)$ud['queue_days'] >= 2 && (int)$ud['queue_days'] <= 7) $queueDays = (int)$ud['queue_days'];
    }
    $regTime = isset($visit['registered_at']) ? (string)$visit['registered_at'] : '';
    if ($regTime === '') return true;
    $since = date('Y-m-d', strtotime('-' . ($queueDays - 1) . ' days'));
    return substr($regTime, 0, 10) >= $since;
}