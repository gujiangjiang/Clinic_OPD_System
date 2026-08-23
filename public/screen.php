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
        .screen-mute { cursor:pointer; user-select:none; }
    </style>
</head>
<body class="call-body" data-token="<?php echo e($token); ?>" data-roomtype="<?php echo e($room['room_type']); ?>"
      data-csrf="<?php echo e(CSRF::token()); ?>" data-hosp="<?php echo e($hosp); ?>" data-hosp2="<?php echo e($hosp2); ?>">

<!-- 顶部统一抬头：LOGO + 医院名 + 时钟 + 静音开关 -->
<header class="call-top">
    <div class="call-brand">
        <?php echo $logoImg; ?>
        <div class="call-hosp">
            <div class="hosp-main"><?php echo e($hosp); ?></div>
            <?php if ($hosp2 !== ''): ?><div class="hosp-sub"><?php echo e($hosp2); ?></div><?php endif; ?>
        </div>
    </div>
    <div class="call-now" id="roomTitle"><?php echo e($room['room_name']); ?></div>
    <div class="call-clock" id="clock">--:--:--</div>
    <div class="call-mute screen-mute" id="muteBtn" title="点击静音/取消静音">🔊</div>
</header>

<div class="screen-main" id="screenMain">
    <div class="empty" style="color:#fff"><div class="spinner" style="border-top-color:#fff;margin:0 auto"></div>正在加载大屏数据…</div>
</div>

<!-- 自动播放解锁遮罩 -->
<div id="autoplayMask" style="position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:28px;gap:20px;cursor:pointer">
    <div>🔊 点击屏幕启动叫号语音大屏系统</div>
    <div style="font-size:16px;color:#aaa">点击后开始自动播报叫号</div>
</div>

<script src="/assets/js/components/screen.js"></script>
</body>
</html>
