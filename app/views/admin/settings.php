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
// 当前数据库类型（SQLITE / MYSQL / PGSQL）：管理员直观查看当前驱动
$dbType = strtoupper(DatabaseManager::driver());
?>
<div class="page-head">
    <div><div class="page-title">⚙️ 系统设置</div><div class="page-desc">按类别分区管理医院基础信息、品牌外观、作息时间与安全设置<br>
    HIS / 支付 / 医保 / DICOM-PACS / HL7 / FHIR 等外部接口已迁移至 <a href="/admin/integration" style="color:var(--primary)">🔌 接口管理</a> 统一维护</div></div>
    <div style="align-self:flex-start">
        <span class="badge badge-primary" style="font-size:11.5px;letter-spacing:.04em" title="当前数据库驱动">🗄️ 数据库：<?php echo e($dbType); ?></span>
    </div>
</div>

<!-- 多 Tab 导航 -->
<div class="flex gap-8 mb-12" id="settingsTabs" style="flex-wrap:wrap">
    <button type="button" class="btn btn-primary btn-sm" data-stab="clinic" onclick="settingsTab('clinic')">🏥 医院机构信息</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="db" onclick="settingsTab('db')">🗄️ 数据库中心</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="cache" onclick="settingsTab('cache')">⚡ 缓存与性能</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="security" onclick="settingsTab('security')">🔐 安全与加密</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="integration" onclick="settingsTab('integration')">🔌 外部集成与接口</button>
</div>

<!-- ============ Tab: 医院机构信息 ============ -->
<div class="stab-pane" id="stab-clinic">
<div class="setting-grid">

    <!-- ===== 医院信息（跨两列） ===== -->
    <div class="card setting-card setting-colspan-2">
        <div class="card-title">🏥 医院信息</div>
        <div class="form-group"><label class="form-label">医院名称 <span class="req">*</span></label>
            <input class="input" id="s_hosp" value="<?php echo e(setting('hospital_name')); ?>"></div>
        <div class="form-group"><label class="form-label">医疗机构代码 <span class="req">*</span></label>
            <input class="input" id="s_org_code" value="<?php echo e(setting('org_code')); ?>" placeholder="如：410105001234">
            <div class="fs-12 text-muted mt-4">医保结算、监管报送与接口对接的唯一标识。</div></div>
        <div class="form-group"><label class="form-label">医院第二名称</label>
            <input class="input" id="s_hosp2" value="<?php echo e(setting('hospital_name2')); ?>"></div>
        <div class="form-group"><label class="form-label">机构简介</label>
            <textarea class="textarea" id="s_intro" rows="4" placeholder="机构简介（选填），供对外展示与后续扩展使用"><?php echo e(setting('hospital_intro')); ?></textarea></div>
        <div class="form-group"><label class="form-label">网站时区</label>
            <select class="select" id="s_tz"><?php echo $tzOpts; ?></select></div>
        <div class="fs-12 text-muted mb-12">页脚版权信息为固定格式，自动显示为【© <?php echo date('Y'); ?> <?php echo e(setting('hospital_name')); ?> 版权所有】。</div>
        <button class="btn btn-primary" onclick="saveSettings()">保存设置</button>
    </div>

    <!-- ===== 品牌外观 ===== -->
    <div class="card setting-card">
        <div class="card-title">🎨 品牌外观</div>
        <div class="flex gap-16" style="align-items:center">
            <div class="fs-13 text-muted" style="flex:1;line-height:1.8">上传医院 LOGO，将作为登录页 / 系统侧边栏 / 浏览器图标（favicon）展示。<br>尚未上传 LOGO，网站将不显示 LOGO 与 favicon。</div>
            <div style="flex-shrink:0;text-align:center">
                <div class="logo-uploader" onclick="document.getElementById('s_logo').click()" title="点击更换 LOGO">
                    <?php if ($logoData !== ''): ?>
                        <img src="<?php echo e($logoData); ?>" alt="LOGO">
                    <?php else: ?>
                        <span class="logo-placeholder">🏥</span>
                    <?php endif; ?>
                    <span class="logo-upload-badge">📷</span>
                </div>
                <div class="fs-12 text-muted mt-4">点击更换</div>
            </div>
        </div>
        <input type="file" id="s_logo" accept="image/*" style="display:none" onchange="uploadLogo()">
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

