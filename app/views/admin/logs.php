<?php
/**
 * ============================================================
 * admin/logs.php — 日志中心
 * ============================================================
 * 说明：管理员统一日志浏览与运维面板，子 Tab 区分三类日志：
 *  1. 服务器日志 server   —— 直读 PHP 日志文件（应用日志 + 外部配置路径）
 *  2. 操作日志 operation —— 登录日志 / 账号变更（左右分栏，左分类右记录）
 *  3. 接口日志 interface —— FHIR/DICOM/HL7/LIS/HIS/医保支付/存证签名
 * 右侧为现代日志浏览器：受控高度、默认定位最新、上滑加载更旧、
 * 支持实时刷新与一键清空；右上角「日志管理」配置开关/行数/级别/保留天数。
 * ============================================================ */
Router::title('日志中心');

$opCats = LogService::operationCategories();
$ifCats = LogService::interfaceCategories();
// 左侧分类首项统一为「全部」（category 传空即不过滤）
$opCats = array('' => array('title' => '全部')) + $opCats;
$ifCats = array('' => '全部') + $ifCats;
$sources = LogService::serverSources();
$levels = array('' => '全部日志', 'normal' => '正常', 'info' => '提示', 'warning' => '警告', 'error' => '错误');
// 各通道开关状态（总开关关闭则全部为关）：用于子 Tab 状态圆点
$logCh = array(
    'server'    => LogService::channelEnabled('server'),
    'operation' => LogService::channelEnabled('operation'),
    'interface' => LogService::channelEnabled('interface'),
);
$logDot = function ($key) use ($logCh) {
    $on = !empty($logCh[$key]);
    return '<span class="log-tab-dot' . ($on ? ' on' : '') . '" data-logdot="' . e($key) . '" title="' . ($on ? '已开启' : '已关闭') . '"></span>';
};
?>
<div class="list-layout log-layout">
<div class="page-head">
    <div><div class="page-title"><?= render_icon('emr:scroll') ?> 日志中心</div>
    <div class="page-desc">统一查看服务器日志、操作日志与接口日志，支持实时刷新、滚动加载与一键清空</div></div>
    <div class="flex gap-8">
        <button type="button" class="btn btn-outline btn-sm" onclick="LogCenter.openSettings()"><?= render_icon('nav:settings') ?> 日志管理</button>
    </div>
</div>

<div class="card" style="padding-bottom:6px;margin-bottom:0">
    <div class="itg-tabs" id="logTabs">
        <button type="button" class="btn btn-sm btn-primary log-tab" data-tab="server" onclick="LogCenter.tab('server')"><?= $logDot('server') ?> 服务器日志</button>
        <button type="button" class="btn btn-sm btn-outline log-tab" data-tab="operation" onclick="LogCenter.tab('operation')"><?= $logDot('operation') ?> 操作日志</button>
        <button type="button" class="btn btn-sm btn-outline log-tab" data-tab="interface" onclick="LogCenter.tab('interface')"><?= $logDot('interface') ?> 接口日志</button>
    </div>
    <div class="fs-12 text-muted" id="logTabDesc" style="padding:10px 14px 12px"></div>
</div>

