<?php
/**
 * admin/items.php — 检验/检查项目管理
 * 说明：项目分类可新增（CT、MR 等）；检验项目需设置计量单位、
 * 正常范围值、危急值上限/下限；新项目需在审核中心通过后可用。
 */
Router::title('检验检查项目');
?>
<div class="page-head">
    <div><div class="page-title">🧪 检验检查项目</div><div class="page-desc">检验/检查项目与分类管理（新项目需审核通过后可用）</div></div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" onclick="openCatMgr()">🗂️ 分类管理</button>
        <button class="btn btn-primary btn-sm" onclick="openItemForm(0)">＋ 新增项目</button>
    </div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="lab" onclick="switchTab('lab')">检验项目</button>
    <button class="btn btn-outline btn-sm" data-tab="exam" onclick="switchTab('exam')">检查项目</button>
</div>

<div class="card" id="itemList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var CUR_TYPE = 'lab';

function switchTab(type) {
    CUR_TYPE = type;
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === type ? 'btn-primary' : 'btn-outline');
    });
    loadItemList();
}

function loadItemList() {
    Clinic.get('/api/admin?action=item_list&type=' + CUR_TYPE, null, {
        onSuccess: function (json) {
            document.getElementById('itemList').innerHTML = json.data.html;
        },
    });
}

function openItemForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'item_form', type: CUR_TYPE, id: id || 0 }, { title: id ? '编辑项目' : '新增项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="itemSave">保存</button>';
        document.getElementById('itemSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'item_save',
                type: CUR_TYPE,
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                category: document.getElementById('f_category').value,
                price: document.getElementById('f_price').value,
                unit: document.getElementById('f_unit') ? document.getElementById('f_unit').value : '',
                normal_range: document.getElementById('f_normal') ? document.getElementById('f_normal').value : '',
                critical_low: document.getElementById('f_clow') ? document.getElementById('f_clow').value : '',
                critical_high: document.getElementById('f_chigh') ? document.getElementById('f_chigh').value : '',
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
    Clinic.modal.confirm('确定删除该项目？', function () {
        Clinic.ajax('/api/admin', { action: 'item_delete', type: type, id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadItemList();
            },
        });
    });
}

/* 分类管理（检验/检查共用，可新增分类） */
function openCatMgr() {
    var loadCats = function (type) {
        Clinic.get('/api/admin?action=cat_list&type=' + type, null, {
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
        '<select class="select" id="catType" style="width:140px" onchange="loadCatsSel()">' +
        '<option value="lab">检验分类</option><option value="exam">检查分类</option></select>' +
        '<input class="input" id="catName" placeholder="新增分类名称" style="flex:1">' +
        '<button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div>' +
        '<div id="catBox"></div>',
        {
            title: '项目分类管理',
            size: 'modal-sm',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        }
    );
    loadCats('lab');
    window.loadCatsSel = function () { loadCats(document.getElementById('catType').value); };
    window.addCat = function () {
        var name = document.getElementById('catName').value.trim();
        if (!name) { Clinic.toast.warning('请输入分类名称'); return; }
        Clinic.ajax('/api/admin', { action: 'cat_add', type: document.getElementById('catType').value, name: name }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                document.getElementById('catName').value = '';
                loadCats(document.getElementById('catType').value);
            },
        });
    };
    window.delCat = function (id) {
        Clinic.ajax('/api/admin', { action: 'cat_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadCats(document.getElementById('catType').value);
            },
        });
    };
}

switchTab('lab');
</script>
