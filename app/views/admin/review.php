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

function loadAudits(status) {
    Clinic.get('/api/admin?action=audit_list&status=' + status, null, {
        onSuccess: function (json) {
            document.getElementById('auditList').innerHTML = json.data.html;
            // 一键全部通过按钮：仅【待审核】页签且有可一键通过的常规事项时显示
            var cnt = json.data && json.data.pending_count ? json.data.pending_count : 0;
            document.getElementById('auditAllBtn').style.display = (status === 'pending' && cnt > 0) ? '' : 'none';
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

switchTab('pending');
</script>