<?php
/* 左右分栏面板通用片段：左侧分类项由 $navItems 渲染，右侧工具栏 + 日志视窗 */
$renderNav = function ($items) {
    $h = '';
    foreach ($items as $key => $it) {
        $title = is_array($it) ? (isset($it['title']) ? $it['title'] : $key) : $it;
        $count = (is_array($it) && !empty($it['count'])) ? (int)$it['count'] : '';
        $h .= '<div class="db-nav log-nav-item" data-cat="' . e($key) . '" onclick="LogCenter.selectCat(this)">' .
            '<span>' . e($title) . '</span>' .
            '<span class="log-nav-count" data-cat-count="' . e($key) . '">' . ($count !== '' ? e($count) : '') . '</span></div>';
    }
    return $h;
};
$renderToolbar = function ($pane, $withDirection) use ($levels) {
    $h = '<div class="log-toolbar">';
    if ($withDirection) {
        $h .= '<select class="select" id="logDir_' . $pane . '" data-csd-keepempty="1" onchange="LogCenter.onFilter(\'' . $pane . '\')">' .
            '<option value="">全部方向</option><option value="inbound">入向</option><option value="outbound">出向</option></select>';
    }
    $h .= '<select class="select" id="logLevel_' . $pane . '" data-csd-keepempty="1" onchange="LogCenter.onFilter(\'' . $pane . '\')">';
    foreach ($levels as $k => $v) $h .= '<option value="' . e($k) . '">' . e($v) . '</option>';
    $h .= '</select>';
    $h .= '<input class="input log-kw" id="logKw_' . $pane . '" placeholder="搜索日志" autocomplete="off" onkeydown="if(event.key===\'Enter\')LogCenter.onFilter(\'' . $pane . '\')">';
    $h .= '<input class="input input-date log-date" id="logDate_' . $pane . '" readonly placeholder="选择日期" onclick="Clinic.datePicker.open(this,{maxToday:false,onChange:function(){LogCenter.onFilter(\'' . $pane . '\')}})">';
    $h .= '<label class="log-live"><input type="checkbox" id="logLive_' . $pane . '" checked onchange="LogCenter.toggleLive(\'' . $pane . '\')"> 实时</label>';
    $h .= '<span class="log-status" id="logStatus_' . $pane . '"></span>';
    $h .= '<button type="button" class="btn btn-outline btn-sm" onclick="LogCenter.clearPane(\'' . $pane . '\')">' . render_icon('action:clean') . ' 清空</button>';
    $h .= '</div>';
    return $h;
};
?>

<!-- ==================== 服务器日志 ==================== -->
<?php
$serverItems = array();
foreach ($sources as $s) $serverItems[$s['id']] = array('title' => $s['title'], 'count' => isset($s['lines']) ? $s['lines'] : 0);
?>
<div class="log-pane" id="logPane_server" data-pane="server">
    <div class="db-center log-center">
        <div class="card db-sidebar log-nav" id="logNav_server"><?= $renderNav($serverItems) ?></div>
        <div class="log-main">
            <?= $renderToolbar('server', false) ?>
            <div class="log-view" id="logView_server"><div class="empty"><div class="spinner"></div></div></div>
        </div>
    </div>
</div>

<!-- ==================== 操作日志 ==================== -->
<div class="log-pane" id="logPane_operation" data-pane="operation" style="display:none">
    <div class="db-center log-center">
        <div class="card db-sidebar log-nav" id="logNav_operation"><?= $renderNav($opCats) ?></div>
        <div class="log-main">
            <?= $renderToolbar('operation', false) ?>
            <div class="log-view" id="logView_operation"><div class="empty"><div class="spinner"></div></div></div>
        </div>
    </div>
</div>

<!-- ==================== 接口日志 ==================== -->
<div class="log-pane" id="logPane_interface" data-pane="interface" style="display:none">
    <div class="db-center log-center">
        <div class="card db-sidebar log-nav" id="logNav_interface"><?= $renderNav($ifCats) ?></div>
        <div class="log-main">
            <?= $renderToolbar('interface', true) ?>
            <div class="log-view" id="logView_interface"><div class="empty"><div class="spinner"></div></div></div>
        </div>
    </div>
</div>
</div><!-- /.list-layout -->

<script>
/* ============================================================
 * 日志中心前端控制器
 * ------------------------------------------------------------
 * 设计：单一全局单例 window.LogCenter，页面视图内联执行；
 *  - SPA 局部导航重复进入时以 reinit 覆盖并清理旧定时器
 *  - 服务器日志走文件尾部游标（offset），系统日志走 id 游标（before_id）
 *  - 默认加载最新并定位到底部，上滑加载更旧，实时轮询追加最新
 * ============================================================ */
