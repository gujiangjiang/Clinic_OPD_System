<?php
/**
 * cashier/paymanage.php — 缴费管理
 * 说明：
 * 1. 上方搜索患者（ID/流水号/身份证）
 * 2. 下方左右两栏：左=患者就诊列表，右=缴费/退费视图
 * 3. 右侧按「缴费凭条批次」展示（同一缴费批次共享流水号，合并一张凭条）
 * 4. 批量缴费合并为一张凭条（唯一缴费流水号），同批次不可单独退费，需整单退
 * 5. 缴费前选择支付方式（现金/医保/银行卡/扫码）
 */
Router::title('缴费管理');
?>
<div class="page-head">
    <div><div class="page-title">💳 缴费管理</div><div class="page-desc">按患者ID / 门诊流水号 / 身份证号查询并处理缴费退费</div></div>
</div>

<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8">
        <input class="input" id="payKw" placeholder="输入患者ID / 门诊流水号 / 身份证号" style="flex:1" autocomplete="off" onkeydown="if(event.key==='Enter')searchVisits()">
        <button class="btn btn-primary btn-sm" onclick="searchVisits()">查询</button>
    </div>
    <div class="fs-12 text-muted mt-8">提示：按患者ID或身份证查询时，将分组显示该患者每次就诊的缴费信息。</div>
</div>

<div class="paymgr-layout">
    <div class="paymgr-left" id="visitList"></div>
    <div class="paymgr-right" id="visitDetail">
        <div class="paymgr-empty">👈 点击左侧就诊记录，查看该次就诊的缴费明细与退费操作</div>
    </div>
</div>

<script>
/* ---------- 查询就诊 ---------- */
function searchVisits() {
    var kw = document.getElementById('payKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入查询关键字'); return; }
    Clinic.get('/api/cashier?action=visit_search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('visitList');
            document.getElementById('visitDetail').innerHTML = '<div class="paymgr-empty">👈 点击左侧就诊记录，查看该次就诊的缴费明细与退费操作</div>';
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未检索到就诊记录</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共检索到 ' + list.length + ' 次就诊：</div>' +
                list.map(function (g) {
                    var v = g.visit, p = g.patient;
                    return '<div class="paymgr-item" onclick="selectVisit(this,\'' + v.id + '\')">' +
                        '<div class="flex-between">' +
                        '<span class="fw-600">' + (p ? Clinic.escHtml(p.name) : '') + ' <span class="fs-12 text-muted fw-400">' +
                        (p ? Clinic.escHtml(p.gender) + '/' + Clinic.escHtml(Clinic.validate.formatAge(p.birth_date)) : '') + '</span></span>' +
                        '<span class="fs-12 text-muted">' + Clinic.escHtml(v.first_dept_name) + ' 第' + String(v.visit_seq).padStart(3, '0') + '号</span></div>' +
                        '<div class="fs-12 text-muted mt-4">患者ID ' + Clinic.escHtml(v.patient_no) + ' ｜ 流水号 ' + Clinic.escHtml(v.flow_no) + ' ｜ ' + Clinic.escHtml(v.registered_at) +
                        ' ｜ <span class="badge ' + (v.status === 'paid' ? 'badge-primary' : (v.status === 'finished' ? 'badge-success' : 'badge-gray')) + '">' + visitStatusName(v.status) + '</span></div></div>';
                }).join('');
        },
    });
}

function visitStatusName(s) {
    var map = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '就诊完毕', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}

/* ---------- 选中左侧就诊 → 加载右侧缴费视图 ---------- */
var CUR_VISIT = null;
function selectVisit(el, visitId) {
    document.querySelectorAll('.paymgr-item').forEach(function (x) { x.classList.remove('active'); });
    if (el) el.classList.add('active');
    CUR_VISIT = visitId;
    loadDetail(visitId);
}

function loadDetail(visitId) {
    Clinic.get('/api/cashier?action=visit_detail&visit_id=' + visitId, null, {
        onSuccess: function (json) {
            document.getElementById('visitDetail').innerHTML = json.data.html;
        },
    });
}

/* ==================== 缴费凭条批次（visit_detail 返回结构） ==================== */

/* 缴费（挂号费） */
function payVisitFee(visitId) {
    openPayMethod('挂号费缴费', function (method) {
        Clinic.ajax('/api/cashier', { action: 'pay_visit', visit_id: visitId, method: method }, {
            loading: true,
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.print.load('/api/print?action=payment&payment_id=' + json.data.payment_id, null, 'ticket');
                loadDetail(visitId);
            },
        });
    });
}

