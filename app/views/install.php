<?php
/**
 * install.php — 首次安装向导（5 步）
 * ============================================================
 * Step 1 欢迎与环境巡检：PHP 版本/扩展/目录权限
 * Step 2 基础设施与数据库配置：驱动选择 + 全新/关联现有 + 缓存
 * Step 3 医疗机构基础设置：机构/时区/就诊规则
 * Step 4 创建超级管理员：账号/密码/邮箱
 * Step 5 最终确认与安装执行
 * ============================================================
 */

/* 时区下拉数据源：按区域分组 */
$tzGroups = array();
foreach (DateTimeZone::listIdentifiers() as $tz) {
    $parts = explode('/', $tz, 2);
    $group = isset($parts[1]) ? $parts[0] : '其他';
    $tzGroups[$group][] = $tz;
}
?>
<div class="auth-card">
    <div class="auth-title">🏥 门诊一体化系统</div>
    <div style="text-align:center;margin:-2px 0 12px">
        <span class="badge badge-outline" style="font-size:11px;letter-spacing:.04em" id="installBadge">准备安装</span>
    </div>
    <div class="auth-sub" id="wizardSub">首次初始化向导 · 共 5 步</div>

    <!-- 步骤指示器 -->
    <div class="step-dots" id="stepDots">
        <span class="step-dot on" data-step="1"></span><span class="step-dot" data-step="2"></span><span class="step-dot" data-step="3"></span><span class="step-dot" data-step="4"></span><span class="step-dot" data-step="5"></span>
    </div>

    <!-- ============ Step 1: 环境巡检 ============ -->
    <div class="wiz-step" data-step="1">
        <div class="form-group"><label class="form-label">环境巡检</label>
            <div class="card" style="padding:12px;max-height:280px;overflow-y:auto" id="preflightBox">
                <div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div>正在检查环境…</div>
            </div>
        </div>
        <div class="form-group" id="mainDbHint" style="display:none">
            <div class="fs-13" style="background:var(--primary-soft);border-radius:var(--radius-md);padding:10px 12px">
                <span id="mainDbHintText"></span>
            </div>
        </div>
    </div>

    <!-- ============ Step 2: 数据库与缓存 ============ -->
    <div class="wiz-step" data-step="2" style="display:none">
        <div class="form-group"><label class="form-label">数据库驱动 <span class="req">*</span></label>
            <select class="select" id="dbDriver"></select>
            <div class="fs-12 text-muted mt-4">选项来自系统驱动注册表（与系统设置-数据库中心一致），未安装扩展的驱动会标注。</div>
        </div>
        <div id="dbParamsBox"></div>
        <div class="form-group"><label class="form-label">安装方式 <span class="req">*</span></label>
            <div class="flex gap-8" style="flex-wrap:wrap">
                <label class="flex gap-4" style="align-items:center;cursor:pointer"><input type="radio" name="installMode" value="fresh" checked> 全新安装（建库并导入基础字典）</label>
                <label class="flex gap-4" style="align-items:center;cursor:pointer"><input type="radio" name="installMode" value="attach"> 关联现有数据库（保留已有数据）</label>
            </div>
            <div class="fs-12 text-muted mt-4">关联现有数据库：若之前已安装过（主库表结构完整），选择后仅重新绑定连接，不破坏任何已有数据。</div>
        </div>
        <div class="form-group"><label class="form-label">缓存驱动</label>
            <select class="select" id="cacheDriver"></select>
            <div class="fs-12 text-muted mt-4">缓存/会话驱动选项同样来自驱动注册表（与系统设置-缓存与性能一致）。</div>
        </div>
        <div id="cacheParamsBox"></div>
    </div>

    <!-- ============ Step 3: 医疗机构 ============ -->
    <div class="wiz-step" data-step="3" style="display:none">
        <div class="form-group"><label class="form-label">医院名称 <span class="req">*</span></label>
            <input type="text" class="input" id="hospital_name" placeholder="如：XX市人民医院"></div>
        <div class="form-group"><label class="form-label">机构代码 <span class="req">*</span></label>
            <input type="text" class="input" id="org_code" placeholder="如：410105001234（医保结算/监管报送唯一标识）"></div>
        <div class="form-group"><label class="form-label">医院第二名称（可选）</label>
            <input type="text" class="input" id="hospital_name2" placeholder="如：XX医科大学附属医院"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">联系电话</label><input type="text" class="input" id="contact_phone" placeholder="如：0371-12345678"></div>
            <div class="form-group"><label class="form-label">联系地址</label><input type="text" class="input" id="contact_addr" placeholder="如：XX市XX区XX路1号"></div>
        </div>
        <div class="form-group"><label class="form-label">网站时区 <span class="req">*</span></label>
            <select class="select" id="timezone" data-csd-search="1">
                <?php foreach ($tzGroups as $group => $tzList): ?>
                <optgroup label="<?php echo e($group); ?>">
                    <?php foreach ($tzList as $tz): ?>
                    <option value="<?php echo e($tz); ?>"><?php echo e($tz); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">医院 LOGO（可选，同时作为网站 favicon）</label>
            <input type="file" class="input" id="logo" accept="image/*"></div>
    </div>

    <!-- ============ Step 4: 管理员 ============ -->
    <div class="wiz-step" data-step="4" style="display:none">
        <div class="form-group"><label class="form-label">管理员用户名 <span class="req">*</span></label>
            <div class="input-wrap"><span class="input-icon">👤</span>
                <input type="text" class="input" id="username" value="admin" placeholder="默认 admin，可修改" autocomplete="username"></div></div>
        <div class="form-group"><label class="form-label">真实姓名</label>
            <div class="input-wrap"><span class="input-icon">👨‍⚕️</span>
                <input type="text" class="input" id="realname" value="系统管理员" placeholder="管理员真实姓名"></div></div>
        <div class="form-group"><label class="form-label">管理员密码 <span class="req">*</span></label>
            <div class="input-wrap"><span class="input-icon">🔑</span>
                <input type="password" class="input" id="password" placeholder="至少 6 位，建议含大小写与数字" autocomplete="new-password"></div></div>
        <div class="form-group"><label class="form-label">确认密码 <span class="req">*</span></label>
            <div class="input-wrap"><span class="input-icon">🔑</span>
                <input type="password" class="input" id="password2" placeholder="再次输入密码" autocomplete="new-password"></div></div>
        <div class="form-group"><label class="form-label">安全邮箱（可选，用于密码找回）</label>
            <div class="input-wrap"><span class="input-icon">📧</span>
                <input type="email" class="input" id="admin_email" placeholder="如：admin@hospital.com"></div></div>
        <div class="form-group" id="attachAdminHint" style="display:none">
            <div class="fs-13" style="background:var(--primary-soft);border-radius:var(--radius-md);padding:10px 12px">已选择「关联现有数据库」，将保留原系统管理员账号，无需新建。</div>
        </div>
    </div>

    <!-- ============ Step 5: 确认与执行 ============ -->
    <div class="wiz-step" data-step="5" style="display:none">
        <div class="form-group"><label class="form-label">安装信息确认</label>
            <div class="card" style="padding:12px" id="confirmBox"></div>
        </div>
        <button type="button" class="btn btn-primary btn-lg btn-block" id="installBtn">🚀 完成安装</button>
        <div class="auth-footer" id="installFoot">安装完成后将自动跳转登录页</div>
    </div>

    <!-- 导航按钮 -->
    <div class="flex gap-8 mt-12" id="wizNav">
        <button type="button" class="btn btn-outline btn-sm" id="prevBtn" style="display:none" onclick="wizPrev()">← 上一步</button>
        <button type="button" class="btn btn-primary btn-sm" id="nextBtn" onclick="wizNext()">下一步 →</button>
    </div>
