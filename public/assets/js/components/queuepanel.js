/**
 * queuepanel.js v1.3.0 — 病历页候诊队列面板
 * 挂载于医生病历编辑页顶部患者信息横条左侧：
 *   「📋 候诊XX」按钮 + 点击弹出近3天患者列表面板。
 * 交互规则：
 *   1. 按钮人数跟随「当日」勾选：勾选 → 当日待就诊人数；
 *      未勾选 → 已有待就诊人数（不限日期）。
 *   2. 已诊/当日 勾选偏好存登录会话（queue_pref 接口）：
 *      本次登录期间跨页面保持，退出登录自动还原不勾选。
 *   3. 搜索关键字切换勾选时保留（跨列表找同一患者），
 *      面板关闭时自动清空重置。
 * 过滤排序（多选组合，本地零请求）：
 *     · 仅「已诊」    → 近3天（含今日）诊毕患者，最后诊毕在最上；
 *     · 仅「当日」    → 当日挂号患者（全部状态）；
 *     · 两者都选      → 近3天诊毕（诊毕倒序）在上 + 当日未诊（挂号正序）在下；
 *     · 都不选        → 近3天未诊患者，最早挂号在最上、最新挂号在最下。
 * 数据源：GET /api/doctor?action=queue_list（一次返回近3天全量+候诊数）。
 */
