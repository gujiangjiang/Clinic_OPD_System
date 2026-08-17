<?php
/**
 * admin/labitems.php — 检验项目管理（独立页面）
 * 说明：与检查项目分开管理。检验支持「组合检验」：
 *   1. 单个检验项目：如红细胞、白细胞，可单独开单（各自价格）
 *   2. 检验组合：如「血细胞分析」包含多个单项目，按组合价格整体收费，
 *      医生开单时可单独开组内项目，也可直接开整个组合
 * 新增项目/组合默认待审核，管理员在【审核中心】通过后即可开单。
 */
Router::title('检验项目管理');
?>
<div class="page-head">
    <div><div class="page-title">🧪 检验项目管理</div><div class="page-desc">检验项目与组合管理（组合按组价整体收费，新项目需审核通过后可用）</div></div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" onclick="openCatMgr()">🗂️ 分类管理</button>
        <button class="btn btn-outline btn-sm" onclick="openGroupForm(0)">🧩 新增检验组合</button>
        <button class="btn btn-primary btn-sm" onclick="openItemForm(0)">＋ 新增检验项目</button>
    </div>
</div>

<div class="card" id="itemList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadItemList() {
    Clinic.get('/api/admin?action=item_list&type=lab', null, {
        onSuccess: function (json) {
            document.getElementById('itemList').innerHTML = json.data.html;
        },
    });
}

function openItemForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'item_form', type: 'lab', id: id || 0 }, { title: id ? '编辑检验项目' : '新增检验项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="itemSave">保存</button>';
        document.getElementById('itemSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', {
                action: 'item_save',
                type: 'lab',
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

/* 新增/编辑检验组合（新增与编辑完全复用同一表单与初始化逻辑） */
function openGroupForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'lab_group_form', id: id || 0 }, { title: id ? '编辑检验组合' : '新增检验组合' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="grpSave">保存</button>';
        document.getElementById('grpSave').addEventListener('click', function () {
            var memberIds = [];
            document.querySelectorAll('.grpMem:checked').forEach(function (c) { memberIds.push(c.value); });
            Clinic.ajax('/api/admin', {
                action: 'lab_group_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                category: document.getElementById('f_category').value,
                price: document.getElementById('f_price').value,
                member_ids: memberIds.join(','),
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
    Clinic.modal.confirm('确定删除该检验项目？', function () {
        Clinic.ajax('/api/admin', { action: 'item_delete', type: type, id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadItemList();
            },
        });
    });
}

/* 删除检验组合（组内项目自动还原为独立项目） */
function delLabGroup(id) {
    Clinic.modal.confirm('确定删除该检验组合？组内项目将还原为独立检验项目。', function () {
        Clinic.ajax('/api/admin', { action: 'lab_group_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadItemList();
            },
        });
    });
}

/* 检验分类管理 */
function openCatMgr() {
    var loadCats = function () {
        Clinic.get('/api/admin?action=cat_list&type=lab', null, {
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
        '<input class="input" id="catName" placeholder="新增检验分类名称（如：血液检验）" style="flex:1">' +
        '<button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div>' +
        '<div id="catBox"></div>',
        {
            title: '检验分类管理',
            size: 'modal-sm',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        }
    );
    loadCats();
    window.addCat = function () {
        var name = document.getElementById('catName').value.trim();
        if (!name) { Clinic.toast.warning('请输入分类名称'); return; }
        Clinic.ajax('/api/admin', { action: 'cat_add', type: 'lab', name: name }, {
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
</script>