</div>
</div><!-- /stab-clinic -->

<!-- ============ Tab: 数据库中心 ============ -->
<div class="stab-pane" id="stab-db" style="display:none">
    <div class="card setting-card">
        <div class="card-title">🗄️ 当前数据库连接</div>
        <div id="dbStatusBox" class="fs-13" style="line-height:2"><div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
        <div class="fs-12 text-muted mt-8">config.db 为基础设施配置库（主库驱动/连接凭证/缓存等）；主业务数据独立存放于主数据库，删除 config.db 仅重置配置，不会破坏业务数据。</div>
    </div>
    <div class="card setting-card">
        <div class="card-title">📋 数据表浏览器</div>
        <div class="fs-13 text-muted mb-8">点击任意表查看字段属性与分页行数据（只读），支持导出 CSV。</div>
        <div id="dbTableList" class="fs-13" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md);padding:6px"><div class="text-muted">加载中…</div></div>
        <div class="flex gap-8 mt-8">
            <button class="btn btn-outline btn-sm" onclick="loadDbStatus()">🔄 刷新状态</button>
            <button class="btn btn-outline btn-sm" onclick="exportDbTableCsv()">⬇️ 导出当前表 CSV</button>
        </div>
    </div>
    <div class="card setting-card">
        <div class="card-title">🔄 数据库迁移工具</div>
        <div class="fs-13 text-muted mb-8">支持 SQLite ↔ MySQL 双向全量迁移（分批 Chunk 同步、外键约束临时关闭、自增序列校准）。迁移期间系统进入只读维护模式。</div>
        <div class="flex gap-8">
            <button class="btn btn-outline btn-sm" onclick="Clinic.toast.info('迁移引擎即将推出，敬请期待')">开始迁移</button>
        </div>
    </div>
</div>

<!-- ============ Tab: 缓存与性能 ============ -->
<div class="stab-pane" id="stab-cache" style="display:none">
    <div class="card setting-card">
        <div class="card-title">⚡ 缓存状态</div>
        <div id="cacheStatusBox" class="fs-13" style="line-height:2"><div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
    </div>
    <div class="card setting-card">
        <div class="card-title">🧹 模块化缓存刷新</div>
        <div class="fs-13 text-muted mb-8">按模块清除缓存文件（系统配置 / ICD-10 与字典 / 排班叫号临时 / 全量）。</div>
        <div class="flex gap-8" style="flex-wrap:wrap">
            <button class="btn btn-outline btn-sm" onclick="flushCache('config')">刷新系统配置缓存</button>
            <button class="btn btn-outline btn-sm" onclick="flushCache('dict')">刷新字典缓存</button>
            <button class="btn btn-outline btn-sm" onclick="flushCache('call')">刷新排班叫号缓存</button>
            <button class="btn btn-danger btn-sm" onclick="flushCache('all')">全量刷新</button>
        </div>
        <div class="fs-12 text-muted mt-8" id="cacheFlushMsg"></div>
    </div>
</div>

