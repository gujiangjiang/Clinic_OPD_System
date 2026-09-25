<?php
/**
 * install.php — 首次安装向导（6 步）
 * ============================================================
 * Step 1 环境巡检：PHP 版本/扩展/目录权限
 * Step 2 数据库配置：驱动选择 + 连接校验 + ICD-10 字典库
 * Step 3 缓存设置：缓存/会话驱动
 * Step 4 医疗机构基础设置：机构/时区/就诊规则
 * Step 5 创建超级管理员：账号/密码/邮箱
 * Step 6 最终确认与安装执行
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
    <div class="auth-sub" id="wizardSub">首次初始化向导 · 共 6 步</div>

    <!-- 步骤指示器 -->
    <div class="step-dots" id="stepDots">
        <span class="step-dot on" data-step="1"></span><span class="step-dot" data-step="2"></span><span class="step-dot" data-step="3"></span><span class="step-dot" data-step="4"></span><span class="step-dot" data-step="5"></span><span class="step-dot" data-step="6"></span>
    </div>

    <!-- ============ Step 1: 环境巡检 ============ -->
    <div class="wiz-step" data-step="1">
        <div class="form-group"><label class="form-label" style="display:flex;align-items:center;justify-content:space-between">环境巡检
            <button type="button" class="btn btn-outline btn-sm" onclick="loadPreflight()">重新检测</button></label>
            <div class="card" style="padding:12px;max-height:300px;overflow-y:auto" id="preflightBox">
                <div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div>正在检查环境…</div>
            </div>
        </div>
    </div>

    <!-- ============ Step 2: 数据库配置 ============ -->
    <div class="wiz-step" data-step="2" style="display:none">
        <div class="form-group"><label class="form-label">数据库驱动 <span class="req">*</span></label>
            <select class="select" id="dbDriver"></select>
            <div class="fs-12 text-muted mt-4">选项来自系统驱动注册表（与系统设置-数据库中心一致），未安装扩展的驱动会标注。</div>
        </div>
        <div id="dbParamsBox"></div>
        <div class="form-group"><label class="form-label">ICD-10 诊断库名称</label>
            <input type="text" class="input" id="icd10_name" value="icd10" placeholder="默认 icd10">
            <div class="fs-12 text-muted mt-4">独立只读字典库，统一存放于 data/db/，可省略 .db 后缀。</div>
        </div>
    </div>

    <!-- ============ Step 3: 缓存设置 ============ -->
    <div class="wiz-step" data-step="3" style="display:none">
        <div class="form-group"><label class="form-label">缓存驱动</label>
            <select class="select" id="cacheDriver"></select>
            <div class="fs-12 text-muted mt-4">缓存/会话驱动选项同样来自驱动注册表（与系统设置-缓存与性能一致）。</div>
        </div>
        <div id="cacheParamsBox"></div>
    </div>

    <!-- ============ Step 4: 医疗机构 ============ -->
    <div class="wiz-step" data-step="4" style="display:none">
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

    <!-- ============ Step 5: 管理员 ============ -->
    <div class="wiz-step" data-step="5" style="display:none">
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

    <!-- ============ Step 6: 确认与执行 ============ -->
    <div class="wiz-step" data-step="6" style="display:none">
        <div class="form-group"><label class="form-label">安装信息确认</label>
            <div class="card" style="padding:12px" id="confirmBox"></div>
        </div>
        <button type="button" class="btn btn-primary btn-lg btn-block" id="installBtn">🚀 完成安装</button>
        <div class="auth-footer" id="installFoot">安装完成后将自动跳转登录页</div>
    </div>

    <!-- 导航按钮：上一步靠左、下一步靠右 -->
    <div class="flex mt-12" id="wizNav" style="justify-content:space-between">
        <button type="button" class="btn btn-outline btn-sm" id="prevBtn" style="visibility:hidden" onclick="wizPrev()">← 上一步</button>
        <button type="button" class="btn btn-primary btn-sm" id="nextBtn" onclick="wizNext()">下一步 →</button>
    </div>
</div>

<script>
/* ==================== 6 步向导控制 ==================== */
var WIZ = { step: 1, preflight: null, mode: 'fresh', dbInstalled: false };
var WIZ_SUBS = ['', '环境巡检', '数据库配置', '缓存设置', '医疗机构信息', '创建管理员', '确认安装'];

