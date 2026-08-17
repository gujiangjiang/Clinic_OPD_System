<?php
/**
 * cashier/paymanage.php — 缴费与退费
 * 说明：
 * 1. 通过患者ID / 门诊流水号 / 身份证号查询患者就诊数据
 * 2. 分组显示每次就诊的已缴费 / 待缴费信息（含开单医生、开单时间）
 * 3. 支持单项目缴费 / 批量缴费，缴费成功后弹出缴费凭条
 *    （收费项目列表、数量、金额、收费员）
 * 4. 退费仅限未使用的项目（检验未登记、检查未登记、药房未发药、
 *    处置未执行），退费成功后对应处方可删除并恢复库存
 */
Router::title('缴费与退费');
?>
<div class="page-head">
    <div><div class="page-title">💳 缴费与退费</div><div class="page-desc">按患者ID / 门诊流水号 / 身份证号查询并处理缴费退费</div></div>
</div>

<div class="card">
    <div class="flex gap-8">
        <input class="input" id="payKw" placeholder="输入患者ID / 门诊流水号 / 身份证号" style="flex:1" autocomplete="off">
        <button class="btn btn-primary btn-sm" onclick="searchVisits()">查询</button>
    </div>
    <div class="fs-12 text-muted mt-8">提示：按患者ID或身份证查询时，将分组显示该患者每次就诊的缴费信息。</div>
</div>

<div id="visitList" class="mt-12"></div>
<div id="visitDetail"></div>

<script>
/* ---------- 查询就诊 ---------- */
function searchVisits() {
    var kw = document.getElementById('payKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入查询关键字'); return; }
    Clinic.get('/api/cashier?action=visit_search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('visitList');
            document.getElementById('visitDetail').innerHTML = '';
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未检索到就诊记录</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共检索到 ' + list.length + ' 次就诊，点击查看缴费明细：</div>' +
                list.map(function (g) {
                    var v = g.visit, p = g.patient;
                    return '<div class="dd-item" style="border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:8px;cursor:pointer" onclick="loadDetail(' + v.id + ')">' +
                        '<div class="flex-between">' +
                        '<span class="fw-600">' + (p ? p.name : '') + ' <span class="fs-12 text-muted fw-400">' + (p ? p.gender + '/' + p.age + '岁' : '') + '</span></span>' +
                        '<span class="fs-12 text-muted">' + v.first_dept_name + ' 第' + String(v.visit_seq).padStart(3, '0') + '号</span></div>' +
                        '<div class="fs-12 text-muted mt-4">患者ID ' + v.patient_no + ' ｜ 流水号 ' + v.flow_no + ' ｜ ' + v.register_time +
                        ' ｜ <span class="badge ' + (v.status === 'paid' ? 'badge-primary' : (v.status === 'finished' ? 'badge-success' : 'badge-gray')) + '">' + visitStatusName(v.status) + '</span></div></div>';
                }).join('');
        },
    });
}

function visitStatusName(s) {
    var map = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '就诊完毕', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}

/* ---------- 加载某次就诊的缴费明细 ---------- */
function loadDetail(visitId) {
    Clinic.get('/api/cashier?action=visit_detail&visit_id=' + visitId, null, {
        onSuccess: function (json) {
            var box = document.getElementById('visitDetail');
            box.innerHTML = json.data.html;
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    });
}

function toggleAll() {
    var all = document.getElementById('batchAll').checked;
    document.querySelectorAll('.batchPay').forEach(function (c) { c.checked = all; });
    updateBatchCount();
}
function updateBatchCount() {
    document.getElementById('batchCount').textContent = document.querySelectorAll('.batchPay:checked').length;
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('batchPay')) updateBatchCount();
});

/* ---------- 单项目缴费 / 批量缴费 ---------- */
function payOrder(orderId) {
    Clinic.modal.confirm('确认为该开单缴费？', function () {
        doPay([orderId]);
    }, { title: '缴费确认', okText: '确认缴费' });
}

function batchPay() {
    var ids = [];
    document.querySelectorAll('.batchPay:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
    if (!ids.length) { Clinic.toast.warning('请先勾选要缴费的项目'); return; }
    doPay(ids);
}

function doPay(ids) {
    Clinic.ajax('/api/cashier', { action: 'pay_orders', order_ids: JSON.stringify(ids) }, {
        loading: true,
        onSuccess: function (json) {
            Clinic.toast.success(json.msg + '，合计 ¥' + parseFloat(json.data.total).toFixed(2));
            // 缴费成功后弹出缴费凭条（收费项目列表、数量、金额、收费员）
            Clinic.print.load('/api/print?action=payment&payment_id=' + json.data.payment_id, null);
            // 刷新明细
            var detail = document.getElementById('visitDetail');
            var vid = detail.querySelector('[data-vid]');
            if (vid) loadDetail(vid.getAttribute('data-vid'));
        },
    });
}

/* ---------- 退费（仅限未使用的项目） ---------- */
function refundOrder(orderId) {
    var reason = prompt('请填写退费原因：', '');
    if (reason === null) return;
    Clinic.modal.confirm('确认退费？仅限未使用的项目（检验未登记、检查未登记、药房未发药、处置未执行）。', function () {
        Clinic.ajax('/api/cashier', { action: 'refund_order', order_id: orderId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                var vid = document.getElementById('visitDetail').querySelector('[data-vid]');
                if (vid) loadDetail(vid.getAttribute('data-vid'));
            },
        });
    }, { title: '退费确认', okText: '确认退费' });
}
</script>
