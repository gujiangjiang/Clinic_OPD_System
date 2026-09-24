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
</style>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">✅ 审核中心</div><div class="page-desc">审核项目添加、模板与报告撤回申请</div></div>
    <!-- 一键全部通过固定在右上角（类似科室管理新增按钮），避免随页签切换显示/隐藏引起布局跳动 -->
    <div class="flex gap-8">
        <button class="btn btn-success btn-sm" id="auditAllBtn" onclick="doAuditAll()">✅ 一键全部通过</button>
    </div>
</div>

<div class="card list-filter" style="flex-shrink:0">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <!-- 日期范围组（靠左）：与其他页面日期筛选栏结构统一 -->
        <span class="flex gap-8" style="align-items:center">
            <input type="text" class="input input-date" id="auditFrom" readonly placeholder="开始日期" 
                onclick="Clinic.datePicker.open(this,{maxToday:false,peer:'auditTo',maxSpan:366})">
            <span class="text-muted">至</span>
            <input type="text" class="input input-date" id="auditTo" readonly placeholder="结束日期" 
                onclick="Clinic.datePicker.open(this,{maxToday:true,peer:'auditFrom',maxSpan:366})">
            <button class="btn btn-primary btn-sm" onclick="loadAudits(getCurrentTab())">查询</button>
            <button class="btn btn-outline btn-sm" onclick="resetAuditDates()">重置</button>
        </span>
        <!-- 页签 / 分组（靠右） -->
        <span class="flex gap-8" style="align-items:center;margin-left:auto">
            <button class="btn btn-primary btn-sm" data-tab="pending" onclick="switchTab('pending')">待审核</button>
            <button class="btn btn-outline btn-sm" data-tab="handled" onclick="switchTab('handled')">已处理</button>
            <select class="select" id="groupSelect" onchange="switchGroup()" style="width:auto">
                <option value="">平铺列表</option>
                <option value="user">按申请人分组</option>
                <option value="type">按类型分组</option>
            </select>
        </span>
    </div>
</div>

<div class="card list-card" id="auditList"><div class="empty"><div class="spinner"></div></div></div>
</div>

<script>
var AUDIT_STATE = { status: 'pending' };   // 平铺分页状态
var AUDIT_PAGED = null;                    // 平铺列表 pagedTable

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

function switchGroup() {
    loadAudits(getCurrentTab());
}

/** 读取日期范围（审核列表筛选，申请时间；跨度上限 1 年由前后端双重钳制） */
function auditDateParams() {
    return '&from=' + encodeURIComponent((document.getElementById('auditFrom') || {}).value || '') +
        '&to=' + encodeURIComponent((document.getElementById('auditTo') || {}).value || '');
}

/** 重置日期范围：恢复默认最近一周（开始=6 天前，结束=今天）并刷新列表 */
function resetAuditDates() {
    var r = Clinic.datePicker.lastRange(6);
    var f = document.getElementById('auditFrom');
    var t = document.getElementById('auditTo');
    if (f) f.value = r.from;
    if (t) t.value = r.to;
    loadAudits(getCurrentTab());
}

/** 平铺分页地址（滚动加载） */
function auditListUrl(p, size, st) {
    return '/api/admin?action=audit_list&status=' + st.status + '&group=&page=' + p + '&size=' + size + auditDateParams();
}

function initAuditPaged() {
    var box = document.getElementById('auditList');
    if (!box) return;
    if (AUDIT_PAGED) { AUDIT_PAGED.reset(); return; }
    box.innerHTML = '<div class="table-wrap"><table class="table" id="auditTable"><tbody></tbody></table></div>';
    AUDIT_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'auditTable',
        state: AUDIT_STATE,
        url: auditListUrl,
        onSuccess: function (json) {
            // 一键全部通过按钮：仅【待审核】页签、平铺、且有可一键通过的常规事项时显示
            var cnt = json.data && json.data.pending_count ? json.data.pending_count : 0;
            var b = document.getElementById('auditAllBtn');
            if (b) b.style.display = (AUDIT_STATE.status === 'pending' && cnt > 0) ? '' : 'none';
        },
    });
}

