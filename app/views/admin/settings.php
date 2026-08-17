<?php
/**
 * admin/settings.php — 系统设置
 * 说明：
 * 1. 医院名称 / 第二名称 / 页脚版权信息
 * 2. 医院 LOGO 上传（同时用作 favicon；未上传则不显示 LOGO）
 * 3. 全站时区（默认取创建管理员时的浏览器时区）
 * 4. 管理员密码修改
 */
Router::title('系统设置');

$logo = setting('logo', '');
$tz = setting('timezone', 'Asia/Shanghai');
$commonTz = array('Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Macau', 'Asia/Taipei', 'Asia/Tokyo', 'Asia/Singapore',
    'Asia/Seoul', 'Australia/Sydney', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles', 'UTC');
if (!in_array($tz, $commonTz, true)) {
    $commonTz[] = $tz;
}
$tzOpts = '';
foreach ($commonTz as $t) {
    $tzOpts .= '<option value="' . e($t) . '"' . ($tz === $t ? ' selected' : '') . '>' . e($t) . '</option>';
}
?>
<div class="page-head">
    <div><div class="page-title">⚙️ 系统设置</div><div class="page-desc">医院基础信息、LOGO、时区与安全设置</div></div>
</div>

<div class="flex gap-16" style="align-items:flex-start">
    <div class="card" style="flex:1;max-width:640px">
        <div class="card-title">医院信息</div>
        <div class="form-group"><label class="form-label">医院名称 <span class="req">*</span></label>
            <input class="input" id="s_hosp" value="<?php echo e(setting('hospital_name')); ?>"></div>
        <div class="form-group"><label class="form-label">医院第二名称</label>
            <input class="input" id="s_hosp2" value="<?php echo e(setting('hospital_name2')); ?>"></div>
        <div class="form-group"><label class="form-label">网站时区</label>
            <select class="select" id="s_tz"><?php echo $tzOpts; ?></select></div>
        <div class="fs-12 text-muted mb-12">页脚版权信息为固定格式，自动显示为【© <?php echo date('Y'); ?> <?php echo e(setting('hospital_name')); ?> 版权所有】，无需手动设置。</div>
        <div class="form-group"><label class="form-label">HIS 预留接口密钥（用于未来住院HIS等系统对接，留空则关闭外部接口）</label>
            <div class="flex gap-8">
                <input class="input" id="s_his_key" value="<?php echo e(setting('his_api_key')); ?>" placeholder="留空 = 关闭 HIS 外部接口" style="font-family:monospace">
                <button class="btn btn-outline btn-sm" onclick="genHisKey()">生成密钥</button>
            </div>
            <div class="fs-12 text-muted mt-4">接口地址：/api/his（GET，携带 api_key 参数或 X-HIS-Key 请求头），仅提供只读查询。</div></div>
        <button class="btn btn-primary" onclick="saveSettings()">保存设置</button>
    </div>

    <div class="card" style="flex:1;max-width:520px">
        <div class="card-title">医院 LOGO（同时作为 favicon）</div>
        <?php if ($logo !== ''): ?>
            <div class="mb-12"><img src="<?php echo e($logo); ?>" style="height:72px;border-radius:10px;background:var(--bg-soft);padding:6px" alt="LOGO"></div>
        <?php else: ?>
            <div class="fs-13 text-muted mb-12">尚未上传 LOGO，网站将不显示 LOGO 与 favicon。</div>
        <?php endif; ?>
        <div class="form-group"><input type="file" class="input" id="s_logo" accept="image/*"></div>
        <button class="btn btn-outline" onclick="uploadLogo()">上传 / 更新 LOGO</button>

        <div class="card-title mt-24">管理员密码</div>
        <div class="fs-13 text-muted mb-8">首次进入系统建议立即修改管理员密码。</div>
        <a class="btn btn-warning btn-sm" href="/password">前往修改密码</a>
    </div>
</div>

<script>
/* 生成随机 HIS 接口密钥 */
function genHisKey() {
    var arr = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    var key = Array.prototype.map.call(arr, function (b) {
        return ('0' + b.toString(16)).slice(-2);
    }).join('');
    document.getElementById('s_his_key').value = key;
    Clinic.toast.success('已生成密钥，请点击【保存设置】生效');
}

function saveSettings() {
    var hosp = document.getElementById('s_hosp').value.trim();
    if (!hosp) { Clinic.toast.warning('请填写医院名称'); return; }
    Clinic.ajax('/api/admin', {
        action: 'settings',
        hospital_name: hosp,
        hospital_name2: document.getElementById('s_hosp2').value.trim(),
        timezone: document.getElementById('s_tz').value,
        his_api_key: document.getElementById('s_his_key').value.trim(),
    }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            setTimeout(function () { location.reload(); }, 600);
        },
    });
}

function uploadLogo() {
    var file = document.getElementById('s_logo').files[0];
    if (!file) { Clinic.toast.warning('请先选择 LOGO 图片'); return; }
    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'upload_logo');
    fd.append('logo', file);
    fetch('/api/admin', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.ok) { Clinic.toast.success(json.msg); setTimeout(function () { location.reload(); }, 600); }
            else Clinic.toast.error(json.msg || '上传失败');
        })
        .catch(function () { Clinic.toast.error('网络请求失败'); });
}
</script>
