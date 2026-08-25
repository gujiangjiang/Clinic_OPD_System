<?php
/**
 * admin/examitems.php — 检查项目管理（独立页面）
 * 说明：检查项目与分类管理（CT、MR、DR、超声等，新项目需审核通过后可用）。
 * 支持快速搜索与分类子标签（全部 / CT / MR / DR / 超声…，按数据动态生成），
 * 计数随筛选动态更新。
 */
Router::title('检查项目管理');
?>
<div class="page-head">
    <div><div class="page-title">🩻 检查项目管理</div><div class="page-desc">检查项目与分类管理（CT、MR、DR、超声等，新项目需审核通过后可用）</div></div>
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
/* 分类子 tab（按数据动态生成） */
function buildExamCats() {
    var cats = [];
    document.querySelectorAll('#itemList tbody tr').forEach(function (tr) {
        var c = tr.getAttribute('data-cat') || '';
        if (c && cats.indexOf(c) === -1) cats.push(c);
    });
    var bar = document.getElementById('examCatTabs');
    bar.innerHTML = '<button class="btn btn-sm ' + (EXAM_CAT === '' ? 'btn-primary' : 'btn-outline') + '" data-cat="" onclick="examCatFilter(this,\'\')">全部</button>' +
        cats.map(function (c) {
            return '<button class="btn btn-sm ' + (EXAM_CAT === c ? 'btn-primary' : 'btn-outline') + '" data-cat="' + c + '" onclick="examCatFilter(this,\'' + c + '\')">' + c + '</button>';
        }).join('');
}
function examCatFilter(btn, c) {
    EXAM_CAT = c;
    document.querySelectorAll('#examCatTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === c ? 'btn-primary' : 'btn-outline');
    });
    applyExamFilter();
}
/* 快速搜索 + 计数动态更新（搜索去掉「共」，分类显示「（分类）」） */
function applyExamFilter() {
    var q = (document.getElementById('examSearch').value || '').trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#itemList tbody tr').forEach(function (tr) {
        var hit = (EXAM_CAT === '' || tr.getAttribute('data-cat') === EXAM_CAT) &&
                  tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('examCountDiv');
    if (cnt) cnt.textContent = EXAM_CAT === ''
        ? (q !== '' ? '检查项目 ' + n + ' 项' : '检查项目共 ' + n + ' 项')
        : '检查项目（' + EXAM_CAT + '）' + (q !== '' ? n + ' 项' : '共 ' + n + ' 项');
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
    Clinic.modal.confirm('确定删除该检查项目？', function () {
        Clinic.ajax('/api/admin', { action: 'item_delete', type: type, id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadItemList();
            },
        });
    });
}

/* 检查分类管理 */
function openCatMgr() {
    var loadCats = function () {
        Clinic.get('/api/admin?action=cat_list&type=exam', null, {
            onSuccess: function (json) {
                var list = json.data.list || [];
                document.getElementById('catBox').innerHTML = list.map(function (c) {
                    return '<span class="badge badge-gray" style="margin:0 6px 6px 0;padding:5px 12px">' + c.name +
                        ' <a href="javascript:void(0)" style="color:var(--danger)" onclick="delCat(' + c.id + ')">✕</a></span>';
                }).join('') || '<span class="text-muted fs-13">暂无分类</span>';
            },
        });
    };
    Clinic.modal.open(
        '<div class="flex gap-8 mb-8">' +
        '<input class="input" id="catName" placeholder="新增检查分类名称（如：CT、MR）" style="flex:1">' +
        '<button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div>' +
        '<div id="catBox"></div>',
        {
            title: '检查分类管理',
            size: 'modal-sm',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        }
    );
    loadCats();
    window.addCat = function () {
        var name = document.getElementById('catName').value.trim();
        if (!name) { Clinic.toast.warning('请输入分类名称'); return; }
        Clinic.ajax('/api/admin', { action: 'cat_add', type: 'exam', name: name }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                document.getElementById('catName').value = '';
                loadCats();
            },
        });
    };
    window.delCat = function (id) {
        Clinic.ajax('/api/admin', { action: 'cat_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadCats();
            },
        });
    };
}

loadItemList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
(function () {
    var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1];
    if (m) openItemForm(parseInt(m, 10));
})();
</script>