window.LogCenter = (function () {
    var PAGE_SIZE = 60;
    var POLL_MS = 3000;

    var LEVELS = { normal: '正常', info: '提示', warning: '警告', error: '错误' };

    /* 子 Tab 介绍文字 */
    var TAB_DESC = {
        server: '直接读取应用日志（data/logs/app.log）与管理员配置的外部 PHP / Web 服务器日志文件，含运行时错误、异常与告警。',
        operation: '记录用户登录、退出以及账号变更（修改密码、重置密码、个人资料、账号新增修改删除、解除锁定等）操作。',
        interface: '记录本系统与外部系统（FHIR / DICOM / HL7 / LIS / HIS / 医保支付 / 存证签名）的入向与出向接口调用及报文。'
    };

    /* 每个面板的运行时状态 */
    function newState(kind) {
        return {
            kind: kind,                 // server / db
            offset: 0,                  // 服务器：已加载行数
            beforeId: 0,                // 系统：最旧已加载 id（加载更旧游标）
            afterId: 0,                 // 系统：最新已加载 id（实时游标）
            lastRaw: '',                // 服务器：最新一行原文（实时去重）
            entries: [],                // {id/raw,...} 已渲染顺序（旧→新）
            hasMore: false,
            loading: false,
            live: true,
            category: '',
            level: '',
            direction: '',
            date: '',
            kw: '',
            inited: false
        };
    }

    var S = {
        tab: 'server',
        server: newState('server'),
        operation: newState('db'),
        interface: newState('db'),
        meta: null
    };

    /* ---------- 通用工具 ---------- */
    function el(id) { return document.getElementById(id); }
    function paneEl(pane) { return el('logPane_' + pane); }
    function viewEl(pane) { return el('logView_' + pane); }

    function esc(s) { return Clinic.escHtml(s == null ? '' : s); }

    function levelBadge(level) {
        var cls = { error: 'danger', warning: 'warning', info: 'info', normal: 'success' }[level] || 'info';
        var name = LEVELS[level] || level || '正常';
        return '<span class="badge badge-' + cls + ' log-lv">' + esc(name) + '</span>';
    }

    function nearBottom(v) {
        return v.scrollHeight - v.scrollTop - v.clientHeight < 60;
    }

    /* ---------- 标签切换 ---------- */
    function tab(name) {
        S.tab = name;
        document.querySelectorAll('#logTabs .log-tab').forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('active', on);
            t.classList.toggle('btn-primary', on);
            t.classList.toggle('btn-outline', !on);
        });
        document.querySelectorAll('.log-pane').forEach(function (p) {
            p.style.display = (p.getAttribute('data-pane') === name) ? '' : 'none';
        });
        var desc = document.getElementById('logTabDesc');
        if (desc) desc.textContent = TAB_DESC[name] || '';
        if (!S[name].inited) initPane(name);
        startPolling();
    }

    /* ---------- 左侧分类选择 ---------- */
    function selectCat(node) {
        var pane = node.closest('.log-pane');
        if (!pane) return;
        var name = pane.getAttribute('data-pane');
        pane.querySelectorAll('.log-nav-item').forEach(function (n) { n.classList.remove('active'); });
        node.classList.add('active');
        S[name].category = node.getAttribute('data-cat') || '';
        resetPane(name);
    }

    /* ---------- 初始化面板（默认选中左侧第一项） ---------- */
    function initPane(name) {
        var nav = el('logNav_' + name);
        if (nav) {
            var first = nav.querySelector('.log-nav-item');
            if (first && !nav.querySelector('.log-nav-item.active')) {
                first.classList.add('active');
                S[name].category = first.getAttribute('data-cat') || '';
            }
        }
        S[name].inited = true;
        loadNewest(name);
        // 滚动到顶部加载更旧（仅绑定一次，防 SPA 重复进入叠加）
        var v = viewEl(name);
        if (v && !v.__logScrollBound) {
            v.__logScrollBound = true;
            v.addEventListener('scroll', function () {
                if (v.scrollTop <= 6) loadOlder(name);
            });
        }
    }

    /* ---------- 重置并加载最新 ---------- */
    function resetPane(name) {
        var st = S[name];
        st.entries = [];
        st.offset = 0;
        st.beforeId = 0;
        st.afterId = 0;
        st.lastRaw = '';
        st.hasMore = false;
        var v = viewEl(name);
        if (v) v.innerHTML = '<div class="empty"><div class="spinner"></div></div>';
        loadNewest(name);
    }

    function readFilters(name) {
        var st = S[name];
        var lv = el('logLevel_' + name);
        var kw = el('logKw_' + name);
        var dir = el('logDir_' + name);
        var dt = el('logDate_' + name);
        st.level = lv ? lv.value : '';
        st.kw = kw ? kw.value.trim() : '';
        st.direction = dir ? dir.value : '';
        st.date = dt ? dt.value.trim() : '';
    }

    function onFilter(name) {
        readFilters(name);
        resetPane(name);
    }

    /* ---------- 加载最新一屏 ---------- */
    function loadNewest(name) {
        var st = S[name];
        if (st.loading) return;
        st.loading = true;
        if (st.kind === 'server') {
            Clinic.get('/api/admin', {
                action: 'log_server_list', source: st.category || 'app',
                offset: 0, limit: PAGE_SIZE, level: st.level, kw: st.kw, date: st.date
            }, {
                silent: true,
                onSuccess: function (json) {
                    st.loading = false;
                    var d = json.data;
                    setServerStatus(name, d);
                    applyServerCounts(d.counts);
                    if (d.disabled) {
                        var v = viewEl(name);
                        if (v) v.innerHTML = '<div class="empty"><div class="empty-ico">' + renderIconSvg('alert:info') + '</div>服务器日志已在日志管理中关闭</div>';
                        return;
                    }
                    st.entries = d.list || [];
                    st.offset = st.entries.length;
                    st.hasMore = d.has_more;
                    render(name, false);
                    if (st.entries.length) { st.lastRaw = st.entries[st.entries.length - 1].raw || ''; }
                    scrollBottom(name);
                },
                onError: function () { st.loading = false; }
            });
            return;
        }
        Clinic.get('/api/admin', {
            action: 'log_list', channel: name,
            category: st.category, direction: st.direction,
            level: st.level, kw: st.kw, date: st.date, limit: PAGE_SIZE
        }, {
            silent: true,
            onSuccess: function (json) {
                st.loading = false;
                var d = json.data;
                applyCounts(name, d.counts);
                st.entries = (d.list || []).slice().reverse();   // 旧→新
                st.hasMore = d.has_more;
                if (st.entries.length) {
                    st.beforeId = st.entries[0].id;
                    st.afterId = st.entries[st.entries.length - 1].id;
                }
                render(name, false);
                scrollBottom(name);
            },
            onError: function () { st.loading = false; }
        });
    }

    /* ---------- 加载更旧一屏（顶部） ---------- */
    function loadOlder(name) {
        var st = S[name];
        if (st.loading || !st.hasMore) return;
        st.loading = true;
        var v = viewEl(name);
        var prevH = v ? v.scrollHeight : 0;
        if (st.kind === 'server') {
            Clinic.get('/api/admin', {
                action: 'log_server_list', source: st.category || 'app',
                offset: st.offset, limit: PAGE_SIZE, level: st.level, kw: st.kw, date: st.date
            }, {
                silent: true,
                onSuccess: function (json) {
                    st.loading = false;
                    var d = json.data;
                    var older = d.list || [];
                    st.entries = older.concat(st.entries);
                    st.offset += older.length;
                    st.hasMore = d.has_more;
                    render(name, false);
                    if (v) v.scrollTop = v.scrollHeight - prevH;
                },
                onError: function () { st.loading = false; }
            });
            return;
        }
        if (!st.beforeId) { st.hasMore = false; st.loading = false; return; }
        Clinic.get('/api/admin', {
            action: 'log_list', channel: name,
            category: st.category, direction: st.direction,
            level: st.level, kw: st.kw, date: st.date, before_id: st.beforeId, limit: PAGE_SIZE
        }, {
            silent: true,
            onSuccess: function (json) {
                st.loading = false;
                var d = json.data;
                applyCounts(name, d.counts);
                var older = (d.list || []).slice().reverse();
                if (older.length) st.beforeId = older[0].id;
                st.entries = older.concat(st.entries);
                st.hasMore = d.has_more;
                render(name, false);
                if (v) v.scrollTop = v.scrollHeight - prevH;
            },
            onError: function () { st.loading = false; }
        });
    }

    /* ---------- 实时轮询（仅当前 Tab） ---------- */
    function poll() {
        var name = S.tab;
        var st = S[name];
        if (!st || !st.inited || !st.live || st.loading) return;
        if (!paneEl(name) || paneEl(name).style.display === 'none') return;
        if (st.kind === 'server') {
            Clinic.get('/api/admin', {
                action: 'log_server_list', source: st.category || 'app',
                offset: 0, limit: 50, level: st.level, kw: st.kw, date: st.date
            }, {
                silent: true,
                onSuccess: function (json) {
                    var d = json.data;
                    applyServerCounts(d.counts);
                    var list = d.list || [];
                    if (!list.length) return;
                    var lastRaw = st.lastRaw;
                    var idx = -1;
                    for (var i = list.length - 1; i >= 0; i--) {
                        if ((list[i].raw || '') === lastRaw) { idx = i; break; }
                    }
                    var fresh = (lastRaw === '') ? list : list.slice(idx + 1);
                    if (!fresh.length) return;
                    st.lastRaw = fresh[fresh.length - 1].raw || lastRaw;
                    st.entries = st.entries.concat(fresh);
                    st.offset += fresh.length;
                    var v = viewEl(name);
                    var stick = v && nearBottom(v);
                    appendEntries(name, fresh);
                    if (stick) scrollBottom(name);
                }
            });
            return;
        }
        Clinic.get('/api/admin', {
            action: 'log_latest', channel: name, after_id: st.afterId,
            category: st.category, direction: st.direction,
            level: st.level, kw: st.kw, date: st.date, limit: 100
        }, {
            silent: true,
            onSuccess: function (json) {
                var d = json.data || {};
                // 实时刷新左侧分类计数（即使本次无新日志，计数也可能变化）
                applyCounts(name, d.counts);
                var list = d.list || [];
                if (!list.length) return;
                st.afterId = list[list.length - 1].id;
                st.entries = st.entries.concat(list);
                var v = viewEl(name);
                var stick = v && nearBottom(v);
                appendEntries(name, list);
                if (stick) scrollBottom(name);
            }
        });
    }

    /* 轮询定时器挂到 window：SPA 重复进入时可在 reinit 清理旧实例 */
    function startPolling() {
        if (window.__logPollTimer) return;
        window.__logPollTimer = setInterval(function () {
            if (!document.getElementById('logPane_server')) {
                clearInterval(window.__logPollTimer);
                window.__logPollTimer = null;
                return;
            }
            poll();
        }, POLL_MS);
    }

    function toggleLive(name) {
        var c = el('logLive_' + name);
        S[name].live = c ? c.checked : true;
    }

    /* ---------- 渲染 ---------- */
    function entryHtml(e, kind) {
        if (kind === 'server') {
            return '<div class="log-item log-lv-' + esc(e.level) + '">' +
                '<div class="log-item-head">' +
                    levelBadge(e.level) +
                    (e.time ? '<span class="log-time">' + esc(e.time) + '</span>' : '') +
                '</div>' +
                '<div class="log-text">' + esc(e.text) + '</div>' +
                '</div>';
        }
        var meta = [];
        if (e.username) meta.push('<span class="log-meta-k">操作人</span>' + esc(e.username));
        if (e.remote_ip) meta.push('<span class="log-meta-k">来源</span>' + esc(e.remote_ip));
        if (e.direction === 'outbound' && e.target) meta.push('<span class="log-meta-k">目标</span>' + esc(e.target));
        var metaHtml = meta.length ? '<div class="log-meta">' + meta.join(' · ') + '</div>' : '';
        var dirName = e.direction === 'inbound' ? '入向' : (e.direction === 'outbound' ? '出向' : '');
        var h = '<div class="log-item log-lv-' + esc(e.level) + '">' +
            '<div class="log-item-head">' +
                levelBadge(e.level) +
                (dirName ? '<span class="badge badge-outline">' + dirName + '</span>' : '') +
                '<span class="log-time">' + esc(e.created_at) + '</span>' +
                (e.action ? '<span class="log-action">' + esc(e.action) + '</span>' : '') +
            '</div>';
        // 除摘要外的明细与操作人/IP 同行：明细左、来源信息右对齐，节省纵向空间
        if (e.detail) {
            h += '<div class="log-summary">' + esc(e.summary) + '</div>' +
                '<div class="log-line"><div class="log-line-main">' + esc(e.detail) + '</div>' + metaHtml + '</div>';
        } else {
            h += '<div class="log-line"><div class="log-line-main log-summary">' + esc(e.summary) + '</div>' + metaHtml + '</div>';
        }
        h += (e.payload ? '<details class="log-payload-wrap"><summary>查看报文</summary><pre class="log-payload">' + esc(e.payload) + '</pre></details>' : '') +
            '</div>';
        return h;
    }

    function render(name, onlyNew) {
        var v = viewEl(name);
        if (!v) return;
        var st = S[name];
        if (!st.entries.length) {
            v.innerHTML = '<div class="empty"><div class="empty-ico">' + renderIconSvg('emr:scroll') + '</div>暂无日志</div>';
            return;
        }
        var html = '';
        var more = st.hasMore ? '<div class="log-more" id="logMore_' + name + '">上滑加载更旧日志</div>' : '<div class="log-more">已加载全部</div>';
        for (var i = 0; i < st.entries.length; i++) html += entryHtml(st.entries[i], st.kind);
        v.innerHTML = more + html;
    }

    function appendEntries(name, list) {
        var v = viewEl(name);
        if (!v) return;
        var st = S[name];
        if (!st.entries.length || v.querySelector('.empty')) { render(name, false); return; }
        var frag = document.createElement('div');
        for (var i = 0; i < list.length; i++) frag.innerHTML += entryHtml(list[i], st.kind);
        // 追加到视窗末尾（保留顶部加载提示）
        while (frag.firstChild) v.appendChild(frag.firstChild);
    }

    function setServerStatus(name, d) {
        var s = el('logStatus_' + name);
        if (!s) return;
        s.textContent = '';
        if (d.disabled) { s.textContent = '已关闭'; return; }
        if (!d.exists) { s.textContent = '日志文件不存在'; return; }
    }

    function applyCounts(name, counts) {
        var nav = el('logNav_' + name);
        if (!nav) return;
        counts = counts || {};
        // 以导航内全部分类计数节点为全集：响应中缺失的类目视为 0。
        // 清空某通道后 counts 为空对象，若只遍历 counts 键，则仅“全部”刷新、
        // 其余分类残留旧值——故此处遍历节点、缺失归零。
        var sum = 0;
        nav.querySelectorAll('[data-cat-count]').forEach(function (n) {
            var cat = n.getAttribute('data-cat-count');
            if (cat === '') return;                 // “全部”在下方按合计统一刷新
            var c = counts[cat] ? counts[cat] : 0;
            sum += c;
            n.textContent = c > 0 ? c : '';
        });
        var all = nav.querySelector('[data-cat-count=""]');
        if (all) all.textContent = sum > 0 ? sum : '';
    }

    /* 服务器日志左侧计数：按来源行数实时刷新（id => 行数） */
    function applyServerCounts(counts) {
        if (!counts) return;
        var nav = el('logNav_server');
        if (!nav) return;
        Object.keys(counts).forEach(function (id) {
            var n = nav.querySelector('[data-cat-count="' + id + '"]');
            if (n) n.textContent = counts[id] > 0 ? counts[id] : '';
        });
    }

    function scrollBottom(name) {
        var v = viewEl(name);
        if (v) v.scrollTop = v.scrollHeight;
    }

    /* ---------- 清空 ---------- */
    function clearPane(name) {
        var st = S[name];
        var title = st.kind === 'server' ? '确认清空该服务器日志文件？' : '确认清空当前分类的全部日志？';
        Clinic.modal.confirm(title + '此操作不可恢复。', function () {
            var data = { action: 'log_clear' };
            if (st.kind === 'server') {
                data.source = st.category || 'app';
            } else {
                data.channel = name;
                data.category = st.category || '';
            }
            Clinic.ajax('/api/admin', data, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg || '已清空');
                    resetPane(name);
                }
            });
        });
    }

    /* ---------- 日志管理（右上角） ---------- */
    function openSettings() {
        Clinic.get('/api/admin', { action: 'log_settings_get' }, {
            onSuccess: function (json) {
                var d = json.data || {};
                var on = (d['log.enabled'] === '1');
                var cb = function (name, v) {
                    return '<label class="log-switch"><input type="checkbox" id="' + name + '"' + (v === '1' ? ' checked' : '') + '> </label>';
                };
                var html =
                    // 总开关：胶囊开关，独立置顶
                    '<div class="log-master">' +
                        '<span class="log-master-label">' + renderIconSvg('emr:scroll') + ' 启用日志记录</span>' +
                        '<label class="log-pill"><input type="checkbox" id="ls_enabled"' + (on ? ' checked' : '') +
                            ' onchange="document.getElementById(\'logSetBody\').classList.toggle(\'disabled\', !this.checked)"><span class="log-pill-track"></span></label>' +
                        '<span class="log-set-hint" style="margin:0 0 0 auto">总开关：关闭后停止记录日志并锁定以下全部设置。</span>' +
                    '</div>' +
                    '<div class="log-set-body' + (on ? '' : ' disabled') + '" id="logSetBody">' +
                        '<div class="log-set-grid">' +
                            '<div class="log-set-card"><div class="log-set-title">日志通道</div>' +
                                '<div class="log-set-row"><span>服务器日志</span>' + cb('ls_channel_server', d['log.channel.server']) + '</div>' +
                                '<div class="log-set-row"><span>操作日志</span>' + cb('ls_channel_operation', d['log.channel.operation']) + '</div>' +
                                '<div class="log-set-row"><span>接口日志</span>' + cb('ls_channel_interface', d['log.channel.interface']) + '</div>' +
                                '<div class="log-set-hint">分别控制服务器日志是否可读、操作日志与接口日志是否记录。</div>' +
                            '</div>' +
                            '<div class="log-set-card"><div class="log-set-title">记录级别</div>' +
                                '<div class="log-set-row"><span>正常</span>' + cb('ls_level_normal', d['log.level.normal']) + '</div>' +
                                '<div class="log-set-row"><span>提示</span>' + cb('ls_level_info', d['log.level.info']) + '</div>' +
                                '<div class="log-set-row"><span>警告</span>' + cb('ls_level_warning', d['log.level.warning']) + '</div>' +
                                '<div class="log-set-row"><span>错误</span>' + cb('ls_level_error', d['log.level.error']) + '</div>' +
                                '<div class="log-set-hint">未勾选的级别不会被记录（如取消「正常」后，正常级别的日志不再写入）。服务器日志由 PHP 直接写文件，不受此处影响。</div>' +
                            '</div>' +
                            '<div class="log-set-card"><div class="log-set-title">容量与保留</div>' +
                                '<div class="log-set-row"><span>日志行数上限</span><input class="input" id="ls_max_rows" type="number" min="100" value="' + esc(d['log.max_rows']) + '"></div>' +
                                '<div class="log-set-row"><span>日志记录天数</span><input class="input" id="ls_retention" type="number" min="0" value="' + esc(d['log.retention_days']) + '"></div>' +
                                '<div class="log-set-row"><span>检索默认天数</span><input class="input" id="ls_query_days" type="number" min="0" max="365" value="' + esc(d['log.query.default_days']) + '"></div>' +
                                '<div class="log-set-hint">超过行数上限自动清理最旧记录；超过记录天数自动清空（0 = 不限制）。检索默认天数：未选日期时仅查最近 N 天（0 = 不限），用于强制走时间索引收敛。</div>' +
                            '</div>' +
                            '<div class="log-set-card"><div class="log-set-title">服务器日志</div>' +
                                '<div class="log-set-row"><span>读取上限 (KB)</span><input class="input" id="ls_max_kb" type="number" min="64" value="' + esc(d['log.server.max_kb']) + '"></div>' +
                                '<div class="log-set-row log-set-col"><span>外部服务器日志路径</span><input class="input" id="ls_ext_path" value="' + esc(d['log.server.external_path']) + '" placeholder="如 /var/log/php_errors.log"></div>' +
                                '<div class="log-set-hint">留空则仅显示应用日志（data/logs/app.log）。</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="log-set-overlay"><div class="log-set-overlay-msg">已禁用日志记录</div></div>' +
                    '</div>';
                Clinic.modal.open(html, {
                    title: '日志管理',
                    size: 'modal-lg',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        { text: '保存', cls: 'btn-primary', onClick: saveSettings }
                    ]
                });
            }
        });
    }

    /** 更新子 Tab 状态圆点 */
    function applyTabDots(ch) {
        document.querySelectorAll('#logTabs [data-logdot]').forEach(function (d) {
            var on = !!ch[d.getAttribute('data-logdot')];
            d.classList.toggle('on', on);
            d.title = on ? '已开启' : '已关闭';
        });
    }

    function chk(id) { var e = el(id); return e && e.checked ? '1' : '0'; }
    function val(id) { var e = el(id); return e ? e.value : ''; }

    function saveSettings() {
        Clinic.ajax('/api/admin', {
            action: 'log_settings_save',
            enabled: chk('ls_enabled'),
            channel_operation: chk('ls_channel_operation'),
            channel_interface: chk('ls_channel_interface'),
            channel_server: chk('ls_channel_server'),
            level_normal: chk('ls_level_normal'),
            level_info: chk('ls_level_info'),
            level_warning: chk('ls_level_warning'),
            level_error: chk('ls_level_error'),
            max_rows: val('ls_max_rows'),
            retention_days: val('ls_retention'),
            query_default_days: val('ls_query_days'),
            server_max_kb: val('ls_max_kb'),
            server_external_path: val('ls_ext_path')
        }, {
            onSuccess: function (json) {
                var master = chk('ls_enabled') === '1';
                applyTabDots({
                    server: master && chk('ls_channel_server') === '1',
                    operation: master && chk('ls_channel_operation') === '1',
                    interface: master && chk('ls_channel_interface') === '1'
                });
                Clinic.toast.success(json.msg || '已保存');
                Clinic.modal.close();
            }
        });
    }

    /* ---------- 初始化 ---------- */
    function reinit() {
        // 清理旧轮询（SPA 重复进入时）
        if (window.__logPollTimer) { clearInterval(window.__logPollTimer); window.__logPollTimer = null; }
        S.server = newState('server');
        S.operation = newState('db');
        S.interface = newState('db');
        tab('server');
    }

    return {
        reinit: reinit,
        tab: tab,
        selectCat: selectCat,
        onFilter: onFilter,
        toggleLive: toggleLive,
        clearPane: clearPane,
        openSettings: openSettings
    };
})();

/* 全页直接执行一次；SPA 局部导航由 nav.js 重执行本内联脚本后，
   DOMContentLoaded 回调会被捕获并触发 reinit（见 nav.js execScripts） */
if (document.readyState !== 'loading') LogCenter.reinit(); else document.addEventListener('DOMContentLoaded', function () { LogCenter.reinit(); });
</script>