function wizGo(n, validate) {
    if (validate && !wizValidate(WIZ.step)) return;
    WIZ.step = n;
    document.querySelectorAll('.wiz-step').forEach(function (s) { s.style.display = (+s.getAttribute('data-step')) === n ? '' : 'none'; });
    document.querySelectorAll('#stepDots .step-dot').forEach(function (d) { d.classList.toggle('on', +d.getAttribute('data-step') === n); });
    document.getElementById('wizardSub').textContent = 'Step ' + n + ' · ' + WIZ_SUBS[n];
    document.getElementById('prevBtn').style.visibility = n === 1 ? 'hidden' : 'visible';
    document.getElementById('nextBtn').style.display = n === 6 ? 'none' : '';
    if (n === 4) fillInstitution();
    if (n === 5) toggleAdminHint();
    if (n === 6) renderConfirm();
}

function wizNext() {
    if (WIZ.step === 2) { checkDbAndProceed(); return; }
    if (WIZ.step === 4) {
        if (!wizValidate(4)) return;
        // 关联现有库且机构关键信息被修改 → 二次确认
        if (WIZ.mode === 'attach' && attachInstitutionChanged()) {
            askInstitutionChanged(function () { wizGo(5, false); });
            return;
        }
        wizGo(5, false);
        return;
    }
    wizGo(WIZ.step + 1, true);
}
function wizPrev() { wizGo(WIZ.step - 1, false); }

/* 当前数据库驱动与参数 */
function dbDriverKey() { return document.getElementById('dbDriver').value; }
function dbParams() {
    var d = dbDriverKey();
    if (d === 'sqlite') {
        var el = document.getElementById('dbp_name');
        return { name: el ? el.value.trim() : '' };
    }
    return collectParams('db', d);
}
/* 数据库必填校验（远程库：主机/端口/库名/用户名/密码） */
function validateDbFields(quiet) {
    var d = dbDriverKey();
    if (d === 'sqlite') {
        return true;
    }
    var p = dbParams();
    var need = { host: '主机', port: '端口', dbname: '数据库名', user: '用户名', pass: '密码' };
    var keys = Object.keys(need);
    for (var i = 0; i < keys.length; i++) {
        if (!String(p[keys[i]] || '').trim()) {
            if (!quiet) Clinic.toast.warning('请填写数据库' + need[keys[i]]);
            return false;
        }
    }
    return true;
}

