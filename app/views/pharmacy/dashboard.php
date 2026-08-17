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
    <div><div class="page-title">💊 药房工作台</div><div class="page-desc">处方发药与药品库存管理</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-tab="queue" onclick="switchTab('queue')">待发药</button>
    <button class="btn btn-outline btn-sm" data-tab="inv" onclick="switchTab('inv')">库存管理</button>
</div>

<div id="phBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function switchTab(tab) {
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    if (tab === 'queue') loadQueue();
    else loadInventory();
}

function loadQueue() {
    Clinic.get('/api/pharmacy?action=queue', null, {
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

switchTab('queue');
</script>
