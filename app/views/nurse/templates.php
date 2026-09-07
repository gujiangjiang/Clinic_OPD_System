<?php
/**
 * nurse/templates.php — 护理记录模板管理（护士站）
 * 说明：仅管理「护理记录模板」类型（护士不可查看/编辑病历模板与知情同意书模板）。
 * 规则与病历模板一致：personal 个人免审即用；dept/hospital 提交审核，
 * 审核期间仅本人可见可用，被驳回自动降级为个人模板。
 * 接口：/api/template（list/get/save/delete，type=nursing_record）
 */
Router::title('护理模板管理');
?>
<div class="page-head">
    <div><div class="page-title">📋 护理模板管理</div><div class="page-desc">护理记录模板（个人免审；科室/全院需管理员审核，审核通过后生效，被驳回自动降级为个人）</div></div>
    <button class="btn btn-primary btn-sm" onclick="openNTplForm(0)">＋ 新建护理模板</button>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="ntplSearchKw" placeholder="🔍 搜索模板名称" style="width:220px" oninput="applyNTplFilter()">
        <span class="flex gap-4" id="ntplScopeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-tscope="" onclick="setNTplScope(this,'')">全部</button>
            <button class="btn btn-sm btn-outline" data-tscope="personal" onclick="setNTplScope(this,'personal')">个人</button>
            <button class="btn btn-sm btn-outline" data-tscope="hospital" onclick="setNTplScope(this,'hospital')">全院</button>
            <button class="btn btn-sm btn-outline" data-tscope="dept" onclick="setNTplScope(this,'dept')">科室</button>
        </span>
    </div>
</div>
<div class="card" id="ntplList">
    <div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>
</div>

<style>
.tpl-form { display: flex; gap: 14px; }
.tpl-form .tpl-left { width: 300px; flex-shrink: 0; }
.tpl-form .tpl-right { flex: 1; min-width: 0; }
</style>

<script>
function escHtml(s) { return Clinic.escHtml(s); }

var NTPL_DATA = [];
var NTPL_SCOPE = '';
var SCOPE_NAMES = { personal: '个人', dept: '科室', hospital: '全院' };
var STATUS_NAMES = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
var STATUS_CLS = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };

function loadNTplList() {
    Clinic.get('/api/template?action=list&type=nursing_record', null, {
        onSuccess: function (j) {
            NTPL_DATA = j.data.list || [];
            renderNTplList();
        },
    });
}

function setNTplScope(btn, s) {
    NTPL_SCOPE = s;
    document.querySelectorAll('#ntplScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-tscope') || '') === s ? 'btn-primary' : 'btn-outline');
    });
    renderNTplList();
}

function applyNTplFilter() {
    var q = (document.getElementById('ntplSearchKw').value || '').trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#ntplList tbody tr').forEach(function (tr) {
        var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('ntplCount');
    if (cnt) cnt.textContent = q ? '搜索到 ' + n + ' 个模板' : '共 ' + n + ' 个模板';
}

function renderNTplList() {
    var filtered = NTPL_DATA.length ? NTPL_DATA.filter(function (t) { return !NTPL_SCOPE || t.scope === NTPL_SCOPE; }) : [];
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
                actions += '<button class="btn btn-outline btn-sm" onclick="openNTplForm(' + t.id + ')">编辑</button>';
                actions += '<button class="btn btn-outline btn-sm" onclick="delNTpl(' + t.id + ')">删除</button>';
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
    }).join('') : '<tr><td colspan="5"><div class="empty">暂无护理模板</div></td></tr>';
    document.getElementById('ntplList').innerHTML =
        '<div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>模板名称</th><th>适用范围</th><th>创建人</th><th>审核状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div>' +
        '<div class="fs-12 text-muted mt-8" id="ntplCount">共 ' + filtered.length + ' 个模板</div>';
    applyNTplFilter();
}

