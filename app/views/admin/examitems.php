<?php
/**
 * admin/examitems.php — 检查项目管理（独立页面）
 * 说明：与检验项目分开管理。检查项目仅需：名称、所属分类（CT/MR等）、
 * 价格、描述；无检验项目的单位/正常范围/危急值，也无组合逻辑。
 * 新增项目默认待审核，管理员在【审核中心】通过后即可开单。
 */
Router::title('检查项目管理');
?>
<div class="page-head">
    <div><div class="page-title">🩻 检查项目管理</div><div class="page-desc">检查项目与分类管理（CT、MR、DR、超声等，新项目需审核通过后可用）</div></div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" onclick="openCatMgr()">🗂️ 分类管理</button>
        <button class="btn btn-primary btn-sm" onclick="openItemForm(0)">＋ 新增检查项目</button>
    </div>
</div>

<div class="card" id="itemList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadItemList() {
    Clinic.get('/api/admin?action=item_list&type=exam', null, {
        onSuccess: function (json) {
            document.getElementById('itemList').innerHTML = json.data.html;
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
</script>
