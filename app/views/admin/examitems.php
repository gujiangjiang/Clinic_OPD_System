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
<div class="page-head">
    <div><div class="page-title">🩻 检查项目管理</div><div class="page-desc">检查项目与分类管理<?php echo $__isAdmin ? '' : '（新项目需审核通过后可用）'; ?></div></div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" id="examCatBtn" onclick="openCatMgr()">🗂️ 分类管理</button>
        <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openItemForm(0)">＋ 新增检查项目</button></div>
    </div>
</div>

<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="examSearch" placeholder="🔍 快速搜索检查项目" style="width:220px" oninput="applyExamFilter()">
        <span class="flex gap-4" id="examCatTabs" style="flex-wrap:wrap"></span>
    </div>
</div>

<div class="card" id="itemList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var EXAM_CAT = '';
var IS_ADMIN = document.body.getAttribute('data-role') === 'admin';
if (!IS_ADMIN) {
    var ct = document.getElementById('examCatBtn'); if (ct) ct.style.display = 'none';
    var ib = document.getElementById('impBtns'); if (ib) ib.style.display = 'none';
}
/* 分类子 tab + 关键字过滤 + 计数（统一走 Clinic.adminItems 公共组件） */
function buildExamCats() {
    Clinic.adminItems.buildCats({ listId: 'itemList', tabsId: 'examCatTabs', current: EXAM_CAT, tabFn: 'examCatFilter' });
}
function examCatFilter(btn, c) {
    Clinic.adminItems.filterByCat({ tabsId: 'examCatTabs', setCat: function (x) { EXAM_CAT = x; }, apply: applyExamFilter }, c);
}
function applyExamFilter() {
    Clinic.adminItems.filterRows({
        listId: 'itemList', countId: 'examCountDiv',
        getCat: function () { return EXAM_CAT; },
        getQuery: function () { return document.getElementById('examSearch').value || ''; },
        countText: function (cat, q, n) {
            return cat === '' ? (q !== '' ? '检查项目 ' + n + ' 项' : '检查项目共 ' + n + ' 项')
                : '检查项目（' + cat + '）' + (q !== '' ? n + ' 项' : '共 ' + n + ' 项');
        },
    });
}
Clinic.importer._reloads['exam'] = loadItemList;
Clinic.importer.attach('exam', 'impBtns', '检查项目');
function loadItemList() {
    Clinic.get('/api/admin?action=item_list&type=exam', null, {
        onSuccess: function (json) {
            document.getElementById('itemList').innerHTML = json.data.html;
            buildExamCats();
            applyExamFilter();
        },
    });
}

function openItemForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'item_form', type: 'exam', id: id || 0 }, { title: id ? '编辑检查项目' : '新增检查项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="itemSave">保存</button>';
        document.getElementById('itemSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'item_save',
                type: 'exam',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                category: document.getElementById('f_category').value,
                price: document.getElementById('f_price').value,
                description: document.getElementById('f_desc').value,
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
    Clinic.adminItems.catManager({ type: 'exam', title: '检查分类管理', placeholder: '新增检查分类名称（如：CT、MR）' });
}

loadItemList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
Clinic.adminItems.bindEditDeepLink(openItemForm);
</script>
