<?php
/**
 * templates.php — 病历模板管理（管理员 / 医生共用）
 * Tab：病历模板 / 知情同意书模板 / 病历嘱托模板（预留）
 * 管理员：全部可见，新建仅 hospital/dept 免审，审核操作
 * 医生：个人+已发布全院/科室模板，新建 personal 免审，dept/hospital 进审核
 * 知情同意书模板内容：{ name: XX, content: 正文 }
 */
Router::title('模板管理');
$u = Auth::user();
$isAdmin = $u['role'] === 'admin';
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">📋 模板管理</div><div class="page-desc">病历模板 / 知情同意书 / 病历嘱托</div></div>
    <div class="flex gap-8">
        <select class="select" id="tplTypeSel" style="width:170px;height:34px;font-size:13px" onchange="setTplTypeSel()">
            <option value="medical_record">病历模板</option>
            <option value="consent">知情同意书模板</option>
            <?php if ($isAdmin) { ?>
            <option value="nursing_record">护理记录模板</option>
            <option value="imaging_report">影像报告模板</option>
            <?php } ?>
            <option value="order_note">病历嘱托模板</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="openTplForm(0)">＋ 新建模板</button>
    </div>
</div>
<div class="card list-filter">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="tplSearchKw" placeholder="🔍 搜索模板名称" style="width:220px">
        <span class="fs-13 text-muted" id="tplCount"></span>
        <span class="flex gap-4" id="tplScopeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-tscope="" onclick="setTplScope(this,'')">全部</button>
            <button class="btn btn-sm btn-outline" data-tscope="personal" onclick="setTplScope(this,'personal')">个人</button>
            <button class="btn btn-sm btn-outline" data-tscope="hospital" onclick="setTplScope(this,'hospital')">全院</button>
            <button class="btn btn-sm btn-outline" data-tscope="dept" onclick="setTplScope(this,'dept')">科室</button>
        </span>
    </div>
</div>
<div class="card list-card" id="tplList">
    <div class="empty"><div class="spinner"></div></div>
</div>
</div>

<style>
</style>

<script>
var TPL_TYPE = 'medical_record';
var TPL_STATE = { kw: '', cat: '' };   // 分页状态：kw=搜索词 / cat=范围筛选（scope）
var TPL_PAGED = null;                  // 模板列表 infiniteList

/* HTML 转义（内联视图用，全局供模板列表渲染等） */

function setTplTypeSel() {
    TPL_TYPE = document.getElementById('tplTypeSel').value;
    TPL_STATE.kw = '';
    TPL_STATE.cat = '';
    var inp = document.getElementById('tplSearchKw');
    if (inp) inp.value = '';
    if (TPL_PAGED) TPL_PAGED.reset(); else initTplPaged();
}

function setTplScope(btn, s) {
    TPL_STATE.cat = s;
    document.querySelectorAll('#tplScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-tscope') || '') === s ? 'btn-primary' : 'btn-outline');
    });
    if (TPL_PAGED) TPL_PAGED.reset();
}

var SCOPE_NAMES = { personal: '个人', dept: '科室', hospital: '全院' };
var STATUS_NAMES = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
var STATUS_CLS = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };

/** 模板分页地址（服务端已支持 page/size/kw/scope 过滤与 thead 返回） */
function tplListUrl(p, size, st) {
    return '/api/template?action=list&type=' + TPL_TYPE + '&page=' + p + '&size=' + size +
        '&kw=' + encodeURIComponent(st.kw) + '&scope=' + encodeURIComponent(st.cat);
}