<!-- ============ Tab: 安全与加密 ============ -->
<div class="stab-pane" id="stab-security" style="display:none">
<div class="setting-grid">

    <!-- ===== 安全设置 ===== -->
    <div class="card setting-card">
        <div class="card-title">🔐 安全设置</div>
        <div class="setting-sec-title" style="margin-top:0;padding-top:0;border-top:none">登录验证码与防爆破锁定</div>
        <div class="form-group"><label class="form-label">登录验证码启用模式</label>
            <select class="select" id="s_captcha_mode">
                <option value="auto"<?php echo setting('login_captcha_mode', 'auto') === 'auto' ? ' selected' : ''; ?>>智能开启（默认：正常不显示，遇错误/风险自动弹出）</option>
                <option value="force"<?php echo setting('login_captcha_mode') === 'force' ? ' selected' : ''; ?>>强制开启（每次登录均要求验证码）</option>
                <option value="off"<?php echo setting('login_captcha_mode') === 'off' ? ' selected' : ''; ?>>不开启（完全不展示与校验验证码）</option>
            </select></div>
        <div class="form-group"><label class="form-label">密码连续错误锁定阈值（次）</label>
            <input class="input" id="s_lock_count" type="number" min="3" max="10" value="<?php echo (int)setting('login_fail_lock_count', 5); ?>">
            <div class="fs-12 text-muted mt-4">连续密码错误达到阈值后账号自动安全锁定（status=0），需管理员在【用户管理】中解锁；解锁时同步重置错误计数与锁定信息。</div></div>
        <button class="btn btn-primary btn-sm" onclick="saveSettings()">保存</button>
        <div class="setting-sec-title" style="margin-top:18px">URL 安全混淆密钥（防链接撞库）</div>
        <div class="fs-13 text-muted mb-8">用于加密就诊、申请单、报告等链接中的实体 ID，防止通过改数字遍历他人医疗数据。</div>
        <div class="fs-12 mb-8" style="font-family:monospace;word-break:break-all;background:var(--bg-soft);border-radius:var(--radius-md);padding:10px" id="obf_secret">加载中…</div>
        <div class="flex gap-8">
            <button class="btn btn-outline btn-sm" onclick="resetObfToken()">🔄 重置密钥</button>
            <button class="btn btn-outline btn-sm" onclick="copyObfSecret()">复制</button>
        </div>
        <div class="fs-12 text-warning mt-8">⚠️ 重置后：此前生成/分享/收藏的所有带 ID 链接立即失效；系统功能不受影响（新链接按新密钥即时生成）。建议在怀疑链接泄露时重置。</div>
    </div>

</div>
</div><!-- /stab-security -->

<!-- ============ Tab: 外部集成与接口 ============ -->
<div class="stab-pane" id="stab-integration" style="display:none">
    <div class="card setting-card">
        <div class="card-title">🔌 外部集成与接口</div>
        <div class="fs-13 mb-8">HIS / 支付 / 医保 / DICOM-PACS / HL7 v2.x / FHIR R4 / 存证·电子签名 等外部接口已统一迁移至接口管理页维护。</div>
        <a class="btn btn-primary" href="/admin/integration">→ 前往接口管理</a>
    </div>
</div>

<script>
/* ---------- 作息时间设置模态框（含夏令时作息） ---------- */
var WS = <?php echo json_encode($ws); ?>;

/* 快捷时间选择：输入框 + ▾ 弹层点选常用时段（可手动输入覆盖） */
function timeInput(id, label, val) {
    return '<div class="form-group"><label class="form-label">' + label + '</label>' +
        '<div class="tp-wrap"><input type="time" class="input" id="' + id + '" value="' + (val || '') + '">' +
        '<button type="button" class="tp-btn" title="快捷选择时间" onclick="toggleTimePick(this,\'' + id + '\')">▾</button></div></div>';
}
function toggleTimePick(btn, id) {
    var old = document.getElementById('tpPop');
    if (old && old.getAttribute('data-target') === id) { old.remove(); return; }
    if (old) old.remove();
    var pop = document.createElement('div');
    pop.id = 'tpPop';
    pop.setAttribute('data-target', id);
    pop.className = 'tp-pop';
    var opts = [];
    for (var h = 6; h <= 23; h++) {
        opts.push(('0' + h).slice(-2) + ':00', ('0' + h).slice(-2) + ':30');
    }
    pop.innerHTML = opts.map(function (t) {
        return '<div class="tp-item" data-t="' + t + '">' + t + '</div>';
    }).join('');
    document.body.appendChild(pop);
    var rect = btn.getBoundingClientRect();
    pop.style.top = Math.min(rect.bottom + 6, window.innerHeight - 260) + 'px';
    pop.style.left = Math.min(rect.left, window.innerWidth - 150) + 'px';
    pop.querySelectorAll('.tp-item').forEach(function (it) {
        it.addEventListener('click', function () {
            document.getElementById(id).value = it.getAttribute('data-t');
            pop.remove();
        });
    });
    setTimeout(function () {
        document.addEventListener('mousedown', function h(e) {
            if (!pop.contains(e.target) && e.target !== btn) {
                pop.remove();
                document.removeEventListener('mousedown', h);
            }
        });
    }, 0);
}