function wizValidate(n) {
    if (n === 2) {
        if (!validateDbFields(false)) return false;
    } else if (n === 4) {
        if (!document.getElementById('hospital_name').value.trim()) { Clinic.toast.warning('请填写医院名称'); return false; }
        if (!document.getElementById('org_code').value.trim()) { Clinic.toast.warning('请填写机构代码'); return false; }
    } else if (n === 5) {
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
/* 数据库参数表单：SQLite 仅需「数据库名称」；远程库在密码栏右侧内嵌测试连接按钮 */
function renderDbParams() {
    var box = document.getElementById('dbParamsBox');
    var d = dbDriverKey();
    var meta = driverMeta('db', d);
    var extWarn = (meta && meta.installed === false)
        ? '<div class="text-danger fs-13 mt-4">当前 PHP 未安装该驱动所需扩展（' + escHtml(meta.extension || '') + '），请先安装或更换驱动。</div>'
        : '';
    if (d === 'sqlite') {
        box.innerHTML = '<div class="form-group"><label class="form-label">数据库名称 <span class="req">*</span></label>' +
            '<input class="input" id="dbp_name" value="clinic_main" placeholder="如 clinic_main">' +
            '<div class="fs-12 text-muted mt-4">统一存放于 data/db/，可省略 .db 后缀。</div></div>' + extWarn;
        return;
    }
    if (!meta || !meta.params || !Object.keys(meta.params).length) { box.innerHTML = extWarn; return; }
    var keys = Object.keys(meta.params);
    var rows = [];
    for (var i = 0; i < keys.length; i += 2) {
        var cell1 = paramInputHtml('db', keys[i], meta.params[keys[i]]);
        var cell2 = '';
        if (i + 1 < keys.length) {
            cell2 = paramInputHtml('db', keys[i + 1], meta.params[keys[i + 1]]);
        } else if (/pass/i.test(keys[i])) {
            // 密码栏右侧内嵌测试连接按钮
            cell2 = '<div class="form-group"><label class="form-label">&nbsp;</label>' +
                '<button type="button" class="btn btn-outline" id="dbTestBtn" style="width:100%" onclick="testDb()">测试连接</button></div>';
        }
        rows.push('<div class="form-row">' + cell1 + cell2 + '</div>');
    }
    box.innerHTML = rows.join('') +
        '<div class="fs-13" id="dbTestMsg" style="min-height:20px"></div>' + extWarn;
}
/* 缓存参数表单 */
function renderCacheParams() {
    var box = document.getElementById('cacheParamsBox');
    var key = document.getElementById('cacheDriver').value;
    var meta = driverMeta('cache', key);
    if (!meta || !meta.params || !Object.keys(meta.params).length) { box.innerHTML = ''; return; }
    var keys = Object.keys(meta.params);
    var rows = [];
    for (var i = 0; i < keys.length; i += 2) {
        var cell1 = paramInputHtml('cache', keys[i], meta.params[keys[i]]);
        var cell2 = (i + 1 < keys.length) ? paramInputHtml('cache', keys[i + 1], meta.params[keys[i + 1]]) : '';
        rows.push('<div class="form-row">' + cell1 + cell2 + '</div>');
    }
    box.innerHTML = rows.join('') +
        '<div class="flex gap-8" style="align-items:center"><button type="button" class="btn btn-outline btn-sm" id="cacheTestBtn" onclick="testRedis()">测试连接</button>' +
        '<span class="fs-13 text-muted" id="redisTestMsg"></span></div>';
}
function renderDrivers(drivers) {
    WIZ.preflight = WIZ.preflight || {};
    WIZ.preflight.drivers = drivers || { db: {}, cache: {} };
    var dbSel = document.getElementById('dbDriver');
    dbSel.innerHTML = Object.keys(drivers.db || {}).map(function (k) {
        var d = drivers.db[k];
        return '<option value="' + k + '"' + (k === 'sqlite' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
    }).join('');
    var cSel = document.getElementById('cacheDriver');
    cSel.innerHTML = Object.keys(drivers.cache || {}).map(function (k) {
        var d = drivers.cache[k];
        return '<option value="' + k + '"' + (k === 'file' ? ' selected' : '') + '>' + escHtml(d.label) + (d.installed ? '' : '（未安装扩展）') + '</option>';
    }).join('');
    renderDbParams();
    renderCacheParams();
}

function toggleAdminHint() {
    document.getElementById('attachAdminHint').style.display = WIZ.mode === 'attach' ? '' : 'none';
    ['username', 'password', 'password2', 'admin_email'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.disabled = WIZ.mode === 'attach';
    });
}

document.getElementById('dbDriver').addEventListener('change', renderDbParams);
document.getElementById('cacheDriver').addEventListener('change', renderCacheParams);

/* ==================== 环境巡检 ==================== */
function loadPreflight() {
    var box = document.getElementById('preflightBox');
    box.innerHTML = '<div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div>正在检查环境…</div>';
    Clinic.get('/api/install', { action: 'preflight' }, {
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
            box.innerHTML = html;
            renderDrivers(d.drivers || { db: {}, cache: {} });
        },
        onError: function () {
            box.innerHTML = '<div class="text-danger">环境检查失败，请确认服务器环境后重试</div>';
        },
    });
}

/* ==================== 数据库连接测试 ==================== */
function dbQueryParams(action, extra) {
    var d = dbDriverKey();
    var p = dbParams();
    var q = {
        action: action,
        driver: d,
        host: p.host || '',
        port: p.port || '',
        dbname: p.dbname || '',
        user: p.user || '',
        pass: p.pass || '',
        name: p.name || '',
        icd10_name: document.getElementById('icd10_name').value.trim() || 'icd10',
    };
    if (extra) { Object.keys(extra).forEach(function (k) { q[k] = extra[k]; }); }
    return q;
}
function testDb() {
    if (!validateDbFields(false)) return;
    var btn = document.getElementById('dbTestBtn');
    var msg = document.getElementById('dbTestMsg');
    msg.textContent = '测试中…';
    if (btn) btn.disabled = true;
    function done() { if (btn) btn.disabled = false; }
    Clinic.get('/api/install', dbQueryParams('test_db'), {
        loading: false,
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; done(); },
        onError: function (x, json) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((json && json.msg) || '连接失败') + '</span>'; done(); },
    });
}
function testRedis() {
    var btn = document.getElementById('cacheTestBtn');
    var msg = document.getElementById('redisTestMsg');
    msg.textContent = '测试中…';
    if (btn) btn.disabled = true;
    function done() { if (btn) btn.disabled = false; }
    var p = collectParams('cache', 'redis');
    Clinic.get('/api/install', {
        action: 'test_redis',
        host: p.host || '',
        port: p.port || '',
        auth: p.auth || '',
    }, {
        loading: false,
        onSuccess: function (json) { msg.innerHTML = '<span class="text-success">✓ ' + escHtml(json.msg) + '</span>'; done(); },
        onError: function (x, json) { msg.innerHTML = '<span class="text-danger">✗ ' + escHtml((json && json.msg) || '连接失败') + '</span>'; done(); },
    });
}