/** 单行模板 HTML（对象 → 行），pagedTable cfg.render 使用 */
function tplRowHtml(t) {
    // 待审核模板：适用范围展示目标范围（全院/科室），但标注当前仅个人可用；
    // 审核通过后自动发布为对应范围
    var scopeBadge = '<span class="badge badge-primary">' + (SCOPE_NAMES[t.scope] || t.scope) + '</span>';
    if (t.status === 'pending_review') {
        scopeBadge += ' <span class="fs-12 text-muted">（待审核·暂仅个人可用）</span>';
    }
    var statusBadge = '<span class="badge ' + (STATUS_CLS[t.status] || 'badge-gray') + '">' + (STATUS_NAMES[t.status] || t.status) + '</span>';
    var deptText = t.dept_names && t.dept_names.length ? '（' + t.dept_names.join('、') + '）' : '';
    var actions = '';
    if (t.is_system) {
        actions = '<span class="fs-12 text-muted">内置模板</span>';
    } else if (t.status === 'pending_review') {
        // 待审核锁定：不可编辑/删除（审核通过/驳回后恢复），管理员去审核中心处理
        if (<?php echo $isAdmin ? 'true' : 'false'; ?>) {
            actions = '<a class="btn btn-outline btn-sm" href="/admin/review">去审核中心审核</a>';
        } else {
            actions = '<span class="fs-12 text-muted">待审核·不可编辑</span>';
        }
    } else {
        // 仅本人创建或管理员可编辑/删除；他人模板复用编辑模态框只读预览
        var canManage = <?php echo $isAdmin ? 'true' : 'false'; ?> || t.creator_id === <?php echo (int)$u['id']; ?>;
        if (canManage) {
            actions += '<button class="btn btn-outline btn-sm" onclick="openTplForm(' + t.id + ')">编辑</button>';
            actions += '<button class="btn btn-outline btn-sm" onclick="delTpl(' + t.id + ')">删除</button>';
        } else {
            actions = '<button class="btn btn-outline btn-sm" onclick="previewTpl(' + t.id + ')">预览</button>';
        }
    }
    return '<tr>' +
        '<td class="fw-600">' + escHtml(t.title) + '</td>' +
        '<td>' + scopeBadge + ' ' + deptText + '</td>' +
        '<td>' + escHtml(t.creator_name) + '</td>' +
        '<td>' + statusBadge + '</td>' +
        '<td><div class="flex gap-4">' + actions + '</div></td></tr>';
}

/** 初始化/重置模板分页列表 */
function initTplPaged() {
    var box = document.getElementById('tplList');
    if (!box) return;
    if (TPL_PAGED) { TPL_PAGED.reset(); return; }
    box.innerHTML = '<div class="table-wrap"><table class="table" id="tplTable"><tbody></tbody></table></div>';
    TPL_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'tplTable',
        state: TPL_STATE,
        url: tplListUrl,
        countEl: 'tplCount',
        kwEl: 'tplSearchKw',
        render: function (list, isFirst, data) {
            return list.map(tplRowHtml).join('');
        },
    });
}

/* ==================== 新建/编辑 ==================== */
function openTplForm(id) {
    var isEdit = id && id > 0;
    var title = isEdit ? '编辑模板' : '新建模板';
    if (!isEdit) {
        // 新建：直接打开空模态框（不走 API，避免 get id=0 报错）
        var html = '<div id="tplFormContent"></div>';
        var mask = Clinic.modal.open(html, { title: title, size: 'modal-xl' });
        buildTplForm(mask, null);
    } else {
        var mask = Clinic.modal.load('/api/template?action=get&id=' + id, null, { title: title, size: 'modal-xl' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
            if (e.detail && e.detail.template) {
                buildTplForm(mask, e.detail.template);
            }
        });
    }
}

/**
 * 模板只读预览：复用「添加/编辑模板」同一个模态框（buildTplForm），
 * 打开后整框强制只读（emrEditor readonly + modalReadonly 禁用所有控件/拦截交互）。
 * 供「他人模板」预览与管理员审核统一调用。
 * @param {number} id      模板 ID
 * @param {string} [type]  模板类型（medical_record/consent/...），可选（用于从审核页跳转时切 Tab）
 * @param {number} [auditId] 审核记录 ID：实体已被删除时按审计快照渲染同一原始弹窗
 */
function previewTpl(id, type, auditId) {
    if (type) {
        TPL_TYPE = type;
        var tsel = document.getElementById('tplTypeSel');
        if (tsel) tsel.value = type;
    }
    // 管理员可预览任意模板（含待审核）；普通用户按可见性（for_apply）过滤
    var role = document.body.getAttribute('data-role');
    var url = role === 'admin'
        ? '/api/template?action=get&id=' + id
        : '/api/template?action=get&id=' + id + '&for_apply=1';
    Clinic.get(url, null, {
        onSuccess: function (j) {
            if (j.data && j.data.template) {
                var mask = Clinic.modal.open('<div id="tplFormContent"></div>', { title: '预览模板', size: 'modal-xl' });
                buildTplForm(mask, j.data.template, true);
                if (Clinic.modalReadonly) Clinic.modalReadonly(mask);
                return;
            }
            // 实体已被删除：按审计快照渲染同一原始弹窗（审核中心「预览」带 audit 参数）
            if (auditId) {
                Clinic.get('/api/admin?action=audit_preview&id=' + auditId, null, {
                    onSuccess: function (j2) {
                        if (j2.data && j2.data.template) {
                            var m2 = Clinic.modal.open('<div id="tplFormContent"></div>', { title: '预览模板（已删除，按提交快照）', size: 'modal-xl' });
                            buildTplForm(m2, j2.data.template, true);
                            if (Clinic.modalReadonly) Clinic.modalReadonly(m2);
                            return;
                        }
                        Clinic.toast.warning('该模板已被删除，且无快照可预览');
                    },
                    onError: function () { Clinic.toast.warning('该模板已被删除，且无快照可预览'); },
                });
                return;
            }
            Clinic.toast.warning('该模板已被删除或不可见，无法预览');
        },
        onError: function () { Clinic.toast.warning('该模板已被删除或不可见，无法预览'); },
    });
}

