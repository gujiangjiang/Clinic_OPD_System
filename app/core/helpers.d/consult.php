<?php
/**
 * ============================================================
 * helpers.d/consult.php — 会诊 / 病历可编辑判定
 * ============================================================
 * 说明：会诊单号生成/惰性补齐、会诊处理上下文判定、可编辑病历
 * 统一规则。由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/** 生成会诊单号（HZ + 时间戳 + 2 位随机，与申请单号同规则、前缀互不冲突） */
function consult_gen_no() {
    return 'HZ' . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
}

/** 惰性补齐会诊单号：旧数据（无 consult_no）首次读取时生成并落库 */
function consult_ensure_no($c) {
    $no = trim((string)(isset($c['consult_no']) ? $c['consult_no'] : ''));
    if ($no === '') {
        do {
            $no = consult_gen_no();
        } while ((int)DB::val('SELECT COUNT(*) FROM consultations WHERE consult_no=?', array($no)) > 0);
        DB::exec('UPDATE consultations SET consult_no=? WHERE id=?', array($no, (int)$c['id']));
        $c['consult_no'] = $no;
    }
    return $c;
}

/**
 * 当前医生在本就诊下的「会诊处理上下文」。
 * 判定规则（后端权威，与前端 URL 参数无关，刷新不丢失）：
 * 该就诊存在「发给当前医生所在科室」的进行中/待处理会诊（pending/doing），
 * 则当前医生对该就诊处于会诊模式——非会诊文书一律只读，只有会诊病历可编辑。
 * 无此会诊（普通接诊/续写/转科/会诊已完毕）返回 null。
 */
function get_consult_context($visit, $u) {
    $visitId = (int)(isset($visit['id']) ? $visit['id'] : 0);
    if ($visitId <= 0) return null;
    // 医生当前所在科室（会话 auth_user 不含 current_dept_id，须从 user 库读取）
    $myDept = current_dept_id($u);
    if ($myDept <= 0) return null;
    return DB::one(        "SELECT * FROM consultations WHERE visit_id=? AND target_dept_id=? AND status IN ('pending','doing') ORDER BY id DESC LIMIT 1",
        array($visitId, $myDept));
}

/**
 * 当前医生在本就诊下是否有「可编辑病历」。
 * 可编辑定义（统一判定，前后端同规则）：
 * · 会诊处理中（get_consult_context 命中）→ 仅本人「会诊病历」可编辑
 *   （consultation_id = 该进行中会诊），其余一律只读；
 * · 普通接诊/续写/转科 → 本人已保存且书写科室 == 就诊当前科室
 *   （会诊病历归属目标科室，非会诊模式下恒为只读，绝不抢占编辑位）。
 * 开单 / 发会诊 / 开诊断证明 / 加诊断等所有需病历支撑的操作统一以此为准。
 * 返回最新可编辑病历行（含 id），无则返回 null。
 */
function get_editable_record($visit, $u) {
    $visitId = (int)(isset($visit['id']) ? $visit['id'] : 0);
    if ($visitId <= 0) return null;
    $uid = (int)$u['id'];
    // 会诊处理中：只有会诊病历可编辑（未创建会诊病历 → 无可编辑病历）
    $cons = get_consult_context($visit, $u);
    if ($cons) {
        return DB::one(            'SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? AND consultation_id=? ORDER BY id DESC LIMIT 1',
            array($visitId, $uid, (int)$cons['id']));
    }
    // 普通模式：书写科室 == 就诊当前科室，且医生当前科室 == 就诊当前科室
    // （跨科室绝对只读——医生在外科不能编辑急诊科文书，反之亦然；
    //   会诊完毕或转科后同样受此规则约束，无需 URL 参数做只读屏障。
    //   会诊病历书写科室为目标科室，非会诊模式下不与就诊当前科室匹配 → 只读）
    $docDept = current_dept_id($u);
    if ($docDept <= 0) return null;
    $visitDept = (int)(isset($visit['current_dept_id']) ? $visit['current_dept_id'] : 0);
    // 医生当前科室 != 就诊当前科室 → 跨科室查看，一切只读
    if ($docDept !== $visitDept) return null;
    // 排除会诊病历（consultation_id>0）——已完结会诊的病历 dept_id 与就诊当前科室一致，
    // 若不排除会被误判为可编辑，与 EmrContextResolver 的 consult_done 只读熔断矛盾
    return DB::one(        'SELECT * FROM patient_records WHERE visit_id=? AND doctor_id=? AND dept_id=? AND (consultation_id IS NULL OR consultation_id=0) ORDER BY id DESC LIMIT 1',
        array($visitId, $uid, $visitDept));
}