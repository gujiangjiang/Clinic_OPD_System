/**
 * ============================================================
 * emr_rules.js — 病历状态机与操作权限统一规则引擎（前端消费者）
 * ============================================================
 * v2 重构：前端不再维护独立 if-else 判定，改为直接消费后端下发的
 * `active_context`（EmrContextResolver SSOT 输出），状态单向派生：
 *   UI 能力（编辑器只读/开单面板/删除按钮/会诊入口）完全由
 *   active_context.capabilities 驱动，不再散落独立布尔判断。
 *
 * 回退策略：若后端未下发 context（旧接口/异常），回退到本地镜像规则，
 * 保证前端不因缺失 context 而崩溃。
 *
 * context 结构（后端权威）：
 *   { active:{writable,container_type,container_id},
 *     capabilities:{can_write,can_order,can_delete_order,can_consult,
 *                   can_append,can_issue_cert,can_diag},
 *     lock_reason }
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.rules = (function () {
    var ctx = Clinic.emr._ctx;

    /** 当前是否处于会诊处理中（__consult_mode 由后端 consult_context 权威驱动） */
    function inConsult() {
        var d = ctx.DATA;
        return !!(d && d.__consult_mode);
    }

    /** 会诊已完毕（当前文书关联的会诊状态为 done） */
    function currentConsultDone() {
        var d = ctx.DATA;
        if (!d || !d.record) return false;
        var cid = d.record.consultation_id || 0;
        if (cid <= 0) return false;
        return (d.consults || []).some(function (cc) {
            return (cc.id || 0) === cid && cc.status === 'done';
        });
    }

    /** 获取后端下发的活跃上下文（SSOT 权威）；无则 null */
    function getActiveContext() {
        var d = ctx.DATA;
        if (!d) return null;
        if (d.active_context && typeof d.active_context === 'object' && d.active_context.active) {
            return d.active_context;
        }
        return null;
    }

    /**
     * 本地回退规则（仅当后端未下发 active_context 时使用）：
     * 与后端 EmrContextResolver 语义一致的最小镜像。
     */
    function fallbackRecordState() {
        var d = ctx.DATA;
        var base = { state: 'others', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: true, canIssueCert: true, canDiag: false, reason: '', writable: false, containerType: 'none', containerId: null };
        if (!d || !d.record) return base;
        if (d.__readonly_view) {
            return { state: 'view_only', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '会诊完毕 · 只读查看模式', writable: false, containerType: 'none', containerId: null };
        }
        if (d.visit && d.visit.status === 'finished') {
            return { state: 'visit_finished', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '该患者已诊毕，病历已归档', writable: false, containerType: 'none', containerId: null };
        }
        if (inConsult()) {
            var cid = d.record.consultation_id || 0;
            if (cid > 0 && !currentConsultDone()) {
                return { state: 'consult_editing', canWrite: true, canOrder: true, canDeleteOrder: true, canConsult: false, canAppend: true, canIssueCert: true, canDiag: true, reason: '会诊病历可编辑', writable: true, containerType: 'consultation', containerId: d.record.record_id || null };
            }
            return { state: 'consult_lock', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '会诊处理中，其他病历仅只读', writable: false, containerType: 'none', containerId: null };
        }
        if (!(d.record.record_id > 0)) {
            return { state: 'new', canWrite: true, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: true, canIssueCert: false, canDiag: true, reason: '新建病历编辑中', writable: true, containerType: 'initial', containerId: null };
        }
        var cid2 = d.record.consultation_id || 0;
        if (cid2 > 0) {
            if (currentConsultDone()) {
                return { state: 'consult_done', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '该会诊已完毕，会诊病历永久只读', writable: false, containerType: 'none', containerId: null };
            }
            return { state: 'consult_lock', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '会诊病历处理中，仅目标科室医生可编辑', writable: false, containerType: 'none', containerId: null };
        }
        if (d.record.dept_match === 1) {
            return { state: 'editable', canWrite: true, canOrder: true, canDeleteOrder: true, canConsult: true, canAppend: true, canIssueCert: true, canDiag: true, reason: '当前科室文书可编辑', writable: true, containerType: 'main_record', containerId: d.record.record_id || null };
        }
        return { state: 'dept_mismatch', canWrite: false, canOrder: false, canDeleteOrder: false, canConsult: false, canAppend: false, canIssueCert: false, canDiag: false, reason: '转科前旧文书，当前科室只读', writable: false, containerType: 'none', containerId: null };
    }

    /**
     * 计算当前文书状态与操作权限（状态单向派生自后端 active_context）。
     * @return {object} {state, canWrite, canOrder, canDeleteOrder, canConsult,
     *                   canAppend, canIssueCert, canDiag, writable,
     *                   containerType, containerId, reason}
     */
    function recordState() {
        var ac = getActiveContext();
        if (ac) {
            var caps = ac.capabilities || {};
            return {
                state: ac.active.container_type || 'none',
                canWrite: !!caps.can_write,
                canOrder: !!caps.can_order,
                canDeleteOrder: !!caps.can_delete_order,
                canConsult: !!caps.can_consult,
                canAppend: !!caps.can_append,
                canIssueCert: !!caps.can_issue_cert,
                canDiag: !!caps.can_diag,
                writable: !!ac.active.writable,
                containerType: ac.active.container_type || 'none',
                containerId: ac.active.container_id || null,
                reason: ac.lock_reason || '',
            };
        }
        return fallbackRecordState();
    }

    /** 当前是否可写（书写/保存） */
    function canWrite() { return recordState().canWrite; }
    /** 当前是否可保存 */
    function canSave() { return recordState().canWrite; }
    /** 能否开单（需已保存可编辑容器） */
    function canOrder() { return recordState().canOrder; }
    /** 能否删除开单（归属判定：目标 container 必须等于活跃容器） */
    function canDeleteOrder() { return recordState().canDeleteOrder; }
    /** 能否发起会诊 */
    function canConsult() { return recordState().canConsult; }
    /** 能否加/改诊断 */
    function canDiag() { return recordState().canDiag; }
    /** 能否补开诊断证明 */
    function canIssueCert() { return recordState().canIssueCert; }
    /** 当前活跃容器 id（归属校验用） */
    function activeContainerId() { return recordState().containerId; }

    return {
        recordState: recordState,
        canWrite: canWrite,
        canSave: canSave,
        canOrder: canOrder,
        canDeleteOrder: canDeleteOrder,
        canConsult: canConsult,
        canDiag: canDiag,
        canIssueCert: canIssueCert,
        activeContainerId: activeContainerId,
        getActiveContext: getActiveContext,
        inConsult: inConsult,
    };
})();