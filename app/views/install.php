<?php
/**
 * install.php — 首次安装页
 * 说明：系统未创建管理员时全站强制跳转到本页：
 * 1. 管理员用户名固定 admin，密码由用户设置
 * 2. 设置医院名称/第二名称/时区（下拉选择，默认取创建管理员时的浏览器时区）
 * 3. 上传医院 LOGO（可选，同时用作 favicon）
 * 页脚版权信息无需手动设置：统一自动生成【© 年份 医院名称 版权所有】。
 */

/* 时区下拉数据源：直接调用服务器 PHP 的 timezone 列表，按区域分组 */
$tzGroups = array();
foreach (DateTimeZone::listIdentifiers() as $tz) {
    $parts = explode('/', $tz, 2);
    $group = isset($parts[1]) ? $parts[0] : '其他';
    $tzGroups[$group][] = $tz;
}
?>
<div class="auth-card">
    <div class="auth-title">🏥 门诊一体化系统</div>
    <div class="auth-sub">首次安装 · 创建系统管理员</div>

    <div class="step-dots"><span class="step-dot on"></span><span class="step-dot"></span><span class="step-dot"></span></div>

    <div class="form-group">
        <label class="form-label">管理员密码（用户名固定为 admin）<span class="req">*</span></label>
        <div class="input-wrap"><span class="input-icon">🔑</span>
            <input type="password" class="input" id="password" placeholder="至少6位" autocomplete="new-password"></div>
        <div class="fs-12 text-warning mt-4">⚠️ 请输入英文字母/数字/符号，并确保输入法已切换为<b>英文状态</b>（中文输入法会吞掉部分字母导致密码不完整）。</div>
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
        <label class="form-label">网站时区（默认选中您当前的浏览器时区，可修改）</label>
        <select class="select" id="timezone">
            <?php foreach ($tzGroups as $group => $tzList): ?>
            <optgroup label="<?php echo e($group); ?>">
                <?php foreach ($tzList as $tz): ?>
                <option value="<?php echo e($tz); ?>"><?php echo e($tz); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">医院 LOGO（可选，同时作为网站 favicon）</label>
        <input type="file" class="input" id="logo" accept="image/*">
    </div>

    <button type="button" class="btn btn-primary btn-lg btn-block" id="installBtn">完成安装</button>
    <div class="auth-footer">安装完成后将自动跳转登录页</div>
</div>

<script>
// 默认时区：优先取创建管理员时的浏览器时区（若不在服务器列表中则回退 Asia/Shanghai）
(function () {
    var tz = 'Asia/Shanghai';
    try {
        tz = Intl.DateTimeFormat().resolvedOptions().timeZone || tz;
    } catch (e) {}
    var sel = document.getElementById('timezone');
    if (sel.querySelector('option[value="' + tz + '"]')) {
        sel.value = tz;
    } else {
        sel.value = 'Asia/Shanghai';
    }
})();

document.getElementById('installBtn').addEventListener('click', function () {
    var btn = this;
    var password = document.getElementById('password').value;
    var password2 = document.getElementById('password2').value;
    var hospital = document.getElementById('hospital_name').value.trim();
    // 校验时带上实际输入长度，便于用户发现输入法/自动填充导致的输入不完整
    if (password.length < 6) { Clinic.toast.warning('管理员密码不能少于6位（当前输入 ' + password.length + ' 位，请确认输入法为英文状态后重新输入）'); return; }
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
