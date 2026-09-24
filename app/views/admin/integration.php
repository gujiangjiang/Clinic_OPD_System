<?php
/**
 * ============================================================
 * admin/integration.php — 接口管理（统一外部接口设置中心）
 * ============================================================
 * 说明：原系统设置中的【HIS 接口配置】迁移至此，并新增按 Tab 选项卡
 * 统一维护的外部接口配置：
 *   1. HIS 接口（API 地址、系统代码、密钥、同步模式）
 *   2. 支付接口（微信支付、支付宝、聚合收单占位）
 *   3. 医保接口（国家/地方医保前置机、机构编码、操作员账号）
 *   4. 医疗影像与互联标准接口（重点）：
 *      - DICOM/PACS（PACS 服务器、AETitle、WADO-RS/DICOMweb、Web 阅片器 URL 模板 {study_uid}）
 *      - HL7 v2.x（MLLP/HTTP 接收地址、发送方/接收方标识）
 *      - FHIR（R4 Endpoint、Token 认证参数）
 * 数据存储：settings 键值对（pacs_/hl7_/fhir_/his_/pay_/yibao_ 前缀），
 * 由 /api/admin action=integration_load / integration_save 读写。
 * ============================================================ */
Router::title('接口管理');

$groups = integration_field_groups();
$vals = array();
foreach ($groups as $g) {
    foreach ($g['fields'] as $f) {
        $vals[$f['key']] = setting($f['key'], $f['default']);
    }
}
// HIS 接口地址：本系统对外地址 + /api/his（自动生成，无需人工填写；供外部 HIS 系统调用）
$hisApiScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hisApiHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$hisApiBase = $hisApiScheme . '://' . $hisApiHost . '/api/his';
$hisKeyNow = trim((string)setting('his_api_key', ''));
// 医疗机构代码（org_code）：HIS 系统代码/医保机构编码自动引用
$orgCode = trim((string)setting('org_code', ''));
?>
<div class="page-head">
    <div><div class="page-title">🔌 接口管理</div>
    <div class="page-desc">统一维护 HIS、支付、医保与医疗影像互联标准接口（DICOM/PACS · HL7 v2.x · FHIR R4）配置</div></div>
</div>

