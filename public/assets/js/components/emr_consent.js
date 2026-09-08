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

    /** 打开知情同意/告知文书模板选择框（复用病历模板选择框，type=consent） */
    function openPicker(ev) {
        Clinic.emr.template.openTemplatePicker(ev, {
            type: 'consent',
            pickPlaceholder: '🔍 搜索知情同意/告知文书模板',
            emptyText: '暂无可用的知情同意/告知文书模板，可前往「模板管理」创建',
            onApply: function (t) {
                var c = t.content || {};
                // 标题展示（服务端权威推导）：新模板（无 name）→ 模板名称原文（完全自定义抬头）；
                // 旧模板（含 name 非空）→ 兼容旧逻辑 name + 知情同意书
                var titleGuess = (c.name && String(c.name).trim()) ? (String(c.name).trim() + '知情同意书') : (t.title || '');
                openEditor({
                    title: titleGuess,
                    content: c.content || '',
                    notice: c.notice || '',
                    // 旧模板无 sections → 默认主诉+初步诊断（旧行为）
                    sections: Array.isArray(c.sections) ? c.sections : ['chief_complaint', 'preliminary_diagnosis'],
                }, 0, t.id);
            },
        });
    }

    var _mask = null;        // 当前知情同意书模态框
    var _consentId = 0;      // 当前编辑的知情同意书 id（0=新建）
    var _templateId = 0;     // 新建时的模板 id（标题服务端推导）
    var _docId = 0;          // 当前查看知情同意书的开具医生 id（用于删除权限）
    var _docTitle = '';      // 当前文书标题（模态框标题展示用）

    /** 跨科室只读查看模式（后端 readonly_view 状态驱动）：知情同意书仅可查看/打印，禁止增删改 */
    function isReadonlyView() {
        return !!(window.Clinic && Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.__readonly_view);
    }

    /** 默认告知话术（占位符；空值保存时后端回落同一文本） */
    var DEFAULT_NOTICE = '患者/委托人已知晓上述病情介绍与知情同意内容，医生已向我详细解释，我已完全理解，愿意承担可能出现风险及并发症，并遵从医嘱，配合治疗。';

    /** 可勾选的病历内容节（键 => 标签，与后端 consent_section_keys 一致） */
    var CONSENT_SECTIONS = [
        ['chief_complaint', '主诉'], ['present_illness', '现病史'], ['past_history', '既往史'],
        ['allergy_history', '过敏史'], ['main_symptoms', '主要症状'], ['vitals', '生命体征'],
        ['consciousness', '意识状态'], ['physical_exam', '体格检查'], ['preliminary_diagnosis', '初步诊断'],
    ];

    /** 病历内容显示节复选框组（sel 为勾选键数组） */
    function sectionsHtml(sel) {
        sel = sel || [];
        return CONSENT_SECTIONS.map(function (s) {
            var on = sel.indexOf(s[0]) !== -1;
            return '<label class="fs-13" style="display:inline-flex;align-items:center;gap:4px;margin:2px 10px 2px 0;cursor:pointer">' +
                '<input type="checkbox" class="ct-sec-chk" value="' + s[0] + '"' + (on ? ' checked' : '') + '>' + s[1] + '</label>';
        }).join('');
    }

    function sectionsFromDom() {
        var out = [];
        document.querySelectorAll('.ct-sec-chk:checked').forEach(function (c) { out.push(c.value); });
        return out;
    }

    /**
     * 打开知情同意/告知文书模态框（模态框标题即文书标题）：
     * 新建（consentId=0）→ 编辑态（内容可编辑 + 保存）；
     * 查看已保存（consentId>0）→ 查看态（内容只读 + 编辑/打印/删除）。
     * data: { title?, content, notice, sections[], doctor_id? }
     */
    function openEditor(data, consentId, templateId) {
        var content = data && data.content ? data.content : '';
        var notice = data && data.notice ? data.notice : '';
        var sections = data && data.sections ? data.sections : ['chief_complaint', 'preliminary_diagnosis'];
        _consentId = consentId || 0;
        _templateId = templateId || 0;
        _docId = data && data.doctor_id ? parseInt(data.doctor_id, 10) || 0 : 0;
        _docTitle = (data && data.title) || '';
        var html =
            // 编辑已保存文书时点击「编辑」弹确认框（见 enterEdit），此处不内嵌提示条
            '<div class="form-group"><label class="form-label">病情介绍显示内容 <span class="fs-12 text-muted fw-400">（保存时按所选节固化病历快照，空内容自动不显示）</span></label>' +
            '<div id="ctSections">' + sectionsHtml(sections) + '</div></div>' +
            '<div class="form-group"><label class="form-label">正文内容 <span class="req">*</span></label>' +
            '<textarea class="textarea" id="ctContent" rows="12" style="min-height:300px" placeholder="请输入正文内容…">' + escHtml(content) + '</textarea></div>' +
            '<div class="form-group"><label class="form-label">告知内容 <span class="fs-12 text-muted fw-400">（显示于签名区上方；留空保存默认话术）</span></label>' +
            '<textarea class="textarea" id="ctNotice" rows="3" placeholder="' + escHtml(DEFAULT_NOTICE) + '">' + escHtml(notice) + '</textarea></div>' +
            '<div class="fs-12 text-muted">开具医生与就诊科室将自动记录（打印时显示，开具后固化）。</div>';
        _mask = Clinic.modal.open(html, {
            title: _docTitle || (_consentId > 0 ? '文书详情' : '新建文书'),
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
        if (_mask.querySelector('#ctContent') && !_consentId) _mask.querySelector('#ctContent').focus();
    }

    /** 进入编辑态：内容可编辑，脚部 取消/保存 */
    function _enterEditState() {
        ['#ctContent', '#ctNotice'].forEach(function (sel) {
            var el = _mask.querySelector(sel);
            if (el) { el.disabled = false; el.readOnly = false; }
        });
        _mask.querySelectorAll('.ct-sec-chk').forEach(function (c) { c.disabled = false; });
        var foot = _mask.querySelector('.modal-foot');
        foot.innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" onclick="Clinic.emr.consent.save()">保存</button>';
    }

    /** 进入查看态：内容只读（disabled 不可点击），脚部 取消/编辑/打印/删除(仅本人) */
    function _enterViewState() {
        ['#ctContent', '#ctNotice'].forEach(function (sel) {
            var el = _mask.querySelector(sel);
            if (el) { el.disabled = true; el.readOnly = true; }
        });
        _mask.querySelectorAll('.ct-sec-chk').forEach(function (c) { c.disabled = true; });
        var myUid = parseInt(document.body.getAttribute('data-uid') || '0', 10) || 0;
        var readonlyView = !!(window.Clinic && Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.__readonly_view);
        var foot = _mask.querySelector('.modal-foot');
        var delBtn = (!readonlyView && _docId > 0 && _docId === myUid)
            ? '<button type="button" class="btn btn-danger" onclick="Clinic.emr.consent.delFromView()">🗑️ 删除</button>'
            : '';
        var editBtn = !readonlyView
            ? '<button type="button" class="btn btn-primary" onclick="Clinic.emr.consent.enterEdit()">✏️ 编辑</button>'
            : '';
        foot.innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            delBtn +
            editBtn +
            '<button type="button" class="btn btn-success" onclick="Clinic.emr.consent.printCurrent()">🖨️ 打印</button>';
    }

    /** 删除当前查看的知情同意书（本人创建），成功后关闭模态框并刷新列表 */
    function delFromView() {
        if (isReadonlyView()) { Clinic.toast.warning('跨科室病历仅只读，当前科室不可删除知情同意书'); return; }
        var id = _consentId;
        Clinic.modal.confirm('确定删除该知情同意书？删除后不可恢复。', function () {
            Clinic.ajax('/api/consent', { action: 'delete', id: id }, {
                onSuccess: function (j) {
                    Clinic.toast.success(j.msg);
                    Clinic.modal.close();
                    renderList();
                },
            });
        }, { title: '删除知情同意书', okText: '确认删除' });
    }

    /** 查看态 → 编辑态 */
    function enterEdit() {
        if (isReadonlyView()) { Clinic.toast.warning('跨科室病历仅只读，当前科室不可编辑知情同意书'); return; }
        // 编辑确认弹框：重存后正文与病情介绍快照将随当前病历更新重新固化
        Clinic.modal.confirm(
            '编辑并保存后，正文内容与病情介绍快照将随当前病历更新重新固化，历史打印内容不再变化。确定编辑吗？',
            function () { _enterEditState(); },
            { title: '编辑' + (_docTitle || '文书'), okText: '开始编辑' }
        );
    }

    /** 保存知情同意/告知文书（新建/编辑），成功后自动弹出打印预览 */
    function save() {
        if (isReadonlyView()) { Clinic.toast.warning('跨科室病历仅只读，当前科室不可保存知情同意书'); return; }
        var content = (document.getElementById('ctContent') || {}).value || '';
        if (!content.trim()) { Clinic.toast.warning('请填写正文内容'); return; }
        var notice = (document.getElementById('ctNotice') || {}).value || '';
        var visitId = document.getElementById('visitId').value;
        var data = {
            action: 'save', visit_id: visitId,
            content: content.trim(), notice: notice.trim(),
            sections: JSON.stringify(sectionsFromDom()),
        };
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
                var readonlyView = !!(Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.__readonly_view);
                el.innerHTML = list.length ? list.map(function (c) {
                    var delBtn = (!readonlyView && c.doctor_id && c.doctor_id === myUid)
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
        if (isReadonlyView()) { Clinic.toast.warning('跨科室病历仅只读，当前科室不可删除知情同意书'); return; }
        Clinic.modal.confirm('确定删除该知情同意书？删除后不可恢复。', function () {
            Clinic.ajax('/api/consent', { action: 'delete', id: id }, {
                onSuccess: function (j) {
                    Clinic.toast.success(j.msg);
                    renderList();
                },
            });
        }, { title: '删除知情同意书', okText: '确认删除' });
    }

    /** 编辑已保存的文书（加载内容后打开编辑模态框；重存将随当前病历重新快照） */
    function edit(id) {
        Clinic.get('/api/consent?action=get&id=' + id, null, {
            onSuccess: function (j) {
                var c = j.data.consent;
                if (!c) return;
                openEditor({
                    title: c.title,
                    content: c.content,
                    notice: c.notice || '',
                    sections: c.sections || ['chief_complaint', 'preliminary_diagnosis'],
                    doctor_id: c.doctor_id,
                }, c.id, 0);
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
        delFromView: delFromView,
        renderList: renderList,
        print: print,
    };
})();
