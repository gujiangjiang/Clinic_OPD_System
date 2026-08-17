<?php
/**
 * install.php — 首次安装页
 * 说明：系统未创建管理员时全站强制跳转到本页：
 * 1. 管理员用户名固定 admin，密码由用户设置
 * 2. 设置医院名称/第二名称/页脚/时区（默认取创建管理员时的浏览器时区）
 * 3. 上传医院 LOGO（可选，同时用作 favicon）
 */
?>
<div class="auth-card">
    <div class="auth-title">🏥 门诊一体化系统</div>
    <div class="auth-sub">首次安装 · 创建系统管理员</div>

    <div class="step-dots"><span class="step-dot on"></span><span class="step-dot"></span><span class="step-dot"></span></div>

    <div class="form-group">
        <label class="form-label">管理员密码（用户名固定为 admin）<span class="req">*</span></label>
        <div class="input-wrap"><span class="input-icon">🔑</span>
            <input type="password" class="input" id="password" placeholder="至少6位" autocomplete="new-password"></div>
    </div>
    <div class="form-group">
        <label class="form-label">确认管理员密码 <span class="req">*</span></label>
        <div class="input-wrap"><span class="input-icon">🔑</span>
            <input type="password" class="input" id="password2" placeholder="再次输入密码" autocomplete="new-password"></div>
    </div>
    <div class="form-group">
        <label class="form-label">医院名称 <span class="req">*</span></label>
        <div class="input-wrap"><span class="input-icon">🏥</span>
            <input type="text" class="input" id="hospital_name" placeholder="如：XX市人民医院"></div>
    </div>
    <div class="form-group">
        <label class="form-label">医院第二名称（可选）</label>
        <input type="text" class="input" id="hospital_name2" placeholder="如：XX医科大学附属医院">
    </div>
    <div class="form-group">
        <label class="form-label">网站时区（默认取您当前的浏览器时区）</label>
        <input type="text" class="input" id="timezone" readonly>
    </div>
    <div class="form-group">
        <label class="form-label">医院 LOGO（可选，同时作为网站 favicon）</label>
        <input type="file" class="input" id="logo" accept="image/*">
    </div>
    <div class="form-group">
        <label class="form-label">页脚版权信息（可选）</label>
        <input type="text" class="input" id="footer" placeholder="如：© 2026 XX市人民医院 版权所有">
    </div>

    <button type="button" class="btn btn-primary btn-lg btn-block" id="installBtn">完成安装</button>
    <div class="auth-footer">安装完成后将自动跳转登录页</div>
</div>

<script>
// 默认时区：取创建管理员时的浏览器时区
(function () {
    var tz = 'Asia/Shanghai';
    try {
        tz = Intl.DateTimeFormat().resolvedOptions().timeZone || tz;
    } catch (e) {}
    document.getElementById('timezone').value = tz;
})();

document.getElementById('installBtn').addEventListener('click', function () {
    var btn = this;
    var password = document.getElementById('password').value;
    var password2 = document.getElementById('password2').value;
    var hospital = document.getElementById('hospital_name').value.trim();
    if (password.length < 6) { Clinic.toast.warning('管理员密码不能少于6位'); return; }
    if (password !== password2) { Clinic.toast.warning('两次输入的密码不一致'); return; }
    if (!hospital) { Clinic.toast.warning('请填写医院名称'); return; }

    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'save');
    fd.append('password', password);
    fd.append('password2', password2);
    fd.append('hospital_name', hospital);
    fd.append('hospital_name2', document.getElementById('hospital_name2').value.trim());
    fd.append('timezone', document.getElementById('timezone').value);
    fd.append('footer', document.getElementById('footer').value.trim());
    var logoFile = document.getElementById('logo').files[0];
    if (logoFile) fd.append('logo', logoFile);

    btn.disabled = true;
    btn.textContent = '安装中…';
    fetch('/api/install', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.ok) {
                Clinic.toast.success(json.msg);
                setTimeout(function () { location.href = '/login'; }, 800);
            } else {
                Clinic.toast.error(json.msg || '安装失败');
                btn.disabled = false;
                btn.textContent = '完成安装';
            }
        })
        .catch(function () {
            Clinic.toast.error('网络请求失败');
            btn.disabled = false;
            btn.textContent = '完成安装';
        });
});
</script>