<div class="card" style="padding-bottom:6px">
    <div class="itg-tabs" id="itgTabs">
        <?php foreach ($groups as $gi => $g): ?>
            <button type="button" class="itg-tab btn btn-sm<?php echo $gi === 0 ? ' btn-primary' : ' btn-outline'; ?>"
                data-tab="<?php echo e($g['id']); ?>" onclick="itgTab('<?php echo e($g['id']); ?>')">
                <?php echo e($g['emoji'] . ' ' . $g['title']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" style="padding:10px 14px 12px">
        提示：以下配置仅保存在本系统数据库（settings 键值对），不会自动连接外部服务；各接口调用行为需由对应功能模块实现。带 <span class="req">*</span> 的密钥类字段请妥善保管。
    </div>
</div>

<?php foreach ($groups as $gi => $g): ?>
    <?php $isHis = ($g['id'] === 'his'); ?>
    <div class="<?php echo $isHis ? 'itg-pane' : 'card itg-pane'; ?>" id="itgPane_<?php echo e($g['id']); ?>" data-tab="<?php echo e($g['id']); ?>"<?php echo $gi === 0 ? '' : ' style="display:none"'; ?>>
        <?php if (!$isHis): ?>
        <div class="card-title"><?php echo e($g['emoji'] . ' ' . $g['title']); ?></div>
        <?php if (!empty($g['desc'])): ?>
            <div class="fs-12 text-muted mb-12" style="margin-top:-8px"><?php echo e($g['desc']); ?></div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($isHis): ?>
        <!-- HIS 接口：左右两栏（详情/配置/接口/测试/说明），不套外层卡片 -->
        <div class="db-center" style="height:auto;align-items:flex-start">
            <div class="card db-sidebar">
                <div class="db-nav active" data-histab="detail" onclick="hisTab('detail')">📊 详情</div>
                <div class="db-nav" data-histab="config" onclick="hisTab('config')">⚙️ 配置</div>
                <div class="db-nav" data-histab="api" onclick="hisTab('api')">🔗 接口</div>
                <div class="db-nav" data-histab="test" onclick="hisTab('test')">🧪 测试</div>
                <div class="db-nav" data-histab="docs" onclick="hisTab('docs')">📖 说明</div>
            </div>
            <div class="db-main">
                <div class="db-pane" id="histab-detail">
                    <div class="card setting-card">
                        <div class="card-title">📊 接口详情</div>
                        <div class="fs-13" style="line-height:2.2">
                            <div class="flex-between"><span class="text-muted">启用状态</span><span id="hisStatusBadge" class="badge badge-gray" style="font-size:11.5px">未启用</span></div>
                            <div class="flex-between"><span class="text-muted">系统代码</span><span id="hisDetailSyscode">—</span></div>
                            <div class="flex-between"><span class="text-muted">同步模式</span><span id="hisDetailMode">—</span></div>
                            <div class="flex-between"><span class="text-muted">接口地址</span><code class="fs-12" id="hisDetailUrl" style="word-break:break-all">—</code></div>
                        </div>
                        <div class="fs-12 text-muted mt-8">接口密钥留空即关闭 HIS 外部只读查询；配置并保存后接口可用。</div>
                    </div>
                </div>
                <div class="db-pane" id="histab-config" style="display:none">
                    <div class="card setting-card">
                        <div class="card-title">⚙️ HIS 接口配置</div>
                        <?php foreach ($g['fields'] as $f): ?>
                        <div class="form-group">
                            <label class="form-label"><?php echo e($f['label']); ?>
                                <?php if (!empty($f['monospace'])): ?><span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span><?php endif; ?>
                                <?php if (!empty($f['source']) && $f['source'] === 'org_code'): ?><span class="fs-12 text-muted" style="font-weight:400">（自动引用医疗机构代码）</span><?php endif; ?>
                            </label>
                            <?php if ($f['type'] === 'select'): ?>
                                <select class="select" id="itg_<?php echo e($f['key']); ?>">
                                    <?php foreach ($f['options'] as $ov => $ot): ?>
                                        <option value="<?php echo e($ov); ?>"<?php echo $vals[$f['key']] === (string)$ov ? ' selected' : ''; ?>><?php echo e($ot); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <div class="flex" style="gap:8px">
                                    <?php $isHisKey = ($f['key'] === 'his_api_key'); $isOrgCode = !empty($f['source']) && $f['source'] === 'org_code'; ?>
                                    <input class="input" id="itg_<?php echo e($f['key']); ?>"
                                        value="<?php echo e($isOrgCode ? $orgCode : $vals[$f['key']]); ?>"
                                        placeholder="<?php echo e($f['placeholder']); ?>"
                                        <?php if ($isHisKey): ?> disabled title="仅可通过随机生成，不支持手动输入"<?php endif; ?>
                                        <?php if (!empty($f['monospace'])): ?> style="font-family:monospace"<?php endif; ?>
                                        <?php if ($isOrgCode): ?> readonly title="自动引用系统医疗机构代码（org_code），无需人工输入"<?php endif; ?>>
                                    <?php if ($isHisKey): ?>
                                        <button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0" onclick="genHisKey()">🔑 生成密钥</button>
                                        <button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0" onclick="clearHisKey()">🧹 清空密钥</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($f['hint'])): ?>
                                <div class="fs-12 text-muted mt-4"><?php echo e($f['hint']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <button class="btn btn-primary btn-sm" onclick="itgSave('his')">保存配置</button>
                    </div>
                </div>
                <div class="db-pane" id="histab-api" style="display:none">
                    <div class="card setting-card">
                        <div class="card-title">🔗 接口地址与调用示例</div>
                        <div class="form-group"><label class="form-label">HIS 接口地址（自动生成，供外部 HIS 系统调用）</label>
                            <div class="flex" style="gap:8px">
                                <code class="itg-his-url" id="hisApiUrl" style="flex:1;margin:0" title="点击复制地址" onclick="copyHisUrl()"><?php
                                    if ($hisKeyNow !== '') { echo e($hisApiBase . '?action=ping&api_key=' . $hisKeyNow); }
                                    else { echo '<span class="itg-his-url-ph">请先填写接口密钥并保存，地址将自动生成</span>'; }
                                ?></code>
                            </div></div>
                        <div class="fs-12 text-muted mb-4">调用示例（GET，保存密钥后随地址一并刷新）：</div>
                        <div class="itg-his-curl">
                            <code id="hisCurlDemo" title="点击复制 curl 示例" onclick="copyHisCurl()"></code>
                        </div>
                    </div>
                </div>
                <div class="db-pane" id="histab-test" style="display:none">
                    <div class="card setting-card">
                        <div class="card-title">🧪 接口连通性测试</div>
                        <div class="fs-12 text-muted mt-2 mb-8">实际请求本系统 /api/his（需先保存密钥），分别验证「请求头 X-HIS-Key」与「GET 参数 api_key」两种认证方式：</div>
                        <button type="button" class="btn btn-primary btn-sm" onclick="testHisApi()">▶ 开始测试</button>
                        <div id="hisTestBox" class="itg-his-result">
                            <div class="itg-his-empty">
                                <div class="itg-his-empty-ico">🧪</div>
                                <div class="itg-his-empty-title">尚未测试</div>
                                <div class="itg-his-empty-sub">保存密钥后点击「开始测试」，将在此展示两种认证方式的测试结果</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="db-pane" id="histab-docs" style="display:none">
                    <div class="card setting-card">
                        <div class="card-title">📖 接口说明</div>
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>action</th><th>参数</th><th>说明</th></tr></thead>
                            <tbody>
                                <tr><td><code>ping</code></td><td>无</td><td>连通性自检，返回系统标识、系统代码与服务器时间</td></tr>
                                <tr><td><code>patient_get</code></td><td><code>id_card</code> 或 <code>patient_no</code></td><td>查询患者档案</td></tr>
                                <tr><td><code>visit_list</code></td><td><code>patient_no</code></td><td>该患者全部就诊记录</td></tr>
                                <tr><td><code>visit_status</code></td><td><code>flow_no</code></td><td>查询某次就诊状态</td></tr>
                                <tr><td><code>order_list</code></td><td><code>visit_id</code></td><td>某次就诊的开单明细</td></tr>
                                <tr><td><code>evidence_verify</code></td><td><code>record_id</code> 或 <code>cert_no</code></td><td>存证校验（病历/证明的哈希指纹与凭据验真）</td></tr>
                            </tbody>
                        </table></div>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- /itg-pane(his) -->
        <?php else: ?>
        <?php foreach ($g['fields'] as $f): ?>
            <div class="form-group">
                <label class="form-label"><?php echo e($f['label']); ?>
                    <?php if (!empty($f['monospace'])): ?><span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span><?php endif; ?>
                    <?php if (!empty($f['source']) && $f['source'] === 'org_code'): ?><span class="fs-12 text-muted" style="font-weight:400">（自动引用医疗机构代码）</span><?php endif; ?>
                </label>
                <?php if ($f['type'] === 'select'): ?>
                    <select class="select" id="itg_<?php echo e($f['key']); ?>">
                        <?php foreach ($f['options'] as $ov => $ot): ?>
                            <option value="<?php echo e($ov); ?>"<?php echo $vals[$f['key']] === (string)$ov ? ' selected' : ''; ?>><?php echo e($ot); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php $isOrgCode = !empty($f['source']) && $f['source'] === 'org_code'; ?>
                    <input class="input" id="itg_<?php echo e($f['key']); ?>"
                        value="<?php echo e($isOrgCode ? $orgCode : $vals[$f['key']]); ?>"
                        placeholder="<?php echo e($f['placeholder']); ?>"
                        <?php if (!empty($f['monospace'])): ?> style="font-family:monospace"<?php endif; ?>
                        <?php if ($isOrgCode): ?> readonly title="自动引用系统医疗机构代码（org_code），无需人工输入"<?php endif; ?>>
                <?php endif; ?>
                <?php if (!empty($f['hint'])): ?>
                    <div class="fs-12 text-muted mt-4"><?php echo e($f['hint']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($g['id'] === 'pacs'): ?>
            <div class="fs-12 text-muted mb-12">
                Web 阅片器 URL 模板支持 <code>{study_uid}</code> 变量替换：书写阅片时系统会将当前检查对应的 Study UID 替换进模板打开阅片器。
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>')">保存本组配置</button>
        </div>
        <?php endif; ?>
<?php endforeach; ?>

<script>
/* ---------- HIS 接口渲染（地址 + curl 示例，保存本组配置后更新） ---------- */
var HIS_BASE = <?php echo json_encode($hisApiBase); ?>;
var HIS_SAVED_KEY = <?php echo json_encode($hisKeyNow); ?>;

function hisUrlText(key) { return HIS_BASE + '?action=ping&api_key=' + key; }

function renderHisLive() {
    var key = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
    var urlEl = document.getElementById('hisApiUrl');
    if (urlEl) {
        urlEl.innerHTML = key === ''
            ? '<span class="itg-his-url-ph">请先填写接口密钥并保存，地址将自动生成</span>'
            : Clinic.escHtml(hisUrlText(key));
    }
    var curlEl = document.getElementById('hisCurlDemo');
    if (curlEl) {
        // 未配置密钥：与接口地址一致的灰色占位；已配置：等宽实体命令文本
        curlEl.innerHTML = key === ''
            ? '<span class="itg-his-url-ph">请先填写接口密钥并保存，调用示例将自动生成</span>'
            : Clinic.escHtml('curl -H "X-HIS-Key: ' + key + '" "' +
                HIS_BASE + '?action=patient_get&id_card=110101199001011234"');
    }
}
renderHisLive();   // 初始渲染（按当前已保存密钥）；密钥修改仅在保存后刷新
renderHisDetail();
window.addEventListener('resize', function () {
    var pane = document.getElementById('itgPane_his');
    if (pane && pane.style.display !== 'none') { if (document.getElementById('itgPane_his').style.display !== 'none') renderHisDetail(); }
});

/* ---------- 生成随机 HIS 接口密钥（一键生成后点击保存生效） ---------- */
function genHisKey() {
    var arr = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    var key = Array.prototype.map.call(arr, function (b) {
        return ('0' + b.toString(16)).slice(-2);
    }).join('');
    var el = document.getElementById('itg_his_api_key');
    if (el) el.value = key;
    Clinic.toast.success('已生成密钥，请点击【保存本组配置】生效');
}

/* ---------- 清空 HIS 接口密钥（一键清空后点击保存生效） ---------- */
function clearHisKey() {
    var el = document.getElementById('itg_his_api_key');
    if (el) el.value = '';
    renderHisLive();
    Clinic.toast.success('已清空密钥，请点击【保存本组配置】生效');
}

/* ---------- 复制 HIS 接口地址（带密钥） ---------- */
function copyText(t) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(function () { Clinic.toast.success('已复制到剪贴板'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = t;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        Clinic.toast.success('已复制到剪贴板');
    }
}
function copyHisUrl() {
    var key = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
    if (key === '') { Clinic.toast.warning('请先填写或生成接口密钥并保存本组配置'); return; }
    copyText(hisUrlText(key));
}
function copyHisCurl() {
    var el = document.getElementById('hisCurlDemo');
    if (!el) return;
    var t = el.textContent.trim();
    if (t.indexOf('<密钥>') !== -1) { Clinic.toast.warning('请先填写或生成接口密钥并保存本组配置'); return; }
    copyText(t);
}

/* ---------- HIS 接口连通性测试（请求头 + GET 参数两种方式） ---------- */
function hisTestEmpty() {
    var box = document.getElementById('hisTestBox');
    if (!box) return;
    box.innerHTML = '<div class="itg-his-empty">' +
        '<div class="itg-his-empty-ico">🧪</div>' +
        '<div class="itg-his-empty-title">尚未测试</div>' +
        '<div class="itg-his-empty-sub">保存密钥后点击「开始测试」，将在此展示两种认证方式的测试结果</div></div>';
}
function testHisApi() {
    var key = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
    var box = document.getElementById('hisTestBox');
    if (!box) return;
    if (key === '') { Clinic.toast.warning('请先填写或生成接口密钥并保存本组配置'); return; }
    if (key !== HIS_SAVED_KEY) { Clinic.toast.warning('密钥已修改但尚未保存，请先点击【保存本组配置】再测试'); return; }
    var base = location.protocol + '//' + location.host + '/api/his?action=ping';
    box.innerHTML = '<div class="fs-12 text-muted" style="padding:10px 2px">⏳ 测试中，请稍候…</div>';
    var render = function (label, url, init) {
        fetch(url, init).then(function (r) { return r.json(); }).then(function (j) {
            var ok = !!(j && j.ok && j.data && j.data.pong);
            var code = JSON.stringify(j, null, 2);
            box.innerHTML += '<div class="itg-his-titem">' +
                '<div class="itg-his-tlabel"><span class="badge ' + (ok ? 'badge-success' : 'badge-danger') + '">' + (ok ? '✓ 通过' : '✕ 失败') + '</span> ' + label + '</div>' +
                '<code class="itg-his-tcode">' + Clinic.escHtml(code) + '</code></div>';
        }).catch(function () {
            box.innerHTML += '<div class="itg-his-titem"><div class="itg-his-tlabel"><span class="badge badge-danger">✕ 请求失败</span> ' + label + '</div></div>';
        });
    };
    render('请求头 X-HIS-Key', base, { headers: { 'X-HIS-Key': key } });
    render('GET 参数 api_key', base + '&api_key=' + encodeURIComponent(key), {});
}

/* ---------- HIS 左右两列高度同步：以左列高度为基准，右列超高时内部滚动 ---------- */
/* ---------- Tab 选项卡切换 ---------- */
function itgTab(id) {
    document.querySelectorAll('#itgTabs .itg-tab').forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('active', on);
        t.classList.toggle('btn-primary', on);
        t.classList.toggle('btn-outline', !on);
    });
    document.querySelectorAll('.itg-pane, .itg-pane-docs').forEach(function (p) {
        p.style.display = (p.getAttribute('data-tab') === id) ? '' : 'none';
    });
    if (id === 'his') { hisTab('detail'); }
}

/* ---------- HIS 接口左右栏子导航（详情/配置/接口/测试/说明） ---------- */
function hisTab(name) {
    document.querySelectorAll('#itgPane_his .db-nav').forEach(function (n) { n.classList.toggle('active', n.getAttribute('data-histab') === name); });
    document.querySelectorAll('#itgPane_his .db-pane').forEach(function (p) { p.style.display = p.id === 'histab-' + name ? '' : 'none'; });
    if (name === 'detail') renderHisDetail();
}

/* 详情面板：展示当前配置值（密钥状态=是否启用、系统代码、同步模式、接口地址） */
function renderHisDetail() {
    var key = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
    var badge = document.getElementById('hisStatusBadge');
    if (badge) {
        badge.textContent = key === '' ? '未启用（密钥为空）' : '已启用';
        badge.className = 'badge ' + (key === '' ? 'badge-gray' : 'badge-success');
        badge.style.fontSize = '11.5px';
    }
    var sys = document.getElementById('hisDetailSyscode');
    if (sys) sys.textContent = ((document.getElementById('itg_his_system_code') || {}).value || '').trim() || '—';
    var mode = document.getElementById('hisDetailMode');
    if (mode) {
        var sel = document.getElementById('itg_his_sync_mode');
        mode.textContent = sel && sel.options && sel.selectedIndex >= 0
            ? sel.options[sel.selectedIndex].text : '—';
    }
    var url = document.getElementById('hisDetailUrl');
    if (url) url.textContent = key === '' ? '—（配置并保存密钥后生成）' : hisUrlText(key);
}

/* ---------- 分组保存（仅提交该组字段） ---------- */
var ITG_KEYS = <?php echo json_encode(array_map(function ($g) {
    return array('id' => $g['id'], 'keys' => array_map(function ($f) { return $f['key']; }, $g['fields']));
}, $groups), JSON_UNESCAPED_UNICODE); ?>;

function itgSave(groupId) {
    var group = null;
    ITG_KEYS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    var data = { action: 'integration_save', group: groupId };
    group.keys.forEach(function (k) {
        var el = document.getElementById('itg_' + k);
        if (el) data[k] = el.value.trim();
    });
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            // HIS 保存后：同步实时渲染状态 + 清空连通性测试结果 + 重新对齐两列高度
            if (groupId === 'his') {
                HIS_SAVED_KEY = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
                renderHisLive();
                renderHisDetail();
                hisTestEmpty();
            }
        },
    });
}
</script>
