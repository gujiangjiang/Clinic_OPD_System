/**
 * ============================================================
 * emr_rules.js — 病历状态机与操作权限统一规则引擎（前端镜像）
 * ============================================================
 * 与后端 app/core/emr_rules.php 的 emr_record_state() 同规则（语义一致），
 * 供前端统一判定：当前文书/就诊处于何种状态、能否书写/保存/开单/发会诊/加诊断。
 * 依赖 Clinic.emr._ctx（DATA / __consult_mode / __consult_id / consults）。
 *
 * 状态枚举（state）：
 *   visit_finished  诊毕归档，一切只读
 *   consult_editing 会诊处理中且当前文书即该会诊病历（可写）
 *   consult_lock    会诊处理中但当前文书非会诊病历（只读）
 *   consult_done    会诊已完毕的会诊病历（永久只读）
 *   editable        本人当前科室普通文书（可写/可开单/可会诊）
 *   dept_mismatch   本人转科前旧文书（只读，须续写）
 *   others          他人文书（只读）
 *   new             新建中（record_id=0，编辑器已渲染）
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

    /**
     * 计算当前文书状态（前端镜像，与后端 emr_record_state 同规则）。
     * @return {object} {state, canWrite, canOrder, canConsult, canDiag, reason}
     */
    function recordState() {
        var d = ctx.DATA;
        var base = { state: 'others', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '' };
        if (!d || !d.record) return base;
        // 只读查看模式（会诊完毕「查看完整病历」）：全锁死，且不允许补开诊断证明
        if (d.__readonly_view) {
            return { state: 'view_only', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '会诊完毕 · 只读查看模式' };
        }
        if (d.visit && d.visit.status === 'finished') {
            return { state: 'visit_finished', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '该患者已诊毕，病历已归档' };
        }
        // 会诊处理中
        if (inConsult()) {
            var cid = d.record.consultation_id || 0;
            if (cid > 0 && !currentConsultDone()) {
                return { state: 'consult_editing', canWrite: true, canOrder: true, canConsult: false, canDiag: true, reason: '会诊病历可编辑' };
            }
            return { state: 'consult_lock', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '会诊处理中，其他病历仅只读' };
        }
        // 新建骨架
        if (!(d.record.record_id > 0)) {
            return { state: 'new', canWrite: true, canOrder: false, canConsult: false, canDiag: true, reason: '新建病历编辑中' };
        }
        // 会诊文书：未完毕可编辑，已完毕永久只读
        var cid2 = d.record.consultation_id || 0;
        if (cid2 > 0) {
            if (currentConsultDone()) {
                return { state: 'consult_done', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '该会诊已完毕，会诊病历永久只读' };
            }
            return { state: 'consult_lock', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '会诊病历处理中，仅目标科室医生可编辑' };
        }
        // 普通文书：本人 + 当前科室（dept_match）
        if (d.record.dept_match === 1) {
            return { state: 'editable', canWrite: true, canOrder: true, canConsult: true, canDiag: true, reason: '当前科室文书可编辑' };
        }
        return { state: 'dept_mismatch', canWrite: false, canOrder: false, canConsult: false, canDiag: false, reason: '转科前旧文书，当前科室只读' };
    }

    /** 当前文书是否可写（书写/保存） */
    function canWrite() { return recordState().canWrite; }
    /** 当前文书是否可保存（与 canWrite 一致，保存即书写落库） */
    function canSave() { return recordState().canWrite; }
    /** 能否开单（需有已保存可编辑文书，record_id>0） */
    function canOrder() { return recordState().canOrder; }
    /** 能否发起会诊 */
    function canConsult() { return recordState().canConsult; }
    /** 能否加/改诊断 */
    function canDiag() { return recordState().canDiag; }

    return {
        recordState: recordState,
        canWrite: canWrite,
        canSave: canSave,
        canOrder: canOrder,
        canConsult: canConsult,
        canDiag: canDiag,
        inConsult: inConsult,
    };
})();