/* 夏令时日期 + 时间 + 快捷选择器 */
function dateInput(id, label, val) {
    return '<div class="form-group"><label class="form-label">' + label + '</label>' +
        '<div class="tp-wrap"><input class="input" id="' + id + '" value="' + (val || '') + '" placeholder="MM-DD，如 06-01">' +
        '<button type="button" class="tp-btn" title="快捷选择日期" onclick="toggleDatePick(this,\'' + id + '\')">📅</button></div></div>';
}
function toggleDatePick(btn, id) {
    var old = document.getElementById('dpPop');
    if (old && old.getAttribute('data-target') === id) { old.remove(); return; }
    if (old) old.remove();
    var dp = { month: 0, day: 0 };
    var months = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
    var pop = document.createElement('div');
    pop.id = 'dpPop';
    pop.setAttribute('data-target', id);
    pop.className = 'tp-pop dp-pop';
    var mRow = months.map(function (m, i) { return '<div class="dp-month" data-i="' + i + '">' + m + '</div>'; }).join('');
    pop.innerHTML = '<div class="dp-months">' + mRow + '</div><div class="dp-days" id="dpDays"></div>';
    document.body.appendChild(pop);
    var rect = btn.getBoundingClientRect();
    pop.style.top = Math.min(rect.bottom + 6, window.innerHeight - 280) + 'px';
    pop.style.left = Math.min(rect.left, window.innerWidth - 300) + 'px';
    var renderDays = function () {
        var dim = new Date(2000, dp.month + 1, 0).getDate();
        var html = '';
        for (var d = 1; d <= dim; d++) html += '<div class="dp-day" data-d="' + d + '">' + d + '</div>';
        document.getElementById('dpDays').innerHTML = html;
        document.getElementById('dpDays').querySelectorAll('.dp-day').forEach(function (el) {
            el.addEventListener('click', function () {
                document.getElementById(id).value =
                    ('0' + (dp.month + 1)).slice(-2) + '-' + ('0' + parseInt(el.getAttribute('data-d'), 10)).slice(-2);
                pop.remove();
            });
        });
    };
    pop.querySelectorAll('.dp-month').forEach(function (el) {
        el.addEventListener('click', function () {
            pop.querySelectorAll('.dp-month').forEach(function (m) { m.classList.remove('active'); });
            el.classList.add('active');
            dp.month = parseInt(el.getAttribute('data-i'), 10);
            renderDays();
        });
    });
    setTimeout(function () { document.addEventListener('mousedown', function h(e) { if (!pop.contains(e.target) && e.target !== btn) { pop.remove(); document.removeEventListener('mousedown', h); } }); }, 0);
}

