<?php
/**
 * admin/review.php — 审核中心
 * 说明：管理员审核功能：检验项目添加、检查项目添加、药品添加、
 * 处置项目添加、病历模板（全科/全院）、检验/检查报告撤回申请。
 */
Router::title('审核中心');
?>
<div class="page-head">
    <div><div class="page-title">✅ 审核中心</div><div class="page-desc">审核项目添加、模板与报告撤回申请</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="pending" onclick="switchTab('pending')">待审核</button>
    <button class="btn btn-outline btn-sm" data-tab="handled" onclick="switchTab('handled')">已处理</button>
</div>

<div class="card" id="auditList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(status) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === status ? 'btn-primary' : 'btn-outline');
    });
    loadAudits(status);
}

function loadAudits(status) {
    Clinic.get('/api/admin?action=audit_list&status=' + status, null, {
        onSuccess: function (json) {
            document.getElementById('auditList').innerHTML = json.data.html;
        },
    });
}

function doAudit(id, approve) {
    var tip = approve ? '确认通过该申请？' : '确认驳回该申请？';
    Clinic.modal.confirm(tip, function () {
        Clinic.ajax('/api/admin', { action: 'audit', id: id, approve: approve }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadAudits('pending');
                loadAudits('handled');
            },
        });
    }, { title: approve ? '审核通过' : '审核驳回' });
}

switchTab('pending');
</script>
