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
    <div><div class="page-title">⚙️ 系统设置</div><div class="page-desc">按类别分区管理医院基础信息、品牌外观、作息时间与安全设置</div></div>
</div>

<!-- 多 Tab 导航 -->
<div class="flex gap-8 mb-12" id="settingsTabs" style="flex-wrap:wrap">
    <button type="button" class="btn btn-primary btn-sm" data-stab="clinic" onclick="settingsTab('clinic')">🏥 医院机构信息</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="db" onclick="settingsTab('db')">🗄️ 数据库中心</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="cache" onclick="settingsTab('cache')">⚡ 缓存与性能</button>
    <button type="button" class="btn btn-outline btn-sm" data-stab="security" onclick="settingsTab('security')">🔐 安全与加密</button>
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

<!-- ============ Tab: 数据库中心（左右两栏） ============ -->
<div class="stab-pane" id="stab-db" style="display:none">
    <style>
        .db-center { display: flex; gap: 14px; align-items: flex-start; height: calc(100vh - var(--topbar-h) - 120px); }
        .db-sidebar { width: 150px; flex-shrink: 0; padding: 10px; }
        .db-nav {
            display: block; width: 100%; text-align: left; padding: 9px 12px; margin-bottom: 4px;
            border-radius: var(--radius-sm); border: 0; background: transparent; cursor: pointer;
            font-size: 13px; color: var(--text); transition: background .15s, color .15s;
        }
        .db-nav:hover { background: var(--bg-soft); }
        .db-nav.active { background: var(--primary); color: #fff; font-weight: 600; }
        .db-main { flex: 1; min-width: 0; height: 100%; overflow-y: auto; padding-bottom: 18px; }
        .db-pane .card.setting-card { margin-bottom: 14px; }
        /* 数据表浏览器：列表延伸至页面底部（卡片占满高度，列表内部滚动） */
        #dbtab-browse { height: 100%; display: flex; flex-direction: column; }
        #dbtab-browse .card.setting-card { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        #dbtab-browse #dbTableList { flex: 1; min-height: 0; overflow-y: auto; }
    </style>
    <div class="db-center">
        <!-- 左侧边栏 -->
        <div class="card db-sidebar">
            <div class="db-nav active" data-dbtab="detail" onclick="dbTab('detail')">📊 详情</div>
            <div class="db-nav" data-dbtab="browse" onclick="dbTab('browse')">📋 浏览</div>
            <div class="db-nav" data-dbtab="migrate" onclick="dbTab('migrate')">🔄 迁移</div>
            <div class="db-nav" data-dbtab="switch" onclick="dbTab('switch')">🔁 切换</div>
            <div class="db-nav" data-dbtab="backup" onclick="dbTab('backup')">💾 备份</div>
        </div>
        <!-- 右侧内容 -->
        <div class="db-main">
            <div class="db-pane" id="dbtab-detail">
                <div class="card setting-card">
                    <div class="card-title">🗄️ 当前数据库连接</div>
                    <div id="dbStatusBox" class="fs-13" style="line-height:2"><div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
                    <div class="fs-12 text-muted mt-8">config.db 为基础设施配置库（主库驱动/连接凭证/缓存等）；主业务数据独立存放于主数据库，删除 config.db 仅重置配置，不会破坏业务数据。</div>
                    <div class="fs-13 mt-12" id="dbActiveTasks"></div>
                </div>
            </div>
            <div class="db-pane" id="dbtab-browse" style="display:none">
                <div class="card setting-card">
                    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between"><span>📋 数据表浏览器</span><span class="badge badge-primary" id="browseDriverBadge" style="font-size:11.5px">—</span></div>
                    <div class="fs-13 text-muted mb-8">点击任意表查看字段属性与滚动加载行数据（只读，模态框内支持 CSV 导出）；SQLite / MySQL / PostgreSQL 均支持。</div>
                    <div id="dbTableList" class="fs-13" style="border:1px solid var(--border);border-radius:var(--radius-md);padding:6px"><div class="text-muted">加载中…</div></div>
                    <div class="mt-8"><button class="btn btn-outline btn-sm" onclick="loadDbStatus()">🔄 刷新表列表</button></div>
                </div>
            </div>
            <div class="db-pane" id="dbtab-migrate" style="display:none">
                <div class="card setting-card">
                    <div class="card-title">🔄 数据库迁移工具</div>
                    <div class="fs-13 text-muted mb-8">支持 SQLite / MySQL / PostgreSQL 三驱动任意双向全量迁移（分批 Chunk 500 行同步、外键约束临时关闭、自增序列校准）。迁移以后台任务执行、刷新页面不中断；期间全站锁定并显示进度条，完成后由管理员确认是否将主库切换为目标数据库（取消/失败自动回退原库）。</div>
                    <div class="form-group"><label class="form-label">目标驱动 <span class="req">*</span></label>
                        <select class="select" id="migDriver" onchange="toggleMigOpts()"></select>
                        <div class="fs-12 text-muted mt-4">选项来自系统驱动注册表（与安装向导一致），自动屏蔽当前驱动。</div></div>
                    <div id="migParamsBox"></div>
                    <div class="flex gap-8" style="flex-wrap:wrap;align-items:center">
                        <button class="btn btn-danger btn-sm" onclick="startMigrate()">⚠️ 开始迁移（全站锁定+进度条）</button>
                    </div>
                    <div class="fs-13 mt-8" id="migMsg"></div>
                </div>
            </div>
            <div class="db-pane" id="dbtab-switch" style="display:none">
                <div class="card setting-card">
                    <div class="card-title">🔁 直接切换主库（不迁移数据）</div>
                    <div class="fs-13 text-muted mb-8">目标库须已存在完整业务数据（users 表非空）。切换强制清除全部用户会话并全站锁定，切换后所有用户重新登录。支持同驱动切换（如 MySQL → 另一 MySQL 库）。</div>
                    <div class="fs-13 mb-8">当前主库：<span class="badge badge-primary" id="swCurDriver" style="font-size:11.5px">—</span></div>
                    <div class="form-group"><label class="form-label">目标驱动 <span class="req">*</span></label>
                        <select class="select" id="swDriver" onchange="toggleSwOpts()"></select></div>
                    <div id="swParamsBox"></div>
                    <button class="btn btn-outline btn-sm" onclick="switchMainDirect()">🔁 切换到所选主库</button>
                    <div class="fs-13 mt-8" id="swMsg"></div>
                </div>
            </div>
            <div class="db-pane" id="dbtab-backup" style="display:none">
                <div class="card setting-card">
                    <div class="card-title">💾 多数据库备份 / 双向同步</div>
                    <div class="fs-13 text-muted mb-8">备份与双向为<b>两个独立功能</b>（二选一）：备份=手动/定时全量同步；双向=每次写入实时镜像到备份库（RAID1 式，仅同驱动可靠，失败自动降级不影响主库体验）。</div>
                    <!-- 共用：驱动选择 + 数据库配置 -->
                    <div class="form-group"><label class="form-label">备份/同步目标驱动</label>
                        <select class="select" id="bkDriver" onchange="toggleBkOpts()"></select>
                        <div class="fs-12 text-muted mt-4">驱动与连接配置为备份/同步共用；下方按钮切换备份或双向同步的特定内容。</div></div>
                    <div id="bkParamsBox"></div>
                    <div class="fs-13 mt-8" id="bkLastAt" style="display:none"></div>
                    <!-- 下方按钮：备份 / 同步 -->
                    <div class="flex gap-8 mb-12 mt-12">
                        <button type="button" class="btn btn-primary btn-sm" id="bkModeBackup" onclick="bkMode('backup')">💾 备份</button>
                        <button type="button" class="btn btn-outline btn-sm" id="bkModeDual" onclick="bkMode('dual')">🔁 同步</button>
                    </div>
                    <!-- 备份特定内容 -->
                    <div class="bk-mode-pane" id="bkmode-backup">
                        <div class="form-group"><label class="form-label">定时自动备份</label>
                            <div class="flex gap-8" style="align-items:center">
                                <select class="select" id="bkHour" style="width:110px"></select>
                                <span class="text-muted fs-12">（每天该时刻自动备份，留空=关闭）</span>
                            </div></div>
                        <div class="flex gap-8">
                            <button class="btn btn-primary btn-sm" onclick="saveBackupCfg()">保存设置</button>
                            <button class="btn btn-danger btn-sm" onclick="runBackup()">立即备份</button>
                            <button class="btn btn-outline btn-sm" onclick="viewBackupLogs()">📜 查看日志</button>
                        </div>
                    </div>
                    <!-- 双向同步特定内容 -->
                    <div class="bk-mode-pane" id="bkmode-dual" style="display:none">
                        <div class="form-row">
                            <div class="form-group"><label class="form-label">镜像延迟（秒）</label>
                                <input class="input" id="dualDelay" type="number" min="0" max="60" value="0" style="width:120px">
                                <div class="fs-12 text-muted mt-4">0=同步镜像（随写入立即镜像）；大于 0 为异步延迟镜像。</div></div>
                            <div class="form-group"><label class="form-label">状态</label>
                                <span class="fs-13" id="dualStatus">未开启</span></div>
                        </div>
                        <div class="flex gap-8">
                            <button class="btn btn-primary btn-sm" onclick="saveBackupCfg()">保存设置</button>
                            <button class="btn btn-outline btn-sm" onclick="viewBackupLogs()">📜 查看日志</button>
                        </div>
                    </div>
                    <div class="fs-13 mt-8" id="bkMsg"></div>
                </div>
            </div>
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
        <div class="card-title">🔁 缓存驱动切换</div>
        <div class="fs-13 text-muted mb-8">选择缓存/会话驱动（写入 config.db，会话驱动将同步生效）。选项来自系统驱动注册表（与安装向导一致），未安装扩展的驱动会标注。</div>
        <div class="form-group"><label class="form-label">驱动</label>
            <select class="select" id="cacheDriverSel" onchange="toggleCacheRedisOpts()"></select></div>
        <div id="cacheParamsBox"></div>
        <button class="btn btn-primary btn-sm" onclick="saveCacheDriver()">保存缓存驱动</button>
        <span class="fs-13 text-muted ml-8" id="cacheDriverMsg"></span>
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
    <div class="card setting-card">
        <div class="card-title">🔐 登录安全与防爆破</div>
        <div class="setting-sec-title" style="margin-top:0;padding-top:0;border-top:none">登录验证码与防爆破锁定</div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">登录验证码启用模式</label>
                <select class="select" id="s_captcha_mode">
                    <option value="auto"<?php echo setting('login_captcha_mode', 'auto') === 'auto' ? ' selected' : ''; ?>>智能开启（默认：正常不显示，遇错误/风险自动弹出）</option>
                    <option value="force"<?php echo setting('login_captcha_mode') === 'force' ? ' selected' : ''; ?>>强制开启（每次登录均要求验证码）</option>
                    <option value="off"<?php echo setting('login_captcha_mode') === 'off' ? ' selected' : ''; ?>>不开启（完全不展示与校验验证码）</option>
                </select></div>
            <div class="form-group"><label class="form-label">密码连续错误锁定阈值（次）</label>
                <input class="input" id="s_lock_count" type="number" min="3" max="10" value="<?php echo (int)setting('login_fail_lock_count', 5); ?>">
                <div class="fs-12 text-muted mt-4">连续密码错误达到阈值后账号自动安全锁定（status=0），需管理员在【用户管理】中解锁；解锁时同步重置错误计数与锁定信息。</div></div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="saveSettings()">保存安全设置</button>
    </div>
    <div class="card setting-card">
        <div class="card-title">🔗 URL 安全混淆密钥（防链接撞库）</div>
        <div class="fs-13 text-muted mb-8">用于加密就诊、申请单、报告等链接中的实体 ID，防止通过改数字遍历他人医疗数据。</div>
        <div class="fs-12 mb-8" style="font-family:monospace;word-break:break-all;background:var(--bg-soft);border-radius:var(--radius-md);padding:10px" id="obf_secret">加载中…</div>
        <div class="flex gap-8">
            <button class="btn btn-outline btn-sm" onclick="resetObfToken()">🔄 重置密钥</button>
            <button class="btn btn-outline btn-sm" onclick="copyObfSecret()">复制</button>
        </div>
        <div class="fs-12 text-warning mt-8">⚠️ 重置后：此前生成/分享/收藏的所有带 ID 链接立即失效；系统功能不受影响（新链接按新密钥即时生成）。建议在怀疑链接泄露时重置。</div>
    </div>
</div><!-- /stab-security -->

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
    if (name === 'db') { dbTab('detail'); loadDbStatus(); }
    if (name === 'cache') { if (!SET_DRIVERS) loadDbStatus(); loadCacheStatus(); }
}
settingsTab('clinic');

