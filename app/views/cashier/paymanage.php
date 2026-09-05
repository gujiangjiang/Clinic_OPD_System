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
<style>
/* 缴费管理：内容区 flex 纵向布局，两栏自适应填满剩余视口，各自内部滚动 */
.content { display: flex; flex-direction: column; }
.paymgr-layout { flex: 1; min-height: 0; }
</style>
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
/* ---------- 查询就诊 ----------
 * @param {boolean} keepDetail 为 true 时仅刷新左侧列表、保留右侧详情
 *   （退费/缴费等操作后调用：右侧已重新加载，无需重置回空态）
 */
function searchVisits(keepDetail) {
    var kw = document.getElementById('payKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入查询关键字'); return; }
    Clinic.get('/api/cashier?action=visit_search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('visitList');
            // 仅首次查询/主动搜索时重置右侧空态；keepDetail=true（退费后刷新）保留右侧
            if (!keepDetail) {
                document.getElementById('visitDetail').innerHTML = '<div class="paymgr-empty">👈 点击左侧就诊记录，查看该次就诊的缴费明细与退费操作</div>';
            }
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未检索到就诊记录</div>';
                return;
            }
            // 保持当前选中项高亮（退费后刷新左侧时选中态不丢失）
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共检索到 ' + list.length + ' 次就诊：</div>' +
                list.map(function (g) {
                    var v = g.visit, p = g.patient;
                    var active = (CUR_VISIT && String(CUR_VISIT) === String(v.id)) ? ' active' : '';
                    return '<div class="paymgr-item' + active + '" onclick="selectVisit(this,\'' + v.id + '\')">' +
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
            // 缓存未缴费明细（模态框渲染用）
            UNPAID_DATA = json.data.unpaid || [];
        },
    });
}

/* ==================== 未缴费项目模态框（优化：明细不铺在右侧，点开看并缴费） ==================== */
var UNPAID_DATA = [];