function buildTplForm(mask, tpl, readonly) {
    var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var isConsent = TPL_TYPE === 'consent';
    var isNurse = TPL_TYPE === 'nursing_record';
    var isImg = TPL_TYPE === 'imaging_report';
    var isAdvice = TPL_TYPE === 'order_note';
    // 知情同意/告知文书模板：标题（模板名称即文书抬头，完全自定义）+ 适用范围 +
    //   正文（textarea）+ 告知内容（占位符=默认话术，空则保存默认）+ 病历内容显示节（复选框）
    // 护理记录模板：名称 + 适用范围 + 正文（textarea）
    // 病历嘱托模板：名称 + 适用范围 + 嘱托正文（textarea）
    // 影像报告模板：名称 + 适用范围 + 影像所见 + 影像诊断（textarea）
    // 病历模板：名称 + 适用范围 + 结构化 EMR 编辑器
    var CONSENT_SECTIONS = [
        ['chief_complaint', '主诉'], ['present_illness', '现病史'], ['past_history', '既往史'],
        ['allergy_history', '过敏史'], ['main_symptoms', '主要症状'], ['vitals', '生命体征'],
        ['consciousness', '意识状态'], ['physical_exam', '体格检查'], ['preliminary_diagnosis', '初步诊断'],
    ];
    function consentSectionsHtml(sel) {
        return CONSENT_SECTIONS.map(function (s) {
            var on = sel.indexOf(s[0]) !== -1;
            return '<label class="fs-13" style="display:inline-flex;align-items:center;gap:4px;margin:2px 10px 2px 0;cursor:pointer">' +
                '<input type="checkbox" class="consent-sec-chk" value="' + s[0] + '"' + (on ? ' checked' : '') + '>' + s[1] + '</label>';
        }).join('');
    }
    var contentField = (isConsent || isNurse || isImg || isAdvice)
        ? (isConsent
            ? '<div class="form-group"><label class="form-label">病情介绍显示内容 <span class="fs-12 text-muted fw-400">（开具时按所选节固化病历快照，空内容自动不显示）</span></label>' +
              '<div id="tfCSections">' + consentSectionsHtml((tpl && tpl.content && tpl.content.sections) || ['chief_complaint', 'preliminary_diagnosis']) + '</div></div>' +
              '<div class="form-group"><label class="form-label">正文内容 <span class="req">*</span></label>' +
              '<textarea class="textarea" id="tfCContent" rows="12" style="min-height:300px" placeholder="请输入正文内容…（标题即左侧「模板名称」，如：门诊告知书 / 病重通知书 / 手术知情同意书）">' + escHtml((tpl && tpl.content && tpl.content.content) || '') + '</textarea></div>' +
              '<div class="form-group"><label class="form-label">告知内容 <span class="fs-12 text-muted fw-400">（显示于签名区上方；留空保存默认话术）</span></label>' +
              '<textarea class="textarea" id="tfCNotice" rows="3" placeholder="患者/委托人已知晓上述病情介绍与知情同意内容，医生已向我详细解释，我已完全理解，愿意承担可能出现风险及并发症，并遵从医嘱，配合治疗。">' + escHtml((tpl && tpl.content && tpl.content.notice) || '') + '</textarea></div>'
          : (isImg
              ? '<div class="form-group"><label class="form-label">影像所见 <span class="req">*</span></label>' +
                '<textarea class="textarea" id="tfFindings" rows="8" placeholder="请输入影像所见描述…">' + escHtml((tpl && tpl.content && tpl.content.findings) || '') + '</textarea></div>' +
                '<div class="form-group"><label class="form-label">影像诊断 <span class="req">*</span></label>' +
                '<textarea class="textarea" id="tfConclusion" rows="5" placeholder="请输入影像诊断（检查结论）…">' + escHtml((tpl && tpl.content && tpl.content.conclusion) || '') + '</textarea></div>'
              : '<div class="form-group"><label class="form-label">' + (isNurse ? '护理记录内容' : '嘱托正文') + ' <span class="req">*</span></label>' +
                '<textarea class="textarea" id="tfCContent" rows="14" style="min-height:380px" placeholder="' + (isNurse ? '请输入护理记录模板正文内容…' : '请输入嘱托模板正文内容…') + '">' + escHtml((tpl && tpl.content && tpl.content.content) || '') + '</textarea></div>'))
        : '<div class="card-title"><span>📝 模板正文</span></div>' +
          '<div class="emr-doc"><div class="doc-body" id="templateEditor" style="border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;min-height:380px"></div></div>';
    var html =
        '<div class="tpl-form">' +
        '  <div class="tpl-left">' +
        '    <div class="form-group"><label class="form-label">模板名称 <span class="req">*</span></label>' +
        '      <input class="input" id="tfTitle" value="' + escHtml(tpl ? tpl.title : '') + '" placeholder="如：骨科门诊病历模板">' +
        (isConsent
            ? '<div class="fs-12 text-muted mt-4">⚠️ 该名称将作为文书抬头完全自定义（如：门诊告知书 / 病重通知书 / 手术知情同意书），开具后按原文显示</div>'
            : '') +
        '    </div>' +
        '    <div class="form-group"><label class="form-label">适用范围</label>' +
        '      <select class="select" id="tfScope" onchange="onTplScopeChange()">' +
        '        <option value="personal"' + (tpl && tpl.scope === 'personal' ? ' selected' : '') + (isAdmin ? ' disabled' : '') + '>个人</option>' +
        '        <option value="dept"' + (tpl && tpl.scope === 'dept' ? ' selected' : '') + '>科室</option>' +
        '        <option value="hospital"' + (tpl && tpl.scope === 'hospital' ? ' selected' : '') + '>全院</option>' +
        '      </select></div>' +
        '    <div class="form-group" id="tfDeptWrap" style="display:none"><label class="form-label">选择科室（多选）</label>' +
        '      <div id="tfDeptTree"></div></div>' +
        '  </div>' +
        '  <div class="tpl-right">' + contentField + '</div>' +
        '</div>';
    mask.querySelector('.modal-body').innerHTML = html;
    // 模板创建/编辑模态框：模板正文等编辑区禁用浏览器原生右键菜单——
    // 输入框（input/textarea/富文本字段）右击调用自定义菜单（复制/剪切/粘贴/清空），
    // 其余区域一律屏蔽原生菜单；本模态框即模板创建场景，嘱托字段隐藏「模板」项。
    var tplFormEl = mask.querySelector('.tpl-form');
    if (tplFormEl) {
        tplFormEl.addEventListener('contextmenu', function (ev) {
            var t = ev.target;
            // 仅文本输入类（input[type=text]/无 type/search、textarea、富文本字段）调用
            // 自定义菜单；勾选框/单选/隐藏输入等其余区域一律屏蔽原生菜单
            var textEntry = t && t.closest &&
                t.closest('input[type="text"], input[type="search"], input:not([type]), textarea, [contenteditable="true"]');
            if (textEntry) {
                if (window.Clinic && Clinic.emrMenu && Clinic.emrMenu.show) {
                    Clinic.emrMenu.show(ev, { hideTemplate: true });
                }
                return;
            }
            ev.preventDefault();
        });
    }
    // 渲染科室三级树（复用 depttree 组件）
    var treeBox = document.getElementById('tfDeptTree');
    if (treeBox) {
        Clinic.deptTree.build(treeBox, { selected: (tpl && tpl.dept_ids) || [] });
    }
    // 病历模板：渲染结构化编辑器（模板模式；readonly 预览传 readonly 禁用编辑）
    if (!isConsent && !isNurse && !isImg && !isAdvice) {
        var container = document.getElementById('templateEditor');
        if (container) {
            try {
                Clinic.emrEditor.render(container, (tpl && tpl.content) || {}, {
                    templateMode: true,
                    readonly: !!readonly,
                    onChange: readonly ? null : function () { window.__tplDirty = true; },
                });
            } catch (e) { console.error('模板编辑器渲染失败', e); }
        }
    }
    window.__tplDirty = false;
    onTplScopeChange();
    // 只读预览：底栏仅提示，不提供保存；编辑模式提供取消/保存
    mask.querySelector('.modal-foot').innerHTML = readonly
        ? '<span class="fs-12 text-muted">🔒 只读预览 — 模板内容不可编辑、不可保存</span>'
        : '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
          '<button type="button" class="btn btn-primary" id="tplSaveBtn">保存</button>';
    if (!readonly) {
        document.getElementById('tplSaveBtn').addEventListener('click', function () { saveTplForm(tpl ? tpl.id : 0, tpl ? tpl.status : ''); });
    }
}