/* 作息时间设置模态框 */
function openWorkModal() {
    var html =
        '<div class="form-row">' + timeInput('w_am_start', '上午上班', WS.am_start) + timeInput('w_am_end', '上午下班', WS.am_end) + '</div>' +
        '<div class="form-row">' + timeInput('w_pm_start', '下午上班', WS.pm_start) + timeInput('w_pm_end', '下午下班', WS.pm_end) + '</div>' +
        '<div class="fs-12 text-muted mb-12">门诊号源仅在上述时段内开放；急诊 24 小时可挂，不受作息限制。</div>' +
        '<div class="form-group"><label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">' +
        '<input type="checkbox" id="w_dst_enabled"' + (WS.dst_enabled === '1' ? ' checked' : '') + ' style="width:auto"> 开启夏令时作息（按日期范围自动切换）</label></div>' +
        '<div id="w_dst_box" style="display:none;background:var(--bg-soft);border-radius:10px;padding:12px">' +
        '<div class="form-row">' +
        dateInput('w_dst_start', '夏令时开始日期', WS.dst_start) +
        dateInput('w_dst_end', '夏令时结束日期', WS.dst_end) + '</div>' +
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

/* ---------- HIS 密钥迁移：已移至【接口管理】（/admin/integration）统一维护 ---------- */

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
    var orgCode = document.getElementById('s_org_code').value.trim();
    if (!orgCode) { Clinic.toast.warning('请填写医疗机构代码'); return; }
    var lockCount = parseInt(document.getElementById('s_lock_count').value, 10) || 5;
    if (lockCount < 3 || lockCount > 10) { Clinic.toast.warning('锁定阈值需在 3-10 次之间'); return; }
    Clinic.ajax('/api/admin', {
        action: 'settings',
        hospital_name: hosp,
        org_code: orgCode,
        hospital_name2: document.getElementById('s_hosp2').value.trim(),
        hospital_intro: document.getElementById('s_intro').value.trim(),
        timezone: document.getElementById('s_tz').value,
        login_captcha_mode: document.getElementById('s_captcha_mode').value,
        login_fail_lock_count: String(lockCount),
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

/* ---------- 系统设置多 Tab 切换 ---------- */
function settingsTab(name) {
    document.querySelectorAll('#settingsTabs [data-stab]').forEach(function (b) {
        b.classList.toggle('btn-primary', b.getAttribute('data-stab') === name);
        b.classList.toggle('btn-outline', b.getAttribute('data-stab') !== name);
    });
    document.querySelectorAll('.stab-pane').forEach(function (p) {
        p.style.display = p.id === 'stab-' + name ? '' : 'none';
    });
    if (name === 'db') loadDbStatus();
    if (name === 'cache') loadCacheStatus();
}
settingsTab('clinic');

/* ---------- 数据库中心 ---------- */
var DB_CUR_TABLE = '';
function loadDbStatus() {
    var box = document.getElementById('dbStatusBox');
    Clinic.get('/api/admin?action=db_status', null, {
        loading: false,
        onSuccess: function (json) {
            var d = json.data || {};
            var cfg = d.config_available ? '✓ 有效（' + escHtml(d.config_path) + '）' : '（无 config.db，按默认配置运行）';
            box.innerHTML =
                '<div class="flex-between"><span class="text-muted">驱动类型</span><span class="fw-600">' + escHtml(d.driver_label) + '</span></div>' +
                '<div class="flex-between"><span class="text-muted">连接延迟</span><span>' + d.delay_ms + ' ms</span></div>' +
                '<div class="flex-between"><span class="text-muted">表数量</span><span>' + d.table_count + ' 张</span></div>' +
                '<div class="flex-between"><span class="text-muted">总行数</span><span>' + d.total_rows + ' 行</span></div>' +
                '<div class="flex-between"><span class="text-muted">库大小</span><span>' + escHtml(d.size_human) + '</span></div>' +
                '<div class="flex-between"><span class="text-muted">配置库</span><span class="fs-12">' + cfg + '</span></div>';
            var tl = document.getElementById('dbTableList');
            tl.innerHTML = (d.tables || []).map(function (t) {
                return '<div class="flex-between" style="padding:5px 8px;border-radius:6px;cursor:pointer" onmouseover="this.style.background=\'var(--bg-soft)\'" onmouseout="this.style.background=\'\'" onclick="openDbTable(\'' + escHtml(t.name) + '\')">' +
                    '<span class="fw-600">' + escHtml(t.name) + '</span>' +
                    '<span class="fs-12 text-muted">' + t.rows + ' 行</span></div>';
            }).join('') || '<div class="text-muted">无表</div>';
        },
        onError: function () { box.innerHTML = '<span class="text-danger">数据库状态读取失败</span>'; },
    });
}

var DB_TABLE_MODAL = null;
function openDbTable(table) {
    DB_CUR_TABLE = table;
    Clinic.get('/api/admin?action=db_table_data&table=' + encodeURIComponent(table) + '&page=1&size=20', null, {
        loading: false,
        onSuccess: function (json) { renderDbTable(json.data); },
        onError: function (x, j) { Clinic.toast.error((j && j.msg) || '读取失败'); },
    });
}
function renderDbTable(d) {
    var head = (d.cols || []).map(function (c) { return '<th>' + escHtml(c.name) + '</th>'; }).join('');
    var body = (d.rows || []).map(function (r) {
        return '<tr>' + (d.cols || []).map(function (c) {
            var v = r[c.name] === null || r[c.name] === undefined ? '' : String(r[c.name]);
            var full = v;
            if (v.length > 60) v = v.substr(0, 60) + '…';
            return '<td class="fs-12" title="' + escHtml(full) + '">' + escHtml(v) + '</td>';
        }).join('') + '</tr>';
    }).join('');
    var html =
        '<div class="flex-between mb-8"><span class="fw-600 fs-14">📋 ' + escHtml(d.table) + '（共 ' + d.total + ' 行）</span>' +
        '<span class="flex gap-8">' +
        '<button class="btn btn-outline btn-sm" onclick="exportDbTableCsv()">⬇️ CSV</button>' +
        (d.page > 1 ? '<button class="btn btn-outline btn-sm" onclick="dbTablePage(' + (d.page - 1) + ')">← 上一页</button>' : '') +
        (d.has_more ? '<button class="btn btn-outline btn-sm" onclick="dbTablePage(' + (d.page + 1) + ')">下一页 →</button>' : '') +
        '</span></div>' +
        '<div class="table-wrap" style="max-height:420px;overflow:auto"><table class="table"><thead><tr>' + head + '</tr></thead><tbody>' +
        (body || '<tr><td colspan="99" class="text-muted">无数据</td></tr>') + '</tbody></table></div>';
    if (DB_TABLE_MODAL) { DB_TABLE_MODAL.innerHTML = html; }
    else { DB_TABLE_MODAL = Clinic.modal.open(html, { title: '数据表查看', size: 'modal-xl' }); }
}
function dbTablePage(p) {
    Clinic.get('/api/admin?action=db_table_data&table=' + encodeURIComponent(DB_CUR_TABLE) + '&page=' + p + '&size=20', null, {
        loading: false,
        onSuccess: function (json) { renderDbTable(json.data); },
    });
}
function exportDbTableCsv() {
    if (!DB_CUR_TABLE) { Clinic.toast.warning('请先选择数据表'); return; }
    Clinic.get('/api/admin?action=db_table_data&table=' + encodeURIComponent(DB_CUR_TABLE) + '&page=1&size=1000', null, {
        loading: false,
        onSuccess: function (json) {
            var d = json.data;
            var cols = (d.cols || []).map(function (c) { return c.name; });
            var lines = [cols.join(',')];
            (d.rows || []).forEach(function (r) {
                lines.push(cols.map(function (c) {
                    var v = r[c] === null || r[c] === undefined ? '' : String(r[c]);
                    return '"' + v.replace(/"/g, '""') + '"';
                }).join(','));
            });
            var blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = d.table + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        },
    });
}

/* ---------- 缓存与性能 ---------- */
function loadCacheStatus() {
    var box = document.getElementById('cacheStatusBox');
    Clinic.get('/api/admin?action=cache_status', null, {
        loading: false,
        onSuccess: function (json) {
            var d = json.data || {};
            box.innerHTML =
                '<div class="flex-between"><span class="text-muted">缓存驱动</span><span class="fw-600">' + escHtml(d.driver_label) + '</span></div>' +
                '<div class="flex-between"><span class="text-muted">键数量</span><span>' + (d.keys || 0) + '</span></div>' +
                (d.memory ? '<div class="flex-between"><span class="text-muted">占用内存</span><span>' + escHtml(d.memory) + '</span></div>' : '') +
                ((d.notes || []).length ? d.notes.map(function (n) { return '<div class="fs-12 text-warning mt-4">⚠ ' + escHtml(n) + '</div>'; }).join('') : '');
        },
        onError: function () { box.innerHTML = '<span class="text-danger">缓存状态读取失败</span>'; },
    });
}
function flushCache(scope) {
    var msg = document.getElementById('cacheFlushMsg');
    msg.textContent = '刷新中…';
    Clinic.get('/api/admin?action=cache_flush&scope=' + encodeURIComponent(scope), null, {
        loading: false,
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; },
        onError: function (x, j) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((j && j.msg) || '刷新失败') + '</span>'; },
    });
}
</script>
