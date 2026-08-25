<?php
/**
 * doctor/home.php — 医生首页
 * 展示个人今日接诊、开单收入、待办与近7天接诊趋势。
 */
Router::title('医生首页');
?>
<div class="page-head">
    <div><div class="page-title">🏠 医生首页</div><div class="page-desc">个人今日工作概览</div></div>
    <div class="flex gap-8">
        <a class="btn btn-primary btn-sm" href="/doctor/dashboard">🩺 进入医生工作站</a>
    </div>
</div>
<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日接诊人次</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日开单金额（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">药费（处方）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">检验费</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">检查费</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">处置费</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日门诊人次</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">我的待完成病历</div></div>
</div>
<div class="card">
    <div class="card-title">近 7 天接诊趋势</div>
    <div id="chartTrend"></div>
</div>
<div class="flex gap-12">
    <div class="card" style="flex:1">
        <div class="card-title">快速入口</div>
        <div class="flex gap-8" style="flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="/doctor/dashboard">🩺 医生工作站</a>
            <a class="btn btn-outline btn-sm" href="/messages">💬 站内消息</a>
            <a class="btn btn-outline btn-sm" href="/profile">👤 个人信息</a>
        </div>
    </div>
    <div class="card" style="flex:1">
        <div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            1. 接诊前请先在【医生工作站】选择当前科室（多科室医生）<br>
            2. 书写病历需完善主诉 / 现病史 / 初步诊断并保存，方可开单 / 打印 / 开具诊断证明<br>
            3. 开检验 / 检查 / 处置 / 处方请点击病历右侧大纲栏分区「＋」<br>
            4. 同一次挂号可多医生续写接诊，开单项目按医生归档，删除仅限本人
        </div>
    </div>
</div>
<script>
Clinic.get('/api/doctor?action=home_stats', null, {
    onSuccess: function (json) {
        var d = json.data; var kpi = d.kpi;
        var cards = [['today_visits','今日接诊人次'],['total','今日开单金额（元）'],['drug','药费（处方）'],
            ['lab','检验费'],['imaging','检查费'],['procedure','处置费'],['today_reg','今日门诊人次'],['drafts','我的待完成病历']];
        document.getElementById('statsBox').innerHTML = cards.map(function (c) {
            return '<div class="stat-card"><div class="stat-num" style="color:var(--primary)">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
        }).join('');
        Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '接诊人次', data: d.trend.data, color: '#409eff' }] });
    },
});
</script>