function onTplScopeChange() {
    var sel = document.getElementById('tfScope');
    var wrap = document.getElementById('tfDeptWrap');
    if (sel && wrap) wrap.style.display = sel.value === 'dept' ? '' : 'none';
}

function saveTplForm(id, origStatus) {
    var title = document.getElementById('tfTitle').value.trim();
    if (!title) { Clinic.toast.warning('请填写模板名称'); return; }
    var scope = document.getElementById('tfScope').value;
    if (scope === 'dept') {
        var checked = document.querySelectorAll('#tfDeptTree .deptChk:checked');
        if (!checked.length) { Clinic.toast.warning('请选择至少一个科室'); return; }
    }
    var isConsent = TPL_TYPE === 'consent';
    var isNurse = TPL_TYPE === 'nursing_record';
    var isImg = TPL_TYPE === 'imaging_report';
    var isAdvice = TPL_TYPE === 'order_note';
    var content = {};
    if (isConsent) {
        var cContent = (document.getElementById('tfCContent') || {}).value || '';
        if (!cContent.trim()) { Clinic.toast.warning('请填写正文内容'); return; }
        // 告知内容：空则后端回落默认话术；勾选节白名单由后端过滤
        var cNotice = (document.getElementById('tfCNotice') || {}).value || '';
        var cSections = [];
        document.querySelectorAll('#tfCSections .consent-sec-chk:checked').forEach(function (c) { cSections.push(c.value); });
        content = { content: cContent.trim(), notice: cNotice.trim(), sections: cSections };
    } else if (isNurse) {
        var nContent = (document.getElementById('tfCContent') || {}).value || '';
        if (!nContent.trim()) { Clinic.toast.warning('请填写护理记录内容'); return; }
        content = { content: nContent.trim() };
    } else if (isAdvice) {
        var aContent = (document.getElementById('tfCContent') || {}).value || '';
        if (!aContent.trim()) { Clinic.toast.warning('请填写嘱托正文'); return; }
        content = { content: aContent.trim() };
    } else if (isImg) {
        var findings = (document.getElementById('tfFindings') || {}).value || '';
        var conclusion = (document.getElementById('tfConclusion') || {}).value || '';
        if (!findings.trim()) { Clinic.toast.warning('请填写影像所见'); return; }
        if (!conclusion.trim()) { Clinic.toast.warning('请填写影像诊断'); return; }
        content = { findings: findings.trim(), conclusion: conclusion.trim() };
    } else {
        try { content = Clinic.emrEditor.collect(); } catch (e) { content = {}; }
        // 主诉/现病史必填（模板正文底线：模板必须先填好主诉与现病史）
        var ccSymptom = (content.chief_complaint && (content.chief_complaint.symptom || '').trim()) || '';
        var piContent = (content.history_present && (content.history_present.content || '').trim()) || '';
        if (!ccSymptom) { Clinic.toast.warning('主诉为必填项，请填写主要症状'); return; }
        if (!piContent) { Clinic.toast.warning('现病史为必填项，请填写具体内容'); return; }
    }
    var deptIds = [];
    document.querySelectorAll('#tfDeptTree .deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
    Clinic.ajax('/api/template', {
        action: 'save', id: id || 0, title: title, type: TPL_TYPE,
        scope: scope, content: JSON.stringify(content), dept_ids: deptIds.join(','),
    }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            Clinic.modal.close();
            initTplPaged();
        },
    });
}

/* ==================== 删除 ==================== */
function delTpl(id) {
    Clinic.modal.confirm('确定删除该模板？', function () {
        Clinic.ajax('/api/template', { action: 'delete', id: id }, {
            onSuccess: function (j) { Clinic.toast.success(j.msg); initTplPaged(); },
        });
    });
}

initTplPaged();

/* 审核中心跳转预览：?preview=ID&type=xxx&audit=审核ID 自动打开模板只读预览（复用编辑模态框） */
(function () {
    var m = (location.search.match(/[?&]preview=(\d+)/) || [])[1];
    if (m) {
        var pt = (location.search.match(/[?&]type=([^&]+)/) || [])[1];
        var a = (location.search.match(/[?&]audit=(\d+)/) || [])[1];
        setTimeout(function () { previewTpl(parseInt(m, 10), pt ? decodeURIComponent(pt) : undefined, a ? parseInt(a, 10) : 0); }, 300);
    }
})();
</script>