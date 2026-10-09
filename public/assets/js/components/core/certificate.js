/**
 * ============================================================
 * certificate.js — 诊断证明弹窗（全站公共实现）
 * ============================================================
 * 说明：病历编辑页开具/查看与就诊历史补开共用同一套弹窗逻辑，
 * 原先在 emr_cert.js（仅 EMR 页加载）与 historypanel.js（全局页加载）
 * 各维护一份逐字重复的实现，现收敛为本模块（JS_CORE 全站加载）：
 *  · 未开具 → 可编辑：病历概要 + 医生建议输入 +「开具并打印」
 *  · 已开具 → 只读：概要含证明号/开具时间，按钮为「打印」
 *    （打印内容始终由服务器 certificate_print 从数据库重新渲染）
 * 调用：Clinic.certificateModal(visitId, title, { warnOnIssued, onIssued })
 *  - warnOnIssued：已开具仍触发开具动作时是否提醒重复
 *  - onIssued(cert)：开具成功后回调（EMR 页据此同步本地 DATA 并刷新左栏）
 * 依赖：Clinic.get / Clinic.ajax / Clinic.modal / Clinic.toast /
 *       Clinic.print / Clinic.textOf / Clinic.escHtml。
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.certificateModal = function (visitId, title, opts) {
    opts = opts || {};
    var escHtml = Clinic.escHtml;
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
            // 行间距统一规则：首行无边距，其余每行 mt-4。
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
                if (opts.warnOnIssued) Clinic.toast.warning('该次就诊已开具过诊断证明');
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
                                text: renderIconSvg('action:print') + ' 打印', cls: 'btn-success',
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
                                    onSuccess: function (res) {
                                        Clinic.toast.success('诊断证明已开具');
                                        Clinic.modal.close();
                                        Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                        if (typeof opts.onIssued === 'function') {
                                            opts.onIssued((res.data && res.data.certificate) || null);
                                        }
                                    },
                                });
                            },
                        },
                    ],
                }
            );
        },
    });
};
