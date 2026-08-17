<?php
/**
 * imaging/dashboard.php — 影像科工作台
 * 说明：与检验科流程一致：待登记 → 登记 → 报告录入
 * （影像所见 + 检查结论）→ 提交自动生成报告并打印 → 已完成。
 */
Router::title('影像科工作台');
?>
<div class="page-head">
    <div><div class="page-title">🩻 影像科工作台</div><div class="page-desc">检查登记、报告录入与报告管理</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="paid" onclick="switchTab('paid')">待登记</button>
    <button class="btn btn-outline btn-sm" data-tab="registered" onclick="switchTab('registered')">待出报告</button>
    <button class="btn btn-outline btn-sm" data-tab="done" onclick="switchTab('done')">已完成</button>
</div>

<div id="imgBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    loadQueue(tab);
}

function loadQueue(status) {
    Clinic.get('/api/imaging?action=queue&status=' + status, null, {
        onSuccess: function (json) {
            document.getElementById('imgBody').innerHTML = json.data.html;
        },
    });
}

function imgRegister(itemId) {
    Clinic.ajax('/api/imaging', { action: 'register', item_id: itemId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            loadQueue('paid');
        },
    });
}

function imgResultForm(itemId) {
    var mask = Clinic.modal.load('/api/imaging', { action: 'result_form', item_id: itemId }, { title: '检查报告录入' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="imgSave">提交并打印报告</button>';
        var saveBtn = mask.querySelector('#imgSave');
        saveBtn.addEventListener('click', function () {
            var findings = document.getElementById('resFindings').value.trim();
            var conclusion = document.getElementById('resConclusion').value.trim();
            if (!findings) { Clinic.toast.warning('请填写影像所见'); return; }
            if (!conclusion) { Clinic.toast.warning('请填写检查结论'); return; }
            Clinic.ajax('/api/imaging', {
                action: 'save_result', item_id: itemId, findings: findings, conclusion: conclusion,
            }, {
                loading: true,
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
                    loadQueue('registered');
                    loadQueue('done');
                },
            });
        });
    });
}

function withdrawReport(reportId) {
    var reason = prompt('请填写撤回原因：', '');
    if (reason === null) return;
    Clinic.modal.confirm('确认申请撤回该报告？需管理员审核通过后生效。', function () {
        Clinic.ajax('/api/imaging', { action: 'withdraw', report_id: reportId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadQueue('done');
            },
        });
    });
}

switchTab('paid');
</script>
