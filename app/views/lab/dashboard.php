<?php
/**
 * lab/dashboard.php — 检验科工作台
 * 说明：患者缴费后检验项目进入【待登记】→ 登记 → 【检验录入】
 * （显示计量单位/正常范围/危急值上下限）→ 提交自动生成报告并打印
 * → 移入【已完成】；已完成报告支持申请撤回（管理员审核）。
 */
Router::title('检验科工作台');
?>
<div class="page-head">
    <div><div class="page-title">🧪 检验科工作台</div><div class="page-desc">检验登记、结果录入与报告管理</div></div>
    <button class="btn btn-outline btn-sm" onclick="openLabItemForm()">＋ 新增检验项目</button>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="paid" onclick="switchTab('paid')">待登记</button>
    <button class="btn btn-outline btn-sm" data-tab="registered" onclick="switchTab('registered')">待出报告</button>
    <button class="btn btn-outline btn-sm" data-tab="done" onclick="switchTab('done')">已完成</button>
</div>

<div id="labBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    loadQueue(tab);
}

function loadQueue(status) {
    Clinic.get('/api/lab?action=queue&status=' + status, null, {
        onSuccess: function (json) {
            document.getElementById('labBody').innerHTML = json.data.html;
        },
    });
}

/* 登记（采样） */
function labRegister(itemId) {
    Clinic.ajax('/api/lab', { action: 'register', item_id: itemId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            loadQueue('paid');
        },
    });
}

/* 检验结果录入（含正常范围与危急值提示） */
function labResultForm(itemId) {
    var mask = Clinic.modal.load('/api/lab', { action: 'result_form', item_id: itemId }, { title: '检验结果录入' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="resSave">提交并打印报告</button>';
        var saveBtn = mask.querySelector('#resSave');
        saveBtn.addEventListener('click', function () {
            var isGroup = !!mask.querySelector('#resGroup');
            var value;
            if (isGroup) {
                // 检验组：收集组内每项检验结果（resValue_<成员id>）
                var vals = {};
                mask.querySelectorAll('[id^="resValue_"]').forEach(function (el) {
                    vals[el.id.replace('resValue_', '')] = el.value.trim();
                });
                value = JSON.stringify(vals);
            } else {
                value = document.getElementById('resValue').value.trim();
            }
            if (!value) { Clinic.toast.warning('请输入检验结果数值'); return; }
            Clinic.ajax('/api/lab', {
                action: 'save_result', item_id: itemId, value: value,
                is_group: isGroup ? 1 : 0,
            }, {
                loading: true,
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    // 自动弹出报告打印
                    Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
                    loadQueue('registered');
                    loadQueue('done');
                },
            });
        });
    });
}

/* 申请撤回报告 */
function withdrawReport(reportId) {
    var reason = prompt('请填写撤回原因：', '');
    if (reason === null) return;
    Clinic.modal.confirm('确认申请撤回该报告？需管理员审核通过后生效。', function () {
        Clinic.ajax('/api/lab', { action: 'withdraw', report_id: reportId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadQueue('done');
            },
        });
    });
}

/* 新增检验项目（提交后需管理员审核，需求19） */
function openLabItemForm() {
    var mask = Clinic.modal.load('/api/lab', { action: 'item_form' }, { title: '新增检验项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="labItemSave">提交审核</button>';
        document.getElementById('labItemSave').addEventListener('click', function () {
            Clinic.ajax('/api/lab', {
                action: 'item_save',
                name: document.getElementById('f_name').value.trim(),
                category: document.getElementById('f_category').value,
                price: document.getElementById('f_price').value,
                unit: document.getElementById('f_unit').value,
                normal_range: document.getElementById('f_normal').value,
                critical_low: document.getElementById('f_clow').value,
                critical_high: document.getElementById('f_chigh').value,
                description: document.getElementById('f_desc').value,
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                },
            });
        });
    });
}

switchTab('paid');
</script>
