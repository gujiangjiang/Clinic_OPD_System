<?php
/**
 * imaging/templates.php — 影像报告模板管理（影像科）
 * 说明：仅管理「影像报告模板」类型（影像科不可查看/编辑病历、知情同意书、护理文书模板）。
 * 规则与病历模板一致：personal 个人免审即用；dept/hospital 提交审核，
 * 审核期间仅本人可见可用，被驳回自动降级为个人模板。
 * 接口：/api/template（list/get/save/delete，type=imaging_report）
 */
Router::title('影像模板管理');
?>
<div class="page-head">
    <div><div class="page-title">📋 影像模板管理</div><div class="page-desc">影像报告模板（影像所见 + 影像诊断；个人免审，科室/全院需管理员审核，被驳回自动降级为个人）</div></div>
    <button class="btn btn-primary btn-sm" onclick="openITplForm(0)">＋ 新建影像模板</button>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="itplSearchKw" placeholder="🔍 搜索模板名称" style="width:220px" oninput="applyITplFilter()">
        <span class="flex gap-4" id="itplScopeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-tscope="" onclick="setITplScope(this,'')">全部</button>
            <button class="btn btn-sm btn-outline" data-tscope="personal" onclick="setITplScope(this,'personal')">个人</button>
            <button class="btn btn-sm btn-outline" data-tscope="hospital" onclick="setITplScope(this,'hospital')">全院</button>
            <button class="btn btn-sm btn-outline" data-tscope="dept" onclick="setITplScope(this,'dept')">科室</button>
        </span>
    </div>
</div>
<div class="card" id="itplList">
    <div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>
</div>

<style>
.tpl-form { display: flex; gap: 14px; }
.tpl-form .tpl-left { width: 300px; flex-shrink: 0; }
.tpl-form .tpl-right { flex: 1; min-width: 0; }
</style>

<script>
function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

var ITPL_DATA = [];
var ITPL_SCOPE = '';
var SCOPE_NAMES = { personal: '个人', dept: '科室', hospital: '全院' };
var STATUS_NAMES = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
var STATUS_CLS = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };

function loadITplList() {
    Clinic.get('/api/template?action=list&type=imaging_report', null, {
        onSuccess: function (j) {
            ITPL_DATA = j.data.list || [];
            renderITplList();
        },
    });
}

function setITplScope(btn, s) {
    ITPL_SCOPE = s;
    document.querySelectorAll('#itplScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-tscope') || '') === s ? 'btn-primary' : 'btn-outline');
    });
    renderITplList();
}

function applyITplFilter() {
    var q = (document.getElementById('itplSearchKw').value || '').trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#itplList tbody tr').forEach(function (tr) {
        var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('itplCount');
    if (cnt) cnt.textContent = q ? '搜索到 ' + n + ' 个模板' : '共 ' + n + ' 个模板';
}

function renderITplList() {
    var filtered = ITPL_DATA.length ? ITPL_DATA.filter(function (t) { return !ITPL_SCOPE || t.scope === ITPL_SCOPE; }) : [];
    var rows = filtered.length ? filtered.map(function (t) {
        var scopeBadge = '<span class="badge badge-primary">' + (SCOPE_NAMES[t.scope] || t.scope) + '</span>';
        if (t.status === 'pending_review') {
            scopeBadge += ' <span class="fs-12 text-muted">（待审核·暂仅个人可用）</span>';
        }
        var statusBadge = '<span class="badge ' + (STATUS_CLS[t.status] || 'badge-gray') + '">' + (STATUS_NAMES[t.status] || t.status) + '</span>';
        var deptText = t.dept_names && t.dept_names.length ? '（' + t.dept_names.join('、') + '）' : '';
        var actions = '';
        if (t.status === 'pending_review') {
            actions = '<span class="fs-12 text-muted">待审核·不可编辑</span>';
        } else {
            var canManage = t.creator_id === <?php echo (int)Auth::user()['id']; ?>;
            if (canManage) {
                actions += '<button class="btn btn-outline btn-sm" onclick="openITplForm(' + t.id + ')">编辑</button>';
                actions += '<button class="btn btn-outline btn-sm" onclick="delITpl(' + t.id + ')">删除</button>';
            } else {
                actions = '<span class="fs-12 text-muted">他人模板</span>';
            }
        }
        return '<tr>' +
            '<td class="fw-600">' + escHtml(t.title) + '</td>' +
            '<td>' + scopeBadge + ' ' + deptText + '</td>' +
            '<td>' + escHtml(t.creator_name) + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><div class="flex gap-4">' + actions + '</div></td></tr>';
    }).join('') : '<tr><td colspan="5"><div class="empty">暂无影像模板</div></td></tr>';
    document.getElementById('itplList').innerHTML =
        '<div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>模板名称</th><th>适用范围</th><th>创建人</th><th>审核状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div>' +
        '<div class="fs-12 text-muted mt-8" id="itplCount">共 ' + filtered.length + ' 个模板</div>';
    applyITplFilter();
}