Clinic.queuePanel = (function () {

    var DATA = null;        // queue_list 接口缓存 { waiting, list[], pref }
    var TIMER = null;       // 30 秒自动刷新
    var seen = false;       // 多选项：已诊
    var todayOnly = false;  // 多选项：当日
    var KEYWORD = '';       // 搜索关键字（仅当前筛选结果范围内过滤；面板关闭时清空）

    /* HTML 转义（组件内私有：emr.js 的 escHtml 为 IIFE 私有不可复用） */
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function todayStr() {
        var d = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    /* 拉取队列数据（force=true 强制刷新；首次加载应用登录会话中的勾选偏好） */
    function load(force, cb) {
        var applyPref = !DATA;
        if (DATA && !force) { if (cb) cb(); return; }
        Clinic.get('/api/doctor?action=queue_list', null, {
            onSuccess: function (json) {
                DATA = json.data;
                if (applyPref && DATA.pref) {
                    seen = !!DATA.pref.seen;
                    todayOnly = !!DATA.pref.today;
                }
                renderBtn();
                if (cb) cb();
            },
        });
    }

    /* 勾选偏好写入登录会话（静默后台同步，不打扰医生操作） */
    function savePref() {
        try {
            var fd = new FormData();
            fd.append('csrf_token', document.body.getAttribute('data-csrf') || '');
            fd.append('seen', seen ? 1 : 0);
            fd.append('today', todayOnly ? 1 : 0);
            fetch('/api/doctor?action=queue_pref', {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).catch(function () { /* 偏好同步失败静默：不影响本次交互 */ });
        } catch (e) { /* 忽略 */ }
    }

    /* 按钮候诊人数：勾选「当日」→ 当日待就诊（paid）人数；未勾选 → 已有待就诊人数 */
    function waitingCount() {
        if (!DATA) return 0;
        if (!todayOnly) return DATA.waiting || 0;
        var t = todayStr();
        return DATA.list.filter(function (r) {
            return r.date === t && r.status === 'paid';
        }).length;
    }

    /* 顶部按钮：候诊XX */
    function renderBtn() {
        var btn = document.getElementById('queueBtn');
        if (!btn || !DATA) return;
        btn.innerHTML = '📋 候诊 <b>' + waitingCount() + '</b>';
    }

    /* ==================== 过滤 + 排序（多选组合规则核心） ==================== */
    function filteredList() {
        if (!DATA) return [];
        var t = todayStr();
        // 诊毕时间回退：旧数据无 finish_time 用挂号时间兜底
        var finKey = function (r) { return (r.finish_date && r.finish_time) ? (r.finish_date + ' ' + r.finish_time) : r.date + ' ' + r.time; };
        var all = DATA.list;
        if (seen && !todayOnly) {
            // 仅已诊：近3天诊毕，最后诊毕在最上
            return all.filter(function (r) { return r.status === 'finished'; })
                .sort(function (a, b) { return finKey(b).localeCompare(finKey(a)); });
        }
        if (!seen && todayOnly) {
            // 仅当日：今日挂号患者（全部状态），按挂号时间倒序（最新在上）
            return all.filter(function (r) { return r.date === t; })
                .sort(function (a, b) { return (b.date + ' ' + b.time).localeCompare(a.date + ' ' + a.time); });
        }
        if (seen && todayOnly) {
            // 双选：近3天诊毕（诊毕倒序）在上 + 今日未诊（挂号正序=候诊顺序）在下
            var done = all.filter(function (r) { return r.status === 'finished'; })
                .sort(function (a, b) { return finKey(b).localeCompare(finKey(a)); });
            var todo = all.filter(function (r) { return r.status !== 'finished' && r.date === t; })
                .sort(function (a, b) { return (a.date + ' ' + a.time).localeCompare(b.date + ' ' + b.time); });
            return done.concat(todo);
        }
        // 都不选：近3天未诊，最早挂号在最上（候诊顺序）、最新挂号在最下
        return all.filter(function (r) { return r.status !== 'finished'; })
            .sort(function (a, b) { return (a.date + ' ' + a.time).localeCompare(b.date + ' ' + b.time); });
    }

    /* 状态徽章 */
    function statusBadge(st) {
        if (st === 'finished') return '<span class="badge badge-gray" style="font-size:11px">诊毕</span>';
        if (st === 'visiting') return '<span class="badge badge-warning" style="font-size:11px">就诊中</span>';
        return '<span class="badge badge-primary" style="font-size:11px">候诊</span>';
    }

    /* 单行患者条目（九列网格纵向对齐）：
       日期 时间 科室 号别 号源 姓名 性别 年龄 状态 */
    function rowHtml(r) {
        var seq = String(r.visit_seq).padStart(3, '0');
        var cell = function (cls, text, title) {
            return '<span class="qp-cell ' + cls + '"' + (title ? ' title="' + escHtml(title) + '"' : '') + '>' + text + '</span>';
        };
        return '<div class="qp-row" data-code="' + r.code + '">' +
            cell('qp-c-date fs-13 text-muted', r.date.substr(5)) +
            cell('qp-c-time fs-13 text-muted', r.time) +
            cell('qp-c-dept fs-13 fw-600', escHtml(r.dept_name), r.dept_name) +
            cell('qp-c-seq fs-13 fw-600', seq) +
            cell('qp-c-sess', '<span class="badge badge-gray">' + r.session_text + '</span>') +
            cell('qp-c-name fs-13', escHtml(r.name), r.name) +
            cell('qp-c-gender fs-12 text-muted', escHtml(r.gender)) +
            cell('qp-c-age fs-12 text-muted', escHtml(r.age_fmt || ''), r.age_fmt) +
            cell('qp-c-st', statusBadge(r.status)) +
            '</div>';
    }

    /* 列表区（表头 + 行）：renderPanel / renderListOnly 共用 */
    function listHtml(list) {
        if (!list.length) {
            return '<div class="qp-empty">' + (KEYWORD ? '未找到匹配的患者' : '当前筛选条件下暂无患者') + '</div>';
        }
        var head = '<div class="qp-row qp-head">' +
            '<span class="qp-cell qp-c-date">日期</span>' +
            '<span class="qp-cell qp-c-time">时间</span>' +
            '<span class="qp-cell qp-c-dept">科室</span>' +
            '<span class="qp-cell qp-c-seq">号别</span>' +
            '<span class="qp-cell qp-c-sess">号源</span>' +
            '<span class="qp-cell qp-c-name">姓名</span>' +
            '<span class="qp-cell qp-c-gender">性别</span>' +
            '<span class="qp-cell qp-c-age">年龄</span>' +
            '<span class="qp-cell qp-c-st">状态</span>' +
            '</div>';
        return head + list.map(rowHtml).join('');
    }

    /* 范围内搜索过滤（先多选组合，后关键字匹配姓名/科室/序号/日期） */
    function scopedList() {
        var list = filteredList();
        if (!KEYWORD) return list;
        return list.filter(function (r) {
            var seq = String(r.visit_seq).padStart(3, '0');
            var hay = (r.name || '') + '|' + (r.dept_name || '') + '|' + seq + '|' + r.date;
            return hay.toLowerCase().indexOf(KEYWORD.toLowerCase()) !== -1;
        });
    }

    /* ==================== 弹层面板 ==================== */
    function panelEl() { return document.getElementById('queuePanel'); }

    function renderPanel() {
        var p = panelEl();
        if (!p) return;
        var list = scopedList();
        p.innerHTML =
            '<div class="qp-chips">' +
            '  <button type="button" class="qp-chip' + (seen ? ' active' : '') + '" data-k="seen">已诊</button>' +
            '  <button type="button" class="qp-chip' + (todayOnly ? ' active' : '') + '" data-k="today">当日</button>' +
            '  <span class="fs-12 text-muted qp-count" style="margin-left:auto">' + list.length + ' 人</span>' +
            '</div>' +
            '<input class="input qp-search" id="qpSearch" placeholder="搜索当前列表：姓名 / 科室 / 序号" value="' + escHtml(KEYWORD) + '">' +
            '<div class="qp-list">' + listHtml(list) + '</div>';
        // 勾选切换：保留搜索关键字（跨列表找同一患者），同步偏好与会话
        p.querySelectorAll('.qp-chip').forEach(function (c) {
            c.addEventListener('click', function () {
                if (c.getAttribute('data-k') === 'seen') seen = !seen; else todayOnly = !todayOnly;
                savePref();
                renderBtn();
            renderPanel();
            /* 列表高度限制：不超过视口（46vh），且不溢出屏幕底部——
               患者再多也只在面板内部滚动，不遮挡页面其他区域 */
            var listEl = p.querySelector('.qp-list');
            if (listEl) {
                var chromeH = p.offsetHeight - listEl.offsetHeight;   // chips+搜索+内边距
                var avail = window.innerHeight - p.getBoundingClientRect().top - chromeH - 12;
                listEl.style.maxHeight = Math.max(140, Math.min(window.innerHeight * 0.46, avail)) + 'px';
            }
            });
        });
        // 搜索即时过滤（重渲染列表区，保持输入框焦点与光标位置）
        var search = p.querySelector('#qpSearch');
        search.addEventListener('input', function () {
            var pos = search.selectionStart;
            KEYWORD = search.value.trim();
            renderListOnly(p);
            var again = p.querySelector('#qpSearch');
            again.focus();
            again.setSelectionRange(pos, pos);
        });
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        // 点击条目 → 跳转该患者病历页（visit_id 为混淆串）
        p.querySelectorAll('.qp-row:not(.qp-head)').forEach(function (row) {
            row.addEventListener('click', function () {
                closePanel();
                location.href = '/doctor/emr?visit_id=' + row.getAttribute('data-code');
            });
        });
    }

    /* 仅刷新列表区与计数（搜索输入时保留输入框状态） */
    function renderListOnly(p) {
        var list = scopedList();
        var box = p.querySelector('.qp-list');
        box.innerHTML = listHtml(list);
        p.querySelector('.qp-count').textContent = list.length + ' 人';
        p.querySelectorAll('.qp-row:not(.qp-head)').forEach(function (row) {
            row.addEventListener('click', function () {
                closePanel();
                location.href = '/doctor/emr?visit_id=' + row.getAttribute('data-code');
            });
        });
    }

    function openPanel() {
        closePanel();
        load(true, function () {
            var btn = document.getElementById('queueBtn');
            var p = document.createElement('div');
            p.id = 'queuePanel';
            p.className = 'queue-panel';
            document.body.appendChild(p);
            var rect = btn.getBoundingClientRect();
            p.style.top = (rect.bottom + window.scrollY + 6) + 'px';
            p.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
            renderPanel();
            setTimeout(function () {
                document.addEventListener('mousedown', outsideClose, true);
                document.addEventListener('keydown', escClose, true);
            }, 0);
        });
    }

    function outsideClose(e) {
        var p = panelEl();
        var btn = document.getElementById('queueBtn');
        if (p && !p.contains(e.target) && e.target !== btn && !btn.contains(e.target)) closePanel();
    }
    function escClose(e) { if (e.key === 'Escape') closePanel(); }
    function closePanel() {
        var p = panelEl();
        if (p) p.remove();
        KEYWORD = '';   // 面板关闭即清空搜索，下次打开为全新搜索
        document.removeEventListener('mousedown', outsideClose, true);
        document.removeEventListener('keydown', escClose, true);
    }

    function init() {
        var bar = document.querySelector('.emr-top-bar');
        var header = document.getElementById('emrHeader');
        if (!bar || !header || document.getElementById('queueBtn')) return;
        var btn = document.createElement('button');
        btn.className = 'btn btn-outline btn-sm';
        btn.id = 'queueBtn';
        btn.style.flexShrink = '0';
        btn.title = '候诊 / 近3天患者列表';
        btn.innerHTML = '📋 候诊 …';
        btn.addEventListener('click', function () {
            if (panelEl()) closePanel(); else openPanel();
        });
        bar.insertBefore(btn, header);
        load(true);
        TIMER = setInterval(function () { load(true); }, 30000);
    }

    return { init: init, refresh: function () { DATA = null; load(true); } };
})();

/* 病历页存在患者信息横条时自动挂载 */
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('emrHeader')) Clinic.queuePanel.init();
});
