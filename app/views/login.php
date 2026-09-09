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
        <div class="login-captcha-row" id="captchaRow">
            <div class="input-wrap login-pwd-wrap" id="pwdWrap"><span class="input-icon">🔒</span>
                <input type="password" class="input" id="password" placeholder="请输入密码" autocomplete="current-password"></div>
            <div class="login-captcha-extra" id="captchaExtra">
                <div class="input-wrap login-captcha-input-wrap"><span class="input-icon">#</span>
                    <input type="text" class="input" id="captcha" placeholder="验证码" maxlength="4" autocomplete="off"></div>
                <img id="captchaImg" class="login-captcha-img" src="/api/auth?action=captcha" alt="验证码" title="点击刷新验证码" style="display:none">
            </div>
        </div>
    </div>
    <input type="hidden" id="next" value="<?php echo e($next); ?>">
    <button type="button" class="btn btn-primary btn-lg btn-block" id="loginBtn">登 录</button>
</div>

<style>
/* ---------- 登录验证码弹性等宽布局 ----------
 * 密码行 .login-captcha-row 为 flex 容器（宽度=用户名输入框 100%）：
 * · 默认（无需验证码）：验证码区 display:none，密码框 flex:1 占满整行；
 * · 激活（需验证码）：容器加 .show-captcha，密码框收缩至 flex:1.9，
 *   验证码输入框 flex:1、验证码图 flex:1.15——三段拼接总宽仍与用户名
 *   输入框严格 1:1 对齐；宽度切换带平滑过渡动画 */
.login-captcha-row { display: flex; gap: 8px; align-items: stretch; }
.login-captcha-row .input-wrap { margin: 0; }
.login-captcha-row .login-pwd-wrap { flex: 1 1 auto; min-width: 0; transition: flex .28s ease; }
.login-captcha-row.show-captcha .login-pwd-wrap { flex: 1.9 1 0; }
.login-captcha-extra { display: none; flex: 1 1 auto; min-width: 0; gap: 8px; }
.login-captcha-row.show-captcha .login-captcha-extra { display: flex; }
.login-captcha-row.show-captcha .login-captcha-input-wrap { flex: 1 1 0; min-width: 0; }
.login-captcha-img {
    flex: 1.15 1 0;
    min-width: 0;
    height: 38px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-soft);
    object-fit: contain;
    cursor: pointer;   /* 点击刷新 */
    transition: flex .28s ease;
}
</style>

<script>
var CAPTCHA_KEY = 'clinic_login_fail_flag';   // 本地失败标记（轨道1）
var captchaTimer = null;                       // 用户名防抖句柄

function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* 隐私模式忽略 */ } }

/** 展开验证码区域并（重）拉取图片（时间戳防缓存） */
function showCaptcha() {
    var row = document.getElementById('captchaRow');
    var img = document.getElementById('captchaImg');
    if (!row.classList.contains('show-captcha')) row.classList.add('show-captcha');
    img.style.display = '';
    img.src = '/api/auth?action=captcha&t=' + Date.now();
}

/** 隐藏验证码区域（off 模式或预检判定不需要） */
function hideCaptcha() {
    var row = document.getElementById('captchaRow');
    row.classList.remove('show-captcha');
    document.getElementById('captchaImg').style.display = 'none';
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
    var needCap = document.getElementById('captchaRow').classList.contains('show-captcha');
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