function openUnpaidModal() {
    if (!UNPAID_DATA.length) { Clinic.toast.info('该次就诊暂无可缴费项目'); return; }
    var total = 0;
    var visitPending = false;
    UNPAID_DATA.forEach(function (u) { total += parseFloat(u.amount) || 0; });
    var rows = UNPAID_DATA.map(function (u, i) {
        if (u.kind === 'visit') visitPending = true;
        var itemsTxt = (u.items || []).map(function (it) {
            return '· ' + Clinic.escHtml(it.item_name) + (it.quantity > 1 ? ' ×' + it.quantity : '') + ' ￥' + parseFloat(it.price * it.quantity).toFixed(2);
        }).join('<br>');
        return '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">' +
            '<div class="flex-between">' +
            '<label class="flex gap-4 fs-13" style="cursor:pointer;flex:1;min-width:0">' +
            '<input type="checkbox" class="unpaidChk" value="' + (u.kind === 'visit' ? 'visit' : u.oid) + '" data-kind="' + u.kind + '" onchange="updateUnpaidCount()" checked>' +
            '<span class="ellipsis">' + (u.kind === 'visit' ? '🎫 ' : '') + Clinic.escHtml(u.name) + (u.doctor ? ' <span class="fs-12 text-muted">｜ ' + Clinic.escHtml(u.doctor) + '</span>' : '') + '</span></label>' +
            '<span class="fs-13 fw-600">¥' + parseFloat(u.amount).toFixed(2) + '</span></div>' +
            (itemsTxt ? '<div class="fs-12 text-muted mt-4" style="padding-left:24px">' + itemsTxt + '</div>' : '') +
            '</div>';
    }).join('');
    var body =
        '<div class="fs-13 text-muted mb-8">共 <b>' + UNPAID_DATA.length + '</b> 项未缴费，勾选后点击下方按钮缴费（合并为一张缴费凭条）。</div>' +
        rows +
        '<div class="flex gap-8 mt-8" style="align-items:center">' +
        '<label class="flex gap-4 fs-13" style="cursor:pointer"><input type="checkbox" id="unpaidAll" checked onchange="toggleUnpaidAll()"> 全选</label>' +
        '<span class="fs-12 text-muted">合计 <b class="fs-14">¥<span id="unpaidTotal">' + total.toFixed(2) + '</span></b></span></div>' +
        '<div class="flex gap-8 mt-8">' +
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-warning" id="unpaidPayAll" onclick="submitUnpaidPay(\'all\')">💰 一键全部缴费</button>' +
        '<button type="button" class="btn btn-success" id="unpaidPayChecked" onclick="submitUnpaidPay(\'checked\')">批量缴费（已选 <span id="unpaidCount">0</span>）</button>' +
        '</div>';
    Clinic.modal.open(body, { title: '💳 未缴费项目（' + UNPAID_DATA.length + ' 项）', size: 'modal-md' });
    updateUnpaidCount();
}

function toggleUnpaidAll() {
    var all = document.getElementById('unpaidAll').checked;
    document.querySelectorAll('.unpaidChk').forEach(function (c) { c.checked = all; });
    updateUnpaidCount();
}

function updateUnpaidCount() {
    var n = 0, total = 0;
    document.querySelectorAll('.unpaidChk:checked').forEach(function (c) {
        n++;
        var idx = Array.prototype.indexOf.call(document.querySelectorAll('.unpaidChk'), c);
        total += parseFloat(UNPAID_DATA[idx] ? (UNPAID_DATA[idx].amount || 0) : 0);
    });
    var cnt = document.getElementById('unpaidCount');
    if (cnt) cnt.textContent = n;
    var tot = document.getElementById('unpaidTotal');
    if (tot) tot.textContent = total.toFixed(2);
    // 未勾选任何项目 → 批量缴费按钮禁用；一键全部缴费不受影响
    var chkBtn = document.getElementById('unpaidPayChecked');
    if (chkBtn) {
        chkBtn.disabled = n === 0;
        chkBtn.style.opacity = n === 0 ? '.5' : '1';
        chkBtn.style.cursor = n === 0 ? 'not-allowed' : 'pointer';
    }
}

function submitUnpaidPay(mode) {
    // 收集选中项：订单走 pay_orders（可批量合并凭条），挂号费走 pay_visit
    var ids = [];
    var needVisit = false;
    document.querySelectorAll('.unpaidChk').forEach(function (c) {
        var checked = (mode === 'all') ? true : c.checked;
        if (!checked) return;
        if (c.getAttribute('data-kind') === 'order') ids.push(c.value);
        else if (c.getAttribute('data-kind') === 'visit') needVisit = true;
    });
    if (!ids.length && !needVisit) { Clinic.toast.warning('请至少勾选一项'); return; }
    // 仅挂号费未缴（无订单）：直接缴挂号费
    if (!ids.length && needVisit) {
        Clinic.ajax('/api/cashier', { action: 'pay_visit', visit_id: CUR_VISIT, method: '现金' }, {
            loading: true,
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.print.load('/api/print?action=payment&payment_id=' + json.data.payment_id, null, 'ticket');
                Clinic.modal.close();
                loadDetail(CUR_VISIT);
            },
        });
        return;
    }
    openPayMethod('批量缴费（' + ids.length + ' 项' + (needVisit ? ' + 挂号费' : '') + '）', function (method) {
        doPay(ids, CUR_VISIT, method);
        if (needVisit) {
            // 挂号费随后单独缴（订单凭条已打，挂号费凭条单独打印）
            Clinic.ajax('/api/cashier', { action: 'pay_visit', visit_id: CUR_VISIT, method: method }, {
                onSuccess: function (json) {
                    Clinic.print.load('/api/print?action=payment&payment_id=' + json.data.payment_id, null, 'ticket');
                },
            });
        }
        Clinic.modal.close();
    });
}

/* ==================== 缴费（挂号费/开单共用 doPay） ==================== */

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