</div>

<script>
/* ==================== 5 步向导控制 ==================== */
var WIZ = { step: 1, preflight: null, mode: 'fresh' };

function wizGo(n, validate) {
    if (validate && !wizValidate(WIZ.step)) return;
    WIZ.step = n;
    document.querySelectorAll('.wiz-step').forEach(function (s) { s.style.display = (+s.getAttribute('data-step')) === n ? '' : 'none'; });
    document.querySelectorAll('#stepDots .step-dot').forEach(function (d) { d.classList.toggle('on', +d.getAttribute('data-step') === n); });
    var subs = ['', '环境巡检', '数据库与缓存', '医疗机构信息', '创建管理员', '确认安装'];
    document.getElementById('wizardSub').textContent = 'Step ' + n + ' · ' + subs[n];
    document.getElementById('prevBtn').style.display = n === 1 ? 'none' : '';
    document.getElementById('nextBtn').style.display = n === 5 ? 'none' : '';
    if (n === 2) toggleDbMode();
    if (n === 4) toggleAdminHint();
    if (n === 5) renderConfirm();
}

function wizNext() {
    if (WIZ.step === 2 && !testDbIfNeeded()) return;
    wizGo(WIZ.step + 1, true);
}
function wizPrev() { wizGo(WIZ.step - 1, false); }

