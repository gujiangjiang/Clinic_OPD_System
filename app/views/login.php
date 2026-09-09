<?php
/**
 * login.php — 登录页
 * 说明：用户名/工号 + 密码 + 验证码（三模式弹性展开）登录；支持 ?next= 回跳。
 * 验证码双轨驱动：
 *   轨道1 本地缓存感知——LocalStorage 登录失败标记，隐私模式无 Storage 则默认隐藏；
 *   轨道2 服务端强校验——用户名 blur/防抖输入后异步调用 check_captcha，
 *         判定需要则无条件展开验证码区域并拉取图片。
 * 布局规范：密码行在展开验证码后平滑收缩，【密码框+验证码框+验证码图】
 * 拼接总宽与用户名输入框严格 1:1 等宽（flex 过渡动画）。
 */
$next = isset($_GET['next']) ? $_GET['next'] : '';
if ($next === '' || $next[0] !== '/') {
    $next = '';
}
?>
<div class="auth-card">
    <div class="auth-title">欢迎登录</div>
    <div class="auth-sub">门诊一体化信息系统 · 多角色工作站</div>

    <div class="form-group">
        <label class="form-label">用户名 / 工号</label>
        <div class="input-wrap"><span class="input-icon">👤</span>
            <input type="text" class="input" id="username" placeholder="请输入用户名或工号" autocomplete="username"></div>
    </div>
    <div class="form-group">
        <label class="form-label">密码</label>
        <div class="input-wrap"><span class="input-icon">🔒</span>
            <input type="password" class="input" id="password" placeholder="请输入密码" autocomplete="current-password"></div>
    </div>
    <div class="form-group login-captcha-group" id="captchaGroup" style="display:none">
        <label class="form-label">验证码</label>
        <div class="login-captcha-box">
            <input type="text" class="input" id="captcha" placeholder="请输入右侧字符" maxlength="4" autocomplete="off">
            <img id="captchaImg" class="login-captcha-img" src="/api/auth?action=captcha" alt="验证码" title="看不清？点击刷新">
        </div>
    </div>
    <input type="hidden" id="next" value="<?php echo e($next); ?>">
    <button type="button" class="btn btn-primary btn-lg btn-block" id="loginBtn">登 录</button>
</div>

<style>
/* ---------- 登录验证码独立一行布局 ----------
 * 验证码为密码下方的独立表单行（展开/折叠 display 切换 + fade 过渡）；
 * 【验证码输入框 + 右侧内嵌图片】同处一个圆角边框容器（.login-captcha-box），
 * 图片被局限在输入框内部右段——与输入框浑然一体，总宽与用户名/密码
 * 输入框严格 1:1 等宽 */
.login-captcha-box {
    display: flex;
    align-items: center;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-card);
    overflow: hidden;           /* 图片贴右缘，随容器圆角裁切 */
    transition: border-color .2s ease;
}
.login-captcha-box:focus-within { border-color: var(--primary); }
.login-captcha-box .input {
    flex: 1 1 auto;
    min-width: 0;
    border: none;               /* 边框由容器统一提供 */
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    height: 38px;
}
.login-captcha-box .input:focus { box-shadow: none; }
.login-captcha-img {
    flex: 0 0 auto;
    width: 110px;               /* 固定右段宽（图片 130×42 等比缩放） */
    height: 38px;
    object-fit: cover;
    cursor: pointer;            /* 点击刷新 */
    border-left: 1px solid var(--border);   /* 与输入区分隔细线 */
    display: block;
}
.login-captcha-group { animation: captchaIn .25s ease; }
@keyframes captchaIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
var CAPTCHA_KEY = 'clinic_login_fail_flag';   // 本地失败标记（轨道1）
var captchaTimer = null;                       // 用户名防抖句柄

function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* 隐私模式忽略 */ } }

/** 展开验证码行并（重）拉取图片（时间戳防缓存） */
function showCaptcha() {
    var g = document.getElementById('captchaGroup');
    var img = document.getElementById('captchaImg');
    if (g.style.display !== '') {
        g.style.display = '';            // 独立一行展开（带 fade 动画）
        img.src = '/api/auth?action=captcha&t=' + Date.now();
    }
}

/** 隐藏验证码行（off 模式或预检判定不需要） */
function hideCaptcha() {
    var g = document.getElementById('captchaGroup');
    g.style.display = 'none';
    document.getElementById('captcha').value = '';
}

/* 轨道2：用户名 blur / 防抖输入（400ms）后服务端嗅探（无论隐私模式与否） */
function probeCaptcha() {
    var username = document.getElementById('username').value.trim();
    if (!username) return;
    var localFlag = lsGet(CAPTCHA_KEY) === '1' ? 1 : 0;
    Clinic.get('/api/auth', { action: 'check_captcha', username: username, flag: localFlag }, {
        loading: false,
        onSuccess: function (j) {
            if (j.data && j.data.require_captcha) showCaptcha();
            else hideCaptcha();
        },
        onError: function () { /* 预检失败静默：提交时服务端仍会硬校验 */ },
    });
}
document.getElementById('username').addEventListener('blur', probeCaptcha);
document.getElementById('username').addEventListener('input', function () {
    clearTimeout(captchaTimer);
    captchaTimer = setTimeout(probeCaptcha, 400);
});

/* 轨道1：本地失败标记优先展示（页面加载即执行，不等服务端） */
if (lsGet(CAPTCHA_KEY) === '1') showCaptcha();

/* 验证码图片点击刷新（时间戳无刷新拉新图） */
document.getElementById('captchaImg').addEventListener('click', function () {
    this.src = '/api/auth?action=captcha&t=' + Date.now();
});

function doLogin() {
    var username = document.getElementById('username').value.trim();
    var password = document.getElementById('password').value;
    var captcha = document.getElementById('captcha').value.trim();
    if (!username || !password) { Clinic.toast.warning('请输入用户名和密码'); return; }
    var needCap = document.getElementById('captchaGroup').style.display !== 'none';
    if (needCap && !captcha) { Clinic.toast.warning('请输入验证码'); return; }
    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.textContent = '登录中…';
    Clinic.ajax('/api/auth', {
        action: 'login',
        username: username,
        password: password,
        captcha: captcha,
        need_captcha: needCap ? '1' : '0',   // 服务端 auto 模式判定参考（客户端轨道）
        next: document.getElementById('next').value || '',
    }, {
        onSuccess: function (json) {
            lsSet(CAPTCHA_KEY, '0');   // 登录成功清除本地失败标记
            Clinic.toast.success('登录成功，欢迎 ' + (json.data.name || ''));
            setTimeout(function () { location.href = json.data.next || '/'; }, 600);
        },
        onError: function (j) {
            // 记录本地失败标记：下次进入登录页优先展示验证码（轨道1）
            lsSet(CAPTCHA_KEY, '1');
            btn.disabled = false;
            btn.textContent = '登 录';
            // 涉及验证码错误/安全场景：强制展开验证码并换新图（旧图已销毁）
            var msg = (j && j.msg) || '';
            if (msg.indexOf('验证码') !== -1 || msg.indexOf('锁定') !== -1 || needCap) showCaptcha();
        },
    });
}
document.getElementById('loginBtn').addEventListener('click', doLogin);
/* 密码框与验证码框回车等同点击登录 */
document.getElementById('password').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') doLogin();
});
document.getElementById('captcha').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') doLogin();
});
</script>
