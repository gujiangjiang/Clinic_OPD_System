<?php
/**
 * ============================================================
 * includes/role_home.php — 各角色首页共享模板
 * ============================================================
 * 说明：收费处/医生/护士/检验/影像/药房首页结构完全一致
 * （页头 + 统计卡片 + 趋势图 + 快速入口 + 使用提示 + home_stats 脚本），
 * 仅配置不同。本文件统一渲染，消除 6 份重复模板。
 *
 * 调用方式（各角色 home.php）：
 *   require APP_ROOT . '/app/includes/role_home.php';
 *   render_role_home(array(
 *       'title'  => '收费处首页',
 *       'desc'   => '今日挂号收费概览',
 *       'cta'    => array('/cashier/register', '🎫 进入挂号收费'),
 *       'api'    => '/api/cashier?action=home_stats',
 *       'stats'  => array(
 *           array('reg_today', '今日挂号数'),
 *           ...
 *       ),
 *       'colors' => array('refund_today' => 'var(--danger)'), // 可选：特例颜色
 *       'chart'  => array('title' => '近 7 天缴费收入趋势', 'name' => '缴费收入'),
 *       'links'  => array(array('/cashier/register', '🎫 挂号收费'), ...),
 *       'tips'   => array('1. ...', ...),
 *   ));
 * ============================================================ */

function render_role_home($cfg) {
    Router::title($cfg['title']);
    $cta = $cfg['cta'];
    ?>
<div class="page-head">
    <div><div class="page-title">🏠 <?php echo e($cfg['title']); ?></div><div class="page-desc"><?php echo e($cfg['desc']); ?></div></div>
    <div class="flex gap-8"><a class="btn btn-primary btn-sm" href="<?php echo e($cta[0]); ?>"><?php echo $cta[1]; ?></a></div>
</div>
<div class="stat-grid" id="statsBox">
    <?php foreach ($cfg['stats'] as $s) { ?>
    <div class="stat-card"><div class="stat-num">—</div><div class="stat-label"><?php echo e($s[1]); ?></div></div>
    <?php } ?>
</div>
<div class="card"><div class="card-title"><?php echo e($cfg['chart']['title']); ?></div><div id="chartTrend"></div></div>
<div class="flex gap-12">
    <div class="card" style="flex:1"><div class="card-title">快速入口</div><div class="flex gap-8" style="flex-wrap:wrap">
        <?php foreach ($cfg['links'] as $l) { ?>
        <a class="btn btn-outline btn-sm" href="<?php echo e($l[0]); ?>"><?php echo $l[1]; ?></a>
        <?php } ?>
    </div></div>
    <div class="card" style="flex:1"><div class="card-title">使用提示</div>
        <div class="fs-13 text-muted" style="line-height:1.9">
            <?php foreach ($cfg['tips'] as $t) { echo $t . '<br>'; } ?>
        </div>
    </div>
</div>
<script>
Clinic.get('<?php echo e($cfg['api']); ?>', null, { onSuccess: function (json) {
    var d = json.data, kpi = d.kpi;
    var cards = <?php echo json_encode($cfg['stats'], JSON_UNESCAPED_UNICODE); ?>;
    var colors = <?php echo json_encode(isset($cfg['colors']) ? $cfg['colors'] : array(), JSON_UNESCAPED_UNICODE); ?>;
    document.getElementById('statsBox').innerHTML = cards.map(function (c) {
        var color = colors[c[0]] || 'var(--primary)';
        return '<div class="stat-card"><div class="stat-num" style="color:' + color + '">' + (kpi[c[0]]===undefined?'—':kpi[c[0]]) + '</div><div class="stat-label">' + c[1] + '</div></div>';
    }).join('');
    Clinic.chart.line('chartTrend', { labels: d.trend.labels, series: [{ name: '<?php echo e($cfg['chart']['name']); ?>', data: d.trend.data, color: '#409eff' }] });
}});
</script>
    <?php
}