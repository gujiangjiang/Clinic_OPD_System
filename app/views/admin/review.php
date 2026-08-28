<?php
/**
 * admin/review.php — 审核中心
 * 说明：管理员审核功能：检验项目添加、检查项目添加、药品添加、
 * 处置项目添加、病历模板（全科/全院）、检验/检查报告撤回申请。
 */
Router::title('审核中心');
?>
<style>
/* 模板预览与编辑保持同一版式：左右分栏（与 templates.php 一致） */
.tpl-form { display: flex; gap: 14px; }
.tpl-form .tpl-left { width: 320px; flex-shrink: 0; }
.tpl-form .tpl-right { flex: 1; min-width: 0; }
</style>
<div class="page-head">
    <div><div class="page-title">✅ 审核中心</div><div class="page-desc">审核项目添加、模板与报告撤回申请</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="pending" onclick="switchTab('pending')">待审核</button>
    <button class="btn btn-outline btn-sm" data-tab="handled" onclick="switchTab('handled')">已处理</button>
    <select class="select" id="groupSelect" onchange="loadAudits(getCurrentTab())" style="width:auto;margin-left:8px">
        <option value="">平铺列表</option>
        <option value="user">按申请人分组</option>
        <option value="type">按类型分组</option>
    </select>
    <button class="btn btn-success btn-sm" id="auditAllBtn" onclick="doAuditAll()">✅ 一键全部通过</button>
</div>

<div class="card" id="auditList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(status) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === status ? 'btn-primary' : 'btn-outline');
    });
    loadAudits(status);
}

function getCurrentTab() {
    var b = document.querySelector('[data-tab].btn-primary');
    return b ? b.getAttribute('data-tab') : 'pending';
}

function loadAudits(status) {
    var group = document.getElementById('groupSelect').value;
    Clinic.get('/api/admin?action=audit_list&status=' + status + '&group=' + group, null, {
        onSuccess: function (json) {
            document.getElementById('auditList').innerHTML = json.data.html;
            // 一键全部通过按钮：仅【待审核】页签、平铺/按类型分组、且有可一键通过的常规事项时显示
            var cnt = json.data && json.data.pending_count ? json.data.pending_count : 0;
            document.getElementById('auditAllBtn').style.display = (status === 'pending' && group !== 'user' && cnt > 0) ? '' : 'none';
        },
    });
}

function doAudit(id, approve) {
    if (approve) {
        Clinic.modal.confirm('确认通过该申请？', function () {
            Clinic.ajax('/api/admin', { action: 'audit', id: id, approve: 1 }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    loadAudits('pending');
                    loadAudits('handled');
                },
            });
        }, { title: '审核通过' });
    } else {
        // 驳回：必须填写驳回理由（将通知提交者，便于其修改后重新提交）
        Clinic.modal.open(
            '<div class="form-group"><label class="form-label">驳回理由 <span class="req">*</span></label>' +
            '<textarea class="textarea" id="rejectNote" rows="3" placeholder="请填写驳回理由，提交者将在站内消息中收到，并点击回到添加页面修改后重新提交"></textarea></div>' +
            '<div class="fs-12 text-muted">提交者将收到驳回理由，并可通过消息跳回添加页面回填本次提交内容。</div>',
            {
                title: '驳回申请',
                size: 'modal-sm',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    {
                        text: '确认驳回', cls: 'btn-danger', autoClose: false,
                        onClick: function () {
                            var note = document.getElementById('rejectNote').value.trim();
                            if (!note) { Clinic.toast.warning('请填写驳回理由'); return; }
                            Clinic.ajax('/api/admin', { action: 'audit', id: id, approve: 0, note: note }, {
                                onSuccess: function (json) {
                                    Clinic.toast.success(json.msg);
                                    Clinic.modal.close();
                                    loadAudits('pending');
                                    loadAudits('handled');
                                },
                            });
                        },
                    },
                ],
            }
        );
    }
}

/* 一键全部通过（常规事项；密码重置与报告撤回不纳入） */
function doAuditAll() {
    Clinic.modal.confirm('确认一键通过全部待审核事项？（密码重置与报告撤回不包含在内）', function () {
        Clinic.ajax('/api/admin', { action: 'audit_all' }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadAudits('pending');
                loadAudits('handled');
            },
        });
    }, { title: '一键全部通过', okText: '全部通过' });
}

