<?php
/**
 * ============================================================
 * admin/integration.php — 接口管理（外部集成中心 · 双向两舱架构）
 * ============================================================
 * 说明：按系统划分子模块（FHIR R4 / DICOM-PACS / HL7 v2 / LIS / HIS /
 * 医保与支付 / 存证签名），每个模块内部强制划分为：
 *   【出向集成 Outbound】本系统作为 Client 调用外部（配置网关、凭证、超时、触发时机）
 *   【入向开放 Inbound】本系统作为 Server 对外暴露（只读展示端点 + 一键复制 +
 *                        认证规则 / IP 白名单 / 签名说明）
 * HIS 模块另附「同步与对账监控」面板（his_sync_tasks Outbox 任务重试 +
 * 入向调用审计），支撑财务单边账补偿与排障溯源。
 * 数据存储：settings 键值对（integration.outbound.* / integration.inbound.*
 * 命名空间，旧键自动迁移），由 /api/admin action=integration_save 读写。
 * ============================================================ */
Router::title('接口管理');

$groups = integration_field_groups();
$vals = array();
foreach ($groups as $g) {
    foreach ($g['fields'] as $f) {
        $vals[$f['key']] = setting($f['key'], isset($f['default']) ? $f['default'] : '');
    }
}
$baseHost = integration_host_base();
$endpoints = integration_inbound_endpoints();
$orgCode = trim((string)setting('org_code', ''));
?>
<div class="page-head">
    <div><div class="page-title"><?= render_icon('nav:plug') ?> 接口管理</div>
    <div class="page-desc">外部系统集成中心：FHIR R4 · DICOM/PACS · HL7 v2 · LIS · HIS · 医保支付，统一出向/入向双向两舱架构</div></div>
</div>