function loadAudits(status) {
    var group = document.getElementById('groupSelect').value;
    if (group === '') {
        // 平铺：分页无限滚动
        AUDIT_STATE.status = status;
        if (AUDIT_PAGED) AUDIT_PAGED.reset(); else initAuditPaged();
        return;
    }
    // 分组：全量加载（服务端分组渲染）
    if (AUDIT_PAGED) { AUDIT_PAGED.stop(); AUDIT_PAGED = null; }
    var box = document.getElementById('auditList');
    if (box) box.innerHTML = '<div class="empty"><div class="spinner"></div></div>';
    Clinic.get('/api/admin?action=audit_list&status=' + status + '&group=' + group + auditDateParams(), null, {
        onSuccess: function (json) {
            document.getElementById('auditList').innerHTML = json.data.html;
            // 一键全部通过按钮：分组视图不显示（按类型分组支持，按申请人分组不支持）
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

/* ==================== 审核预览（只读展示提交内容） ====================
 * 原则：预览一律复用「添加/编辑」原始模态框（同一份渲染代码，仅只读化）——
 *   · 检验/检查/药品/处置/药品设置：audit_preview 返回 forms.php 同源
 *     原始表单（快照或实时数据），modal.load 打开 + makeReadonly；
 *   · 模板/套餐：跳转管理页复用原始弹窗（previewTpl/previewPkg + readonly，
 *     实体被删除时按 audit_id 从审计快照渲染同一原始弹窗，杜绝 review 内
 *     复制弹窗代码）。 */

function previewAudit(btn) {
    var type = btn.getAttribute('data-type');
    var refId = parseInt(btn.getAttribute('data-ref'), 10) || 0;
    var auditId = parseInt(btn.getAttribute('data-id'), 10) || 0;
    var status = btn.getAttribute('data-status') || 'pending';
    var isHandled = status !== 'pending';
    var titleMap = {
        template: '预览 · 病历模板', nursing_template: '预览 · 护理记录模板', imaging_template: '预览 · 影像报告模板',
        item_lab: '预览 · 检验项目', item_exam: '预览 · 检查项目',
        item_drug: '预览 · 药品', item_disp: '预览 · 处置项目', drugsetting: '预览 · 药品设置',
    };
    var modalTitle = titleMap[type] || '预览';
    // 模板/套餐：SPA 全局局部刷新导航到管理页，管理页自动弹出【原始弹窗】只读预览
    //（audit 参数供实体被删除时按审计快照渲染同一原始弹窗），不新开窗口
    var tplTypeMap = { template: 'medical_record', nursing_template: 'nursing_record', imaging_template: 'imaging_report' };
    if (tplTypeMap[type]) {
        Clinic.nav.go('/admin/templates?preview=' + refId + '&type=' + tplTypeMap[type] + '&audit=' + auditId);
        return;
    }
    if (type === 'package') {
        Clinic.nav.go('/admin/packages?preview=' + refId + '&audit=' + auditId);
        return;
    }
    // 检验/检查/药品/处置/药品设置：
    // 已处理记录统一用【与添加/编辑完全相同的 modal.load】打开 audit_preview
    //（audit_preview 返回 forms.php 同源原始表单：快照数据回填或实时数据，
    //  弹窗尺寸/布局与编辑弹窗完全一致），加载后 makeReadonly 只读化
    var formParams = {
        item_lab: { action: 'item_form', type: 'lab', id: refId },
        item_exam: { action: 'item_form', type: 'exam', id: refId },
        item_drug: { action: 'drug_form', id: refId },
        item_disp: { action: 'disposal_form', id: refId },
        drugsetting: { action: 'audit_preview', id: auditId },
    };
    var openForm = function () {
        var mask = Clinic.modal.load('/api/admin', formParams[type] || { action: 'audit_preview', id: auditId }, { title: modalTitle });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
            makeReadonly(mask);
        });
    };
    if (type === 'drugsetting' || !isHandled) { openForm(); return; }
    var mask = Clinic.modal.load('/api/admin', { action: 'audit_preview', id: auditId }, { title: modalTitle });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        makeReadonly(mask);
    });
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

/* 进入页面：填充默认最近一周日期并加载列表（resetAuditDates 已含刷新） */
resetAuditDates();
</script>
