<?php
/**
 * refund_approve.php — 退费申请审批页
 * 说明：收费员发起退费申请后，开单医生 / 检验 / 影像 / 药房 / 护士站
 * 各审批人通过站内消息进入本页，查看患者信息与项目执行状态，
 * 选择「同意退费」或「拒绝」；全部同意后收费员方可执行退费。
 */
Router::title('退费申请审批');
?>
<div class="page-head">
    <div><div class="page-title">🧾 退费申请审批</div><div class="page-desc">核对患者与项目执行状态后确认是否同意退费</div></div>
</div>
<div id="reqBox"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var REQ_ID = new URLSearchParams(location.search).get('id') || '';
var MY_ROLE = document.body.getAttribute('data-role') || '';

function loadReq() {
    Clinic.get('/api/refund?action=detail&id=' + encodeURIComponent(REQ_ID), null, {
        onSuccess: function (json) {
            var d = json.data || {};
            var r = d.request || {}, approvals = d.approvals || [], orders = d.orders || [];
            var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
            var statusMap = {
                open: ['badge-warning', '待缴费'], paid: ['badge-primary', '已缴费'],
                registered: ['badge-info', '已登记'], dispensing: ['badge-warning', '发药中'],
                dispensed: ['badge-success', '已发药'], done: ['badge-success', '已完成'],
                rejected: ['badge-danger', '已驳回'], refunded: ['badge-gray', '已退费'], cancelled: ['badge-gray', '已取消'],
            };
            var visitStatusMap = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '已诊毕', refunded: '已退费', cancelled: '已取消' };
            // 患者信息
            var html =
                '<div class="card" style="padding:14px;margin-bottom:12px">' +
                '<div class="flex-between"><div class="fw-700 fs-16">' + Clinic.escHtml(r.patient.name) +
                ' <span class="fs-12 text-muted fw-400">患者ID ' + Clinic.escHtml(r.patient.patient_no) + ' ｜ 流水号 ' + Clinic.escHtml(r.patient.flow_no) + '</span></div>' +
                '<span class="badge badge-warning">' + (visitStatusMap[r.patient.visit_status] || r.patient.visit_status) + '</span></div>' +
                '<div class="fs-13 mt-4">缴费批次：' + Clinic.escHtml(r.payment_no) + '</div>' +
                '<div class="fs-13 text-muted mt-4">申请时间：' + Clinic.escHtml(r.created_at) + '</div>' +
                (r.reason ? '<div class="fs-13 mt-4">申请理由：' + Clinic.escHtml(r.reason) + '</div>' : '') +
                '<div class="mt-4">状态：' +
                (r.status === 'approved' ? '<span class="badge badge-success">已全部同意</span>' :
                    (r.status === 'rejected' ? '<span class="badge badge-danger">已拒绝</span>' : '<span class="badge badge-warning">待审批</span>')) + '</div></div>';
            // 审批进度
            html += '<div class="card" style="padding:14px;margin-bottom:12px"><div class="fs-14 fw-700 mb-8">审批进度</div>';
            approvals.forEach(function (a) {
                var cls = a.verdict === 'approve' ? 'badge-success' : (a.verdict === 'reject' ? 'badge-danger' : 'badge-gray');
                var txt = a.verdict === 'approve' ? '已同意' : (a.verdict === 'reject' ? '已拒绝' : '待审批');
                html += '<div class="flex-between" style="padding:6px 0;border-top:1px dashed var(--border)">' +
                    '<span class="fs-13">' + Clinic.escHtml(a.user_name) + ' <span class="fs-12 text-muted">（' + Clinic.escHtml(a.role) + '）</span>' +
                    (a.note ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(a.note) + '</span>' : '') + '</span>' +
                    '<span><span class="badge ' + cls + '" style="font-size:11px">' + txt + '</span></span></div>';
            });
            html += '</div>';
            // 项目执行状态
            html += '<div class="card" style="padding:14px;margin-bottom:12px"><div class="fs-14 fw-700 mb-8">项目执行状态</div>';
            orders.forEach(function (o) {
                html += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
                    '<div class="fs-13 fw-600">' + (typeNames[o.order_type] || '') + ' ' + Clinic.escHtml(o.order_no) +
                    ' ｜ 开单医生 ' + Clinic.escHtml(o.doctor_name) + '</div>';
                var steps = (o.flow || []).map(function (s) {
                    var refund = s.refunded;
                    var cls = refund ? 'var(--danger)' : (s.done ? 'var(--success)' : 'var(--border)');
                    if (s.rejected) cls = 'var(--danger)';
                    return '<span style="color:' + cls + ';font-size:12px;white-space:nowrap">' +
                        (refund || s.rejected ? '✕ ' : (s.done ? '✓ ' : '○ ')) + Clinic.escHtml(s.label) + '</span>';
                }).join('<span style="color:var(--border)"> → </span>');
                html += '<div style="margin:6px 0;overflow-x:auto;white-space:nowrap">' + steps + '</div>';
                (o.items || []).forEach(function (it) {
                    var st = statusMap[it.status] || ['badge-gray', it.status || ''];
                    html += '<div class="flex-between" style="padding:4px 0;border-top:1px dashed var(--border)">' +
                        '<span class="fs-13">· ' + Clinic.escHtml(it.name) + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</span>' +
                        '<span><span class="badge ' + st[0] + '" style="font-size:11px">' + st[1] + '</span>' +
                        (it.executed_by ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(it.executed_by) + '</span>' : '') + '</span></div>';
                });
                html += '</div>';
            });
            html += '</div>';
            // 审批判定：审批人按 role+user 精确匹配（详情已返回 user_name，按当前登录名比对）
            var myName = document.body.getAttribute('data-name') || '';
            var canAct = r.status === 'pending' && approvals.some(function (a) { return a.user_name === myName; });
            if (r.status === 'pending' && (canAct || MY_ROLE === 'admin')) {
                html += '<div class="card" style="padding:14px">' +
                    '<div class="fs-14 fw-700 mb-8">我的审批</div>' +
                    '<div class="form-group"><label class="form-label">意见（可选）</label>' +
                    '<textarea class="textarea" id="apNote" rows="2" placeholder="如：患者已完成该检查，同意退费"></textarea></div>' +
                    '<div class="flex gap-8 mt-8">' +
                    '<button class="btn btn-danger" onclick="doVote(\'reject\')">✕ 拒绝退费</button>' +
                    '<button class="btn btn-primary" onclick="doVote(\'approve\')">✓ 同意退费</button></div>' +
                    (MY_ROLE === 'admin' ? '<div class="fs-12 text-muted mt-4">管理员代审</div>' : '') +
                    '</div>';
            } else if (r.status === 'pending') {
                html += '<div class="card" style="padding:14px"><div class="fs-13 text-muted">您不是该申请的审批人，无法操作。</div></div>';
            }
            document.getElementById('reqBox').innerHTML = html;
        },
        onError: function () {
            document.getElementById('reqBox').innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>退费申请不存在或已失效</div>';
        },
    });
}

function doVote(verdict) {
    var note = (document.getElementById('apNote') || {}).value || '';
    Clinic.ajax('/api/refund', { action: 'approve', id: REQ_ID, verdict: verdict, note: note.trim() }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            loadReq();
        },
    });
}

loadReq();
</script>
