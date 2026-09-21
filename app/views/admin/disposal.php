<?php
/**
 * admin/disposal.php — 处置项目管理
 * 说明：处置名称、费用、描述备注；新增处置项目需在审核中心通过后可用。
 */
Router::title('处置项目');
$__isAdmin = Auth::user() && Auth::user()['role'] === 'admin';
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">🩹 处置项目</div><div class="page-desc">处置项目与费用管理<?php echo $__isAdmin ? '' : '（新增需审核通过后可用）'; ?></div></div>
    <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openDisposalForm(0)">＋ 新增处置项目</button></div>
</div>
<div class="card list-filter">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="dispSearch" placeholder="🔍 快速搜索处置项目" style="width:220px">
        <span class="fs-13 text-muted" id="dispCountDiv"></span>
    </div>
</div>
<div class="card list-card" id="dispList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
</div>

<script>
/* v8.17.3 统一分页无限滚动：服务端 page/size/kw 过滤，滚动到底自动加载 */
var DISP_PAGED = null;
var DISP_STATE = { kw: '' };
function dispListUrl(p, size, st) {
    return '/api/admin?action=disposal_list&page=' + p + '&size=' + size + '&kw=' + encodeURIComponent(st.kw || '');
}
function initDispPaged() {
    var box = document.getElementById('dispList');
    if (!box) return;
    if (DISP_PAGED) { DISP_PAGED.reset(); return; }
    box.innerHTML = '<div class="table-wrap"><table class="table" id="dispTable"><tbody></tbody></table></div>';
    DISP_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'dispTable',
        state: DISP_STATE,
        url: dispListUrl,
        countEl: 'dispCountDiv',
        kwEl: 'dispSearch',
    });
}
Clinic.importer._reloads['disp'] = loadDispList;
Clinic.importer.attach('disp', 'impBtns', '处置项目');
function loadDispList() { initDispPaged(); }
initDispPaged();

function openDisposalForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'disposal_form', id: id || 0 }, { title: id ? '编辑处置项目' : '新增处置项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;width:100%">' +
            '<button type="button" id="enabledToggle" class="btn btn-sm btn-success" onclick="toggleItemEnabled()">✅ 启用</button>' +
            '<span><button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="dispSave">保存</button></span></div>';
        initEnabledToggle(id > 0);
        document.getElementById('dispSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'disposal_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                fee: document.getElementById('f_fee').value,
                description: document.getElementById('f_desc').value.trim(),
                is_nurse: document.getElementById('f_nurse').checked ? 1 : 0,
                enabled: document.getElementById('f_enabled').value,
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
