<?php
Router::title('护士站首页');
?>
<div class="page-head">
    <div><div class="page-title">🏠 护士站首页</div><div class="page-desc">今日处置执行概览</div></div>
    <div class="flex gap-8"><a class="btn btn-primary btn-sm" href="/nurse/dashboard">💉 进入护士工作站</a></div>
</div>
<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日处置执行数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待执行处置</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日处置费用（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">处置项目总数</div></div>
</div>
<div class="card"><div class="card-title">近 7 天处置执行趋势</div><div id="chartTrend"></div></div>
<div class="flex gap-12">
    <div class="card" style="flex:1"><div class="card-title">快速入口</div><div class="flex gap-8" style="flex-wrap:wrap">
        <a class="btn btn-outline btn-sm" href="/nurse/dashboard">💉 护士工作站</a>
        <a class="btn btn-outline btn-sm" href="/messages">💬 站内消息</a>
    </div></div>
    <div class="card" style="flex:1"><div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            1. 缴费后的处置 / 医嘱在【护士工作站】中执行<br>
            2. 生命体征录入后医生端实时同步<br>
            3. 静脉输液等途径的处方会自动标记「护士站执行」
        </div>
    </div>
</div>
<script>
Clinic.get('/api/nurse?action=home_stats', null, { onSuccess: function (json) {
    var d = json.data, kpi = d.kpi;
    var cards = [['today_done','今日处置执行数'],['pending_exec','待执行处置'],['today_fee','今日处置费用（元）'],['disp_total','处置项目总数']];
    document.getElementById('statsBox').innerHTML = cards.map(function (c) {
        return '<div class="stat-card"><div class="stat-num" style="color:var(--primary)">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
    }).join('');
    Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '处置执行', data: d.trend.data, color: '#409eff' }] });
}});
</script>