/* 单个开单缴费 */
function payOrder(orderId, visitId) {
    openPayMethod('缴费确认', function (method) {
        doPay([orderId], visitId, method);
    });
}

/* 一键全部缴费：全部未缴费开单合并为一张凭条（挂号费走「缴挂号费」独立按钮） */
function batchPayAll() {
    var ids = [];
    document.querySelectorAll('.batchPay').forEach(function (c) {
        if (c.checked) ids.push(c.value);
    });
    if (!ids.length) {
        document.querySelectorAll('.batchPay').forEach(function (c) { ids.push(c.value); });
    }
    if (!ids.length) { Clinic.toast.warning('暂无可缴费项目'); return; }
    openPayMethod('一键批量缴费（' + ids.length + ' 项）', function (method) {
        doPay(ids, CUR_VISIT, method);
    });
}

/* 批量缴费 */
function batchPay() {
    var ids = [];
    document.querySelectorAll('.batchPay:checked').forEach(function (c) { ids.push(c.value); });
    if (!ids.length) { Clinic.toast.warning('请先勾选要缴费的项目'); return; }
    openPayMethod('批量缴费（' + ids.length + ' 项）', function (method) {
        doPay(ids, CUR_VISIT, method);
    });
}

function doPay(ids, visitId, method) {
    Clinic.ajax('/api/cashier', { action: 'pay_orders', order_ids: JSON.stringify(ids), method: method }, {
        loading: true,
        onSuccess: function (json) {
            Clinic.toast.success(json.msg + '，合计 ¥' + parseFloat(json.data.total).toFixed(2) + '（' + method + '）');
            // 批量缴费合并为一张凭条（同 payment_no，缴费流水号展示在凭条上）
            Clinic.print.load('/api/print?action=payment&payment_id=' + json.data.payment_id, null, 'ticket');
            loadDetail(visitId || CUR_VISIT);
        },
    });
}

function toggleAll() {
    var all = document.getElementById('batchAll').checked;
    document.querySelectorAll('.batchPay').forEach(function (c) { c.checked = all; });
    updateBatchCount();
}
function updateBatchCount() {
    var el = document.getElementById('batchCount');
    if (el) el.textContent = document.querySelectorAll('.batchPay:checked').length;
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('batchPay')) updateBatchCount();
});

/* 批次详情：弹窗展示该凭条全部项目 + 每项目执行状态流程（每行一个项目） */
function showBatchDetail(paymentNo) {
    Clinic.get('/api/cashier?action=payment_batch_detail&payment_no=' + encodeURIComponent(paymentNo), null, {
        onSuccess: function (json) {
            var d = json.data || {};
            var orders = d.orders || [];
            var rows = '';
            var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
            var statusMap = {
                open: ['badge-warning', '待缴费'],
                paid: ['badge-primary', '已缴费'],
                registered: ['badge-info', '已登记'],
                dispensing: ['badge-warning', '发药中'],
                dispensed: ['badge-success', '已发药'],
                done: ['badge-success', '已完成'],
                rejected: ['badge-danger', '已驳回'],
                refunded: ['badge-gray', '已退费'],
                cancelled: ['badge-gray', '已取消'],
            };
            orders.forEach(function (o) {
                rows += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
                    '<div class="fs-13 fw-600">' + (typeNames[o.order_type] || '') + ' ' + Clinic.escHtml(o.order_no) +
                    ' ｜ 开单医生 ' + Clinic.escHtml(o.doctor_name) + ' ｜ ¥' + parseFloat(o.total).toFixed(2) + '</div>';
                // 订单级流程（横向进度：开单→缴费→执行→完成）
                var steps = (o.flow || []).map(function (s) {
                    var cls = s.done ? 'var(--success)' : 'var(--border)';
                    if (s.rejected) cls = 'var(--danger)';
                    return '<span style="color:' + cls + ';font-size:12px;white-space:nowrap">' +
                        (s.done ? '✓ ' : '○ ') + Clinic.escHtml(s.label) +
                        (s.operator ? '（' + Clinic.escHtml(s.operator) + '）' : '') + '</span>';
                }).join('<span style="color:var(--border)"> → </span>');
                rows += '<div style="margin:6px 0;overflow-x:auto;white-space:nowrap">' + steps + '</div>';
                // 项目明细（每项目一行）
                (o.items || []).forEach(function (it) {
                    var st = statusMap[it.status] || ['badge-gray', it.status || ''];
                    rows += '<div class="flex-between" style="padding:4px 0;border-top:1px dashed var(--border)">' +
                        '<span class="fs-13">· ' + Clinic.escHtml(it.name) + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</span>' +
                        '<span><span class="badge ' + st[0] + '" style="font-size:11px">' + st[1] + '</span>' +
                        (it.executed_by ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(it.executed_by) + '</span>' : '') + '</span></div>';
                });
                rows += '</div>';
            });
            Clinic.modal.open(
                '<div class="fs-13 fw-700 mb-4">缴费凭条流水号：' + Clinic.escHtml(d.payment_no) + '</div>' +
                '<div class="fs-12 text-muted mb-8">共 ' + orders.length + ' 张开单，以下为全部缴费项目与执行进度</div>' + rows,
                { title: '🧾 缴费凭条详情', size: 'modal-lg' }
            );
        },
    });
}