/* ==================== 轻量对话框队列（安装页未加载 modal.js） ====================
 * 多个校验弹窗依次弹出：同一时刻仅显示一个，关闭动画结束后再弹下一个。 */
var WIZ_DLG_QUEUE = [], WIZ_DLG_BUSY = false;
function wizDialog(o) { WIZ_DLG_QUEUE.push(o); wizDlgPump(); }
function wizDlgPump() {
    if (WIZ_DLG_BUSY) return;
    var o = WIZ_DLG_QUEUE.shift();
    if (!o) return;
    WIZ_DLG_BUSY = true;
    var mask = document.createElement('div');
    mask.className = 'modal-mask';
    var btns = (o.buttons || []).map(function (b, i) {
        return '<button type="button" class="btn ' + (b.cls || 'btn-primary') + ' btn-sm" data-i="' + i + '">' + escHtml(b.text) + '</button>';
    }).join('');
    mask.innerHTML = '<div class="modal modal-sm">' +
        '<div class="modal-head"><div class="modal-title">' + escHtml(o.title || '提示') + '</div></div>' +
        '<div class="modal-body fs-13" style="line-height:1.9">' + (o.html || '') + '</div>' +
        '<div class="modal-foot" style="display:flex;justify-content:flex-end;gap:8px">' + btns + '</div></div>';
    document.body.appendChild(mask);
    requestAnimationFrame(function () { mask.classList.add('show'); });
    function close(cb) {
        mask.classList.remove('show');
        setTimeout(function () {
            if (mask.parentNode) mask.parentNode.removeChild(mask);
            WIZ_DLG_BUSY = false;
            if (cb) cb();
            wizDlgPump();
        }, 200);
    }
    (o.buttons || []).forEach(function (b, i) {
        mask.querySelector('[data-i="' + i + '"]').addEventListener('click', function () { close(b.onClick); });
    });
}