<div class="card" style="padding-bottom:6px">
    <div class="itg-tabs" id="itgTabs">
        <?php foreach ($groups as $gi => $g): ?>
            <button type="button" class="itg-tab btn btn-sm<?php echo $gi === 0 ? ' btn-primary' : ' btn-outline'; ?>"
                data-tab="<?php echo e($g['id']); ?>" onclick="itgTab('<?php echo e($g['id']); ?>')">
                <?php echo $g['emoji'] . ' ' . e($g['title']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" style="padding:10px 14px 12px">
        出向 = 本系统调用外部（Client）；入向 = 外部调用本系统（Server，端点只读展示、一键复制）。配置仅保存在本系统数据库，各接口行为由对应服务引擎实现。
    </div>
</div>

<?php foreach ($groups as $gi => $g): ?>
<?php
    $fieldsOut = array();
    $fieldsIn = array();
    $fieldsPub = array();
    foreach ($g['fields'] as $f) {
        $zone = isset($f['zone']) ? $f['zone'] : '';
        if ($zone === 'outbound') $fieldsOut[] = $f;
        elseif ($zone === 'inbound') $fieldsIn[] = $f;
        else $fieldsPub[] = $f;
    }
    $hasEndpoints = !empty($g['endpoints']);
?>
<div class="card itg-pane" id="itgPane_<?php echo e($g['id']); ?>" data-tab="<?php echo e($g['id']); ?>"<?php echo $gi === 0 ? '' : ' style="display:none"'; ?>>
    <div class="card-title"><?php echo $g['emoji'] . ' ' . e($g['title']); ?></div>
    <?php if (!empty($g['desc'])): ?>
        <div class="fs-12 text-muted mb-12" style="margin-top:-8px"><?php echo e($g['desc']); ?></div>
    <?php endif; ?>

    <?php if ($fieldsPub): ?>
    <div class="itg-zone">
        <div class="itg-zone-title">公共配置</div>
        <?php foreach ($fieldsPub as $f): ?>
            <?php render_itg_field($f, $vals); ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($fieldsOut): ?>
    <div class="itg-zone">
        <div class="itg-zone-title"><span class="itg-zone-arrow">→</span> 出向集成（Outbound · 本系统调用外部）</div>
        <?php foreach ($fieldsOut as $f): ?>
            <?php render_itg_field($f, $vals); ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($fieldsIn || $hasEndpoints): ?>
    <div class="itg-zone">
        <div class="itg-zone-title"><span class="itg-zone-arrow">←</span> 入向开放（Inbound · 外部调用本系统）</div>
        <?php if ($hasEndpoints): ?>
        <div class="form-group">
            <label class="form-label">对外暴露端点（只读，点击复制）</label>
            <?php foreach ($g['endpoints'] as $ep): ?>
                <?php if (!empty($ep['path'])): ?>
                <div class="itg-ep">
                    <div class="itg-ep-head">
                        <?php if (!empty($ep['method'])): ?><span class="badge badge-success"><?php echo e($ep['method']); ?></span><?php endif; ?>
                        <span class="fs-13 fw-600"><?php echo e($ep['label']); ?></span>
                    </div>
                    <div class="flex" style="gap:8px">
                        <code class="itg-ep-url" title="点击复制" style="cursor:pointer" onclick="itgCopy(this.textContent.trim())"><?php echo e($baseHost . $ep['path']); ?></code>
                        <button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0" onclick="itgCopy('<?php echo e($baseHost . $ep['path']); ?>')">复制</button>
                    </div>
                    <div class="fs-12 text-muted mt-4"><?php echo e($ep['note']); ?></div>
                    <?php if (!empty($ep['example'])): ?>
                        <div class="itg-ep-example fs-12 mt-4"><?php echo e($ep['example']); ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="itg-ep">
                    <div class="itg-ep-head"><span class="fs-13 fw-600"><?php echo e($ep['label']); ?></span></div>
                    <div class="fs-12 text-muted mt-4"><?php echo e($ep['note']); ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php foreach ($fieldsIn as $f): ?>
            <?php render_itg_field($f, $vals); ?>
        <?php endforeach; ?>
        <?php if ($g['id'] === 'his'): ?>
            <div class="flex" style="gap:8px;margin-top:2px">
                <button type="button" class="btn btn-outline btn-sm" onclick="genHisToken()"><?= render_icon('nav:key') ?> 生成 Token</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="testHisApi()"><?= render_icon('action:next') ?> 连通性测试</button>
            </div>
            <div id="hisTestBox" class="itg-his-result" style="display:none"></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($g['id'] === 'his'): ?>
    <!-- HIS 同步与对账监控面板（Outbox 任务补偿 + 入向审计） -->
    <div class="itg-zone">
        <div class="itg-zone-title"><?= render_icon('nav:chart') ?> HIS 同步与对账监控</div>
        <div class="fs-12 text-muted mb-8">出向任务（his_sync_tasks）：挂号/结算/发药本地事务提交后异步入队，失败自动累计重试次数，可一键重试。</div>
        <div class="itg-mon-row" id="itgMonStats"></div>
        <div class="flex" style="gap:8px;margin:10px 0 12px">
            <button type="button" class="btn btn-primary btn-sm" onclick="itgMonRun()"><?= render_icon('action:next') ?> 执行待办</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="itgMonRetryAll()">重试全部失败</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="itgMonClear()">清空历史</button>
        </div>
        <div class="flex" style="gap:8px;margin-bottom:10px">
            <select class="select" id="itgMonStatus" style="width:130px">
                <option value="">全部状态</option>
                <option value="pending">待处理</option>
                <option value="success">成功</option>
                <option value="failed">失败</option>
            </select>
            <input class="input" id="itgMonKw" placeholder="业务类型 / 业务ID / 载荷关键字" style="max-width:260px">
            <button type="button" class="btn btn-outline btn-sm" onclick="itgMonLoad(1)">查询</button>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>ID</th><th>业务类型</th><th>业务ID</th><th>状态</th><th>重试</th><th>错误 / 载荷</th><th>更新时间</th><th></th></tr></thead>
            <tbody id="itgMonBody"></tbody>
        </table></div>
        <div id="itgMonMore" class="flex-center" style="padding:8px"></div>
        <div class="itg-zone-sub-title mt-16">入向调用审计（inbound_events，全部开放端点接收记录）</div>
        <div class="flex" style="gap:8px;margin:8px 0">
            <input class="input" id="itgInboundKw" placeholder="端点 / 提供方 / 摘要" style="max-width:260px">
            <label class="checkbox" style="align-items:center"><input type="checkbox" id="itgInboundFail"> 仅看失败</label>
            <button type="button" class="btn btn-outline btn-sm" onclick="itgInboundLoad(1)">查询</button>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>ID</th><th>端点</th><th>结果</th><th>摘要</th><th>来源 IP</th><th>时间</th></tr></thead>
            <tbody id="itgInboundBody"></tbody>
        </table></div>
        <div id="itgInboundMore" class="flex-center" style="padding:8px"></div>
    </div>
    <?php endif; ?>

    <div class="flex" style="gap:8px;margin-top:6px">
        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>')">保存本组配置</button>
    </div>
</div>
<?php endforeach; ?>

<?php
/**
 * 字段渲染助手（input / select / textarea + show_if 联动）
 * @param array $f    字段定义
 * @param array $vals 当前值表
 */
function render_itg_field($f, $vals) {
    $key = $f['key'];
    $val = isset($vals[$key]) ? (string)$vals[$key] : (isset($f['default']) ? (string)$f['default'] : '');
    $showIf = isset($f['show_if']) ? ' data-show-if="' . e($f['show_if']['key']) . '" data-show-val="' . e($f['show_if']['value']) . '"' : '';
    $mono = !empty($f['monospace']) ? ' style="font-family:monospace"' : '';
    $isPort = isset($f['rule']) && $f['rule'] === 'port';
    echo '<div class="form-group"' . $showIf . '>';
    echo '<label class="form-label">' . e($f['label']);
    if (!empty($f['monospace'])) echo ' <span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span>';
    echo '</label>';
    if (isset($f['type']) && $f['type'] === 'select') {
        echo '<select class="select" id="itg_' . e($key) . '">';
        foreach ($f['options'] as $ov => $ot) {
            echo '<option value="' . e($ov) . '"' . ($val === (string)$ov ? ' selected' : '') . '>' . e($ot) . '</option>';
        }
        echo '</select>';
    } elseif (isset($f['type']) && $f['type'] === 'textarea') {
        echo '<textarea class="input" id="itg_' . e($key) . '" rows="3" placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '"' . $mono . '>' . e($val) . '</textarea>';
    } else {
        $inputType = $isPort ? 'number' : 'text';
        echo '<input class="input" id="itg_' . e($key) . '" type="' . $inputType . '" value="' . e($val) . '"' . $mono .
            ' placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '">';
    }
    if (!empty($f['hint'])) echo '<div class="fs-12 text-muted mt-4">' . e($f['hint']) . '</div>';
    echo '</div>';
}
?>

<script>
var ITG_GROUPS = <?php echo json_encode(array_map(function ($g) {
    return array('id' => $g['id'], 'title' => $g['title'], 'keys' => array_map(function ($f) { return $f['key']; }, $g['fields']));
}, $groups), JSON_UNESCAPED_UNICODE); ?>;

/* ---------- Tab 切换 ---------- */
function itgTab(id) {
    document.querySelectorAll('#itgTabs .itg-tab').forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('active', on);
        t.classList.toggle('btn-primary', on);
        t.classList.toggle('btn-outline', !on);
    });
    document.querySelectorAll('.itg-pane').forEach(function (p) {
        p.style.display = (p.getAttribute('data-tab') === id) ? '' : 'none';
    });
    if (id === 'his') itgMonLoad(1);
}

