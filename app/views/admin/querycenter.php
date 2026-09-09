<?php
/**
 * admin/querycenter.php — 管理员查询中心
 * 说明：全院数据查询入口。目前包含「危急值查询」——按医生端列表呈现
 * 全院危急值记录（发起科室/发起时间/接收医生/处理情况/处理时长），
 * 点击 → 只读详情查看完整处理过程；后续可在此扩展更多查询子项。
 */
Router::title('查询中心');
?>
<div class="page-head">
    <div><div class="page-title">🔍 查询中心</div><div class="page-desc">全院业务数据查询与溯源</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" onclick="qcTab('critical')">🚨 危急值查询</button>
    <button class="btn btn-outline btn-sm" onclick="qcTab('more')">更多子项（规划中）</button>
</div>

<div id="qcCritical"></div>
<div id="qcMore" style="display:none"><div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">📊</div>更多查询子项规划中，敬请期待</div></div></div>

<script>
function qcTab(tab) {
    document.getElementById('qcCritical').style.display = tab === 'critical' ? '' : 'none';
    document.getElementById('qcMore').style.display = tab === 'more' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
    Clinic.critical.initListPage({
        role: 'admin',
        container: 'qcCritical',
        listBody: 'critListBody',
        totalEl: 'critTotal',
        footEl: 'critMore',
        defaultFrom: '<?php echo date('Y-m-d', strtotime('-2 days')); ?>',
        defaultTo: '<?php echo date('Y-m-d'); ?>',
    });
});
</script>