function openITplForm(id) {
    var isEdit = id && id > 0;
    if (!isEdit) {
        var mask = Clinic.modal.open('<div id="itplFormContent"></div>', { title: '新建影像模板', size: 'modal-lg' });
        buildITplForm(mask, null);
    } else {
        var mask = Clinic.modal.load('/api/template?action=get&id=' + id, null, { title: '编辑影像模板', size: 'modal-lg' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
            if (e.detail && e.detail.template) buildITplForm(mask, e.detail.template);
        });
    }
}

function buildITplForm(mask, tpl) {
    var html =
        '<div class="tpl-form">' +
        '  <div class="tpl-left">' +
        '    <div class="form-group"><label class="form-label">模板名称 <span class="req">*</span></label>' +
        '      <input class="input" id="itfTitle" value="' + escHtml(tpl ? tpl.title : '') + '" placeholder="如：胸部CT报告模板"></div>' +
        '    <div class="form-group"><label class="form-label">适用范围</label>' +
        '      <select class="select" id="itfScope" onchange="onITplScopeChange()">' +
        '        <option value="personal"' + (tpl && tpl.scope === 'personal' ? ' selected' : '') + '>个人</option>' +
        '        <option value="dept"' + (tpl && tpl.scope === 'dept' ? ' selected' : '') + '>科室</option>' +
        '        <option value="hospital"' + (tpl && tpl.scope === 'hospital' ? ' selected' : '') + '>全院</option>' +
        '      </select>' +
        '      <div class="fs-12 text-muted mt-4">个人模板免审核；科室/全院模板需管理员审核，审核期间仅本人可用，被驳回自动降级为个人。</div></div>' +
        '    <div class="form-group" id="itfDeptWrap" style="display:none"><label class="form-label">选择科室（多选）</label>' +
        '      <div id="itfDeptTree"></div></div>' +
        '  </div>' +
        '  <div class="tpl-right">' +
        '    <div class="form-group"><label class="form-label">影像所见 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="itfFindings" rows="8" placeholder="请输入影像所见描述…">' + escHtml((tpl && tpl.content && tpl.content.findings) || '') + '</textarea></div>' +
        '    <div class="form-group"><label class="form-label">影像诊断 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="itfConclusion" rows="5" placeholder="请输入影像诊断（检查结论）…">' + escHtml((tpl && tpl.content && tpl.content.conclusion) || '') + '</textarea></div>' +
        '  </div>' +
        '</div>';
    mask.querySelector('.modal-body').innerHTML = html;
    var treeBox = document.getElementById('itfDeptTree');
    if (treeBox) Clinic.deptTree.build(treeBox, { selected: (tpl && tpl.dept_ids) || [] });
    onITplScopeChange();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" id="itplSaveBtn">保存</button>';
    document.getElementById('itplSaveBtn').addEventListener('click', function () { saveITplForm(tpl ? tpl.id : 0); });
}

function onITplScopeChange() {
    var sel = document.getElementById('itfScope');
    var wrap = document.getElementById('itfDeptWrap');
    if (sel && wrap) wrap.style.display = sel.value === 'dept' ? '' : 'none';
}

function saveITplForm(id) {
    var title = document.getElementById('itfTitle').value.trim();
    if (!title) { Clinic.toast.warning('请填写模板名称'); return; }
    var findings = (document.getElementById('itfFindings') || {}).value || '';
    var conclusion = (document.getElementById('itfConclusion') || {}).value || '';
    if (!findings.trim()) { Clinic.toast.warning('请填写影像所见'); return; }
    if (!conclusion.trim()) { Clinic.toast.warning('请填写影像诊断'); return; }
    var scope = document.getElementById('itfScope').value;
    if (scope === 'dept') {
        var checked = document.querySelectorAll('#itfDeptTree .deptChk:checked');
        if (!checked.length) { Clinic.toast.warning('请选择至少一个科室'); return; }
    }
    var deptIds = [];
    document.querySelectorAll('#itfDeptTree .deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
    Clinic.ajax('/api/template', {
        action: 'save', id: id || 0, title: title, type: 'imaging_report',
        scope: scope, content: JSON.stringify({ findings: findings.trim(), conclusion: conclusion.trim() }),
        dept_ids: deptIds.join(','),
    }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            Clinic.modal.close();
            loadITplList();
        },
    });
}

function delITpl(id) {
    Clinic.modal.confirm('确定删除该影像模板？', function () {
        Clinic.ajax('/api/template', { action: 'delete', id: id }, {
            onSuccess: function (j) { Clinic.toast.success(j.msg); loadITplList(); },
        });
    });
}

loadITplList();
</script>
