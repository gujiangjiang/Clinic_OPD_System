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
            // 患者信息 + 审批进度 + 项目执行状态（三张卡片）：统一走共享渲染
            // （Clinic.refundDetailHtml，与站内消息弹窗同一实现，间距/流程步骤/退药单位口径一致）
            var html = Clinic.refundDetailHtml(d);
            // 审批判定：审批人按 role+user 精确匹配（详情已返回 user_name，按当前登录名比对）
            var myName = document.body.getAttribute('data-name') || '';
            var canAct = r.status === 'pending' && approvals.some(function (a) { return a.user_name === myName; });
            if (r.status === 'pending' && (canAct || MY_ROLE === 'admin')) {
                html += '<div class="card">' +
                    '<div class="fs-14 fw-700 mb-8">我的审批</div>' +
                    '<div class="form-group"><label class="form-label">意见（可选）</label>' +
                    '<textarea class="textarea" id="apNote" rows="2" placeholder="如：患者已完成该检查，同意退费"></textarea></div>' +
                    '<div class="flex gap-8 mt-8">' +
                    '<button class="btn btn-danger" onclick="doVote(\'reject\')">✕ 拒绝退费</button>' +
                    '<button class="btn btn-primary" onclick="doVote(\'approve\')">✓ 同意退费</button></div>' +
                    (MY_ROLE === 'admin' ? '<div class="fs-12 text-muted mt-4">管理员代审</div>' : '') +
                    '</div>';
            } else if (r.status === 'pending') {
                html += '<div class="card"><div class="fs-13 text-muted">您不是该申请的审批人，无法操作。</div></div>';
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
