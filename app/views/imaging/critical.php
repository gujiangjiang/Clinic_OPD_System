<?php
/**
 * imaging/critical.php — 影像科危急值管理
 * 说明：展示影像科已发送的全部危急值（发送医生 / 接收医生 / 是否处理 /
 * 处理时间 / 处理时长），支持时间范围与状态筛选；点击 → 只读详情。
 */
Router::title('危急值管理');
?>
<div class="page-head">
    <div><div class="page-title">🚨 危急值管理</div><div class="page-desc">影像科已发送的危急值（处理情况可溯源）</div></div>
</div>
<div id="critPage"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    Clinic.critical.initListPage({
        role: 'imaging',
        container: 'critPage',
        listBody: 'critListBody',
        totalEl: 'critTotal',
        footEl: 'critMore',
    });
});
</script>
