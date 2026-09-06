<?php
/**
 * ============================================================
 * pharmacy/dashboard.php — 药房工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：待发药 / 完成 / 当日；点击患者弹出只读打印样式
 *   处方单，可直接「通过发药 / 拒绝」审方。
 * 数据接口：/api/deptwork（queue/patient）+ /api/pharmacy（audit/
 * rx_slip）。
 * ============================================================ */
require APP_ROOT . '/app/includes/dept_workbench.php';
dept_workbench(array(
    'role' => 'pharmacy',
    'title' => '药房工作台',
    'desc' => '处方审方发药（药品信息请到「药品信息 / 药品设置」维护）',
    'emoji' => '💊',
    'extra_actions' => '<button type="button" class="btn btn-outline btn-sm" id="dwInvBtn" title="库存管理（入库/出库）">📦 库存</button>',
));
?>
<script>
/* ==================== 药房工作台：患者工作台渲染 ==================== */
Clinic.deptwork.configure({
    role: 'pharmacy',
    render: renderRxWork,
    afterAction: afterRxAction,
});

function afterRxAction() {
    Clinic.deptwork.reloadPatient();
    Clinic.deptwork.refreshQueue();
}

function esc(s) { return Clinic.escHtml(s); }
function money(n) { return '¥' + (parseFloat(n) || 0).toFixed(2); }
function orderStatusName(s) {
    var map = { pending: '待缴费', paid: '待审方', dispensed: '已发药', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}
function rxStatusBadge(s) {
    var cls = s === 'dispensed' ? 'badge-success' : (s === 'paid' ? 'badge-warning' : 'badge-gray');
    return '<span class="badge ' + cls + '" style="font-size:11px">' + orderStatusName(s) + '</span>';
}

function renderRxWork(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'prescription'; });
    // 右栏大纲：处方导航
    var sideItems = orders.map(function (o) {
        var dot = o.status === 'dispensed' ? 'ok' : (o.status === 'paid' ? 'pending' : 'done');
        return '<div class="dw-side-item" onclick="scrollToRx(\'' + esc(o.order_id) + '\')"><span class="dot ' + dot + '"></span>' +
            esc(o.order_no) + '（' + o.items.length + ' 项）</div>';
    }).join('');
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">💊 本次处方（' + orders.length + ' 张）</div>' +
        (sideItems || '<div class="dw-side-item">暂无处方</div>') + '</div>';

    // 主区：只读打印样式处方单
    var head = '<div class="fs-13 text-muted mb-8">患者 ' + esc(v.name) +
        '（' + esc(p.patient_id) + '）本次就诊处方共 ' + orders.length + ' 张</div>';
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">💊</div>本次就诊暂无处方</div></div>';
    } else {
        orders.forEach(function (o) { body += rxOrderHtml(data, o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
    // 绑定审方操作
    orders.forEach(function (o) {
        if (o.status === 'paid') {
            var pass = document.getElementById('rxPass_' + o.order_id);
            if (pass) pass.onclick = function () { doRxPass(o); };
            var rej = document.getElementById('rxReject_' + o.order_id);
            if (rej) rej.onclick = function () { doRxReject(o); };
        }
    });
}

function scrollToRx(orderId) {
    var el = document.getElementById('rxSec_' + orderId);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function rxRowHtml(it, sub) {
    return '<tr>' +
        '<td class="fw-600">' + (sub ? '　└ ' : '') + esc(it.item_name) + (it.is_nurse ? ' <span class="badge badge-warning" style="font-size:10px">护士站执行</span>' : '') + '</td>' +
        '<td>' + esc(it.single_dose || '—') + '</td>' +
        '<td>' + esc(it.frequency || '—') + '</td>' +
        '<td>' + esc(it.route || '—') + '</td>' +
        '<td>' + (it.quantity || 0) + '</td>' +
        '<td>' + money((parseFloat(it.price) || 0) * (it.quantity || 0)) + '</td></tr>';
}

function rxOrderHtml(data, o) {
    var v = data.visit || {}, p = data.patient || {};
    var hosp = document.body.getAttribute('data-hosp') || '';
    var mainItems = o.items.filter(function (it) { return it.sub_of === 0; });
    var rows = '';
    mainItems.forEach(function (mi) {
        rows += rxRowHtml(mi, false);
        o.items.filter(function (it) { return it.sub_of > 0 && it.group_no === mi.group_no && mi.group_no > 0; })
            .forEach(function (sub) { rows += rxRowHtml(sub, true); });
    });
    var allNurse = mainItems.length > 0 && mainItems.every(function (mi) { return mi.is_nurse; });
    var actions = '';
    if (o.status === 'paid') {
        actions = '<div class="dw-report-actions">' +
            '<button class="btn btn-success btn-sm" id="rxPass_' + esc(o.order_id) + '">✅ 通过发药</button>' +
            '<button class="btn btn-danger btn-sm" id="rxReject_' + esc(o.order_id) + '">❌ 拒绝</button>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-4">审方通过即整单发药并打印取药凭条；拒绝需填写理由并通知开单医生，库存自动恢复。</div>';
    } else if (o.status === 'dispensed') {
        actions = '<div class="dw-report-actions">' +
            (allNurse
                ? '<span class="badge badge-warning">全部护士站执行</span>'
                : '<button class="btn btn-outline btn-sm" onclick="reprintRx(\'' + esc(o.order_id) + '\')">🖨️ 处方提示</button>') +
            '</div>';
    }
    return '<div class="card dw-rx-card" id="rxSec_' + esc(o.order_id) + '" style="margin-bottom:14px">' +
        '<div class="dw-rx-hosp">' + esc(hosp) + '</div>' +
        '<div class="dw-rx-title">处 方 笺 ' + rxStatusBadge(o.status) + '</div>' +
        '<div class="dw-rx-meta">' +
        '<span>姓名：<b>' + esc(v.name) + '</b></span>' +
        '<span>性别：' + esc(v.gender) + '　年龄：' + esc(v.age_fmt || '') + '</span>' +
        '<span>患者ID：' + esc(p.patient_id) + '</span>' +
        '<span>处方号：' + esc(o.order_no) + '</span>' +
        '<span>开单医生：' + esc(o.doctor_name || '') + '</span>' +
        '<span>开单科室：' + esc(o.dept_name || '') + '</span>' +
        '<span>开单时间：' + esc((o.created_at || '').substr(0, 16)) + '</span>' +
        '<span>流水号：' + esc(v.visit_no) + '</span>' +
        '</div>' +
        '<table class="dw-rx-table"><thead><tr><th>药品</th><th>剂量</th><th>频次</th><th>途径</th><th>数量</th><th>小计</th></tr></thead><tbody>' +
        rows + '</tbody></table>' +
        '<div class="flex-between mt-8"><span class="fs-13">共 ' + o.items.length + ' 项</span>' +
        '<span class="fw-600">合计：' + money(o.total_amount) + '</span></div>' +
        (o.status === 'dispensed'
            ? '<div class="dw-rx-sign"><span>开单医生：' + esc(o.doctor_name || '') + '</span>' +
              '<span>发药药师：' + esc(o.done_by || '') + '</span><span>发药时间：' + esc((o.dispensed_at || '').substr(0, 16)) + '</span></div>'
            : '') +
        actions + '</div>';
}

function doRxPass(o) {
    Clinic.ajax('/api/pharmacy', { action: 'audit', order_id: o.order_id, verdict: 'pass' }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            if (json.data && json.data.has_slip) {
                Clinic.print.load('/api/pharmacy?action=rx_slip&order_id=' + o.order_id, null, 'ticket');
            }
            afterRxAction();
        },
    });
}

function doRxReject(o) {
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">拒绝理由 <span class="req">*</span></label>' +
        '<textarea class="textarea" id="rxRejectReason" rows="3" placeholder="如：剂量超限 / 配伍禁忌 / 库存不足"></textarea></div>' +
        '<div class="fs-12 text-muted">拒绝后该处方全部明细置为已拒绝、库存自动恢复，并通知开单医生。</div>',
        {
            title: '❌ 拒绝处方 ' + o.order_no,
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '确认拒绝', cls: 'btn-danger', autoClose: false,
                    onClick: function () {
                        var reason = (document.getElementById('rxRejectReason') || {}).value || '';
                        if (!reason.trim()) { Clinic.toast.warning('请填写拒绝理由'); return; }
                        Clinic.ajax('/api/pharmacy', { action: 'audit', order_id: o.order_id, verdict: 'reject', reason: reason.trim() }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                afterRxAction();
                            },
                        });
                    },
                },
            ],
        }
    );
}

function reprintRx(orderId) {
    Clinic.print.load('/api/pharmacy?action=rx_slip&order_id=' + orderId, null, 'ticket');
}

/* ==================== 库存管理（顶栏「📦 库存」入口，保持原功能） ==================== */
function openInventory() {
    var mask = Clinic.modal.open(
        '<div id="invBody"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>',
        { title: '📦 库存管理', size: 'modal-lg', buttons: [{ text: '关闭', cls: 'btn-outline' }] }
    );
    Clinic.get('/api/pharmacy?action=inventory', null, {
        onSuccess: function (json) {
            var box = document.getElementById('invBody');
            if (box) box.innerHTML = json.data.html;
        },
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
                                openInventory();
                            },
                        });
                    },
                },
            ],
        }
    );
}

(function () {
    var btn = document.getElementById('dwInvBtn');
    if (btn) btn.onclick = openInventory;
})();

Clinic.deptwork.init();
</script>
