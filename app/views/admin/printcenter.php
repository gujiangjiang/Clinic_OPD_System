<?php
/**
 * admin/printcenter.php — 统一打印中心
 * 说明：集中补打所有单据：挂号凭条、门急诊电子病历、
 * 处方单、检验申请单、检查申请单、处置单、检验检查报告、诊断证明。
 */
Router::title('打印中心');
?>
<div class="page-head">
    <div><div class="page-title">🖨️ 统一打印中心</div><div class="page-desc">集中补打挂号凭条 / 电子病历 / 申请单 / 处方 / 报告 / 诊断证明</div></div>
</div>

<div class="card">
    <div class="flex gap-8">
        <input class="input" id="pcKw" placeholder="输入患者ID / 门诊流水号 / 身份证号" style="flex:1" autocomplete="off" onkeydown="if(event.key==='Enter')searchVisit()">
        <button class="btn btn-primary btn-sm" onclick="searchVisit()">查询</button>
    </div>
</div>

<div id="pcList" class="mt-12"></div>
<div id="pcItems" class="mt-12"></div>

<script>
function searchVisit() {
    var kw = document.getElementById('pcKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入查询关键字'); return; }
    Clinic.get('/api/cashier?action=visit_search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('pcList');
            document.getElementById('pcItems').innerHTML = '';
            if (!list.length) {
                box.innerHTML = '<div class="empty">未检索到就诊记录</div>';
                return;
            }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">共 ' + list.length + ' 次就诊，点击选择：</div>' +
                list.map(function (g) {
                    var v = g.visit, p = g.patient;
                    return '<div class="dd-item" style="border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:8px;cursor:pointer" onclick="showPrintItems(\'' + v.id + '\')">' +
                        '<div class="flex-between"><span class="fw-600">' + (p ? p.name : '') + '</span>' +
                        '<span class="fs-12 text-muted">' + v.flow_no + ' ｜ ' + v.first_dept_name + ' ｜ ' + v.registered_at + '</span></div></div>';
                }).join('');
        },
    });
}

function showPrintItems(visitId) {
    Clinic.get('/api/admin?action=print_items&visit_id=' + visitId, null, {
        onSuccess: function (json) {
            document.getElementById('pcItems').innerHTML = json.data.html;
        },
    });
}
</script>
