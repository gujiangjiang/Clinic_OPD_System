<?php
/**
 * doctor/critical.php — 医生端危急值管理
 * 说明：展示当前医生接收到的全部危急值（待处理/已处理），支持时间范围
 * 与状态筛选；点击待处理 → 处理弹窗，已处理 → 只读详情。
 */
Router::title('危急值管理');
?>
<div class="page-head">
    <div><div class="page-title">🚨 危急值管理</div><div class="page-desc">我接收到的危急值（待处理 / 已处理，处理时长可溯源）</div></div>
</div>
<div id="critPage"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    Clinic.critical.initListPage({
        role: 'doctor',
        container: 'critPage',
        listBody: 'critListBody',
        totalEl: 'critTotal',
        footEl: 'critMore',
        defaultFrom: '<?php echo date('Y-m-d', strtotime('-2 days')); ?>',
        defaultTo: '<?php echo date('Y-m-d'); ?>',
    });
});
</script>
