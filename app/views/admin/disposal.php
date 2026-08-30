<?php
/**
 * admin/disposal.php — 处置项目管理
 * 说明：处置名称、费用、描述备注；新增处置项目需在审核中心通过后可用。
 */
Router::title('处置项目');
$__isAdmin = Auth::user() && Auth::user()['role'] === 'admin';
?>
<div class="page-head">
    <div><div class="page-title">🩹 处置项目</div><div class="page-desc">处置项目与费用管理<?php echo $__isAdmin ? '' : '（新增需审核通过后可用）'; ?></div></div>
    <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openDisposalForm(0)">＋ 新增处置项目</button></div>
</div>
<div class="card" style="margin-bottom:12px">
    <input class="input" placeholder="🔍 快速搜索处置项目" oninput="quickFilter(this.value,'dispList')">
</div>
<div class="card" id="dispList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
/* 快速搜索：按行文本过滤 + 动态计数（搜索时去掉「共」） */
function quickFilter(q, boxId) {
    q = q.trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#' + boxId + ' tbody tr').forEach(function (tr) {
        var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('dispCountDiv');
    if (cnt) cnt.textContent = q !== '' ? '处置项目 ' + n + ' 个' : '共 ' + n + ' 个处置项目';
}
Clinic.importer._reloads['disp'] = loadDispList;
Clinic.importer.attach('disp', 'impBtns', '处置项目');
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
                is_nurse: document.getElementById('f_nurse').checked ? 1 : 0,
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
