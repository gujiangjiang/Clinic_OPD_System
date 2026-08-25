<?php
Router::title('药房首页');
?>
<div class="page-head">
    <div><div class="page-title">🏠 药房首页</div><div class="page-desc">今日发药与药品库存概览</div></div>
    <div class="flex gap-8"><a class="btn btn-primary btn-sm" href="/pharmacy/dashboard">💊 进入药房工作台</a></div>
</div>
<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">药品总数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日发药数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日发药金额（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待发药处方</div></div>
    <div class="stat-card"><div class="stat-num" style="color:var(--danger)">—</div><div class="stat-label">低库存药品</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待审核药品</div></div>
</div>
<div class="card"><div class="card-title">近 7 天发药量趋势</div><div id="chartTrend"></div></div>
<div class="flex gap-12">
    <div class="card" style="flex:1"><div class="card-title">快速入口</div><div class="flex gap-8" style="flex-wrap:wrap">
        <a class="btn btn-outline btn-sm" href="/pharmacy/dashboard">💊 药房工作台</a>
        <a class="btn btn-outline btn-sm" href="/admin/drugs">📋 药品信息</a>
        <a class="btn btn-outline btn-sm" href="/admin/drugsettings">📦 药品设置</a>
        <a class="btn btn-outline btn-sm" href="/messages">💬 站内消息</a>
    </div></div>
    <div class="card" style="flex:1"><div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            1. 缴费后的处方在【药房工作台】→「待发药」中处理<br>
            2. 发药后药品库存自动扣减，可在「库存管理」中出入库<br>
            3. 低库存药品（≤50）首页红色高亮，请及时补货<br>
            4. 新增药品 / 药品设置项需管理员审核后生效
        </div>
    </div>
</div>
<script>
Clinic.get('/api/pharmacy?action=home_stats', null, { onSuccess: function (json) {
    var d = json.data, kpi = d.kpi;
    var cards = [['drug_total','药品总数'],['today_disp','今日发药数'],['today_fee','今日发药金额（元）'],
        ['pending_rx','待发药处方'],['low_stock','低库存药品'],['pending_audit','待审核药品']];
    document.getElementById('statsBox').innerHTML = cards.map(function (c) {
        var color = c[0] === 'low_stock' ? 'var(--danger)' : 'var(--primary)';
        return '<div class="stat-card"><div class="stat-num" style="color:' + color + '">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
    }).join('');
    Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '发药量', data: d.trend.data, color: '#409eff' }] });
}});
</script>