/* ---------- 退费 ---------- */
function refundOrder(orderId) {
    var reason = prompt('请填写退费原因：', '');
    if (reason === null) return;
    Clinic.modal.confirm('确认退费？仅限未使用的项目（检验未登记、检查未登记、药房未发药、处置未执行）。', function () {
        Clinic.ajax('/api/cashier', { action: 'refund_order', order_id: orderId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDetail(CUR_VISIT);
            },
        });
    }, { title: '退费确认', okText: '确认退费' });
}

/* 整单退费：同缴费批次（同一缴费凭条）的全部项目一起退。
   优化7：先检测批次项目状态——全部未执行（仅 paid）可直接退；
   存在已执行（已登记/已检查/已发药/患者已就诊）需先提交退费申请，
   经开单医生/检验/影像/药房/护士站全部同意后方可执行退费。 */
function refundBatch(paymentNo) {
    Clinic.get('/api/refund?action=check&payment_no=' + encodeURIComponent(paymentNo), null, {
        onSuccess: function (json) {
            var d = json.data || {};
            if (d.pending_request_id) {
                Clinic.modal.open('该缴费批次已有待审批的退费申请，请等待相关人员审批通过后再执行退费。',
                    { title: '退费申请进行中', size: 'modal-sm', buttons: [{ text: '知道了', cls: 'btn-primary' }] });
                return;
            }
            if (d.all_paid) {
                // 全部未执行 → 直接整单退费
                var reason = prompt('该凭条包含同批次多张开单，需整单退费。请填写退费原因：', '');
                if (reason === null) return;
                Clinic.modal.confirm('确认整单退费？该缴费凭条（流水号 ' + paymentNo + '）上的全部项目将一起退费。', function () {
                    Clinic.ajax('/api/cashier', { action: 'refund_batch', payment_no: paymentNo, reason: reason }, {
                        onSuccess: function (json) {
                            Clinic.toast.success(json.msg);
                            loadDetail(CUR_VISIT);
                        },
                    });
                }, { title: '整单退费', okText: '确认整单退费' });
                return;
            }
            // 存在已执行项目 → 走退费申请审批流
            var blocks = (d.blocked || []).map(function (b) {
                return '· ' + Clinic.escHtml(b.name) + '（' + Clinic.escHtml(b.status) + '）';
            }).join('<br>');
            var html =
                '<div class="fs-13" style="background:var(--danger-soft,rgba(239,68,68,.08));border:1px solid var(--danger,#ef4444);color:var(--danger,#ef4444);border-radius:8px;padding:10px 12px;margin-bottom:10px">' +
                '⚠️ 该凭条存在已开始执行的项目，无法直接退费：<br>' + blocks +
                '<div class="fs-12 mt-4" style="color:var(--text-muted)">将提交退费申请并通知开单医生/检验/影像/药房/护士站审批，全部同意后方可退费。</div></div>' +
                '<div class="form-group"><label class="form-label">退费理由（可选）</label>' +
                '<textarea class="textarea" id="rfReason" rows="2" placeholder="如：患者需转院，已执行的检查项目申请退费"></textarea></div>';
            Clinic.modal.open(html, {
                title: '提交退费申请（需多方审批）',
                size: 'modal-md',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    {
                        text: '📨 提交退费申请', cls: 'btn-primary', autoClose: false,
                        onClick: function () {
                            var reason = (document.getElementById('rfReason') || {}).value || '';
                            Clinic.ajax('/api/refund', { action: 'apply', payment_no: paymentNo, reason: reason.trim() }, {
                                onSuccess: function (json) {
                                    Clinic.toast.success(json.msg);
                                    Clinic.modal.close();
                                    loadDetail(CUR_VISIT);
                                },
                            });
                        },
                    },
                ],
            });
        },
    });
}

/* ==================== 支付方式选择（优化6，公共组件 Clinic.payMethod） ==================== */
function openPayMethod(title, onDone) {
    Clinic.payMethod.open(title, onDone);
}
</script>
