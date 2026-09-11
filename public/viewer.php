<?php
/**
 * ============================================================
 * public/viewer.php — 独立视窗阅片窗口（模式 B，多显示器拖拽全屏阅片）
 * ============================================================
 * 说明：影像科工作台「独立视窗阅片」按钮通过 window.open 打开本页：
 *   1. 无系统侧边栏/顶栏，纯深色阅片工作台（window.open 控制无工具栏）；
 *   2. 与主窗口共享登录 Session（本页走登录门 + 影像科角色门）；
 *   3. Session 安全锁定（优化项11）：锁定判定以服务端会话预检为准
 *      （/api/auth?action=me，打开时 + 60s 心跳），BroadcastChannel
 *      auth:logout 事件仅在携带同一 session id 时立即锁定——
 *      杜绝历史会话登出里程碑导致「登录状态下误锁」；
 *   4. 实时同步（优化项12）：订阅主窗口 viewer:context 广播，
 *      主窗口切换患者/序列后本窗口实时刷新展示对象。
 * 参数：?visit=<混淆就诊ID>（可选，携带时初始展示该患者）
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
<body class="pacs-solo-body" data-sid="<?php echo e(session_id()); ?>" data-uid="<?php echo (int)$u['id']; ?>">
<div class="pacs-solo">
    <div class="pacs-solo-head">
        <span>🩻 独立阅片工作站</span>
        <span class="solo-sub" id="soloPatient"><?php echo $visitCode !== '' ? '就诊 ' . e($visitCode) : '等待主系统选择患者…'; ?></span>
    </div>
    <div class="pacs-solo-stage" id="soloStage">
        <div class="pacs-viewer-mount" id="soloMount"></div>
        <div class="pacs-viewer-placeholder" id="soloPlaceholder">
            <div class="ph-ico">🖥️</div>
            <div class="ph-main" id="phMain">独立阅片视窗已就绪</div>
            <div class="ph-sub" id="phSub">在主系统工作台选择患者或序列后，本窗口实时同步展示<br>
            DICOM Viewer（DICOMweb / WADO-RS）接入后，影像将自动挂载至此视窗</div>
        </div>
        <div class="pacs-viewer-tag" id="soloTagL"></div>
        <div class="pacs-viewer-tag pacs-viewer-tag-r" id="soloTagR"></div>
    </div>
    <div class="pacs-solo-foot">
        <button type="button" class="pacs-tool-btn" onclick="soloFit()">⤢ 适应窗口</button>
        <button type="button" class="pacs-tool-btn" onclick="soloReset()">↺ 重置</button>
        <button type="button" class="pacs-tool-btn" onclick="soloEmbed()">🪟 内嵌阅片器</button>
        <button type="button" class="pacs-tool-btn" onclick="window.close()">✕ 关闭视窗</button>
        <span class="solo-sub" style="margin-left:auto;align-self:center" id="soloSyncState"></span>
    </div>
</div>

<!-- Session 锁定遮罩：服务端确认会话失效后激活（高斯模糊 + 居中提示） -->
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
var CURRENT = { visit: '<?php echo e($visitCode); ?>', item: '', label: '' };

/* ---------- 锁定（服务端会话失效确认后调用） ---------- */
function lockViewer() {
    if (LOCKED) return;
    LOCKED = true;
    document.getElementById('soloLockMask').classList.add('show');
    var ph = document.getElementById('soloPlaceholder');
    if (ph) ph.innerHTML = '<div class="ph-ico">🔒</div><div class="ph-main" style="color:#ef4444">阅片工作站已锁定</div>';
    var tags = [document.getElementById('soloTagL'), document.getElementById('soloTagR')];
    tags.forEach(function (t) { if (t) t.textContent = ''; });
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
}

/* ---------- 会话预检心跳（优化项11 核心）：
   锁定判定以服务端为准——打开时立即预检一次，此后 60s 一次；
   收到 auth:logout 广播后也立即预检确认（广播仅作触发，不作结论）。 ---------- */
var heartbeatTimer = null;
function sessionProbe() {
    fetch('/api/auth?action=me', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
            if (r.status === 401) { lockViewer(); return null; }
            return r.json();
        })
        .then(function (j) {
            if (j && j.ok) {
                var st = document.getElementById('soloSyncState');
                if (st) st.textContent = '会话正常 · ' + new Date().toLocaleTimeString();
            }
        })
        .catch(function () { /* 网络抖动不锁定，等下一轮心跳 */ });
}

/* ---------- 订阅主窗口事件（同会话登出 → 预检确认后锁定；上下文 → 实时刷新） ---------- */
Clinic.authSync.onLogout(
    function () { sessionProbe(); },   // 收到广播：不直接锁，服务端预检确认
    function (ctx) { applyContext(ctx); }
);
if (heartbeatTimer) clearInterval(heartbeatTimer);
sessionProbe();
heartbeatTimer = setInterval(sessionProbe, 60000);

