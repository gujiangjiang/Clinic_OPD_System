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
    <div class="card itg-pane" id="itgPane_<?php echo e($g['id']); ?>"<?php echo $gi === 0 ? '' : ' style="display:none"'; ?>>
        <div class="card-title"><?php echo e($g['emoji'] . ' ' . $g['title']); ?></div>
        <?php if (!empty($g['desc'])): ?>
            <div class="fs-12 text-muted mb-12" style="margin-top:-8px"><?php echo e($g['desc']); ?></div>
        <?php endif; ?>
        <?php if ($g['id'] === 'his'): ?>
        <div class="itg-his-cols">
            <div class="itg-his-left">
        <?php endif; ?>
        <?php foreach ($g['fields'] as $f): ?>
            <div class="form-group">
                <label class="form-label"><?php echo e($f['label']); ?>
                    <?php if (!empty($f['monospace'])): ?><span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span><?php endif; ?>
                </label>
                <?php if ($f['type'] === 'select'): ?>
                    <select class="select" id="itg_<?php echo e($f['key']); ?>">
                        <?php foreach ($f['options'] as $ov => $ot): ?>
                            <option value="<?php echo e($ov); ?>"<?php echo $vals[$f['key']] === (string)$ov ? ' selected' : ''; ?>><?php echo e($ot); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="flex" style="gap:8px">
                        <input class="input" id="itg_<?php echo e($f['key']); ?>"
                            value="<?php echo e($vals[$f['key']]); ?>"
                            placeholder="<?php echo e($f['placeholder']); ?>"
                            <?php if (!empty($f['monospace'])): ?> style="font-family:monospace"<?php endif; ?>>
                        <?php if ($g['id'] === 'his' && $f['key'] === 'his_api_key'): ?>
                            <button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0" onclick="genHisKey()">🔑 生成密钥</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($f['hint'])): ?>
                    <div class="fs-12 text-muted mt-4"><?php echo e($f['hint']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($g['id'] === 'his'): ?>
                <div class="itg-his-addr">
                    <div class="itg-his-addr-head">
                        <div>
                            <div class="fw-600 fs-13">🌐 HIS 接口地址（自动生成 · 实时更新）</div>
                            <div class="fs-12 text-muted mt-2">外部 HIS 系统调用本系统的接口地址，按当前访问地址自动生成并附带密钥；密钥变化时地址实时刷新。</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" onclick="copyHisUrl()">📋 复制地址</button>
                    </div>
                    <code class="itg-his-url" id="hisApiUrl"><?php
                        if ($hisKeyNow !== '') { echo e($hisApiBase . '?action=ping&api_key=' . $hisKeyNow); }
                        else { echo '<span class="itg-his-url-ph">请先填写接口密钥并保存，地址将自动生成</span>'; }
                    ?></code>
                </div>
                <div class="itg-his-docs">
                    <div class="fw-600 fs-13 mb-8">📖 接口说明（外部系统调用）</div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>action</th><th>参数</th><th>说明</th></tr></thead>
                        <tbody>
                            <tr><td><code>ping</code></td><td>无</td><td>连通性自检，返回系统标识、系统代码与服务器时间</td></tr>
                            <tr><td><code>patient_get</code></td><td><code>id_card</code> 或 <code>patient_no</code></td><td>查询患者档案</td></tr>
                            <tr><td><code>visit_list</code></td><td><code>patient_no</code></td><td>该患者全部就诊记录</td></tr>
                            <tr><td><code>visit_status</code></td><td><code>flow_no</code></td><td>查询某次就诊状态</td></tr>
                            <tr><td><code>order_list</code></td><td><code>visit_id</code></td><td>某次就诊的开单明细</td></tr>
                        </tbody>
                    </table></div>
                    <div class="fs-12 text-muted mt-8 mb-4">调用示例（GET，密钥实时更新）：</div>
                    <div class="itg-his-curl">
                        <code id="hisCurlDemo"></code>
                        <button type="button" class="btn btn-outline btn-sm" title="复制 curl 示例" onclick="copyHisCurl()">📋</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="itgSave('his')">保存本组配置</button>
            </div>
            <div class="itg-his-right">
                <div class="itg-his-test">
                    <div class="fw-600 fs-13">🧪 接口连通性测试</div>
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
        </div>
        <?php else: ?>
        <?php if ($g['id'] === 'pacs'): ?>
            <div class="fs-12 text-muted mb-12">
                Web 阅片器 URL 模板支持 <code>{study_uid}</code> 变量替换：书写阅片时系统会将当前检查对应的 Study UID 替换进模板打开阅片器。
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>')">保存本组配置</button>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script>
/* ---------- HIS 接口实时渲染（地址 + curl 示例跟随密钥动态刷新） ---------- */
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
        curlEl.textContent = 'curl -H "X-HIS-Key: ' + (key === '' ? '<密钥>' : key) + '" "' +
            HIS_BASE + '?action=patient_get&id_card=110101199001011234"';
    }
}
(function bindHisLive() {
    var keyInp = document.getElementById('itg_his_api_key');
    if (keyInp) keyInp.addEventListener('input', renderHisLive);
    renderHisLive();
})();

/* ---------- 生成随机 HIS 接口密钥（一键生成后点击保存生效） ---------- */
function genHisKey() {
    var arr = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    var key = Array.prototype.map.call(arr, function (b) {
        return ('0' + b.toString(16)).slice(-2);
    }).join('');
    var el = document.getElementById('itg_his_api_key');
    if (el) el.value = key;
    renderHisLive();
    Clinic.toast.success('已生成密钥，请点击【保存本组配置】生效');
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

/* ---------- Tab 选项卡切换 ---------- */
function itgTab(id) {
    document.querySelectorAll('#itgTabs .itg-tab').forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('active', on);
        t.classList.toggle('btn-primary', on);
        t.classList.toggle('btn-outline', !on);
    });
    document.querySelectorAll('.itg-pane').forEach(function (p) {
        p.style.display = (p.id === 'itgPane_' + id) ? '' : 'none';
    });
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
            // HIS 保存后：同步实时渲染状态 + 清空连通性测试结果
            if (groupId === 'his') {
                HIS_SAVED_KEY = ((document.getElementById('itg_his_api_key') || {}).value || '').trim();
                renderHisLive();
                hisTestEmpty();
            }
        },
    });
}
</script>
