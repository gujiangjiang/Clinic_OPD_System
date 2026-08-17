<?php
/**
 * ============================================================
 * landing.php — 系统首页（落地页）
 * ============================================================
 * 说明：已安装且未登录时访问 / 展示本页：
 * 1. 医院名称 / LOGO（无 LOGO 不显示）
 * 2. Hero 区 + 功能矩阵（挂号收费/医生/护士/检验/影像/药房）
 * 3. 就诊流程 + 特点清单 + 登录 CTA
 * 4. 页脚版权信息（管理员可配置）
 * 登录/安装后分别跳转到对应工作台。
 * ============================================================ */
$hosp  = setting('hospital_name', '门诊一体化系统');
$hosp2 = setting('hospital_name2', '');
$logo  = setting('logo', '');
$footer= setting('footer', '门诊一体化信息系统 © ' . date('Y'));
$favicon = $logo !== '' ? '<link rel="icon" href="' . e($logo) . '">' : '';
$logoImg = $logo !== '' ? '<img src="' . e($logo) . '" alt="LOGO">' : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($hosp); ?> · 门诊一体化信息系统</title>
    <?php echo $favicon; ?>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/dark.css">
    <link rel="stylesheet" href="/assets/css/landing.css">
</head>
<body class="landing-body" data-theme-pref="<?php echo e(Auth::theme()); ?>" data-theme="light"
      data-hosp="<?php echo e($hosp); ?>" data-hosp2="<?php echo e($hosp2); ?>">

<!-- ===== 顶栏 ===== -->
<nav class="landing-nav">
    <div class="brand">
        <?php echo $logoImg; ?>
        <span><?php echo e($hosp); ?></span>
    </div>
    <div class="nav-actions">
        <a href="/login">登录系统</a>
        <a href="/install">首次安装</a>
    </div>
</nav>

<main class="landing-main">

    <!-- ===== Hero ===== -->
    <section class="landing-hero">
        <div class="hero-inner">
            <span class="hero-badge"><span class="dot"></span> 多科室一体化 · 站内消息 · 统一打印</span>
            <h1>让门诊就诊更<span class="grad">高效、安全、有序</span></h1>
            <p class="sub">
                一套系统同时服务挂号收费处、医生工作站、护士站、检验科、影像科与药房，
                覆盖挂号、缴费、电子病历、开单、报告、发药全流程。
                支持明亮 / 夜间 / 自动主题与分散式数据库，即装即用。
            </p>
            <div class="hero-ctas">
                <a class="btn-hero primary" href="/login">进入系统 →</a>
                <a class="btn-hero ghost" href="/install">首次安装配置</a>
            </div>
            <div class="hero-stats">
                <div class="stat"><div class="num">6+</div><div class="lbl">科室工作站</div></div>
                <div class="stat"><div class="num">1</div><div class="lbl">统一数据平台</div></div>
                <div class="stat"><div class="num">8</div><div class="lbl">类单据打印</div></div>
                <div class="stat"><div class="num">3</div><div class="lbl">种界面主题</div></div>
            </div>
        </div>
    </section>

    <!-- ===== 功能矩阵 ===== -->
    <section class="landing-section">
        <div class="section-head">
            <h2>一站式门诊业务</h2>
            <p>各角色登录后仅可见自己的工作台，权限互不越界</p>
        </div>
        <div class="feature-grid">
            <div class="feature-card"><div class="ico">🎫</div><h3>挂号收费处</h3><p>身份证自动校验与既往登记回填，号源实时展示，挂号缴费后一键打印凭条，支持退费与补打。</p></div>
            <div class="feature-card"><div class="ico">🩺</div><h3>医生工作站</h3><p>所见即所得电子病历，ICD10 诊断联动，检验/检查/处置/处方开单，流程进度一目了然。</p></div>
            <div class="feature-card"><div class="ico">💉</div><h3>护士站</h3><p>护士站处置执行、生命体征录入（与医生站双向同步）、护理记录管理。</p></div>
            <div class="feature-card"><div class="ico">🧪</div><h3>检验科</h3><p>检验登记、结果录入（正常范围与危急值提示）、报告自动生成与打印、支持申请撤回。</p></div>
            <div class="feature-card"><div class="ico">🩻</div><h3>影像科</h3><p>检查登记与报告书写（影像所见 + 结论），报告一键打印，与医生实时联动。</p></div>
            <div class="feature-card"><div class="ico">💊</div><h3>药房</h3><p>处方发药队列、库存管理（入库/出库/低库存预警），开方自动减库存、退费自动恢复。</p></div>
        </div>
    </section>

    <!-- ===== 就诊流程 ===== -->
    <section class="landing-section">
        <div class="section-head">
            <h2>清晰的就诊流程</h2>
            <p>从挂号到离院，每一步都有迹可循</p>
        </div>
        <div class="flow-strip">
            <div class="flow-step"><div class="n">1</div><div class="t">挂号</div><div class="d">身份证登记 · 号源选择</div></div>
            <div class="flow-step"><div class="n">2</div><div class="t">缴费</div><div class="d">挂号费 · 项目缴费</div></div>
            <div class="flow-step"><div class="n">3</div><div class="t">就诊</div><div class="d">电子病历 · 开单</div></div>
            <div class="flow-step"><div class="n">4</div><div class="t">检查检验</div><div class="d">登记 · 报告</div></div>
            <div class="flow-step"><div class="n">5</div><div class="t">取药 / 处置</div><div class="d">药房发药 · 护士执行</div></div>
            <div class="flow-step"><div class="n">6</div><div class="t">离院</div><div class="d">诊断证明 · 病历打印</div></div>
        </div>
    </section>

    <!-- ===== 特点清单 ===== -->
    <section class="landing-section">
        <div class="section-head">
            <h2>为日常使用而设计</h2>
            <p>部署简单，维护省心，界面现代</p>
        </div>
        <ul class="tick-list">
            <li>PHP 7.x + SQLite 即装即用，预留 MySQL 切换接口</li>
            <li>分散式数据库 + 统一迁移，各模块独立文件便于维护</li>
            <li>框架式界面，AJAX 局部刷新 + 模态对话框，无需整页跳转</li>
            <li>明亮 / 夜间 / 自动三模式，偏好跟随用户保存</li>
            <li>统一打印中心：凭条 / 病历 / 处方 / 申请单 / 报告 / 证明</li>
            <li>站内消息通知 + 打印提醒，业务流转不错过</li>
            <li>CSRF 防护、预处理防注入、角色级权限隔离</li>
            <li>全中文注释，公共字典集中管理，可快速二次开发</li>
        </ul>
    </section>

    <!-- ===== CTA ===== -->
    <section class="landing-cta">
        <h2>准备开始？</h2>
        <p><?php echo e($hosp); ?>，随时开诊。</p>
        <a class="btn-hero primary" href="/login">登录系统 →</a>
    </section>
</main>

<!-- ===== 页脚 ===== -->
<footer class="landing-footer"><?php echo e($footer); ?> ｜ Powered by 门诊一体化信息系统</footer>

<script src="/assets/js/components/theme.js"></script>
<script>document.addEventListener('DOMContentLoaded', function () { Clinic.theme.init(); });</script>
</body>
</html>
