/**
 * ============================================================
 * emr_cert.js — 诊断证明弹窗（开具 / 补开 / 查看）
 * ============================================================
 * 说明：自 emr.js 拆出的诊断证明模块——病历编辑页开具/查看、
 * 就诊历史补开共用同一套弹窗。经 Clinic.emr._ctx 读写共享状态
 * 与内部函数（renderLeftNav / requireSaved / isRecordComplete）。
 * 依赖：Clinic.get / Clinic.ajax / Clinic.modal / Clinic.toast /
 * Clinic.print / Clinic.textOf / Clinic.escHtml。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.cert = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var renderLeftNav = ctx.renderLeftNav;

    function viewCertificate() {
        var visitId = document.getElementById('visitId').value;
        Clinic.print.load('/api/print?action=certificate&visit_id=' + visitId, null);
    }

    /**
     * 诊断证明弹窗（开具/补开/查看共用同一套代码，方便维护）
     * ——区别仅是模态框标题与入参就诊 ID：
     * · 开具：visitId = 当前编辑页就诊（本次就诊的病历）
     * · 补开：visitId = 就诊历史中的目标就诊（那一次的病历）
     * 三种形态：
     * · 未开具 → 可编辑：病历概要 + 医生建议输入 +「开具并打印」
     * · 已开具 → 只读：概要含证明号/开具时间，医生建议只读，
     *   按钮显示为「打印」——打印内容始终由服务器 certificate_print
     *   从数据库重新渲染（前端只读区域仅作展示，改不了真实数据）。
     * @param bool warnOnIssued 已开具时是否弹「已开具过」提醒——仅用于
     *        「仍触发开具 / 补开动作」的场景；单纯点击查看不提示。
     */
    function certificateModal(visitId, title, onIssued, warnOnIssued) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var r = j.data.record || {};
                var issued = !!j.data.has_certificate;
                var cert = j.data.certificate || {};
                var text = Clinic.textOf;
                var cc, pi, diag;
                // 病历摘要取值优先级：证书快照（已开具，固化不变）→
                // cert_summary（未开具，与开具时写入的快照同源，所见即所冻）
                // → 实时投影回退（历史证明兼容）。诊断证明为法律文书，
                // 一经出具内容永不随后续续写漂移。
                var cs = j.data.certificate || {};
                var cs2 = j.data.cert_summary || {};
                var pick = function (a, b, c) { return (a && String(a).trim()) || (b && String(b).trim()) || c || ''; };
                cc = text(pick(cs.chief_complaint, cs2.chief_complaint, r.chief_complaint));
                pi = text(pick(cs.present_illness, cs2.present_illness, r.present_illness));
                diag = pick(cs.preliminary_diagnosis, cs2.preliminary_diagnosis, r.preliminary_diagnosis);

                // 病历概要区（两种形态共用；已开具时附证明号与开具时间）。
                // 行间距统一规则：首行无边距，其余每行 mt-4——
                // 修复「开具时间与主诉贴在一起」的缺失行距问题。
                var rows = [];
                if (issued) {
                    rows.push('<div><strong>证明号：</strong>' + escHtml(cert.cert_no || '') + '</div>');
                    rows.push('<div class="mt-4"><strong>开具时间：</strong>' + escHtml(cert.created_at || '') + '</div>');
                }
                rows.push('<div' + (rows.length ? ' class="mt-4"' : '') + '><strong>主诉：</strong>' + escHtml(cc) + '</div>');
                rows.push('<div class="mt-4"><strong>现病史：</strong>' + escHtml(pi) + '</div>');
                rows.push('<div class="mt-4"><strong>初步诊断：</strong>' + escHtml(diag) + '</div>');
                var summary =
                    '<div class="fs-13 mb-8" style="border:1px solid var(--border);border-radius:8px;padding:10px">' +
                    rows.join('') +
                    '</div>';

                /* ---- 已开具：查看 + 打印（打印取服务器存档数据） ---- */
                if (issued) {
                    // 仅「已开具仍触发开具 / 补开动作」时提醒重复；
                    // 单纯查看已开具证明（右栏条目点击）不打扰
                    if (warnOnIssued) Clinic.toast.warning('该次就诊已开具过诊断证明');
                    Clinic.modal.open(
                        summary +
                        '<div class="form-group"><label class="form-label">医生建议</label>' +
                        // 纯展示只读框：灰底、禁用、去掉右下角拖拽手柄、不显示文本光标
                        '<textarea class="textarea" rows="3" disabled ' +
                        'style="background:var(--bg);cursor:default;resize:none;">' +
                        escHtml(cert.content || '') + '</textarea></div>',
                        {
                            title: title,
                            size: 'modal-sm',
                            buttons: [
                                { text: '关闭', cls: 'btn-outline' },
                                {
                                    // 打印走 certificate_print：由服务器重新渲染存档数据
                                    text: '🖨️ 打印', cls: 'btn-success',
                                    onClick: function () {
                                        Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                    },
                                },
                            ],
                        }
                    );
                    return;
                }

                /* ---- 未开具：可编辑开具 ---- */
                if (!cc || !pi || !diag) {
                    Clinic.toast.warning('该次就诊病历不完整（缺少主诉/现病史/初步诊断），无法开具诊断证明');
                    return;
                }
                Clinic.modal.open(
                    '<div class="fs-13 text-muted mb-8">将自动引用该次就诊病历，医生建议请手动填写：</div>' +
                    summary +
                    '<div class="form-group"><label class="form-label">医生建议</label>' +
                    '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
                    {
                        title: title,
                        size: 'modal-sm',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '开具并打印', cls: 'btn-success', autoClose: false,
                                onClick: function () {
                                    var content = document.getElementById('certContent').value.trim();
                                    if (!content) { Clinic.toast.warning('请填写医生建议'); return; }
                                    Clinic.ajax('/api/record', {
                                        action: 'certificate', visit_id: visitId, content: content,
                                    }, {
                                        onSuccess: function () {
                                            Clinic.toast.success('诊断证明已开具');
                                            Clinic.modal.close();
                                            Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                            renderLeftNav();
                                            if (typeof onIssued === 'function') onIssued();
                                        },
                                    });
                                },
                            },
                        ],
                    }
                );
            },
        });
    }

    /**
     * 开具诊断证明（本次就诊，单次就诊仅一次）
     * 与「补开诊断证明」共用 certificateModal，仅标题不同；
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
