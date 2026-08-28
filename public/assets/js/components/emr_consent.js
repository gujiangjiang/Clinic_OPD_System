/**
 * ============================================================
 * emr_consent.js — 知情同意书模块
 * ============================================================
 * 说明：医生工作站知情同意书——从模板选择框选模板 → 专属编辑模态框
 * 编辑正文 → 保存 → 侧边栏列表渲染 → 打印。复用 emr_template 的
 * 模板选择框（type=consent + onApply 回调）。
 * 依赖：Clinic.emr.template / Clinic.get / Clinic.ajax / Clinic.modal。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.consent = (function () {

    /** 打开知情同意书模板选择框（复用病历模板选择框，type=consent） */
    function openPicker(ev) {
        Clinic.emr.template.openTemplatePicker(ev, {
            type: 'consent',
            pickPlaceholder: '🔍 搜索知情同意书模板',
            emptyText: '暂无可用的知情同意书模板，可前往「模板管理」创建',
            onApply: function (t) {
                var c = t.content || {};
                openEditor({
                    name: c.name || '通用',
                    content: c.content || '',
                    title: t.title || '',
                }, 0, t.id);
            },
        });
    }

    /** 打开知情同意书编辑模态框（新建/编辑，可输入） */
    function openEditor(data, consentId, templateId) {
        var isEdit = consentId && consentId > 0;
        var name = data && data.name ? data.name : '';
        var content = data && data.content ? data.content : '';
        // 标题：名称输入框只读不可更改（由模板决定）
        var html =
            '<div class="form-group"><label class="form-label">知情同意书名称 <span class="req">*</span></label>' +
            '<input class="input" id="ctName" value="' + escHtml(name) + '" readonly placeholder="由模板确定，不可更改"></div>' +
            '<div class="form-group"><label class="form-label">知情同意内容 <span class="req">*</span></label>' +
            '<textarea class="textarea" id="ctContent" rows="14" style="min-height:360px" placeholder="请输入知情同意内容…">' + escHtml(content) + '</textarea></div>' +
            '<div class="fs-12 text-muted">开具医生将自动记录（打印时显示）。</div>';
        var mask = Clinic.modal.open(html, {
            title: (isEdit ? '编辑' : '新建') + '知情同意书',
            size: 'modal-lg',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '保存', cls: 'btn-primary', autoClose: false, onClick: function () { save(consentId, templateId); } },
            ],
        });
        mask.querySelector('#ctName').focus();
    }

    /** 保存知情同意书 */
    function save(consentId, templateId) {
        var content = (document.getElementById('ctContent') || {}).value || '';
        if (!content.trim()) { Clinic.toast.warning('请填写知情同意内容'); return; }
        var visitId = document.getElementById('visitId').value;
        var data = { action: 'save', visit_id: visitId, content: content.trim() };
        if (consentId && consentId > 0) data.id = consentId;
        // 新建时传递 template_id（服务端据此推导标题，防止篡改）
        if (templateId && templateId > 0) data.template_id = templateId;
        Clinic.ajax('/api/consent', data, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                Clinic.modal.close();
                renderList();
            },
        });
    }

    /** 渲染侧边栏「知情同意书」列表 */
    function renderList() {
        var el = document.getElementById('navConsent');
        if (!el) return;
        var visitId = document.getElementById('visitId').value;
        if (!visitId) return;
        Clinic.get('/api/consent?action=list&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var list = j.data.list || [];
                el.innerHTML = list.length ? list.map(function (c) {
                    return '<div class="ena-item" style="cursor:pointer" title="点击编辑" onclick="Clinic.emr.consent.edit(' + c.id + ')">' +
                        '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                        escHtml(c.title) + '</span>' +
                        '<span class="text-muted" style="flex-shrink:0;font-size:11px">' + escHtml(c.doctor_name) + '</span>' +
                        '<span class="ena-del" title="打印" onclick="event.stopPropagation();Clinic.emr.consent.print(' + c.id + ')">🖨️</span>' +
                        '</div>';
                }).join('') : '<div class="ena-empty">暂无知情同意书</div>';
            },
        });
    }

    /** 编辑已保存的知情同意书（加载内容后打开编辑模态框） */
    function edit(id) {
        Clinic.get('/api/consent?action=get&id=' + id, null, {
            onSuccess: function (j) {
                var c = j.data.consent;
                if (!c) return;
                // title 形如「手术知情同意书」→ 反推名称「手术」
                var name = c.title.replace(/知情同意书$/, '');
                openEditor({ name: name, content: c.content }, c.id, 0);
            },
        });
    }

    /** 打印知情同意书（A5 专属打印模板） */
    function print(id) {
        Clinic.print.load('/api/print?action=consent&id=' + id, null, 'a5');
    }

    /** 取当前登录用户名（页面 body data-uid 无姓名，用简单显示） */
    function escHtml(s) { return Clinic.escHtml(s); }

    return {
        openPicker: openPicker,
        openEditor: openEditor,
        save: save,
        edit: edit,
        renderList: renderList,
        print: print,
    };
})();