/* ---------- 联动显隐（PACS 协议模式等） ---------- */
function itgSyncShowIf() {
    document.querySelectorAll('[data-show-if]').forEach(function (row) {
        var k = row.getAttribute('data-show-if');
        var v = row.getAttribute('data-show-val');
        var el = document.getElementById('itg_' + k);
        row.style.display = (el && el.value === v) ? '' : 'none';
    });
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.id && e.target.id.indexOf('itg_') === 0) itgSyncShowIf();
});
itgSyncShowIf();

/* ---------- 复制 ---------- */
function itgCopy(t) {
    var done = function () { Clinic.toast.success('已复制到剪贴板'); };
    var fallback = function () {
        // 兼容非安全上下文 / 剪贴板 API 被拒绝：textarea + execCommand 兜底
        var ta = document.createElement('textarea');
        ta.value = t;
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            Clinic.toast.warning('复制失败，请手动选择复制');
        }
        ta.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(done).catch(fallback);
    } else {
        fallback();
    }
}

/* ---------- 分组保存（仅提交该组字段） ---------- */
function itgSave(groupId) {
    var group = null;
    ITG_GROUPS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    var data = { action: 'integration_save', group: groupId };
    group.keys.forEach(function (k) {
        var el = document.getElementById('itg_' + k);
        if (el) data[k] = el.value.trim();
    });
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            if (groupId === 'his') { renderHisTokenLive(); }
        },
    });
}

