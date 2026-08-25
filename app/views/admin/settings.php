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
$logoData = img_data($logo);
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
    <div><div class="page-title">⚙️ 系统设置</div><div class="page-desc">按类别分区管理医院基础信息、品牌外观、作息时间与安全设置</div></div>
</div>

<div class="setting-grid">

    <!-- ===== 医院信息 ===== -->
    <div class="card setting-card">
        <div class="card-title">🏥 医院信息</div>
        <div class="form-group"><label class="form-label">医院名称 <span class="req">*</span></label>
            <input class="input" id="s_hosp" value="<?php echo e(setting('hospital_name')); ?>"></div>
        <div class="form-group"><label class="form-label">医院第二名称</label>
            <input class="input" id="s_hosp2" value="<?php echo e(setting('hospital_name2')); ?>"></div>
        <div class="form-group"><label class="form-label">网站时区</label>
            <select class="select" id="s_tz"><?php echo $tzOpts; ?></select></div>
        <div class="fs-12 text-muted mb-12">页脚版权信息为固定格式，自动显示为【© <?php echo date('Y'); ?> <?php echo e(setting('hospital_name')); ?> 版权所有】。</div>
        <div class="setting-sec-title">🔌 接口设置</div>
        <div class="form-group"><label class="form-label">HIS 预留接口密钥（留空则关闭外部接口）</label>
            <div class="flex gap-8">
                <input class="input" id="s_his_key" value="<?php echo e(setting('his_api_key')); ?>" placeholder="留空 = 关闭 HIS 外部接口" style="font-family:monospace">
                <button class="btn btn-outline btn-sm" onclick="genHisKey()">生成密钥</button>
            </div>
            <div class="fs-12 text-muted mt-4">接口地址：/api/his（GET，携带 api_key 参数或 X-HIS-Key 请求头），仅提供只读查询。</div></div>
        <button class="btn btn-primary" onclick="saveSettings()">保存设置</button>
    </div>

    <!-- ===== 品牌外观 ===== -->
    <div class="card setting-card">
        <div class="card-title">🎨 品牌外观</div>
        <div class="fs-13 text-muted mb-8">上传医院 LOGO，将作为登录页 / 系统侧边栏 / 浏览器图标（favicon）展示。</div>
        <?php if ($logoData !== ''): ?>
            <div class="mb-12"><img src="<?php echo e($logoData); ?>" style="height:72px;border-radius:10px;background:var(--bg-soft);padding:6px" alt="LOGO"></div>
        <?php else: ?>
            <div class="fs-13 text-muted mb-12">尚未上传 LOGO，网站将不显示 LOGO 与 favicon。</div>
        <?php endif; ?>
        <div class="form-group"><input type="file" class="input" id="s_logo" accept="image/*"></div>
        <button class="btn btn-outline" onclick="uploadLogo()">上传 / 更新 LOGO</button>
    </div>

    <!-- ===== 作息时间 ===== -->
    <div class="card setting-card">
        <div class="card-title">⏰ 作息时间</div>
        <?php
        $ws = work_schedule();
        $wsState = work_session_now();
        $stateText = array('before' => '未上班', 'am' => '上午可挂号', 'noon' => '午休', 'pm' => '下午可挂号', 'after' => '已下班');
        ?>
        <div class="fs-13" style="line-height:2">
            上午：<b><?php echo e($ws['am_start'] . ' ~ ' . $ws['am_end']); ?></b><br>
            下午：<b><?php echo e($ws['pm_start'] . ' ~ ' . $ws['pm_end']); ?></b><br>
            夏令时：<b><?php echo $ws['dst_enabled'] === '1' ? '已开启（' . e($ws['dst_start']) . ' ~ ' . e($ws['dst_end']) . '）' : '关闭'; ?></b><?php echo $ws['is_dst'] === '1' ? ' <span class="badge badge-warning">当前生效中</span>' : ''; ?><br>
            当前状态：<span class="badge badge-<?php echo in_array($wsState, array('am', 'pm'), true) ? 'success' : 'gray'; ?>"><?php echo $stateText[$wsState]; ?></span>
        </div>
        <div class="fs-12 text-muted mt-8 mb-12">门诊号源仅在作息时段内开放；急诊 24 小时可挂，不受作息限制。</div>
        <button class="btn btn-primary btn-sm" onclick="openWorkModal()">⏰ 设置作息时间</button>
    </div>

    <!-- ===== 安全设置 ===== -->
    <div class="card setting-card">
        <div class="card-title">🔐 安全设置</div>
        <div class="setting-sec-title">URL 安全混淆密钥（防链接撞库）</div>
        <div class="fs-13 text-muted mb-8">用于加密就诊、申请单、报告等链接中的实体 ID，防止通过改数字遍历他人医疗数据。</div>
        <div class="fs-12 mb-8" style="font-family:monospace;word-break:break-all;background:var(--bg-soft);border-radius:8px;padding:10px" id="obf_secret">加载中…</div>
        <div class="flex gap-8">
            <button class="btn btn-outline btn-sm" onclick="resetObfToken()">🔄 重置密钥</button>
            <button class="btn btn-outline btn-sm" onclick="copyObfSecret()">复制</button>
        </div>
        <div class="fs-12 text-warning mt-8">⚠️ 重置后：此前生成/分享/收藏的所有带 ID 链接立即失效；系统功能不受影响（新链接按新密钥即时生成）。建议在怀疑链接泄露时重置。</div>
    </div>

    <!-- ===== 账号安全 ===== -->
    <div class="card setting-card">
        <div class="card-title">👤 账号安全</div>
        <div class="fs-13 text-muted mb-8">修改当前登录管理员账号的登录密码。</div>
        <a class="btn btn-warning btn-sm" href="/password">前往修改密码</a>
    </div>

