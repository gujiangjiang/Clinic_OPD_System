<?php
/**
 * login.php — 登录页
 * 说明：用户名 + 密码登录；支持 ?next= 回跳原页面。
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
        <label class="form-label">用户名</label>
        <div class="input-wrap"><span class="input-icon">👤</span>
            <input type="text" class="input" id="username" placeholder="请输入用户名" autocomplete="username"></div>
    </div>
    <div class="form-group">
        <label class="form-label">密码</label>
        <div class="input-wrap"><span class="input-icon">🔒</span>
            <input type="password" class="input" id="password" placeholder="请输入密码" autocomplete="current-password"></div>
    </div>
    <input type="hidden" id="next" value="<?php echo e($next); ?>">
    <button type="button" class="btn btn-primary btn-lg btn-block" id="loginBtn">登 录</button>
    <div class="auth-footer">首次使用请先完成 <a href="/install">系统安装</a></div>
</div>

<script>
function doLogin() {
    var username = document.getElementById('username').value.trim();
    var password = document.getElementById('password').value;
    if (!username || !password) { Clinic.toast.warning('请输入用户名和密码'); return; }
    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.textContent = '登录中…';
    Clinic.ajax('/api/auth', {
        action: 'login',
        username: username,
        password: password,
        next: document.getElementById('next').value || '',
    }, {
        onSuccess: function (json) {
            Clinic.toast.success('登录成功，欢迎 ' + (json.data.name || ''));
            setTimeout(function () { location.href = json.data.next || '/'; }, 600);
        },
        onError: function () {
            btn.disabled = false;
            btn.textContent = '登 录';
        },
    });
}
document.getElementById('loginBtn').addEventListener('click', doLogin);
document.getElementById('password').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') doLogin();
});
</script>
