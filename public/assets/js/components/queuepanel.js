/**
 * queuepanel.js v1.2.0 — 病历页候诊队列面板
 * 挂载于医生病历编辑页顶部患者信息横条左侧：
 *   「📋 候诊XX」按钮（XX=当前科室今日未就诊人数），
 *   点击弹出近3天患者列表面板：已诊/当日 子多选项（可任意组合），
 *   按组合本地过滤排序（零请求）：
 *     · 仅「已诊」    → 近3天（含今日）诊毕患者，最后诊毕在最上；
 *     · 仅「当日」    → 当日挂号患者（全部状态）；
 *     · 两者都选      → 近3天诊毕（诊毕倒序）在上 + 当日未诊（挂号正序）在下；
 *     · 都不选        → 近3天未诊患者，最早挂号在最上、最新挂号在最下。
 * 数据源：GET /api/doctor?action=queue_list（一次返回近3天全量+候诊数）。
 */
Clinic.queuePanel = (function () {

    var DATA = null;        // queue_list 接口缓存 { waiting, list[] }
    var TIMER = null;       // 30 秒自动刷新
    var seen = false;       // 多选项：已诊
    var todayOnly = false;  // 多选项：当日

    /* 拉取队列数据（force=true 强制刷新） */
    function load(force, cb) {
        if (DATA && !force) { if (cb) cb(); return; }
        Clinic.get('/api/doctor?action=queue_list', null, {
            onSuccess: function (json) {
                DATA = json.data;
                renderBtn();
                if (cb) cb();
            },
        });
    }

    /* 顶部按钮：候诊XX（未就诊=今日该科 status='paid' 人数） */
    function renderBtn() {
        var btn = document.getElementById('queueBtn');
        if (!btn || !DATA) return;
        btn.innerHTML = '📋 候诊 <b>' + (DATA.waiting || 0) + '</b>';
    }

    /* ==================== 过滤 + 排序（多选组合规则核心） ==================== */
    function filteredList() {
        if (!DATA) return [];
        var today = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
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
            return all.filter(function (r) { return r.date === todayStr; })
                .sort(function (a, b) { return (b.date + ' ' + b.time).localeCompare(a.date + ' ' + a.time); });
        }
        if (seen && todayOnly) {
            // 双选：近3天诊毕（诊毕倒序）在上 + 今日未诊（挂号正序=候诊顺序）在下
            var done = all.filter(function (r) { return r.status === 'finished'; })
                .sort(function (a, b) { return finKey(b).localeCompare(finKey(a)); });
            var todo = all.filter(function (r) { return r.status !== 'finished' && r.date === todayStr; })
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

    /* 单行患者条目：日期 时间 科室（序号） 号源 姓名 性别 年龄 */
    function rowHtml(r) {
        var seq = String(r.visit_seq).padStart(3, '0');
        return '<div class="qp-row" data-code="' + r.code + '">' +
            '<span class="fs-13 text-muted">' + r.date.substr(5) + ' ' + r.time + '</span>' +
            '<span class="fs-13 fw-600">' + escHtml(r.dept_name) + '（' + seq + '）</span>' +
            '<span class="badge badge-gray qp-sess">' + r.session_text + '</span>' +
            '<span class="fs-13">' + escHtml(r.name) + '</span>' +
            '<span class="fs-12 text-muted">' + escHtml(r.gender) + ' / ' + escHtml(r.age_fmt || '') + '</span>' +
            statusBadge(r.status) +
            '</div>';
    }

    /* ==================== 弹层面板 ==================== */
    var KEYWORD = '';   // 搜索关键字（仅在当前筛选结果范围内过滤）

    function panelEl() { return document.getElementById('queuePanel'); }

    function renderPanel() {
        var p = panelEl();
        if (!p) return;
        // 先按多选组合筛选，再做范围内搜索（搜索不改变筛选范围）
        var list = filteredList().filter(function (r) {
            if (!KEYWORD) return true;
            var seq = String(r.visit_seq).padStart(3, '0');
            var hay = (r.name || '') + '|' + (r.dept_name || '') + '|' + seq + '|' + r.date;
            return hay.toLowerCase().indexOf(KEYWORD.toLowerCase()) !== -1;
        });
        var body = list.length
            ? list.map(rowHtml).join('')
            : '<div class="qp-empty">' + (KEYWORD ? '未找到匹配的患者' : '当前筛选条件下暂无患者') + '</div>';
        p.innerHTML =
            '<div class="qp-chips">' +
            '  <button type="button" class="qp-chip' + (seen ? ' active' : '') + '" data-k="seen">已诊</button>' +
            '  <button type="button" class="qp-chip' + (todayOnly ? ' active' : '') + '" data-k="today">当日</button>' +
            '  <span class="fs-12 text-muted qp-count" style="margin-left:auto">' + list.length + ' 人</span>' +
            '</div>' +
            '<input class="input qp-search" id="qpSearch" placeholder="搜索当前列表：姓名 / 科室 / 序号" value="' + escHtml(KEYWORD) + '">' +
            '<div class="qp-list">' + body + '</div>';
        p.querySelectorAll('.qp-chip').forEach(function (c) {
            c.addEventListener('click', function () {
                if (c.getAttribute('data-k') === 'seen') seen = !seen; else todayOnly = !todayOnly;
                KEYWORD = '';
                renderPanel();
            });
        });
        // 搜索即时过滤（保持焦点，避免重渲染打断输入）
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
        p.querySelectorAll('.qp-row').forEach(function (row) {
            row.addEventListener('click', function () {
                closePanel();
                location.href = '/doctor/emr?visit_id=' + row.getAttribute('data-code');
            });
        });
    }

    /* 仅刷新列表区与计数（搜索输入时保留输入框状态） */
    function renderListOnly(p) {
        var list = filteredList().filter(function (r) {
            if (!KEYWORD) return true;
            var seq = String(r.visit_seq).padStart(3, '0');
            var hay = (r.name || '') + '|' + (r.dept_name || '') + '|' + seq + '|' + r.date;
            return hay.toLowerCase().indexOf(KEYWORD.toLowerCase()) !== -1;
        });
        var box = p.querySelector('.qp-list');
        box.innerHTML = list.length
            ? list.map(rowHtml).join('')
            : '<div class="qp-empty">' + (KEYWORD ? '未找到匹配的患者' : '当前筛选条件下暂无患者') + '</div>';
        p.querySelector('.qp-count').textContent = list.length + ' 人';
        p.querySelectorAll('.qp-row').forEach(function (row) {
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
