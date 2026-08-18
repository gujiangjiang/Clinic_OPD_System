<?php
/**
 * admin/disposal.php — 处置项目管理
 * 说明：处置名称、费用、描述备注；新增处置项目需在审核中心通过后可用。
 */
Router::title('处置项目');
?>
<div class="page-head">
    <div><div class="page-title">🩹 处置项目</div><div class="page-desc">处置项目与费用管理（新增需审核通过后可用）</div></div>
    <button class="btn btn-primary btn-sm" onclick="openDisposalForm(0)">＋ 新增处置项目</button>
</div>
<div class="card" id="dispList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadDispList() {
    Clinic.get('/api/admin?action=disposal_list', null, {
        onSuccess: function (json) {
            document.getElementById('dispList').innerHTML = json.data.html;
        },
    });
}

function openDisposalForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'disposal_form', id: id || 0 }, { title: id ? '编辑处置项目' : '新增处置项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="dispSave">保存</button>';
        document.getElementById('dispSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'disposal_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                fee: document.getElementById('f_fee').value,
                description: document.getElementById('f_desc').value.trim(),
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    loadDispList();
                },
            });
        });
    });
}

function delDisposal(id) {
    Clinic.modal.confirm('确定删除该处置项目？', function () {
        Clinic.ajax('/api/admin', { action: 'disposal_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDispList();
            },
        });
    });
}

loadDispList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
(function () {
    var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1];
    if (m) openDisposalForm(parseInt(m, 10));
})();
</script>
