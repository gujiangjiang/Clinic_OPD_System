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

    var _mask = null;        // 当前知情同意书模态框
    var _consentId = 0;      // 当前编辑的知情同意书 id（0=新建）
    var _templateId = 0;     // 新建时的模板 id（标题服务端推导）

    /**
     * 打开知情同意书模态框：
     * 新建（consentId=0）→ 编辑态（内容可编辑 + 保存）；
     * 查看已保存（consentId>0）→ 查看态（内容只读 + 编辑/打印）。
     */
    function openEditor(data, consentId, templateId) {
        var name = data && data.name ? data.name : '';
        var content = data && data.content ? data.content : '';
        _consentId = consentId || 0;
        _templateId = templateId || 0;
        var html =
            '<div class="form-group"><label class="form-label">知情同意书名称 <span class="req">*</span></label>' +
            '<input class="input" id="ctName" value="' + escHtml(name) + '" readonly placeholder="由模板确定，不可更改"></div>' +
            '<div class="form-group"><label class="form-label">知情同意内容 <span class="req">*</span></label>' +
            '<textarea class="textarea" id="ctContent" rows="14" style="min-height:360px" placeholder="请输入知情同意内容…">' + escHtml(content) + '</textarea></div>' +
            '<div class="fs-12 text-muted">开具医生将自动记录（打印时显示）。</div>';
        _mask = Clinic.modal.open(html, {
            title: (_consentId > 0 ? '知情同意书' : '新建知情同意书'),
            size: 'modal-lg',
            buttons: [],
        });
        if (_consentId > 0) {
            // 查看已保存：默认查看态（内容只读）
            _enterViewState();
        } else {
            // 新建：编辑态
            _enterEditState();
        }
        _mask.querySelector('#ctName').focus();
    }

    /** 进入编辑态：内容可编辑，脚部 取消/保存 */
    function _enterEditState() {
        ['#ctName', '#ctContent'].forEach(function (sel) {
            var el = _mask.querySelector(sel);
            if (el) { el.disabled = false; el.readOnly = false; }
        });
        var foot = _mask.querySelector('.modal-foot');
        foot.innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" onclick="Clinic.emr.consent.save()">保存</button>';
    }

    /** 进入查看态：内容只读（disabled 不可点击），脚部 取消/编辑/打印 */
    function _enterViewState() {
        ['#ctName', '#ctContent'].forEach(function (sel) {
            var el = _mask.querySelector(sel);
            if (el) { el.disabled = true; el.readOnly = true; }
        });
        var foot = _mask.querySelector('.modal-foot');
        foot.innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" onclick="Clinic.emr.consent.enterEdit()">✏️ 编辑</button>' +
            '<button type="button" class="btn btn-success" onclick="Clinic.emr.consent.printCurrent()">🖨️ 打印</button>';
    }

    /** 查看态 → 编辑态 */
    function enterEdit() {
        _enterEditState();
    }

    /** 保存知情同意书（新建/编辑），成功后自动弹出打印预览 */
    function save() {
        var content = (document.getElementById('ctContent') || {}).value || '';
        if (!content.trim()) { Clinic.toast.warning('请填写知情同意内容'); return; }
        var visitId = document.getElementById('visitId').value;
        var data = { action: 'save', visit_id: visitId, content: content.trim() };
        if (_consentId > 0) data.id = _consentId;
        if (_templateId > 0) data.template_id = _templateId;
        Clinic.ajax('/api/consent', data, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                var savedId = j.data && j.data.id ? j.data.id : _consentId;
                Clinic.modal.close();
                renderList();
                // 保存后自动弹出打印预览
                if (savedId) print(savedId);
            },
        });
    }

    /** 打印当前查看的知情同意书 */
    function printCurrent() {
        if (_consentId > 0) print(_consentId);
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
                var myUid = parseInt(document.body.getAttribute('data-uid') || '0', 10) || 0;
                el.innerHTML = list.length ? list.map(function (c) {
                    var delBtn = (c.doctor_id && c.doctor_id === myUid)
                        ? '<span class="ena-del" title="删除" onclick="event.stopPropagation();Clinic.emr.consent.del(' + c.id + ')">🗑️</span>'
                        : '';
                    return '<div class="ena-item" style="cursor:pointer" title="点击查看" onclick="Clinic.emr.consent.edit(' + c.id + ')">' +
                        '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                        escHtml(c.title) + '</span>' +
                        '<span class="text-muted" style="flex-shrink:0;font-size:11px">' + escHtml(c.doctor_name) + '</span>' +
                        delBtn +
                        '</div>';
                }).join('') : '<div class="ena-empty">暂无知情同意书</div>';
            },
        });
    }

    /** 删除本人创建的知情同意书 */
    function del(id) {
        Clinic.modal.confirm('确定删除该知情同意书？删除后不可恢复。', function () {
            Clinic.ajax('/api/consent', { action: 'delete', id: id }, {
                onSuccess: function (j) {
                    Clinic.toast.success(j.msg);
                    renderList();
                },
            });
        }, { title: '删除知情同意书', okText: '确认删除' });
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
        enterEdit: enterEdit,
        save: save,
        printCurrent: printCurrent,
        edit: edit,
        del: del,
        renderList: renderList,
        print: print,
    };
})();