/* ==================== 第 2 步校验并处理校验弹窗 ==================== */
function checkDbAndProceed(createIcd10) {
    if (!wizValidate(2)) return;
    var btn = document.getElementById('nextBtn');
    btn.disabled = true;
    function done() { btn.disabled = false; }
    Clinic.get('/api/install', dbQueryParams('check_db', createIcd10 ? { create_icd10: '1' } : null), {
        loading: true,
        onSuccess: function (json) {
            done();
            var data = json.data || {};
            // 依次处理：ICD-10 缺失 → 已有安装 → 非本系统库，全部解决后才进入下一步
            if (data.icd10_missing) { askIcd10Missing(); return; }
            WIZ.dbInstalled = !!data.installed;
            if (WIZ.dbInstalled) { askInstallMode(); return; }
            if (data.foreign) { askForeignDb(); return; }
            WIZ.mode = 'fresh';
            wizGo(3, false);
        },
        onError: function () { done(); },
    });
}
/* ICD-10 诊断库文件不存在：提示放入可用文件或创建空库 */
function askIcd10Missing() {
    wizDialog({
        title: 'ICD-10 诊断库不存在',
        html: '未找到 ICD-10 诊断库文件。请将可用的诊断库文件放入 <b>data/db/</b> 目录后重试，' +
              '或创建一个空的诊断数据库文件（仅含表结构）。',
        buttons: [
            { text: '取消', cls: 'btn-outline' },
            { text: '创建空数据库', cls: 'btn-primary', onClick: function () { checkDbAndProceed(true); } },
        ],
    });
}
/* 目标库非空但未检测到本系统数据：提示继续将在其中创建本系统表结构 */
function askForeignDb() {
    wizDialog({
        title: '目标数据库非空',
        html: '所选数据库已存在数据表，但未检测到本系统的安装数据。继续安装将在该库中创建本系统表结构。',
        buttons: [
            { text: '取消', cls: 'btn-outline' },
            { text: '继续安装', cls: 'btn-primary', onClick: function () { WIZ.mode = 'fresh'; wizGo(3, false); } },
        ],
    });
}
/* 检测到已有安装数据：选择「关联现有 / 全新安装」 */
function askInstallMode() {
    wizDialog({
        title: '检测到已有数据库',
        html: '所选数据库已存在安装完成的数据，请选择处理方式：' +
              '<div class="mt-12"><b>关联现有数据库</b>：保留全部已有数据，仅重新绑定连接。</div>' +
              '<div class="mt-4 text-danger"><b>全新安装</b>：清空该数据库全部数据后重新创建。</div>',
        buttons: [
            { text: '取消', cls: 'btn-outline' },
            { text: '全新安装', cls: 'btn-danger', onClick: function () { WIZ.mode = 'fresh'; wizGo(3, false); } },
            { text: '关联现有数据库', cls: 'btn-primary', onClick: function () { WIZ.mode = 'attach'; loadAttachInstitution(); } },
        ],
    });
}
/* 关联现有库：读取主库机构设置并预填 Step4 */
function loadAttachInstitution() {
    WIZ.attachPrefilled = false;
    Clinic.get('/api/install', dbQueryParams('load_db_settings'), {
        loading: true,
        onSuccess: function (json) {
            WIZ.attachInst = json.data || {};
            wizGo(3, false);
        },
        onError: function () { wizGo(3, false); },
    });
}
/* 进入 Step4 时用已有主库数据预填机构信息 */
function fillInstitution() {
    if (!WIZ.attachInst || WIZ.attachPrefilled) return;
    WIZ.attachPrefilled = true;
    var map = { hospital_name: 'hospital_name', org_code: 'org_code', hospital_name2: 'hospital_name2', contact_phone: 'contact_phone', contact_addr: 'contact_addr' };
    Object.keys(map).forEach(function (k) {
        var el = document.getElementById(map[k]);
        if (el && typeof WIZ.attachInst[k] === 'string') el.value = WIZ.attachInst[k];
    });
    if (WIZ.attachInst.timezone) {
        var tz = document.getElementById('timezone');
        if (tz.querySelector('option[value="' + WIZ.attachInst.timezone + '"]')) tz.value = WIZ.attachInst.timezone;
    }
    WIZ.attachOriginal = {
        hospital_name: (WIZ.attachInst.hospital_name || '').trim(),
        org_code: (WIZ.attachInst.org_code || '').trim(),
        hospital_name2: (WIZ.attachInst.hospital_name2 || '').trim(),
    };
}
/* 关联现有库时，机构关键信息若被修改则二次确认 */
function attachInstitutionChanged() {
    if (!WIZ.attachOriginal) return false;
    var o = WIZ.attachOriginal;
    return o.hospital_name !== document.getElementById('hospital_name').value.trim() ||
           o.org_code !== document.getElementById('org_code').value.trim() ||
           o.hospital_name2 !== document.getElementById('hospital_name2').value.trim();
}
function askInstitutionChanged(onOk) {
    wizDialog({
        title: '机构信息已修改',
        html: '医院名称 / 机构代码 / 第二名称发生变化。修改已安装系统的机构信息可能导致历史单据、票据与报表口径不一致，请确认。',
        buttons: [
            { text: '取消', cls: 'btn-outline' },
            { text: '确认修改', cls: 'btn-danger', onClick: onOk },
        ],
    });
}