function openNTplForm(id) {
    var isEdit = id && id > 0;
    if (!isEdit) {
        var mask = Clinic.modal.open('<div id="ntplFormContent"></div>', { title: '新建护理模板', size: 'modal-lg' });
        buildNTplForm(mask, null);
    } else {
        var mask = Clinic.modal.load('/api/template?action=get&id=' + id, null, { title: '编辑护理模板', size: 'modal-lg' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
            if (e.detail && e.detail.template) buildNTplForm(mask, e.detail.template);
        });
    }
}

function buildNTplForm(mask, tpl) {
    var html =
        '<div class="tpl-form">' +
        '  <div class="tpl-left">' +
        '    <div class="form-group"><label class="form-label">模板名称 <span class="req">*</span></label>' +
        '      <input class="input" id="ntfTitle" value="' + escHtml(tpl ? tpl.title : '') + '" placeholder="如：入院评估记录模板"></div>' +
        '    <div class="form-group"><label class="form-label">适用范围</label>' +
        '      <select class="select" id="ntfScope" onchange="onNTplScopeChange()">' +
        '        <option value="personal"' + (tpl && tpl.scope === 'personal' ? ' selected' : '') + '>个人</option>' +
        '        <option value="dept"' + (tpl && tpl.scope === 'dept' ? ' selected' : '') + '>科室</option>' +
        '        <option value="hospital"' + (tpl && tpl.scope === 'hospital' ? ' selected' : '') + '>全院</option>' +
        '      </select>' +
        '      <div class="fs-12 text-muted mt-4">个人模板免审核；科室/全院模板需管理员审核，审核期间仅本人可用，被驳回自动降级为个人。</div></div>' +
        '    <div class="form-group" id="ntfDeptWrap" style="display:none"><label class="form-label">选择科室（多选）</label>' +
        '      <div id="ntfDeptTree"></div></div>' +
        '  </div>' +
        '  <div class="tpl-right">' +
        '    <div class="form-group"><label class="form-label">护理记录内容 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="ntfContent" rows="14" style="min-height:380px" placeholder="请输入护理记录模板正文内容…">' + escHtml((tpl && tpl.content && tpl.content.content) || '') + '</textarea></div>' +
        '  </div>' +
        '</div>';
    mask.querySelector('.modal-body').innerHTML = html;
    var treeBox = document.getElementById('ntfDeptTree');
    if (treeBox) Clinic.deptTree.build(treeBox, { selected: (tpl && tpl.dept_ids) || [] });
    onNTplScopeChange();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" id="ntplSaveBtn">保存</button>';
    document.getElementById('ntplSaveBtn').addEventListener('click', function () { saveNTplForm(tpl ? tpl.id : 0); });
}

function onNTplScopeChange() {
    var sel = document.getElementById('ntfScope');
    var wrap = document.getElementById('ntfDeptWrap');
    if (sel && wrap) wrap.style.display = sel.value === 'dept' ? '' : 'none';
}

function saveNTplForm(id) {
    var title = document.getElementById('ntfTitle').value.trim();
    if (!title) { Clinic.toast.warning('请填写模板名称'); return; }
    var content = (document.getElementById('ntfContent') || {}).value || '';
    if (!content.trim()) { Clinic.toast.warning('请填写护理记录内容'); return; }
    var scope = document.getElementById('ntfScope').value;
    if (scope === 'dept') {
        var checked = document.querySelectorAll('#ntfDeptTree .deptChk:checked');
        if (!checked.length) { Clinic.toast.warning('请选择至少一个科室'); return; }
    }
    var deptIds = [];
    document.querySelectorAll('#ntfDeptTree .deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
    Clinic.ajax('/api/template', {
        action: 'save', id: id || 0, title: title, type: 'nursing_record',
        scope: scope, content: JSON.stringify({ content: content.trim() }), dept_ids: deptIds.join(','),
    }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            Clinic.modal.close();
            loadNTplList();
        },
    });
}

function delNTpl(id) {
    Clinic.modal.confirm('确定删除该护理模板？', function () {
        Clinic.ajax('/api/template', { action: 'delete', id: id }, {
            onSuccess: function (j) { Clinic.toast.success(j.msg); loadNTplList(); },
        });
    });
}

loadNTplList();
</script>
