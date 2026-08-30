<?php
/**
 * pharmacy/dashboard.php — 药房工作台
 * 说明：
 * 1. 待发药：患者缴费后的处方（医生开方即减库存）
 * 2. 发药：发药后通知开单医生
 * 3. 库存管理：入库/出库并记录库存流水
 */
Router::title('药房工作台');
?>
<div class="page-head">
    <div><div class="page-title">💊 药房工作台</div><div class="page-desc">处方发药、药品出入库（药品信息请到「药品信息 / 药品设置」维护）</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="queue" onclick="switchTab('queue')">待发药</button>
    <button class="btn btn-outline btn-sm" data-tab="done" onclick="switchTab('done')">发药完成</button>
    <button class="btn btn-outline btn-sm" data-tab="inv" onclick="switchTab('inv')">库存管理</button>
</div>

<div id="phBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    if (tab === 'queue') loadQueue('paid');
    else if (tab === 'done') loadQueue('dispensed');
    else loadInventory();
}

function loadQueue(status) {
    Clinic.get('/api/pharmacy?action=queue&status=' + status, null, {
        onSuccess: function (json) {
            document.getElementById('phBody').innerHTML = json.data.html;
        },
    });
}

function loadInventory() {
    Clinic.get('/api/pharmacy?action=inventory', null, {
        onSuccess: function (json) {
            document.getElementById('phBody').innerHTML = json.data.html;
        },
    });
}

function dispenseDrug(itemId) {
    Clinic.modal.confirm('确认该药品已发放给患者？', function () {
        Clinic.ajax('/api/pharmacy', { action: 'dispense', item_id: itemId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadQueue();
            },
        });
    });
}

function stockModal(drugId, drugName) {
    Clinic.modal.open(
        '<div class="fs-13 text-muted mb-8">药品：' + drugName + '</div>' +
        '<div class="form-row">' +
        '<div class="form-group"><label class="form-label">操作类型</label><select class="select" id="stType">' +
        '<option value="in">入库</option><option value="out">出库</option></select></div>' +
        '<div class="form-group"><label class="form-label">数量</label><input class="input" type="number" min="1" id="stQty" value="1"></div></div>' +
        '<div class="form-group"><label class="form-label">备注</label><input class="input" id="stNote" placeholder="如：进货单号 / 报损"></div>',
        {
            title: '库存变动',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '确定', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var qty = parseInt(document.getElementById('stQty').value, 10);
                        if (!qty || qty <= 0) { Clinic.toast.warning('请输入正确的数量'); return; }
                        Clinic.ajax('/api/pharmacy', {
                            action: 'stock', drug_id: drugId,
                            qty: qty, type: document.getElementById('stType').value,
                            note: document.getElementById('stNote').value.trim(),
                        }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                loadInventory();
                            },
                        });
                    },
                },
            ],
        }
    );
}

/* 新增药品（页面与管理员新增药品一致，提交后需审核，需求20）；id>0 为驳回后回填原提交内容重新提交 */
function openDrugForm(id) {
    var mask = Clinic.modal.load('/api/pharmacy', { action: 'drug_form', id: id || 0 }, { title: id ? '修改并重新提交药品' : '新增药品' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
        var routeMap = (e.detail && e.detail.route_nurse) || {};
        var nurseChk = document.getElementById('f_nurse');
        window.__routeMap = routeMap;
        window.syncNurse = function () {
            var route = document.getElementById('f_route').value;
            if (routeMap[route] === 1) nurseChk.checked = true;
        };
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="phDrugSave">提交审核</button>';
        document.getElementById('phDrugSave').addEventListener('click', function () {
            Clinic.ajax('/api/pharmacy', {
                action: 'drug_save',
                name: document.getElementById('f_name').value.trim(),
                generic_name: document.getElementById('f_generic').value.trim(),
                category: document.getElementById('f_category').value,
                vendor: document.getElementById('f_vendor').value.trim(),
                vendor_short: document.getElementById('f_vendor_short').value.trim(),
                package_unit: document.getElementById('f_pkg').value,
                spec: document.getElementById('f_spec').value.trim(),
                form: document.getElementById('f_form').value,
                single_dose: document.getElementById('f_dose').value.trim(),
                frequency: document.getElementById('f_freq').value,
                route: document.getElementById('f_route').value,
                price: document.getElementById('f_price').value,
                qty: document.getElementById('f_qty').value,
                is_rx: document.getElementById('f_rx').checked ? 1 : 0,
                is_limited: document.getElementById('f_limited').checked ? 1 : 0,
                is_nurse: document.getElementById('f_nurse').checked ? 1 : 0,
                note: document.getElementById('f_note').value.trim(),
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                },
            });
        });
    });
}

/* 新增药品分类（需求20） */
function openCategoryForm() {
    var mask = Clinic.modal.load('/api/pharmacy', { action: 'category_form' }, { title: '新增药品分类' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="phCatSave">保存</button>';
        document.getElementById('phCatSave').addEventListener('click', function () {
            var name = document.getElementById('f_cat_name').value.trim();
            if (!name) { Clinic.toast.warning('请输入分类名称'); return; }
            Clinic.ajax('/api/pharmacy', { action: 'category_save', name: name }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                },
            });
        });
    });
}

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit_item=ID） */
(function () {
    var m = (location.search.match(/[?&]edit_item=(\d+)/) || [])[1];
    if (m) {
        openDrugForm(parseInt(m, 10));
        history.replaceState({}, '', location.pathname);
    }
})();

switchTab('queue');
</script>