/* ==================== 审核预览（只读展示提交内容，复用原模态框） ==================== */
function previewAudit(btn) {
    var type = btn.getAttribute('data-type');
    var refId = parseInt(btn.getAttribute('data-ref'), 10) || 0;
    var auditId = parseInt(btn.getAttribute('data-id'), 10) || 0;
    var titleMap = {
        template: '预览 · 病历模板', item_lab: '预览 · 检验项目', item_exam: '预览 · 检查项目',
        item_drug: '预览 · 药品', item_disp: '预览 · 处置项目', drugsetting: '预览 · 药品设置',
    };
    var modalTitle = titleMap[type] || '预览';
    if (type === 'template') {
        // 模板预览：病历模板 → emrEditor 只读；知情同意书模板 → 名称+正文文本
        Clinic.get('/api/template?action=get&id=' + refId, null, {
            onSuccess: function (j) {
                var t = j.data && j.data.template;
                if (!t) { Clinic.toast.warning('模板数据不存在'); return; }
                var scopeNames = { personal: '个人', dept: '科室', hospital: '全院' };
                var isConsent = t.type === 'consent';
                var rightHtml = isConsent
                    ? '<div class="card-title"><span>📝 知情同意书模板（只读）</span></div>' +
                      '<div class="form-group"><label class="form-label">知情同意书名称（XX）</label>' +
                      '<input class="input" value="' + escHtml((t.content && t.content.name) || '') + '" readonly></div>' +
                      '<div class="form-group"><label class="form-label">知情同意内容</label>' +
                      '<textarea class="textarea" rows="14" readonly style="min-height:380px">' + escHtml((t.content && t.content.content) || '') + '</textarea></div>'
                    : '<div class="card-title"><span>📝 模板正文（只读）</span></div>' +
                      '<div class="emr-doc"><div class="doc-body" id="previewTemplateEditor" style="border:1px solid var(--border);border-radius:8px;padding:14px;min-height:380px"></div></div>';
                var html = '<div class="tpl-form">' +
                    '<div class="tpl-left">' +
                    '<div class="form-group"><label class="form-label">模板名称</label>' +
                    '<input class="input" value="' + escHtml(t.title) + '" readonly></div>' +
                    '<div class="form-group"><label class="form-label">适用范围</label>' +
                    '<input class="input" value="' + (scopeNames[t.scope] || t.scope) + '" readonly></div>' +
                    '</div>' +
                    '<div class="tpl-right">' + rightHtml + '</div></div>';
                var mask = Clinic.modal.open(html, { title: modalTitle, size: 'modal-xl' });
                if (!isConsent) {
                    var container = document.getElementById('previewTemplateEditor');
                    if (container && t.content) {
                        Clinic.emrEditor.render(container, t.content, { templateMode: true, readonly: true });
                    }
                }
                makeReadonly(mask);
            },
        });
    } else {
        // 检验/检查/药品/处置/药品设置：复用原表单接口，加载完成后统一只读化
        var url = '/api/admin';
        var params = {};
        if (type === 'item_lab') { params = { action: 'item_form', type: 'lab', id: refId }; }
        else if (type === 'item_exam') { params = { action: 'item_form', type: 'exam', id: refId }; }
        else if (type === 'item_drug') { params = { action: 'drug_form', id: refId }; }
        else if (type === 'item_disp') { params = { action: 'disposal_form', id: refId }; }
        else if (type === 'drugsetting') { params = { action: 'audit_preview', id: auditId }; }
        var mask = Clinic.modal.load(url, params, { title: modalTitle });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
            makeReadonly(mask);
        });
    }
}

/* 通用只读化：禁用模态框内全部交互元素，仅保留滚动能力 */
function makeReadonly(mask) {
    if (!mask) return;
    var body = mask.querySelector('.modal-body');
    if (!body) return;
    // 禁用表单控件
    body.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = true;
        el.setAttribute('readonly', '');
    });
    // 禁用按钮
    body.querySelectorAll('button, .btn').forEach(function (el) {
        el.disabled = true;
    });
    // 内容可编辑 → 不可编辑
    body.querySelectorAll('[contenteditable]').forEach(function (el) {
        el.setAttribute('contenteditable', 'false');
    });
    // 移除所有 onclick / onmousedown 内联事件
    body.querySelectorAll('[onclick], [onmousedown]').forEach(function (el) {
        el.removeAttribute('onclick');
        el.removeAttribute('onmousedown');
    });
    // 捕获阶段拦截 click，防止 checkbox/label/div 等默认交互；
    // 不拦截 mousedown/wheel，保证滚动条与滚轮滚动不受影响
    body.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); }, true);
    // 禁止复制/剪切/粘贴与右键（防数据外泄）：
    // - contextmenu（右键菜单，含"复制"等项）
    // - copy / cut / paste 事件
    // - Ctrl/Cmd + C/X/V/A 快捷键（全选后复制）
    // - user-select:none 禁止文本选中
    body.style.userSelect = 'none';
    body.style.webkitUserSelect = 'none';
    body.addEventListener('contextmenu', function (e) { e.preventDefault(); return false; }, true);
    body.addEventListener('copy', function (e) { e.preventDefault(); }, true);
    body.addEventListener('cut', function (e) { e.preventDefault(); }, true);
    body.addEventListener('paste', function (e) { e.preventDefault(); }, true);
    body.addEventListener('keydown', function (e) {
        var k = e.key || '';
        if ((e.ctrlKey || e.metaKey) && /^[cxva]$/i.test(k)) {
            e.preventDefault();
            return false;
        }
    }, true);
    // 视觉提示：模态框脚部隐藏 "保存" 按钮，改为只读提示
    var foot = mask.querySelector('.modal-foot');
    if (foot) {
        foot.innerHTML = '<span class="fs-12 text-muted">🔒 只读预览 — 内容不可编辑、复制，可滚动查看</span>';
    }
    // 遮罩点击也可关闭
    mask.addEventListener('click', function (e) {
        if (e.target === mask) Clinic.modal.close();
    });
}

/* 内联 HTML 转义（预览模板名称用） */
function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

switchTab('pending');
</script>
