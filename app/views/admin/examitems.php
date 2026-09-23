<?php
/**
 * admin/examitems.php — 检查项目管理（独立页面）
 * 说明：检查项目与分类管理（CT、MR、DR、超声等，新项目需审核通过后可用）。
 * 支持快速搜索与分类子标签（全部 / CT / MR / DR / 超声…，按数据动态生成），
 * 计数随筛选动态更新。
 */
Router::title('检查项目管理');
$__isAdmin = Auth::user() && Auth::user()['role'] === 'admin';
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">🩻 检查项目管理</div><div class="page-desc">检查项目与分类管理<?php echo $__isAdmin ? '' : '（新项目需审核通过后可用）'; ?></div></div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" id="examCatBtn" onclick="openCatMgr()">🗂️ 分类管理</button>
        <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openItemForm(0)">＋ 新增检查项目</button></div>
    </div>
</div>

<div class="card list-filter">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="examSearch" placeholder="🔍 快速搜索检查项目" style="width:220px">
        <span class="fs-13 text-muted" id="examCountDiv"></span>
        <span class="flex gap-4" id="examCatTabs" style="flex-wrap:wrap"></span>
    </div>
</div>

<div class="card list-card" id="itemList"><div class="empty"><div class="spinner"></div></div></div>
</div>

<script>
var EXAM_CAT = '';
var IS_ADMIN = document.body.getAttribute('data-role') === 'admin';
if (!IS_ADMIN) {
    var ct = document.getElementById('examCatBtn'); if (ct) ct.style.display = 'none';
    var ib = document.getElementById('impBtns'); if (ib) ib.style.display = 'none';
}
/* v8.17.3 统一分页无限滚动：服务端 page/size/kw/cat 过滤，滚动到底自动加载 */
var EXAM_PAGED = null;
var EXAM_STATE = { kw: '', cat: '' };
function examListUrl(p, size, st) {
    return '/api/admin?action=item_list&type=exam&page=' + p + '&size=' + size +
        '&kw=' + encodeURIComponent(st.kw) + '&cat=' + encodeURIComponent(st.cat);
}
function initExamPaged() {
    var box = document.getElementById('itemList');
    if (!box) return;
    if (EXAM_PAGED) { EXAM_PAGED.reset(); return; }
    box.innerHTML = '<div class="table-wrap"><table class="table" id="examTable"><tbody></tbody></table></div>';
    EXAM_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'examTable',
        state: EXAM_STATE,
        url: examListUrl,
        countEl: 'examCountDiv',
        catsEl: 'examCatTabs',
        kwEl: 'examSearch',
    });
}
Clinic.importer._reloads['exam'] = loadItemList;
Clinic.importer.attach('exam', 'impBtns', '检查项目');
function loadItemList() { initExamPaged(); }
initExamPaged();

function openItemForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'item_form', type: 'exam', id: id || 0 }, { title: id ? '编辑检查项目' : '新增检查项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;width:100%">' +
            '<button type="button" id="enabledToggle" class="btn btn-sm btn-success" onclick="toggleItemEnabled()">✅ 启用</button>' +
            '<span><button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="itemSave">保存</button></span></div>';
        initEnabledToggle(id > 0);
        document.getElementById('itemSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'item_save',
                type: 'exam',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                category: document.getElementById('f_category').value,
                price: document.getElementById('f_price').value,
                description: document.getElementById('f_desc').value,
                enabled: document.getElementById('f_enabled').value,
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    loadItemList();
                },
            });
        });
    });
}

function delItem(type, id) {
    Clinic.adminItems.delItem({ confirm: '确定删除该检查项目？', url: '/api/admin', action: 'item_delete', params: { type: type }, reload: loadItemList }, id);
}

/* 检查分类管理（统一走 Clinic.adminItems 公共组件） */
function openCatMgr() {
    Clinic.adminItems.catManager({ type: 'exam', title: '检查分类管理', placeholder: '新增检查分类名称（如：CT、MR）', onChanged: loadItemList });
}

loadItemList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
Clinic.adminItems.bindEditDeepLink(openItemForm);
</script>