/* ---------- 数据库中心 ---------- */
var DB_CUR_TABLE = '';
/* 活动任务展示（迁移/切换/备份/双向同步，未启用不显示） */
function renderActiveTasks(t) {
    var box = document.getElementById('dbActiveTasks');
    if (!box) return;
    var items = [];
    if (t.migration) {
        var st = t.migration.status;
        var stTxt = st === 'running' ? '<span style="color:var(--primary)">🔄 进行中</span>' : (st === 'done' ? '<span style="color:var(--success)">✅ 完成待确认</span>' : '<span style="color:var(--danger)">⛔ ' + escHtml(st) + '</span>');
        items.push('<div class="flex-between" style="padding:6px 10px;background:var(--bg-soft);border-radius:var(--radius-md);margin-bottom:6px">' +
            '<span class="fw-600">🔄 数据库迁移/切换</span><span>' + stTxt + '</span>' +
            '<span class="fs-12 text-muted">' + escHtml(t.migration.from) + ' → ' + escHtml(t.migration.to) + '（' + t.migration.done_tables + '/' + t.migration.total_tables + ' 表）</span></div>');
    }
    if (t.dual_write) {
        items.push('<div class="flex-between" style="padding:6px 10px;background:var(--bg-soft);border-radius:var(--radius-md);margin-bottom:6px">' +
            '<span class="fw-600">🔁 双向实时同步</span><span style="color:var(--success)">已开启</span>' +
            '<span class="fs-12 text-muted">镜像目标：' + escHtml((t.dual_write.target || '').toUpperCase()) + (t.dual_write.target_path ? '（' + escHtml(t.dual_write.target_path) + '）' : '') + '</span></div>');
    }
    if (t.backup_schedule) {
        var last = t.backup_schedule.last_result === 'ok' ? '最近成功：' + escHtml(t.backup_schedule.last_at) : (t.backup_schedule.last_result ? '最近：' + escHtml(t.backup_schedule.last_result) : '尚未执行');
        items.push('<div class="flex-between" style="padding:6px 10px;background:var(--bg-soft);border-radius:var(--radius-md);margin-bottom:6px">' +
            '<span class="fw-600">💾 定时自动备份</span><span>每天 ' + escHtml(t.backup_schedule.hour) + '</span>' +
            '<span class="fs-12 text-muted">' + last + '</span></div>');
    }
    box.innerHTML = items.length
        ? '<div class="fs-13 fw-700 mb-4" style="color:var(--text-muted)">活动任务</div>' + items.join('')
        : '';
}
function loadDbStatus() {
    var box = document.getElementById('dbStatusBox');
    Clinic.get('/api/admin?action=db_status', null, {
        loading: false,
        onSuccess: function (json) {
            var d = json.data || {};
            var cfg = d.config_available ? '✓ 有效（' + escHtml(d.config_path) + '）' : '（无 config.db，按默认配置运行）';
            box.innerHTML =
                '<div class="flex-between"><span class="text-muted">驱动类型</span><span class="badge badge-primary" style="font-size:11.5px">' + escHtml(d.driver_label) + '</span></div>' +
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
            // 驱动下拉动态渲染（注册表唯一数据源，与安装向导共用；迁移目标排除当前驱动）
            SET_CUR_DRIVER = d.driver || '';
            renderSettingsDrivers(d.drivers || { db: {}, cache: {} });
            // 数据表浏览器标题右侧当前库类型徽章
            var bb = document.getElementById('browseDriverBadge');
            if (bb) bb.textContent = d.driver_label || (SET_CUR_DRIVER || '').toUpperCase();
            // 活动任务（迁移/切换/备份/双向，未启用不显示）
            renderActiveTasks(d.active_tasks || {});
        },
        onError: function () { box.innerHTML = '<span class="text-danger">数据库状态读取失败</span>'; },
    });
}