/* ==================== 确认汇总 ==================== */
function renderConfirm() {
    var box = document.getElementById('confirmBox');
    var dbKey = dbDriverKey();
    var dbMeta = driverMeta('db', dbKey);
    var dbp = dbParams();
    var rows = [];
    var dbDesc = dbKey === 'sqlite'
        ? ('data/db/' + (dbp.name || 'clinic_main').replace(/\.db$/i, '') + '.db')
        : ((dbp.host || '') + ':' + (dbp.port || '') + '/' + (dbp.dbname || ''));
    rows.push(['数据库驱动', (dbMeta ? dbMeta.label : dbKey) + '（' + dbDesc + '）']);
    rows.push(['安装方式', WIZ.mode === 'attach' ? '关联现有数据库（保留数据）' : '全新安装（建库并导入基础字典）']);
    if (WIZ.mode === 'fresh' && WIZ.dbInstalled) {
        rows.push(['⚠️ 注意', '将清空目标数据库全部已有数据后重新创建']);
    }
    var icd10 = document.getElementById('icd10_name').value.trim() || 'icd10';
    rows.push(['ICD-10 诊断库', 'data/db/' + icd10.replace(/\.db$/i, '') + '.db']);
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
        var warn = r[0].indexOf('⚠️') === 0;
        return '<div class="flex-between mb-4"><span class="' + (warn ? 'text-danger' : 'text-muted') + '">' + escHtml(r[0]) + '</span><span class="fw-600' + (warn ? ' text-danger' : '') + '">' + escHtml(r[1]) + '</span></div>';
    }).join('');
}

/* ==================== 执行安装 ==================== */
document.getElementById('installBtn').addEventListener('click', function () {
    var btn = this;
    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'save');
    fd.append('mode', WIZ.mode);
    var dbKey = dbDriverKey();
    var dbp = dbParams();
    fd.append('db_driver', dbKey);
    fd.append('db_host', dbp.host || '');
    fd.append('db_port', dbp.port || '');
    fd.append('db_name', dbp.dbname || '');
    fd.append('db_user', dbp.user || '');
    fd.append('db_pass', dbp.pass || '');
    fd.append('sqlite_name', dbKey === 'sqlite' ? (dbp.name || '') : '');
    fd.append('icd10_name', document.getElementById('icd10_name').value.trim());
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
