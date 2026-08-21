<?php
/**
 * ============================================================
 * doctor/call.php v1.1.0 — 诊室门口叫号屏幕
 * ============================================================
 * 说明：医生工作站附加的独立叫号显示页（全屏、醒目、大字体）：
 *   1. 顶部：医院 LOGO + 医院名称（第二名称并排，与LOGO齐平）；
 *      右上角：实时年月日时分秒
 *   2. 中上方：当前门诊名称；主区：就诊中 / 下一位
 *   3. 下方：医生介绍卡（左侧照片、右侧姓名与职称）
 *   4. 最下方：温馨提示（请保持安静 / 请按序排队 / 请主动拒绝医托）
 * 数据由 call.js 每 10 秒轮询 /api/doctor?action=call_queue 自动刷新。
 * 本页面为独立全屏页，不套用系统框架布局。
 * ============================================================ */
Router::title('叫号屏幕');
$hosp  = setting('hospital_name', '门诊一体化系统');
$hosp2 = setting('hospital_name2', '');
// LOGO 以 base64 Data URI 内联显示：不暴露文件 URL，且不受页面层级影响
$logoData = img_data(setting('logo', ''));
$favicon = $logoData !== '' ? '<link rel="icon" href="' . e($logoData) . '">' : '';
$logoImg = $logoData !== '' ? '<img src="' . e($logoData) . '" alt="LOGO">' : '';
?>
<!DOCTYPE html>
<!-- 说明：叫号屏不再自带科室切换，显示科室完全跟随医生端在【医生工作站】的选择（服务端 current_dept_id） -->
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($hosp); ?> · 叫号屏幕</title>
    <?php echo $favicon; ?>
    <link rel="stylesheet" href="/assets/css/call.css">
</head>
<body class="call-body" data-csrf="<?php echo e(CSRF::token()); ?>" data-hosp="<?php echo e($hosp); ?>" data-hosp2="<?php echo e($hosp2); ?>">

<!-- ===== 顶部：LOGO + 医院名称 + 时钟 ===== -->
<header class="call-top">
    <div class="call-brand">
        <?php echo $logoImg; ?>
        <div class="call-hosp">
            <div class="hosp-main"><?php echo e($hosp); ?></div>
            <?php if ($hosp2 !== ''): ?><div class="hosp-sub"><?php echo e($hosp2); ?></div><?php endif; ?>
        </div>
    </div>
    <div class="call-clock" id="callClock"></div>
</header>

<!-- ===== 中上方：当前科室名称（跟随医生端选择动态变化） ===== -->
<div class="call-deptbar">
    <div class="call-dept" id="callDept">正在加载科室…</div>
</div>

<!-- ===== 主区：就诊中 / 下一位 ===== -->
<main class="call-main">
    <div class="call-now">
        <div class="call-label">就 诊 中</div>
        <div class="call-name" id="callNowName">—</div>
        <div class="call-sub" id="callNowSub"></div>
    </div>
    <div class="call-arrow">▼</div>
    <div class="call-next">
        <div class="call-label call-label-next">下 一 位</div>
        <div class="call-name" id="callNextName">—</div>
        <div class="call-sub" id="callNextSub"></div>
    </div>
</main>

<!-- ===== 下方：医生介绍 ===== -->
<section class="call-doctor" id="callDoctor">
    <div class="doc-photo" id="docPhoto">👨‍⚕️</div>
    <div class="doc-info">
        <div class="doc-name" id="docName">—</div>
        <div class="doc-title" id="docTitle"></div>
        <div class="doc-intro" id="docIntro"></div>
    </div>
</section>

<!-- ===== 最下方：温馨提示 ===== -->
<footer class="call-tips">
    <span>📢 请保持安静</span>
    <span>🔢 请按序排队候诊</span>
    <span>🚫 请主动拒绝医托，谨防上当受骗</span>
    <span>🏥 祝您早日康复</span>
</footer>

<script src="/assets/js/components/ajax.js"></script>
<script src="/assets/js/components/toast.js"></script>
<script src="/assets/js/components/datetime.js"></script>
<script src="/assets/js/components/call.js"></script>
</body>
</html>
