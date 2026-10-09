/**
 * ============================================================
 * emr_cert.js — 诊断证明弹窗（EMR 编辑页适配层）
 * ============================================================
 * 说明：弹窗主体已下沉到全站公共模块 core/certificate.js
 * （Clinic.certificateModal，随 JS_CORE 全站加载）；本文件仅保留
 * EMR 编辑页专属适配：
 *  · viewCertificate / openCertificate 的入口与完整性校验
 *  · 开具成功后即时同步本地 DATA 并刷新右栏「诊断证明」条目
 * 经 Clinic.emr._ctx 读写共享状态与内部函数（renderLeftNav /
 * requireSaved / isRecordComplete）。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.cert = (function () {
    var ctx = Clinic.emr._ctx;
    var renderLeftNav = ctx.renderLeftNav;

    function viewCertificate() {
        var visitId = document.getElementById('visitId').value;
        Clinic.print.load('/api/print?action=certificate&visit_id=' + visitId, null);
    }

    /**
     * 诊断证明弹窗（开具/补开/查看共用公共实现）
     * @param bool warnOnIssued 已开具时是否弹「已开具过」提醒
     * @param Function onIssued  开具成功回调（原样透传公共模块）
     */
    function certificateModal(visitId, title, onIssued, warnOnIssued) {
        Clinic.certificateModal(visitId, title, {
            warnOnIssued: !!warnOnIssued,
            onIssued: function (cert) {
                // EMR 编辑页：即时同步本地 DATA（has_certificate + 证明行），
                // renderLeftNav 立即显示右侧「诊断证明」条目，无需刷新页面
                if (ctx.DATA) {
                    ctx.DATA.has_certificate = 1;
                    ctx.DATA.certificate = cert || null;
                    ctx.DATA.cert_summary = null;
                }
                renderLeftNav();
                if (typeof onIssued === 'function') onIssued(cert);
            },
        });
    }

    /**
     * 开具诊断证明（本次就诊，单次就诊仅一次）
     * visitId 固定取当前编辑页的就诊 ID——引用的是本次就诊病历。
     * 完整性判断与打印病历按钮完全一致（isRecordComplete）：
     * 已诊毕直接放行；未诊毕须本人文书已完善并保存，否则提示先完善病历。
     */
    function openCertificate() {
        // 病历有修改未保存：先保存再开具（证明快照取自已保存内容）
        if (!ctx.requireSaved('开具诊断证明')) return;
        var visitId = document.getElementById('visitId').value;
        // 与打印病历按钮同一套判断逻辑与提示语（仅场景词不同）
        if (!ctx.isRecordComplete()) {
            Clinic.toast.warning('请先在病历中完善主诉、现病史与初步诊断并保存，再开具诊断证明');
            return;
        }
        // warnOnIssued=true：已开具仍触发开具动作（正常已被「＋」隐藏拦截，
        // 此处为特殊手段强制打开的兜底提醒）
        certificateModal(visitId, '开具诊断证明', null, true);
    }

    return {
        viewCertificate: viewCertificate,
        certificateModal: certificateModal,
        openCertificate: openCertificate,
    };
})();
