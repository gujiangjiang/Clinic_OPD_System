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

/* 审方：拉取整张处方明细 → 模态框展示 + 通过/拒绝 */
function reviewRx(orderId) {
    Clinic.get('/api/pharmacy?action=rx_detail&order_id=' + orderId, null, {
        onSuccess: function (json) {
            var d = json.data || {};
            var o = d.order || {}, p = d.patient || {};
            var items = d.items || [];
            var rows = '';
            items.forEach(function (m) {
                rows += '<tr>' +
                    '<td class="fw-600">' + Clinic.escHtml(m.item_name) + '</td>' +
                    '<td class="fs-13">' + Clinic.escHtml(m.single_dose || '—') + '</td>' +
                    '<td class="fs-13">' + Clinic.escHtml(m.frequency || '—') + '</td>' +
                    '<td class="fs-13">' + Clinic.escHtml(m.route || '—') + '</td>' +
                    '<td class="fs-13">' + (m.quantity || 0) + '</td>' +
                    '<td class="fs-13 text-muted">¥' + (parseFloat(m.price || 0) * (m.quantity || 0)).toFixed(2) + '</td></tr>';
                (m.subs || []).forEach(function (s) {
                    rows += '<tr>' +
                        '<td class="fs-13">　└ ' + Clinic.escHtml(s.item_name) + '</td>' +
                        '<td class="fs-13">' + Clinic.escHtml(s.single_dose || '—') + '</td>' +
                        '<td class="fs-13">' + Clinic.escHtml(s.frequency || '—') + '</td>' +
                        '<td class="fs-13">' + Clinic.escHtml(s.route || '—') + '</td>' +
                        '<td class="fs-13">' + (s.quantity || 0) + '</td>' +
                        '<td class="fs-13 text-muted">¥' + (parseFloat(s.price || 0) * (s.quantity || 0)).toFixed(2) + '</td></tr>';
                });
            });
            var html =
                '<div class="fs-13 fw-700 mb-4">患者：' + Clinic.escHtml(p.name) + '（' + Clinic.escHtml(p.patient_no) + '）</div>' +
                '<div class="fs-12 text-muted mb-8">处方号：' + Clinic.escHtml(o.order_no) +
                ' ｜ 开单医生：' + Clinic.escHtml(o.doctor_name || '') +
                (o.dept_name ? ' ｜ ' + Clinic.escHtml(o.dept_name) : '') + ' ｜ ' + Clinic.escHtml(o.created_at || '') + '</div>' +
                '<div class="table-wrap"><table class="table"><thead><tr>' +
                '<th>药品</th><th>剂量</th><th>频次</th><th>途径</th><th>数量</th><th>小计</th></tr></thead><tbody>' +
                rows + '</tbody></table></div>' +
                '<div class="flex-between mt-8"><span></span><span class="fw-600">合计：¥' + parseFloat(o.total_amount || 0).toFixed(2) + '</span></div>' +
                '<div class="fs-12 text-muted mt-4">审方通过即整单发药并打印处方提示；拒绝需填写理由，将通知开单医生。</div>' +
                '<div id="rejectBox" style="display:none" class="mt-8"><label class="form-label">拒绝理由 <span class="req">*</span></label>' +
                '<textarea class="textarea" id="rejectReason" rows="2" placeholder="如：剂量超限 / 配伍禁忌 / 库存不足"></textarea></div>';
            Clinic.modal.open(html, {
                title: '💊 处方审方',
                size: 'modal-lg',
                buttons: [
                    { text: '关闭', cls: 'btn-outline' },
                    { text: '❌ 拒绝', cls: 'btn-danger', autoClose: false, onClick: doReject },
                    { text: '✅ 通过发药', cls: 'btn-primary', autoClose: false, onClick: doPass },
                ],
            });
            var box = document.getElementById('rejectBox');
            if (box) box.style.display = '';
        },
    });

    function doPass() {
        Clinic.ajax('/api/pharmacy', { action: 'audit', order_id: orderId, verdict: 'pass' }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
                // 发药完成 → 弹出处方提示凭条（可现场交给患者）
                Clinic.print.load('/api/pharmacy?action=rx_slip&order_id=' + orderId, null, 'ticket');
                loadQueue('paid');
            },
        });
    }
    function doReject() {
        var reason = (document.getElementById('rejectReason') || {}).value || '';
        if (!reason.trim()) { Clinic.toast.warning('请填写拒绝理由'); return; }
        Clinic.ajax('/api/pharmacy', { action: 'audit', order_id: orderId, verdict: 'reject', reason: reason.trim() }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
                loadQueue('paid');
            },
        });
    }
}

/* 发药完成：补打处方提示凭条 */
function reprintRxSlip(orderId) {
    Clinic.print.load('/api/pharmacy?action=rx_slip&order_id=' + orderId, null, 'ticket');
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