var DB_TABLE_MODAL = null;
var DB_INF = null;   // 数据表无限滚动句柄
function openDbTable(table) {
    DB_CUR_TABLE = table;
    if (DB_INF) { DB_INF.stop(); DB_INF = null; }
    if (DB_TABLE_MODAL) {
        try { if (document.body.contains(DB_TABLE_MODAL)) Clinic.modal.close(); } catch (e) {}
        DB_TABLE_MODAL = null;
    }
    // 固定大小模态框（宽 modal-xl + 表格区固定高），数据过多时表格区内部滚动 + 滚动加载
    var html =
        '<div class="flex-between mb-8"><span class="fw-600 fs-14" id="dbTableTitle">📋 ' + escHtml(table) + '</span>' +
        '<button class="btn btn-outline btn-sm" onclick="exportDbTableCsv()">⬇️ CSV</button></div>' +
        '<div class="table-wrap" id="dbTableScroll" style="height:440px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius-md)">' +
        '<div class="text-muted text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>';
    DB_TABLE_MODAL = Clinic.modal.open(html, { title: '数据表查看', size: 'modal-xl' });
    var first = true;   // 首屏渲染完整表格，后续页仅插入行
    DB_INF = Clinic.infiniteList({
        el: document.getElementById('dbTableScroll'),
        pageSize: 50,
        threshold: 80,
        emptyHtml: '<div class="empty" style="padding:40px 0"><div class="empty-ico">📋</div>该表暂无数据</div>',
        url: function (p, size) {
            return '/api/admin?action=db_table_data&table=' + encodeURIComponent(DB_CUR_TABLE) + '&page=' + p + '&size=' + size;
        },
        render: function (list, isFirst, data) {
            var cols = (data && data.cols) || [];
            var headHtml = '<tr>' + cols.map(function (c) { return '<th>' + escHtml(c.name) + '</th>'; }).join('') + '</tr>';
            var rowHtml = list.map(function (r) {
                return '<tr>' + cols.map(function (c) {
                    var v = r[c.name] === null || r[c.name] === undefined ? '' : String(r[c.name]);
                    var full = v;
                    if (v.length > 60) v = v.substr(0, 60) + '…';
                    return '<td class="fs-12" title="' + escHtml(full) + '">' + escHtml(v) + '</td>';
                }).join('') + '</tr>';
            }).join('');
            var title = document.getElementById('dbTableTitle');
            if (title && isFirst) title.textContent = '📋 ' + (data && data.table ? data.table : DB_CUR_TABLE) + '（共 ' + ((data && data.total) || 0) + ' 行）';
            // 首屏返回完整表格结构，后续页仅返回行（由 append 插入 tbody）
            return isFirst
                ? '<table class="table"><thead>' + headHtml + '</thead><tbody>' + rowHtml + '</tbody></table>'
                : rowHtml;
        },
        append: function (el, html) {
            if (first) { el.innerHTML = html; first = false; return; }
            var tb = el.querySelector('tbody');
            if (tb) tb.insertAdjacentHTML('beforeend', html);
        },
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
            // 缓存驱动切换下拉选中当前实际驱动
            var csel = document.getElementById('cacheDriverSel');
            if (csel && d.driver) {
                csel.value = d.driver;
                toggleCacheRedisOpts();
            }
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

/* ---------- 驱动选项动态渲染（注册表唯一数据源，与安装向导共用） ---------- */
var SET_DRIVERS = null;
var SET_CUR_DRIVER = '';   // 当前数据库驱动（迁移目标排除同驱动）

/* ---------- 数据库中心左右两栏切换 ---------- */
function dbTab(name) {
    document.querySelectorAll('#stab-db .db-nav').forEach(function (n) {
        n.classList.toggle('active', n.getAttribute('data-dbtab') === name);
    });
    document.querySelectorAll('#stab-db .db-pane').forEach(function (p) {
        p.style.display = p.id === 'dbtab-' + name ? '' : 'none';
    });
    if (name === 'detail' || name === 'browse') loadDbStatus();
}
function settingsDriverMeta(kind, key) {
    var dr = SET_DRIVERS || { db: {}, cache: {} };
    return (dr[kind] && dr[kind][key]) ? dr[kind][key] : null;
}
function settingsParamInput(kind, key, p) {
    var id = (kind === 'db' ? 'migp_' : 'csp_') + key;
    var isPass = /pass|auth/i.test(key);
    var val = p.default || '';
    return '<div class="form-group"><label class="form-label">' + escHtml(p.label || key) + '</label>' +
        '<input class="input" id="' + id + '" value="' + escHtml(val) + '"' +
        (p.placeholder ? ' placeholder="' + escHtml(p.placeholder) + '"' : '') +
        (isPass ? ' type="password"' : ' type="text"') + '></div>';
}
function settingsRenderParams(kind, driverKey, containerId) {
    var box = document.getElementById(containerId);
    var meta = settingsDriverMeta(kind, driverKey);
    if (!box) return;
    if (!meta || !meta.params || !Object.keys(meta.params).length) { box.innerHTML = ''; return; }
    var keys = Object.keys(meta.params);
    var rows = [];
    for (var i = 0; i < keys.length; i += 2) {
        var c1 = settingsParamInput(kind, keys[i], meta.params[keys[i]]);
        var c2 = (i + 1 < keys.length) ? settingsParamInput(kind, keys[i + 1], meta.params[keys[i + 1]]) : '';
        rows.push('<div class="form-row">' + c1 + c2 + '</div>');
    }
    box.innerHTML = rows.join('');
}
function settingsCollectParams(kind, driverKey) {
    var meta = settingsDriverMeta(kind, driverKey);
    var out = {};
    if (meta && meta.params) {
        Object.keys(meta.params).forEach(function (k) {
            var el = document.getElementById((kind === 'db' ? 'migp_' : 'csp_') + k);
            out[k] = el ? el.value : (meta.params[k].default || '');
        });
    }
    return out;
}
function renderSettingsDrivers(drivers) {
    SET_DRIVERS = drivers || { db: {}, cache: {} };
    // 迁移目标驱动下拉：排除当前驱动（同格式迁移无意义）
    var migSel = document.getElementById('migDriver');
    if (migSel) {
        migSel.innerHTML = Object.keys(drivers.db || {}).map(function (k) {
            if (k === SET_CUR_DRIVER) return '';   // 屏蔽当前驱动
            var d = drivers.db[k];
            return '<option value="' + k + '">' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
        }).join('');
        toggleMigOpts();
    }
    // 备份库驱动下拉（全部驱动，备份可同驱动备份到另一文件/库）
    var bkSel = document.getElementById('bkDriver');
    if (bkSel) {
        bkSel.innerHTML = Object.keys(drivers.db || {}).map(function (k) {
            var d = drivers.db[k];
            return '<option value="' + k + '"' + (k === 'sqlite' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
        }).join('');
        toggleBkOpts();
    }
    // 直接切换目标驱动下拉（允许同驱动切换，如 MySQL → 另一 MySQL 库）
    var swSel = document.getElementById('swDriver');
    if (swSel) {
        swSel.innerHTML = Object.keys(drivers.db || {}).map(function (k) {
            var d = drivers.db[k];
            return '<option value="' + k + '">' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
        }).join('');
        toggleSwOpts();
    }
    // 当前主库类型徽章（切换面板）
    var swCur = document.getElementById('swCurDriver');
    if (swCur) swCur.textContent = (SET_CUR_DRIVER || '').toUpperCase();
    // 定时备份小时下拉（0-23，留空=关闭）
    var bhSel = document.getElementById('bkHour');
    if (bhSel && !bhSel.options.length) {
        var bhs = ['<option value="">关闭</option>'];
        for (var h = 0; h <= 23; h++) bhs.push('<option value="' + ('0' + h).slice(-2) + ':00">' + ('0' + h).slice(-2) + ':00</option>');
        bhSel.innerHTML = bhs.join('');
    }
    // 缓存驱动下拉
    var cSel = document.getElementById('cacheDriverSel');
    if (cSel) {
        cSel.innerHTML = Object.keys(drivers.cache || {}).map(function (k) {
            var d = drivers.cache[k];
            return '<option value="' + k + '"' + (k === 'file' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
        }).join('');
        toggleCacheRedisOpts();
    }
}

/* ---------- 数据库迁移 ---------- */
function toggleMigOpts() {
    var v = document.getElementById('migDriver').value;
    settingsRenderParams('db', v, 'migParamsBox');
}
function startMigrate() {
    var v = document.getElementById('migDriver').value;
    var meta = settingsDriverMeta('db', v);
    var msg = document.getElementById('migMsg');
    var p = v === 'sqlite'
        ? '目标 SQLite 文件将写入全部业务数据，迁移完成后主库切换到 SQLite'
        : '目标 ' + (meta ? meta.label : v) + ' 数据库将覆盖其中与业务表同名的表，迁移完成后主库切换';
    var mp = settingsCollectParams('db', v);
    Clinic.modal.confirm('⚠️ 即将执行数据库迁移：\n' + p + '。\n迁移期间系统进入只读维护模式，请勿刷新页面。确定继续？', function () {
        var btn = event.target;
        msg.innerHTML = '<div class="flex gap-8" style="align-items:center"><div class="spinner" style="border-top-color:var(--primary);width:20px;height:20px;margin:0"></div>正在迁移，请勿关闭页面…</div>';
        Clinic.ajax('/api/admin', {
            action: 'db_migrate',
            to_driver: v,
            to_db_host: mp.host || '',
            to_db_port: mp.port || '',
            to_db_name: mp.dbname || '',
            to_db_user: mp.user || '',
            to_db_pass: mp.pass || '',
            to_sqlite_path: mp.path || '',
        }, {
            onSuccess: function (json) {
                // 保存管理员取消令牌（锁定页据此显示取消按钮）
                try { localStorage.setItem('migration_token', json.data.token || ''); } catch (e) {}
                msg.innerHTML = '<span class="text-success fw-600">✓ ' + escHtml(json.msg) + '</span>';
                setTimeout(function () { location.reload(); }, 1200);   // 刷新进入全站锁定页（进度条）
            },
            onError: function (x, j) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((j && j.msg) || '迁移启动失败') + '</span>'; },
        });
    }, { title: '数据库迁移确认', okText: '开始迁移' });
}

/* ---------- 直接切换主库（不迁移数据） ---------- */
function toggleSwOpts() {
    settingsRenderParams('db', document.getElementById('swDriver').value, 'swParamsBox');
}
function switchMainDirect() {
    var v = document.getElementById('swDriver').value;
    var meta = settingsDriverMeta('db', v);
    var mp = settingsCollectParams('db', v);
    var p = '将直接切换主数据库到：' + (meta ? meta.label : v) + '。\n\n切换不迁移数据，目标库必须已存在完整业务数据（users 表非空）。\n切换将强制清除全部用户会话，所有用户需重新登录。\n\n确定继续？';
    Clinic.modal.confirm(p, function () {
        Clinic.ajax('/api/admin', {
            action: 'db_switch',
            to_driver: v,
            to_db_host: mp.host || '',
            to_db_port: mp.port || '',
            to_db_name: mp.dbname || '',
            to_db_user: mp.user || '',
            to_db_pass: mp.pass || '',
            to_sqlite_path: mp.path || '',
        }, {
            onSuccess: function (json) {
                try { localStorage.removeItem('migration_token'); } catch (e) {}
                alert(json.msg);
                location.reload();
            },
            onError: function (x, j) { alert((j && j.msg) || '切换失败'); },
        });
    }, { title: '直接切换主库确认', okText: '切换' });
}

/* ---------- 多数据库备份 / 双向同步（共用驱动选择，下方按钮切换模式） ---------- */
var BK_MODE = 'backup';
function bkMode(name) {
    BK_MODE = name;
    document.getElementById('bkModeBackup').className = 'btn btn-sm ' + (name === 'backup' ? 'btn-primary' : 'btn-outline');
    document.getElementById('bkModeDual').className = 'btn btn-sm ' + (name === 'dual' ? 'btn-primary' : 'btn-outline');
    document.querySelectorAll('.bk-mode-pane').forEach(function (p) {
        p.style.display = p.id === 'bkmode-' + name ? '' : 'none';
    });
    if (name === 'dual') refreshDualStatus();
}
function toggleBkOpts() {
    settingsRenderParams('db', document.getElementById('bkDriver').value, 'bkParamsBox');
}
function refreshDualStatus() {
    var on = false;
    try { on = (localStorage.getItem('dual_on') || '') === '1'; } catch (e) {}
    document.getElementById('dualStatus').textContent = on ? '已开启（写入实时镜像到备份库）' : '未开启';
}
function saveBackupCfg() {
    var msg = document.getElementById('bkMsg');
    var k = document.getElementById('bkDriver').value;
    var bp = settingsCollectParams('db', k);
    msg.textContent = '保存中…';
    // 共用驱动配置 + 备份配置
    var pay = {
        action: 'backup_save',
        backup_driver: k,
        backup_db_host: bp.host || '',
        backup_db_port: bp.port || '',
        backup_db_name: bp.dbname || '',
        backup_db_user: bp.user || '',
        backup_db_pass: bp.pass || '',
        backup_sqlite_path: bp.path || '',
        backup_hour: BK_MODE === 'backup' ? document.getElementById('bkHour').value : '',
    };
    Clinic.ajax('/api/admin', pay, {
        onSuccess: function (json) {
            // 备份模式关闭双写；同步模式开启双写（二选一）
            Clinic.ajax('/api/admin', {
                action: 'dual_save',
                dual_enabled: BK_MODE === 'dual' ? '1' : '0',
            }, {
                onSuccess: function (j2) {
                    try { localStorage.setItem('dual_on', BK_MODE === 'dual' ? '1' : '0'); } catch (e) {}
                    msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '；' + escHtml(j2.msg) + '</span>';
                    if (BK_MODE === 'dual') refreshDualStatus();
                    showBackupLastAt();
                },
                onError: function () { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; },
            });
        },
        onError: function (x, j) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((j && j.msg) || '保存失败') + '</span>'; },
    });
}
/* 最近一次备份/同步时间显示（从 db_status 活动任务读取） */
function showBackupLastAt() {
    var box = document.getElementById('bkLastAt');
    if (!box) return;
    Clinic.get('/api/admin?action=db_status', null, {
        loading: false,
        onSuccess: function (json) {
            var t = (json.data && json.data.active_tasks) || {};
            var info = [];
            if (t.backup_schedule) {
                var last = t.backup_schedule.last_result === 'ok'
                    ? '最近备份：' + (t.backup_schedule.last_at || '—')
                    : (t.backup_schedule.last_result ? '最近备份：' + t.backup_schedule.last_result : '尚未备份');
                info.push(last);
            } else {
                var lat = (json.data && json.data.last_backup_at) || '';
                if (lat) info.push('最近备份：' + lat);
            }
            if (t.dual_write) {
                var lat2 = (json.data && json.data.last_dual_at) || '';
                info.push('双向同步：' + (lat2 ? '最近 ' + lat2 : '已开启（镜像中）'));
            }
            box.style.display = info.length ? '' : 'none';
            box.innerHTML = '<span class="text-muted fs-12">' + info.join('　') + '</span>';
        },
    });
}
function runBackup() {
    var msg = document.getElementById('bkMsg');
    Clinic.modal.confirm('即将把当前主库全部数据同步到备份库。\n备份期间建议避免大量写入操作，但系统可继续使用（不动主库指针）。\n确定执行备份？', function () {
        msg.innerHTML = '<div class="flex gap-8" style="align-items:center"><div class="spinner" style="border-top-color:var(--primary);width:20px;height:20px;margin:0"></div>正在备份，请稍候…</div>';
        Clinic.ajax('/api/admin', { action: 'backup_run' }, {
            onSuccess: function (json) { msg.innerHTML = '<span class="text-success fw-600">✓ ' + escHtml(json.msg) + '</span>'; },
            onError: function (x, j) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((j && j.msg) || '备份失败') + '</span>'; },
        });
    }, { title: '执行数据库备份', okText: '开始备份' });
}
/* 备份/双向操作日志：查看模态框（滚动列表 + 清空 + 导出 .log 文件） */
function viewBackupLogs() {
    var mask = Clinic.modal.open(
        '<div class="flex gap-8 mb-8"><button class="btn btn-outline btn-sm" onclick="exportBackupLog()">⬇️ 导出日志</button>' +
        '<button class="btn btn-danger btn-sm" onclick="clearBackupLog()">🗑️ 清空日志</button></div>' +
        '<div id="bkLogBox" style="max-height:400px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md);padding:10px;font-family:monospace;font-size:12px;line-height:1.8">' +
        '<div class="text-center" style="padding:20px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>',
        { title: '📜 数据库操作日志（备份 / 双向同步）', size: 'modal-lg' }
    );
    loadBackupLogs(mask);
}
function loadBackupLogs(mask) {
    var box = document.getElementById('bkLogBox');
    if (!box) return;
    Clinic.get('/api/admin?action=backup_logs', null, {
        loading: false,
        onSuccess: function (json) {
            var lines = (json.data && json.data.lines) || [];
            box.innerHTML = lines.length
                ? lines.map(function (l) { return escHtml(l); }).join('<br>')
                : '<div class="text-muted text-center" style="padding:20px">暂无日志记录</div>';
        },
        onError: function () { box.innerHTML = '<span class="text-danger">日志读取失败</span>'; },
    });
}
window.clearBackupLog = function () {
    Clinic.modal.confirm('确定清空全部操作日志吗？', function () {
        Clinic.ajax('/api/admin', { action: 'backup_log_clear' }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadBackupLogs(null); },
            onError: function (x, j) { Clinic.toast.error((j && j.msg) || '清空失败'); },
        });
    });
};
window.exportBackupLog = function () {
    window.open('/api/admin?action=backup_log_export', '_blank');
};

/* ---------- 缓存驱动切换 ---------- */
function toggleCacheRedisOpts() {
    settingsRenderParams('cache', document.getElementById('cacheDriverSel').value, 'cacheParamsBox');
}
function saveCacheDriver() {
    var msg = document.getElementById('cacheDriverMsg');
    var key = document.getElementById('cacheDriverSel').value;
    var cp = settingsCollectParams('cache', key);
    msg.textContent = '保存中…';
    Clinic.ajax('/api/admin', {
        action: 'cache_driver_save',
        driver: key,
        redis_host: cp.host || '',
        redis_port: cp.port || '',
        redis_auth: cp.auth || '',
        redis_prefix: cp.prefix || '',
        redis_timeout: cp.timeout || '',
        memcached_servers: cp.servers || '',
        memcached_prefix: cp.prefix || '',
    }, {
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; loadCacheStatus(); },
        onError: function (x, j) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((j && j.msg) || '保存失败') + '</span>'; },
    });
}
</script>