function wizValidate(n) {
    if (n === 2) {
        var dp = collectParams('db', document.getElementById('dbDriver').value);
        // sqlite 无 dbname 校验；mysql/pgsql 需数据库名
        if (document.getElementById('dbDriver').value !== 'sqlite' && !(dp.dbname || '').trim()) {
            Clinic.toast.warning('请填写数据库名'); return false;
        }
    } else if (n === 3) {
        if (!document.getElementById('hospital_name').value.trim()) { Clinic.toast.warning('请填写医院名称'); return false; }
        if (!document.getElementById('org_code').value.trim()) { Clinic.toast.warning('请填写机构代码'); return false; }
    } else if (n === 4) {
        if (WIZ.mode !== 'attach') {
            var u = document.getElementById('username').value.trim();
            var p = document.getElementById('password').value;
            var p2 = document.getElementById('password2').value;
            if (!u || !/^[A-Za-z]/.test(u)) { Clinic.toast.warning('管理员用户名必须以英文字母开头'); return false; }
            if (p.length < 6) { Clinic.toast.warning('管理员密码不能少于6位（当前 ' + p.length + ' 位）'); return false; }
            if (p !== p2) { Clinic.toast.warning('两次输入的密码不一致'); return false; }
        }
    }
    return true;
}

/* ==================== 驱动选项动态渲染（注册表唯一数据源） ==================== */
function driverMeta(kind, key) {
    var dr = (WIZ.preflight && WIZ.preflight.drivers) || { db: {}, cache: {} };
    return (dr[kind] && dr[kind][key]) ? dr[kind][key] : null;
}
function renderParamsBox(containerId, kind, driverKey) {
    var box = document.getElementById(containerId);
    var meta = driverMeta(kind, driverKey);
    if (!box) return;
    if (!meta || !meta.params || !Object.keys(meta.params).length) { box.innerHTML = ''; return; }
    var rows = [];
    var keys = Object.keys(meta.params);
    // 两列布局（奇数个参数时最后单独一行）
    for (var i = 0; i < keys.length; i += 2) {
        var cell1 = paramInputHtml(kind, keys[i], meta.params[keys[i]]);
        var cell2 = (i + 1 < keys.length) ? paramInputHtml(kind, keys[i + 1], meta.params[keys[i + 1]]) : '';
        rows.push('<div class="form-row">' + cell1 + cell2 + '</div>');
    }
    box.innerHTML = rows.join('') +
        '<div class="flex gap-8"><button type="button" class="btn btn-outline btn-sm" id="testDrvBtn" onclick="' + (kind === 'db' ? 'testDb()' : 'testRedis()') + '">测试连接</button>' +
        '<span class="fs-13 text-muted" id="' + (kind === 'db' ? 'dbTestMsg' : 'redisTestMsg') + '"></span></div>';
}
function paramInputHtml(kind, key, p) {
    var id = (kind === 'db' ? 'dbp_' : 'cp_') + key;
    var isPass = /pass|auth/i.test(key);
    var val = p.default || '';
    return '<div class="form-group"><label class="form-label">' + escHtml(p.label || key) + '</label>' +
        '<input class="input" id="' + id + '" value="' + escHtml(val) + '"' +
        (p.placeholder ? ' placeholder="' + escHtml(p.placeholder) + '"' : '') +
        (isPass ? ' type="password"' : ' type="text"') + '></div>';
}
function collectParams(kind, driverKey) {
    var meta = driverMeta(kind, driverKey);
    var out = {};
    if (meta && meta.params) {
        Object.keys(meta.params).forEach(function (k) {
            var el = document.getElementById((kind === 'db' ? 'dbp_' : 'cp_') + k);
            out[k] = el ? el.value : (meta.params[k].default || '');
        });
    }
    return out;
}
function toggleDbMode() {
    var d = document.getElementById('dbDriver').value;
    renderParamsBox('dbParamsBox', 'db', d);
    var rd = document.querySelector('input[name="installMode"]:checked');
    WIZ.mode = rd ? rd.value : 'fresh';
}
function toggleCacheMode() {
    renderParamsBox('cacheParamsBox', 'cache', document.getElementById('cacheDriver').value);
}
function renderDrivers(drivers) {
    WIZ.preflight = WIZ.preflight || {};
    WIZ.preflight.drivers = drivers || { db: {}, cache: {} };
    // 数据库驱动下拉
    var dbSel = document.getElementById('dbDriver');
    dbSel.innerHTML = Object.keys(drivers.db || {}).map(function (k) {
        var d = drivers.db[k];
        return '<option value="' + k + '"' + (k === 'sqlite' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
    }).join('');
    // 缓存驱动下拉
    var cSel = document.getElementById('cacheDriver');
    cSel.innerHTML = Object.keys(drivers.cache || {}).map(function (k) {
        var d = drivers.cache[k];
        return '<option value="' + k + '"' + (k === 'file' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
    }).join('');
    toggleDbMode();
    toggleCacheMode();
}

function toggleAdminHint() {
    document.getElementById('attachAdminHint').style.display = WIZ.mode === 'attach' ? '' : 'none';
    ['username', 'password', 'password2', 'admin_email'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.disabled = WIZ.mode === 'attach';
    });
}

document.querySelectorAll('input[name="installMode"]').forEach(function (r) {
    r.addEventListener('change', function () { toggleDbMode(); });
});
document.getElementById('dbDriver').addEventListener('change', toggleDbMode);
document.getElementById('cacheDriver').addEventListener('change', toggleCacheMode);

/* ==================== 环境巡检 ==================== */
function loadPreflight() {
    var box = document.getElementById('preflightBox');
    Clinic.get('/api/install?action=preflight', null, {
        loading: false,
        onSuccess: function (json) {
            WIZ.preflight = json.data || {};
            var d = WIZ.preflight;
            var html = '';
            html += '<div class="flex-between mb-8"><span class="fw-600">PHP 版本</span><span>' + escHtml(d.php_version || '-') + '</span></div>';
            var exts = (d.extensions || []);
            html += '<div class="fw-600 mb-4">PHP 扩展</div>' + exts.map(function (e) {
                return '<div class="flex-between"><span>' + escHtml(e.name) + '</span><span class="' + (e.ok ? 'text-success' : 'text-danger') + '">' + (e.ok ? '✓ 已安装' : '✗ 缺失') + '</span></div>';
            }).join('');
            var dirs = (d.dirs || []);
            html += '<div class="fw-600 mt-8 mb-4">目录权限</div>' + dirs.map(function (x) {
                return '<div class="flex-between"><span>' + escHtml(x.path) + '</span><span class="' + (x.ok ? 'text-success' : 'text-danger') + '">' + (x.ok ? '✓ 可写' : '✗ 不可写') + '</span></div>';
            }).join('');
            html += '<div class="fw-600 mt-8 mb-4">已有数据</div>';
            html += '<div class="flex-between"><span>已安装检测</span><span>' + (d.existing_installed ? '<span class="text-success">检测到已安装系统（可关联现有库）</span>' : '<span class="text-muted">未检测到（全新安装）</span>') + '</span></div>';
            html += '<div class="flex-between"><span>现有主库</span><span>' + escHtml(d.existing_main || '—') + '</span></div>';
            box.innerHTML = html;
            // 驱动选项动态渲染（数据库/缓存下拉与参数表单，注册表唯一数据源）
            renderDrivers(d.drivers || { db: {}, cache: {} });
            if (d.existing_installed) {
                document.getElementById('mainDbHint').style.display = '';
                document.getElementById('mainDbHintText').textContent = '检测到已安装的数据库（' + (d.existing_main || '未知位置') + '）。若需重置系统，请选择「关联现有数据库」以保留已有数据。';
            }
        },
        onError: function () {
            box.innerHTML = '<div class="text-danger">环境检查失败，请确认服务器环境后重试</div>';
        },
    });
}

/* ==================== 数据库/缓存连接测试 ==================== */
function testDb() {
    var btn = document.getElementById('testDrvBtn');
    var msg = document.getElementById('dbTestMsg');
    msg.textContent = '测试中…';
    btn.disabled = true;
    var p = collectParams('db', document.getElementById('dbDriver').value);
    Clinic.get('/api/install?action=test_db', {
        driver: document.getElementById('dbDriver').value,
        host: p.host || '',
        port: p.port || '',
        dbname: p.dbname || '',
        user: p.user || '',
        pass: p.pass || '',
        path: p.path || '',
    }, {
        loading: false,
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; },
        onError: function (x, json) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((json && json.msg) || '连接失败') + '</span>'; },
        complete: function () { btn.disabled = false; },
    });
}
function testDbIfNeeded() { return true; }   // 连接在安装执行时再次校验
function testRedis() {
    var btn = document.getElementById('testDrvBtn');
    var msg = document.getElementById('redisTestMsg');
    msg.textContent = '测试中…';
    btn.disabled = true;
    var p = collectParams('cache', 'redis');
    Clinic.get('/api/install?action=test_redis', {
        host: p.host || '',
        port: p.port || '',
        auth: p.auth || '',
    }, {
        loading: false,
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; },
        onError: function (x, json) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((json && json.msg) || '连接失败') + '</span>'; },
        complete: function () { btn.disabled = false; },
    });
}