/* ---------- 上下文实时同步（优化项12） ----------
   主窗口切换患者/序列时广播 viewer:context，本窗口立即更新：
   - 切换患者：重新拉取该患者工作台数据（共享 Session 鉴权，401 即锁定）；
   - 切换序列：更新视窗标签与占位信息。 */
function applyContext(ctx) {
    if (LOCKED) return;
    if (!ctx) return;
    var changedVisit = ctx.visit && ctx.visit !== CURRENT.visit;
    var changedItem = ctx.item && ctx.item !== CURRENT.item;
    CURRENT = { visit: ctx.visit || CURRENT.visit, item: ctx.item || '', label: ctx.label || '' };
    if (changedVisit && CURRENT.visit) loadSoloPatient(CURRENT.visit);
    else if (CURRENT.label) paintTag(CURRENT.label);
    // 序列切换 → 自动重挂阅片器（已配置 pacs_viewer_url 时；优化项12）
    if (changedItem && CURRENT.item) soloEmbed(true);
}

/* 内嵌 Web 阅片器（优化项3）：无插件、窗宽窗位/缩放平移/多序列/MPR/测量
   由阅片器自身提供；影像本体走区域影像存储（WADO-RS/DICOMweb） */
function soloEmbed(silent) {
    if (LOCKED) return;
    if (!CURRENT.item) { if (!silent) CliniclessToast('请先在主系统选择检查序列'); return; }
    fetch('/api/imaging?action=viewer_url&item_id=' + encodeURIComponent(CURRENT.item), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
        if (r.status === 401) { lockViewer(); return null; }
        return r.json();
    }).then(function (j) {
        if (!j || !j.ok) { if (!silent && j && j.msg) paintMain(j.msg); return; }
        var d = j.data || {};
        var mount = document.getElementById('soloMount');
        if (mount) {
            mount.innerHTML = '<iframe src="' + escHtmlAttr(d.url) + '" style="width:100%;height:100%;border:0" title="Web 阅片器" allow="fullscreen"></iframe>';
        }
        var ph = document.getElementById('soloPlaceholder');
        if (ph) ph.style.display = 'none';
        var tl = document.getElementById('soloTagL');
        if (tl) tl.textContent = '> 阅片器已挂载 · Study ' + (d.study_uid || '');
    }).catch(function () { /* 网络失败保持占位 */ });
}

function CliniclessToast(msg) { paintMain(msg); }
function paintMain(msg) {
    var m = document.getElementById('phMain');
    if (m) m.textContent = msg;
}
function escHtmlAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function paintTag(label) {
    var tl = document.getElementById('soloTagL');
    if (tl) tl.textContent = '> ' + label;
    var main = document.getElementById('phMain');
    if (main && label) main.textContent = label;
}

function loadSoloPatient(code) {
    var ph = document.getElementById('soloPlaceholder');
    if (ph) ph.innerHTML = '<div class="ph-ico">🩻</div><div class="ph-main">正在同步患者…</div>';
    fetch('/api/deptwork?action=patient&visit_id=' + encodeURIComponent(code), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
        if (r.status === 401) { lockViewer(); throw new Error('unauthorized'); }
        return r.json();
    }).then(function (j) {
        if (!j || !j.ok) {
            if (ph) ph.innerHTML = '<div class="ph-ico">⚠️</div><div class="ph-main">' + ((j && j.msg) || '患者数据加载失败') + '</div>';
            return;
        }
        var v = j.data.visit || {}, p = j.data.patient || {};
        var el = document.getElementById('soloPatient');
        if (el) el.textContent = v.name + ' ｜ ' + v.gender + ' / ' + (v.age_fmt || '') + ' ｜ ' + (v.visit_no || '');
        var tr = document.getElementById('soloTagR');
        if (tr) tr.textContent = '患者ID ' + (p.patient_id || '—') + ' ｜ ' + (v.visit_no || '');
        ph.innerHTML =
            '<div class="ph-ico">🩻</div>' +
            '<div class="ph-main">' + ((v.name || '') + ' · ' + (v.visit_no || '')) + '</div>' +
            '<div class="ph-sub">' + (CURRENT.label ? CURRENT.label + '<br>' : '') +
            '该患者影像序列将随主系统选择实时同步<br>DICOMweb 接入后影像自动挂载</div>';
        var tl = document.getElementById('soloTagL');
        if (tl) tl.textContent = CURRENT.label ? ('> ' + CURRENT.label) : ('> ' + (v.name || ''));
    }).catch(function () { /* 锁定时静默 */ });
}

/* 初始患者（URL 携带 visit 时直接加载） */
<?php if ($visitCode !== ''): ?>
loadSoloPatient('<?php echo e($visitCode); ?>');
<?php endif; ?>

/* ---------- 占位交互（DICOM Viewer 接入前的友好交互占位） ---------- */
function soloFit() { if (LOCKED) return; soloTag('⤢ 适应窗口'); }
function soloReset() { if (LOCKED) return; soloTag('↺ 已重置视窗'); }
function soloTag(txt) {
    var el = document.getElementById('soloTagL');
    if (el) el.textContent = '> ' + txt + ' @ ' + new Date().toLocaleTimeString();
}
</script>
</body>
</html>
