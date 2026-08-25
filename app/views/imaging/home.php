<?php
Router::title('影像科首页');
?>
<div class="page-head">
    <div><div class="page-title">🏠 影像科首页</div><div class="page-desc">今日检查工作概览</div></div>
    <div class="flex gap-8"><a class="btn btn-primary btn-sm" href="/imaging/dashboard">🩻 进入影像科工作台</a></div>
</div>
<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日检查量</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日检查费用（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待登记</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待出报告</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">检查项目总数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待审核项目</div></div>
</div>
<div class="card"><div class="card-title">近 7 天检查量趋势</div><div id="chartTrend"></div></div>
<div class="flex gap-12">
    <div class="card" style="flex:1"><div class="card-title">快速入口</div><div class="flex gap-8" style="flex-wrap:wrap">
        <a class="btn btn-outline btn-sm" href="/imaging/dashboard">🩻 影像科工作台</a>
        <a class="btn btn-outline btn-sm" href="/admin/examitems">📋 检查管理</a>
        <a class="btn btn-outline btn-sm" href="/messages">💬 站内消息</a>
    </div></div>
    <div class="card" style="flex:1"><div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            1. 缴费后的检查项目在【影像科工作台】→「待登记」列表中<br>
            2. 登记后进入「待出报告」，填写影像所见与结论后生成报告<br>
            3. 新增检查项目请到【检查管理】提交，需管理员审核后可用<br>
            4. 报告撤回需管理员在审核中心处理
        </div>
    </div>
</div>
<script>
Clinic.get('/api/imaging?action=home_stats', null, { onSuccess: function (json) {
    var d = json.data, kpi = d.kpi;
    var cards = [['today_items','今日检查量'],['today_fee','今日检查费用（元）'],['pending_reg','待登记'],
        ['pending_rep','待出报告'],['item_total','检查项目总数'],['pending_audit','待审核项目']];
    document.getElementById('statsBox').innerHTML = cards.map(function (c) {
        return '<div class="stat-card"><div class="stat-num" style="color:var(--primary)">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
    }).join('');
    Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '检查量', data: d.trend.data, color: '#409eff' }] });
}});
</script>