/* ==================== 确认汇总 ==================== */
function renderConfirm() {
    var box = document.getElementById('confirmBox');
    var dbKey = document.getElementById('dbDriver').value;
    var dbMeta = driverMeta('db', dbKey);
    var dbParams = collectParams('db', dbKey);
    var rows = [];
    var dbDesc = dbKey === 'sqlite'
        ? (dbParams.path || '默认 data/db/clinic_main.db')
        : ((dbParams.host || '') + ':' + (dbParams.port || '') + '/' + (dbParams.dbname || ''));
    rows.push(['数据库驱动', (dbMeta ? dbMeta.label : dbKey) + '（' + dbDesc + '）']);
    rows.push(['安装方式', WIZ.mode === 'attach' ? '关联现有数据库（保留数据）' : '全新安装（建库并导入基础字典）']);
    var cKey = document.getElementById('cacheDriver').value;
    var cMeta = driverMeta('cache', cKey);
    rows.push(['缓存驱动', cMeta ? cMeta.label : cKey]);
    rows.push(['医院名称', document.getElementById('hospital_name').value.trim()]);
    rows.push(['机构代码', document.getElementById('org_code').value.trim()]);
    if (WIZ.mode !== 'attach') {
        rows.push(['管理员', document.getElementById('username').value.trim() + '（' + (document.getElementById('realname').value.trim() || '系统管理员') + '）']);
    } else {
        rows.push(['管理员', '保留现有系统管理员']);
    }
    box.innerHTML = rows.map(function (r) {
        return '<div class="flex-between mb-4"><span class="text-muted">' + escHtml(r[0]) + '</span><span class="fw-600">' + escHtml(r[1]) + '</span></div>';
    }).join('');
}