/* 批次详情：弹窗展示该凭条全部项目 + 每项目独立执行进度（每行一个项目） */
function showBatchDetail(paymentNo) {
    Clinic.get('/api/cashier?action=payment_batch_detail&payment_no=' + encodeURIComponent(paymentNo), null, {
        onSuccess: function (json) {
            var d = json.data || {};
            var orders = d.orders || [];
            var head = d.head || {};
            var refund = d.refund || null;
            var rows = '';
            var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
            // 每项目独立进度渲染（只显示节点，不显示操作人姓名；退费节点红色）
            var flowText = function (flow) {
                if (!flow || !flow.length) return '';
                return '<span class="fs-11" style="white-space:nowrap">' +
                    flow.map(function (s) {
                        var color = s.refunded ? 'var(--danger)' : (s.done ? 'var(--success)' : 'var(--border)');
                        if (s.rejected) color = 'var(--danger)';
                        return '<span style="color:' + color + '">' +
                            (s.refunded ? '✕' : (s.done ? '✓' : '○')) + ' ' + Clinic.escHtml(s.label) + '</span>';
                    }).join('<span style="color:var(--border)"> → </span>') +
                    '</span>';
            };
            orders.forEach(function (o) {
                rows += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
                    '<div class="flex-between">' +
                    '<div class="fs-13 fw-600">' + (typeNames[o.order_type] || '') + ' ' + Clinic.escHtml(o.order_no) +
                    '<span class="fs-12 text-muted fw-400"> ｜ 开单医生 ' + Clinic.escHtml(o.doctor_name) + '</span></div>' +
                    '<span class="fs-13 fw-600">¥' + parseFloat(o.total).toFixed(2) + '</span></div>';
                // 项目明细（每项目一行）：名称占左 2/3，进度靠右侧约 1/3 分隔线靠左对齐
                (o.items || []).forEach(function (it) {
                    rows += '<div style="display:flex;padding:5px 0;border-top:1px dashed var(--border);align-items:center">' +
                        '<span class="fs-13" style="flex:2;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">· ' + Clinic.escHtml(it.name) + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</span>' +
                        '<span style="flex:1;min-width:0;padding-left:10px;border-left:1px solid var(--border);overflow-x:auto">' + flowText(it.flow) + '</span></div>';
                });
                rows += '</div>';
            });
            // 凭条头信息：收费员 / 收费时间 / 收费方式；退费后保留并追加退费行
            var headHtml =
                '<div class="fs-13 fw-700 mb-2">缴费凭条流水号：' + Clinic.escHtml(d.payment_no) + '</div>' +
                '<div class="fs-12 text-muted mb-1">收费时间 ' + Clinic.escHtml((head.created_at || '').substring(0, 19)) +
                ' ｜ 收费方式 ' + Clinic.escHtml(head.method || '现金') + ' ｜ 收费员 ' + Clinic.escHtml(head.cashier_name || '') + '</div>' +
                (refund
                    ? '<div class="fs-12 mb-1" style="color:var(--danger)">✕ 退费时间 ' + Clinic.escHtml((refund.created_at || '').substring(0, 19)) +
                    ' ｜ 退费员 ' + Clinic.escHtml(refund.cashier_name || '') +
                    (refund.reason ? ' ｜ 理由 ' + Clinic.escHtml(refund.reason) : '') + '</div>'
                    : '') +
                '<div class="fs-12 text-muted mb-6">共 ' + orders.length + ' 张开单，以下为全部缴费项目与各自执行进度</div>';
            Clinic.modal.open(headHtml + rows, { title: '🧾 缴费凭条详情', size: 'modal-lg' });
        },
    });
}

/* 退费 / 取消挂号（挂号费退费，regmanage 与 paymanage 共用逻辑） */
function cancelVisit(visitId, status) {
    var tip = status === 'paid' ? '确定为该挂号退费？退费后该患者可在同一首次科室重新挂号。' : '确定取消该挂号？';
    Clinic.modal.confirm(tip, function () {
        var reason = prompt('请填写' + (status === 'paid' ? '退费' : '取消') + '原因（可留空）：', '');
        if (reason === null) return;
        Clinic.ajax('/api/cashier', { action: 'cancel_visit', visit_id: visitId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadDetail(CUR_VISIT);
                // 退费后左侧就诊列表状态同步刷新（原仅刷新右侧详情，左侧仍显示旧状态）
                if (document.getElementById('payKw').value.trim()) searchVisits(true);
            },
        });
    }, { title: status === 'paid' ? '退费确认' : '取消确认' });
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
                if (document.getElementById('payKw').value.trim()) searchVisits(true);
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
                            // 整单退费后左侧就诊状态同步刷新（原仅刷新右侧，凭条仍显示退费/补打按钮）
                            if (document.getElementById('payKw').value.trim()) searchVisits(true);
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