</div>

<script>
/* ---------- 作息时间设置模态框（含夏令时作息） ---------- */
var WS = <?php echo json_encode($ws); ?>;

function timeInput(id, label, val) {
    return '<div class="form-group"><label class="form-label">' + label + '</label>' +
        '<input type="time" class="input" id="' + id + '" value="' + (val || '') + '"></div>';
}

function openWorkModal() {
    var html =
        '<div class="form-row">' + timeInput('w_am_start', '上午上班', WS.am_start) + timeInput('w_am_end', '上午下班', WS.am_end) + '</div>' +
        '<div class="form-row">' + timeInput('w_pm_start', '下午上班', WS.pm_start) + timeInput('w_pm_end', '下午下班', WS.pm_end) + '</div>' +
        '<div class="fs-12 text-muted mb-12">门诊号源仅在上述时段内开放；急诊 24 小时可挂，不受作息限制。</div>' +
        '<div class="form-group"><label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">' +
        '<input type="checkbox" id="w_dst_enabled"' + (WS.dst_enabled === '1' ? ' checked' : '') + ' style="width:auto"> 开启夏令时作息（按日期范围自动切换）</label></div>' +
        '<div id="w_dst_box" style="display:none;background:var(--bg-soft);border-radius:10px;padding:12px">' +
        '<div class="form-row">' +
        '<div class="form-group"><label class="form-label">夏令时开始日期</label><input class="input" id="w_dst_start" value="' + (WS.dst_start || '') + '" placeholder="MM-DD，如 06-01"></div>' +
        '<div class="form-group"><label class="form-label">夏令时结束日期</label><input class="input" id="w_dst_end" value="' + (WS.dst_end || '') + '" placeholder="MM-DD，如 09-30"></div></div>' +
        '<div class="fs-12 text-muted mb-8">每年循环生效，支持跨年区间（如 11-01 ~ 03-31）。夏令时作息留空的时间项沿用上方常规作息。</div>' +
        '<div class="form-row">' + timeInput('w_dst_am_start', '夏令时上午上班', WS.dst_am_start) + timeInput('w_dst_am_end', '夏令时上午下班', WS.dst_am_end) + '</div>' +
        '<div class="form-row">' + timeInput('w_dst_pm_start', '夏令时下午上班', WS.dst_pm_start) + timeInput('w_dst_pm_end', '夏令时下午下班', WS.dst_pm_end) + '</div></div>';
    Clinic.modal.open(html, {
        title: '⏰ 作息时间设置',
        buttons: [
            { text: '取消', cls: 'btn-outline' },
            { text: '保存', cls: 'btn-primary', autoClose: false, onClick: saveWork },
        ],
    });
    syncDstBox();
    document.getElementById('w_dst_enabled').addEventListener('change', syncDstBox);
}

function syncDstBox() {
    document.getElementById('w_dst_box').style.display =
        document.getElementById('w_dst_enabled').checked ? '' : 'none';
}

function saveWork() {
    var data = {
        action: 'work_save',
        work_am_start: document.getElementById('w_am_start').value,
        work_am_end: document.getElementById('w_am_end').value,
        work_pm_start: document.getElementById('w_pm_start').value,
        work_pm_end: document.getElementById('w_pm_end').value,
        dst_enabled: document.getElementById('w_dst_enabled').checked ? '1' : '0',
        dst_start: document.getElementById('w_dst_start').value.trim(),
        dst_end: document.getElementById('w_dst_end').value.trim(),
        dst_am_start: document.getElementById('w_dst_am_start').value,
        dst_am_end: document.getElementById('w_dst_am_end').value,
        dst_pm_start: document.getElementById('w_dst_pm_start').value,
        dst_pm_end: document.getElementById('w_dst_pm_end').value,
    };
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            Clinic.modal.close();
            setTimeout(function () { location.reload(); }, 700);
        },
    });
}

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

/* ---------- URL 安全混淆密钥管理 ---------- */
var OBF_SECRET = '';
function loadObfStatus() {
    Clinic.get('/api/admin?action=obf_status', null, {
        onSuccess: function (json) {
            OBF_SECRET = json.data.secret || '';
            document.getElementById('obf_secret').textContent = OBF_SECRET || '（未生成）';
        },
    });
}
function resetObfToken() {
    Clinic.modal.open(
        '<div class="fs-14" style="line-height:1.9">确定要<b>重置 URL 混淆密钥</b>吗？<br>' +
        '<span class="text-warning fs-13">⚠️ 此前所有带 ID 的链接（打印链接、病历入口等）将立即失效；<br>系统功能不受影响，新链接会按新密钥即时生成。</span></div>',
        {
            title: '重置 URL 混淆密钥',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '确认重置', cls: 'btn-danger', autoClose: false, onClick: function () {
                    Clinic.ajax('/api/admin', { action: 'obf_reset' }, {
                        onSuccess: function (json) {
                            Clinic.modal.close();
                            Clinic.toast.success(json.msg);
                            loadObfStatus();
                        },
                    });
                } },
            ],
        }
    );
}
function copyObfSecret() {
    if (!OBF_SECRET) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(OBF_SECRET).then(function () { Clinic.toast.success('密钥已复制'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = OBF_SECRET; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        Clinic.toast.success('密钥已复制');
    }
}
loadObfStatus();

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
