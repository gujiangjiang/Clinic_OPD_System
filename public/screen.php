<?php
/**
 * ============================================================
 * public/screen.php — 叫号大屏（免登常驻页面）
 * ============================================================
 * 说明：通过 ?token= 访问，独立于登录会话；供电视/平板/浏览器常驻显示。
 * 数据由 screen.js 每 3 秒轮询 /api/screen.php?action=heartbeat&token=xxx。
 * 双模式：
 *   模式 A（doctor 医生诊室）：经典大卡片——正在就诊 / 请就诊 / 候诊列表；
 *   模式 B（lab/imaging/pharmacy/nurse 医技）：列表看板——队列 + 当前呼叫高亮。
 * ============================================================ */
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
// 预览模式锁定：管理端预览 iframe 传入 pv_w/pv_h。
// 页面直接以「显示出来的画布尺寸」为基准排版（body 锁定为该尺寸 + container-type 统一
// cqmin 基准），不再用大设计尺寸 + transform 缩放，避免小画布下文字过小/偏移。
$pvW = (int)(isset($_GET['pv_w']) ? $_GET['pv_w'] : 0);
$pvH = (int)(isset($_GET['pv_h']) ? $_GET['pv_h'] : 0);
$pvStyle = '';
$pvClass = '';
if ($pvW >= 100 && $pvH >= 100) {
    $pvClass = ' pv-locked';
    $pvStyle = ' width:' . $pvW . 'px;height:' . $pvH . 'px;min-height:' . $pvH . 'px;';
}
$noToken = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
    '<title>大屏链接无效</title><style>body{background:#111;color:#eee;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-size:24px}</style></head>' .
    '<body><div>🔗 大屏链接无效或已失效，请联系管理员获取新的访问链接</div></body></html>';

if ($token === '') { echo $noToken; exit; }

// 校验 token 存在（免登，仅做存在性校验；实时数据由接口轮询）
require_once __DIR__ . '/../app/config/bootstrap.php';
$room = DB::one('clinic_rooms', 'SELECT * FROM clinic_rooms WHERE screen_token=?', array($token));
if (!$room) { echo $noToken; exit; }

$hosp  = setting('hospital_name', '门诊一体化系统');
$hosp2 = setting('hospital_name2', '');
$logoData = img_data(setting('logo', ''));
$favicon = $logoData !== '' ? '<link rel="icon" href="' . e($logoData) . '">' : '';
$logoImg = $logoData !== '' ? '<img src="' . e($logoData) . '" alt="LOGO">' : '<span style="font-size:28px">🏥</span>';
$isDoctor = $room['room_type'] === 'doctor';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($hosp); ?> · 叫号大屏</title>
    <?php echo $favicon; ?>
    <link rel="stylesheet" href="/assets/css/call.css">
    <style>
        body { background: linear-gradient(135deg,#0f2027,#203a43,#2c5364); color:#fff; }
        .apm-icon { animation: apm-breathe 1.8s ease-in-out infinite; }
        @keyframes apm-breathe {
            0%, 100% { transform: scale(1); opacity: .85; }
            50% { transform: scale(1.12); opacity: 1; }
        }
        <?php if ($pvClass !== ''): ?>html, body { overflow: hidden; }<?php endif; ?>
    </style>
</head>
<body class="call-body<?php echo $pvClass; ?>" data-token="<?php echo e($token); ?>" data-roomtype="<?php echo e($room['room_type']); ?>"
      data-csrf="<?php echo e(CSRF::token()); ?>" data-hosp="<?php echo e($hosp); ?>" data-hosp2="<?php echo e($hosp2); ?>"
      style="<?php echo trim($pvStyle); ?>">

<!-- 顶部抬头：LOGO + 医院名 + 时钟（紧凑单行，不做大字号，语音开关由管理员在设置页控制） -->
<header class="call-top">
    <div class="call-brand">
        <?php echo $logoImg; ?>
        <div class="call-hosp">
            <div class="hosp-main"><?php echo e($hosp); ?></div>
            <?php if ($hosp2 !== ''): ?><div class="hosp-sub"><?php echo e($hosp2); ?></div><?php endif; ?>
        </div>
    </div>
    <div class="call-clock" id="clock">--:--:--</div>
</header>

<!-- 科室 + 诊室名称条（位于标题栏下方，标题栏保持紧凑） -->
<div class="call-deptbar" id="deptBar">
    <div class="call-dept" id="roomTitle"><?php echo e($room['room_name']); ?></div>
</div>

<div class="screen-main" id="screenMain">
    <div class="empty" style="color:#fff"><div class="spinner" style="border-top-color:#fff;margin:0 auto"></div>正在加载大屏数据…</div>
</div>

<!-- 底部温馨提示（多条目自动轮播/跑马灯） -->
<footer class="call-tips" id="tipsBar">
    <div class="call-tips-inner" id="tipsInner"></div>
</footer>

<!-- 自动播放解锁遮罩（提示语分段展示 + 字号随屏幕缩放，小尺寸屏幕不换行溢出） -->
<div id="autoplayMask" style="position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:clamp(10px,4vh,26px);padding:clamp(16px,4vh,40px);text-align:center;cursor:pointer">
    <div class="apm-icon" style="font-size:clamp(36px,12vh,84px);line-height:1">🔊</div>
    <div style="font-size:clamp(16px,5vh,40px);font-weight:700;color:#fff;letter-spacing:3px;line-height:1.45;max-width:90%">点击屏幕<br>启动叫号语音大屏系统</div>
    <div style="font-size:clamp(12px,3.2vh,24px);color:#a8c8e8;letter-spacing:2px">点击后自动开始播报叫号</div>
</div>

<script src="/assets/js/components/screen.js"></script>
</body>
</html>