/* ---------- HIS 入向 Token 生成 / 连通性测试 ---------- */
function genHisToken() {
    var arr = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    var key = Array.prototype.map.call(arr, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var el = document.getElementById('itg_integration.inbound.his.token');
    if (el) el.value = key;
    renderHisTokenLive();
    Clinic.toast.success('已生成 Token，请点击【保存本组配置】生效');
}
function renderHisTokenLive() {
    var el = document.getElementById('itg_integration.inbound.his.token');
    var key = el ? (el.value || '').trim() : '';
    document.querySelectorAll('#itgPane_his .itg-ep-url').forEach(function (c) {
        if (c.getAttribute('data-no-token')) return;
        var base = c.textContent;
        if (base.indexOf('?') >= 0) c.textContent = key ? base + '&token=' + key : base;
    });
}
function testHisApi() {
    var el = document.getElementById('itg_integration.inbound.his.token');
    var key = el ? (el.value || '').trim() : '';
    var box = document.getElementById('hisTestBox');
    if (!box) return;
    if (key === '') { Clinic.toast.warning('请先填写或生成入向 Token 并保存本组配置'); return; }
    box.style.display = '';
    box.innerHTML = '<div class="fs-12 text-muted" style="padding:10px 2px">测试中，请稍候…</div>';
    var base = location.protocol + '//' + location.host + '/api/external/his/read?action=ping';
    var render = function (label, url, init) {
        fetch(url, init).then(function (r) { return r.json(); }).then(function (j) {
            var ok = !!(j && j.ok && j.data && j.data.pong);
            box.innerHTML += '<div class="itg-his-titem">' +
                '<div class="itg-his-tlabel"><span class="badge ' + (ok ? 'badge-success' : 'badge-danger') + '">' + (ok ? '通过' : '失败') + '</span> ' + label + '</div>' +
                '<code class="itg-his-tcode">' + Clinic.escHtml(JSON.stringify(j, null, 2)) + '</code></div>';
        }).catch(function () {
            box.innerHTML += '<div class="itg-his-titem"><div class="itg-his-tlabel"><span class="badge badge-danger">请求失败</span> ' + label + '</div></div>';
        });
    };
    render('请求头 X-HIS-Token', base, { headers: { 'X-HIS-Token': key } });
    render('GET 参数 token', base + '&token=' + encodeURIComponent(key), {});
}

/* ---------- HIS 同步与对账监控 ---------- */
var itgMonPage = 1;
function itgMonStatsBadge(s) {
    if (s === 'success') return '<span class="badge badge-success">成功</span>';
    if (s === 'failed') return '<span class="badge badge-danger">失败</span>';
    return '<span class="badge badge-warning">待处理</span>';
}
function itgMonLoad(page) {
    itgMonPage = page || 1;
    var status = document.getElementById('itgMonStatus').value;
    var kw = document.getElementById('itgMonKw').value.trim();
    Clinic.ajax('/api/admin', { action: 'integration_outbox_list', status: status, kw: kw, page: itgMonPage, page_size: 20 }, {
        onSuccess: function (json) {
            var d = json.data;
            var s = d.stats || {};
            document.getElementById('itgMonStats').innerHTML =
                '<span class="badge badge-warning">待处理 ' + (s.pending || 0) + '</span> ' +
                '<span class="badge badge-success">成功 ' + (s.success || 0) + '</span> ' +
                '<span class="badge badge-danger">失败 ' + (s.failed || 0) + '</span>';
            var body = document.getElementById('itgMonBody');
            if (!d.list.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted fs-12" style="padding:20px">暂无同步任务</td></tr>';
            } else {
                body.innerHTML = d.list.map(function (t) {
                    return '<tr>' +
                        '<td class="fs-12">' + t.id + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(t.business_type) + '</td>' +
                        '<td class="fs-12">' + t.business_id + '</td>' +
                        '<td>' + itgMonStatsBadge(t.status) + '</td>' +
                        '<td class="fs-12">' + t.retry_count + '</td>' +
                        '<td class="fs-12"><div class="itg-mon-err">' + (t.last_error ? Clinic.escHtml(t.last_error) : Clinic.escHtml(t.payload)) + '</div></td>' +
                        '<td class="fs-12">' + Clinic.escHtml(t.updated_at) + '</td>' +
                        '<td>' + (t.status !== 'success'
                            ? '<button class="btn btn-outline btn-sm" onclick="itgMonRetry(' + t.id + ')">重试</button>'
                            : '') + '</td>' +
                        '</tr>';
                }).join('');
            }
            document.getElementById('itgMonMore').innerHTML = d.has_more
                ? '<button class="btn btn-outline btn-sm" onclick="itgMonLoad(' + (itgMonPage + 1) + ')">加载更多</button>'
                : (d.total > 20 ? '<span class="fs-12 text-muted">共 ' + d.total + ' 条</span>' : '');
        },
    });
}
function itgMonRetry(id) {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_retry', id: id }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}
function itgMonRetryAll() {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_retry', id: 0 }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}
function itgMonRun() {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_run' }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            setTimeout(function () { itgMonLoad(1); }, 1500);
        },
    });
}
function itgMonClear() {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_clear' }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}

/* ---------- 入向调用审计 ---------- */
var itgInboundPage = 1;
function itgInboundLoad(page) {
    itgInboundPage = page || 1;
    var kw = document.getElementById('itgInboundKw').value.trim();
    var fail = document.getElementById('itgInboundFail').checked ? 1 : 0;
    Clinic.ajax('/api/admin', { action: 'integration_inbound_list', kw: kw, fail: fail, page: itgInboundPage, page_size: 20 }, {
        onSuccess: function (json) {
            var d = json.data;
            var body = document.getElementById('itgInboundBody');
            if (!d.list.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-center text-muted fs-12" style="padding:20px">暂无入向调用记录</td></tr>';
            } else {
                body.innerHTML = d.list.map(function (r) {
                    return '<tr>' +
                        '<td class="fs-12">' + r.id + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.endpoint) + '/' + Clinic.escHtml(r.provider) + '</td>' +
                        '<td>' + (r.ok ? '<span class="badge badge-success">成功</span>' : '<span class="badge badge-danger">失败</span>') + '</td>' +
                        '<td class="fs-12"><div class="itg-mon-err" title="' + Clinic.escHtml(r.body || '') + '">' + Clinic.escHtml(r.summary) + '</div></td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.remote_ip) + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.created_at) + '</td>' +
                        '</tr>';
                }).join('');
            }
            document.getElementById('itgInboundMore').innerHTML = d.has_more
                ? '<button class="btn btn-outline btn-sm" onclick="itgInboundLoad(' + (itgInboundPage + 1) + ')">加载更多</button>'
                : (d.total > 20 ? '<span class="fs-12 text-muted">共 ' + d.total + ' 条</span>' : '');
        },
    });
}
</script>