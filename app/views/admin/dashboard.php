<?php
/**
 * admin/dashboard.php — 管理员工作台
 * 说明：展示全站核心运营指标（今日挂号/缴费/待审核/库存预警等）。
 */
Router::title('工作台');
?>
<div class="page-head">
    <div><div class="page-title">🏠 工作台</div><div class="page-desc">全站运营概览</div></div>
</div>

<div class="stat-grid" id="statsBox">
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日挂号数</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">今日缴费金额（元）</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">当前候诊患者</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">待审核事项</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">库存预警药品</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">启用科室</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">启用用户</div></div>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label">我的未读消息</div></div>
</div>

<div class="flex gap-12">
    <div class="card" style="flex:1">
        <div class="card-title">快速入口</div>
        <div class="flex gap-8" style="flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="/admin/departments">🏥 科室管理</a>
            <a class="btn btn-outline btn-sm" href="/admin/users">👥 用户管理</a>
            <a class="btn btn-outline btn-sm" href="/admin/labitems">🧪 检验管理</a>
            <a class="btn btn-outline btn-sm" href="/admin/examitems">🩻 检查管理</a>
            <a class="btn btn-outline btn-sm" href="/admin/drugs">💊 药品信息</a>
            <a class="btn btn-outline btn-sm" href="/admin/review">✅ 审核中心</a>
            <a class="btn btn-outline btn-sm" href="/admin/printcenter">🖨️ 打印中心</a>
            <a class="btn btn-outline btn-sm" href="/cashier/register">🎫 挂号收费（体验）</a>
        </div>
    </div>
    <div class="card" style="flex:1">
        <div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            1. 先到【科室管理】添加科室（门诊科室需设置上午/下午号源）<br>
            2. 再到【用户管理】创建医生/护士/检验/影像/药房等账号<br>
            3. 添加检验/检查/药品/处置项目后，在【审核中心】审核通过即可使用<br>
            4. 挂号收费处完成挂号缴费后，各科室工作站即可处理就诊
        </div>
    </div>
</div>

<script>
Clinic.get('/api/admin?action=stats', null, {
    onSuccess: function (json) {
        var d = json.data;
        var labels = [
            ['reg_today', '今日挂号数'], ['revenue', '今日缴费金额（元）'], ['waiting', '当前候诊患者'],
            ['pending_audits', '待审核事项'], ['low_stock', '库存预警药品'], ['dept_count', '启用科室'],
            ['user_count', '启用用户'], ['msg_count', '我的未读消息'],
        ];
        var box = document.getElementById('statsBox');
        box.innerHTML = labels.map(function (l) {
            return '<div class="stat-card"><div class="stat-num" style="color:var(--primary)">' + (d[l[0]] === undefined ? '—' : d[l[0]]) + '</div>' +
                '<div class="stat-label">' + l[1] + '</div></div>';
        }).join('');
    },
});
</script>
