<?php
/**
 * ============================================================
 * emr_rules.php — 病历状态机与操作权限统一规则引擎
 * ============================================================
 * 本文件为后端各接口判定提供【唯一权威来源】；前端 emr_rules.js
 * 为同规则镜像（语义一致，展示层兜底）。
 *
 * v2 重构：核心判定逻辑收敛至 EmrContextResolver（SSOT），
 * 本文件保留兼容门面：
 *   - emr_record_state()  兼容旧调用（内部委托 resolve）
 *   - emr_find_editable_record() 委托 get_editable_record
 * 状态单向派生：UI 能力完全由 Context 派生，不维护独立布尔状态。
 *
 * 状态枚举（container_type）：
 *   main_record  本人当前科室普通文书（可写）
 *   supplement   续写段落（可写）
 *   consultation 会诊病历（可写）
 *   initial      新建骨架（record_id=0）
 *   others / dept_mismatch / consult_lock / consult_done / visit_finished（只读熔断）
 * ============================================================ */

/**
 * 计算当前文书状态与操作权限（唯一权威判定，委托 EmrContextResolver）。
 * @param array  $visit  就诊行（registrations 行）
 * @param array  $u      当前用户（需含 id、name；current_dept_id 缺省自动补查）
 * @param array|null $record 当前文书行（patient_records 行）；null 表示新建骨架
 * @return array{state:string,can_write:bool,can_order:bool,can_consult:bool,can_diag:bool,reason:string,context:array}
 */
function emr_record_state($visit, $u, $record = null) {
    $ctx = EmrContextResolver::resolve($visit, $u, $record);
    $caps = $ctx['capabilities'];
    // 兼容旧状态枚举：由 container_type 派生
    $stateMap = array(
        'main_record' => 'editable',
        'supplement'  => 'editable',
        'consultation' => 'consult_editing',
        'initial'     => 'new',
        'consult_lock' => 'consult_lock',
        'consult_done' => 'consult_done',
        'others'      => 'others',
        'dept_mismatch' => 'dept_mismatch',
        'visit_finished' => 'visit_finished',
    );
    $type = $ctx['active']['container_type'];
    return array(
        'state' => isset($stateMap[$type]) ? $stateMap[$type] : $type,
        'can_write' => $caps['can_write'],
        'can_order' => $caps['can_order'],
        'can_consult' => $caps['can_consult'],
        'can_diag' => $caps['can_diag'],
        'reason' => $ctx['lock_reason'],
        'context' => $ctx,   // 完整上下文（active/capabilities/lock_reason）
    );
}

/**
 * 当前医生在本就诊下是否有可编辑的【已保存】文书（开单/发会诊等需绑定场景）。
 * 规则：会诊处理中 → 仅会诊病历；普通模式 → 书写科室==当前科室。
 * @return array|null 可编辑文书行（含 id），无则 null
 */
function emr_find_editable_record($visit, $u) {
    return get_editable_record($visit, $u);
}