/* ==================== 执行安装 ==================== */
document.getElementById('installBtn').addEventListener('click', function () {
    var btn = this;
    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'save');
    fd.append('mode', WIZ.mode);
    // 数据库/缓存驱动参数按注册表动态收集（安装向导与系统设置共用同一套）
    var dbKey = document.getElementById('dbDriver').value;
    var dbp = collectParams('db', dbKey);
    fd.append('db_driver', dbKey);
    fd.append('db_host', dbp.host || '');
    fd.append('db_port', dbp.port || '');
    fd.append('db_name', dbp.dbname || '');
    fd.append('db_user', dbp.user || '');
    fd.append('db_pass', dbp.pass || '');
    fd.append('sqlite_path', dbp.path || '');
    var cKey = document.getElementById('cacheDriver').value;
    var cp = collectParams('cache', cKey);
    fd.append('cache_driver', cKey);
    fd.append('redis_host', cp.host || '');
    fd.append('redis_port', cp.port || '');
    fd.append('redis_auth', cp.auth || '');
    fd.append('memcached_servers', cp.servers || '');
    fd.append('memcached_prefix', cp.prefix || '');
    fd.append('hospital_name', document.getElementById('hospital_name').value.trim());
    fd.append('org_code', document.getElementById('org_code').value.trim());
    fd.append('hospital_name2', document.getElementById('hospital_name2').value.trim());
    fd.append('contact_phone', document.getElementById('contact_phone').value.trim());
    fd.append('contact_addr', document.getElementById('contact_addr').value.trim());
    fd.append('timezone', document.getElementById('timezone').value);
    fd.append('username', document.getElementById('username').value.trim());
    fd.append('realname', document.getElementById('realname').value.trim());
    fd.append('password', document.getElementById('password').value);
    fd.append('password2', document.getElementById('password2').value);
    fd.append('admin_email', document.getElementById('admin_email').value.trim());
    var logoFile = document.getElementById('logo').files[0];
    if (logoFile) fd.append('logo', logoFile);

    btn.disabled = true;
    btn.textContent = '安装中…';
    document.getElementById('installFoot').textContent = '正在执行安装，请勿关闭页面…';
    fetch('/api/install', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.ok) {
                Clinic.toast.success(json.msg);
                setTimeout(function () { location.href = '/login'; }, 800);
            } else {
                Clinic.toast.error(json.msg || '安装失败');
                btn.disabled = false;
                btn.textContent = '🚀 完成安装';
                document.getElementById('installFoot').textContent = '安装失败，请检查后重试';
            }
        })
        .catch(function () {
            Clinic.toast.error('网络请求失败，请重试');
            btn.disabled = false;
            btn.textContent = '🚀 完成安装';
        });
});

/* ==================== 初始化 ==================== */
// 页面内容先于 layout 末尾的 ajax.js 输出，须待 DOM 就绪（Clinic 已加载）后再初始化
document.addEventListener('DOMContentLoaded', function () {
    // 默认时区：优先取浏览器时区
    var tz = 'Asia/Shanghai';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || tz; } catch (e) {}
    var sel = document.getElementById('timezone');
    if (sel.querySelector('option[value="' + tz + '"]')) sel.value = tz; else sel.value = 'Asia/Shanghai';
    loadPreflight();
});
</script>