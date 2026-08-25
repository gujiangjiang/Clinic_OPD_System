<?php
Router::title('收费处首页');
?>
<div class="page-head">
    <div><div class="page-title">🏠 收费处首页</div><div class="page-desc">今日挂号收费概览</div></div>
    <div class="flex gap-8"><a class="btn btn-primary btn-sm" href="/cashier/register">🎫 进入挂号收费</a></div>
</div>
<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日挂号数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日挂号费收入（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日缴费金额（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日退费金额（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日待就诊</div></div>
</div>
<div class="card"><div class="card-title">近 7 天缴费收入趋势</div><div id="chartTrend"></div></div>
<div class="card"><div class="card-title">快速入口</div><div class="flex gap-8" style="flex-wrap:wrap">
    <a class="btn btn-outline btn-sm" href="/cashier/register">🎫 挂号收费</a>
    <a class="btn btn-outline btn-sm" href="/cashier/regmanage">📋 挂号管理</a>
    <a class="btn btn-outline btn-sm" href="/cashier/paymanage">💳 缴费与退费</a>
    <a class="btn btn-outline btn-sm" href="/messages">💬 站内消息</a>
</div></div>
<script>
Clinic.get('/api/cashier?action=home_stats', null, { onSuccess: function (json) {
    var d = json.data, kpi = d.kpi;
    var cards = [['reg_today','今日挂号数'],['reg_fee','今日挂号费收入（元）'],['paid_today','今日缴费金额（元）'],
        ['refund_today','今日退费金额（元）'],['waiting','今日待就诊']];
    document.getElementById('statsBox').innerHTML = cards.map(function (c) {
        var color = c[0] === 'refund_today' ? 'var(--danger)' : 'var(--primary)';
        return '<div class="stat-card"><div class="stat-num" style="color:' + color + '">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
    }).join('');
    Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '缴费收入', data: d.trend.data, color: '#409eff' }] });
}});
</script>