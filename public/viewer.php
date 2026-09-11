<?php
/**
 * ============================================================
 * public/viewer.php — 独立视窗阅片窗口（模式 B，多显示器拖拽全屏阅片）
 * ============================================================
 * 说明：影像科工作台「独立视窗阅片」按钮通过 window.open 打开本页：
 *   1. 无系统侧边栏/顶栏，纯深色阅片工作台（window.open 控制无工具栏）；
 *   2. 与主窗口共享登录 Session（本页走 Router 登录门 + 影像科角色门）；
 *   3. Session 安全锁定（核心规范）：通过 Clinic.authSync（BroadcastChannel
 *      clinic_auth_sync + localStorage 兜底）监听主窗口退出登录/鉴权失败，
 *      一旦失效立即激活高斯模糊遮罩并禁止任何影像操作。
 * 参数：?visit=<混淆就诊ID>（可选，携带时展示该患者信息占位）
 * 数据接口：/api/deptwork?action=patient&visit_id=（登录 Session 鉴权）
 * ============================================================ */
require_once __DIR__ . '/../app/config/bootstrap.php';

/* ---------- 登录门 + 角色门（仅影像科/管理员） ---------- */
$u = Auth::user();
if (!$u || !Auth::assertActive()) {
    header('Location: /login?next=' . urlencode('/viewer.php'));
    exit;
}
if (!in_array($u['role'], array('imaging', 'admin'), true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>403</title></head>' .
        '<body style="background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif">' .
        '<div style="text-align:center"><div style="font-size:64px">🔒</div><div style="margin-top:16px">仅影像科角色可使用独立阅片窗口</div></div></body></html>';
    exit;
}

$visitCode = isset($_GET['visit']) ? trim((string)$_GET['visit']) : '';
$hosp = setting('hospital_name', '门诊一体化系统');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($hosp); ?> · 独立阅片工作站</title>
    <link rel="stylesheet" href="/assets/css/pacs.css">
</head>
<body class="pacs-solo-body">
<div class="pacs-solo">
    <div class="pacs-solo-head">
        <span>🩻 独立阅片工作站</span>
        <span class="solo-sub" id="soloPatient"><?php echo $visitCode !== '' ? '就诊 ' . e($visitCode) : '未指定患者'; ?></span>
    </div>
    <div class="pacs-solo-stage" id="soloStage">
        <div class="pacs-viewer-mount" id="soloMount"></div>
        <div class="pacs-viewer-placeholder" id="soloPlaceholder">
            <div class="ph-ico">🖥️</div>
            <div class="ph-main">独立阅片视窗已就绪</div>
            <div class="ph-sub">本窗口用于多显示器全屏拖拽阅片，与主系统共享登录会话<br>
            DICOM Viewer（DICOMweb / WADO-RS）接入后，影像将自动挂载至此视窗</div>
        </div>
        <div class="pacs-viewer-tag" id="soloTagL"></div>
        <div class="pacs-viewer-tag pacs-viewer-tag-r" id="soloTagR"></div>
    </div>
    <div class="pacs-solo-foot">
        <button type="button" class="pacs-tool-btn" onclick="soloFit()">⤢ 适应窗口</button>
        <button type="button" class="pacs-tool-btn" onclick="soloReset()">↺ 重置</button>
        <button type="button" class="pacs-tool-btn" onclick="window.close()">✕ 关闭视窗</button>
    </div>
</div>

<!-- Session 锁定遮罩：登出/鉴权失败时立即激活（高斯模糊 + 居中提示） -->
<div class="pacs-lock-mask" id="soloLockMask">
    <div class="pacs-lock-card">
        <div class="lock-ico">🔒</div>
        <div class="lock-title">登录会话已失效</div>
        <div class="lock-desc">登录会话已失效，阅片工作站已锁定，请重新登录主系统</div>
    </div>
</div>

<script src="/assets/js/components/authsync.js"></script>
<script>
var LOCKED = false;

/* ---------- Session 失效 → 立即锁定（核心规范） ---------- */
function lockViewer() {
    if (LOCKED) return;
    LOCKED = true;
    document.getElementById('soloLockMask').classList.add('show');
    // 禁止任何影像操作：占位符清空 + 交互全部屏蔽
    var ph = document.getElementById('soloPlaceholder');
    if (ph) ph.innerHTML = '<div class="ph-ico">🔒</div><div class="ph-main" style="color:#ef4444">阅片工作站已锁定</div>';
    var tags = [document.getElementById('soloTagL'), document.getElementById('soloTagR')];
    tags.forEach(function (t) { if (t) t.textContent = ''; });
}

/* 订阅主窗口登出广播；若初始化时已存在失效里程碑（广播发出后才打开本窗口）立即锁定 */
var initState = Clinic.authSync.onLogout(lockViewer);
if (initState.isLoggedOut) lockViewer();

/* ---------- 占位交互（DICOM Viewer 接入前的友好交互占位） ---------- */
function soloFit() { if (LOCKED) return; soloTag('⤢ 适应窗口'); }
function soloReset() { if (LOCKED) return; soloTag('↺ 已重置视窗'); }
function soloTag(txt) {
    var el = document.getElementById('soloTagL');
    if (el) el.textContent = '> ' + txt + ' @ ' + new Date().toLocaleTimeString();
}

/* ---------- 患者信息回填（共享 Session 鉴权；401 时同步锁定） ---------- */
<?php if ($visitCode !== ''): ?>
(function () {
    fetch('/api/deptwork?action=patient&visit_id=' + encodeURIComponent('<?php echo e($visitCode); ?>'), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
        if (r.status === 401) { lockViewer(); throw new Error('unauthorized'); }
        return r.json();
    }).then(function (j) {
        if (!j.ok) return;
        var v = j.data.visit || {}, p = j.data.patient || {};
        var el = document.getElementById('soloPatient');
        if (el) el.textContent = v.name + ' ｜ ' + v.gender + ' / ' + (v.age_fmt || '') + ' ｜ 患者ID ' + (p.patient_id || '—') + ' ｜ ' + (v.visit_no || '');
        var tl = document.getElementById('soloTagL'), tr = document.getElementById('soloTagR');
        if (tl) tl.textContent = '> 患者ID ' + (p.patient_id || '—');
        if (tr) tr.textContent = v.visit_no || '';
    }).catch(function () { /* 网络失败保持占位 */ });
})();
<?php endif; ?>
</script>
</body>
</html>
