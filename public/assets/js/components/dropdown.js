/**
 * dropdown.js v1.0.0 — 全站自定义下拉菜单组件
 * ============================================================
 * 说明：原生 <select> 弹出的选项列表在 Chrome/Safari 为操作系统原生样式，
 * 无法用 CSS 主题化。本组件通过「文档级事件委托 + 自定义弹层」为全站所有
 * <select> 提供与整体 UI 一致（含明暗主题）的下拉面板：
 *   1. 点击 / 键盘聚焦 <select> 打开自定义弹层（阻止原生弹层）
 *   2. 弹层选项：悬停/键盘高亮、当前值 ✓ 标注、禁用项置灰、视口内夹紧
 *   3. 选择后写回 <select>.value 并派发 change（既有业务 change 处理器全部生效）
 *   4. 外部点击 / Esc / 滚动（弹层外）关闭；再次点击同一下拉 = 切换收起
 *   5. 颜色全部走 CSS 变量（--bg-card/--border/--primary/--text-*），自动适配明暗主题
 * 依赖：无（纯原生，页面全局加载一次）
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.dropdown = (function () {

    var pop = null;      // 当前弹层元素
    var target = null;   // 触发的 <select>
    var lastCodeScroll = 0;   // 代码滚动时间戳（选项定位用，避免误关闭）

    /** 构建弹层选项 HTML */
    function rowsHtml(select) {
        var rows = [];
        Array.prototype.forEach.call(select.options, function (o, i) {
            var cls = 'csd-opt' + (o.disabled ? ' disabled' : '') + (o.selected ? ' selected' : '');
            rows.push('<div class="' + cls + '" data-i="' + i + '"' + (o.disabled ? ' aria-disabled="true"' : '') + '>' +
                Clinic.escHtml(o.text) + '</div>');
        });
        return rows.join('') || '<div class="csd-empty">无可选项</div>';
    }

    /** 打开自定义弹层 */
    function open(select) {
        close();
        target = select;
        pop = document.createElement('div');
        pop.className = 'csd-pop';
        pop.setAttribute('role', 'listbox');
        pop.innerHTML = rowsHtml(select);
        // 弹层选项点击选择（mousedown 及时关闭；文档 capture 监听已确认点在弹层内，不会误关）
        pop.addEventListener('mousedown', function (e) {
            var opt = e.target.closest ? e.target.closest('.csd-opt') : null;
            if (!opt || opt.classList.contains('disabled')) return;
            e.preventDefault();
            pick(target, parseInt(opt.getAttribute('data-i'), 10));
        });
        document.body.appendChild(pop);
        position(select);
        // 当前选中项可见 + 键盘高亮
        var cur = pop.querySelector('.csd-opt.selected');
        if (cur) cur.classList.add('current');
        scrollToCurrent(false);
        // 供键盘操作（preventDefault 后 select 未获得焦点）
        try { select.focus({ preventScroll: true }); } catch (e) { select.focus(); }
    }

    /** 定位弹层：fixed 视口坐标，下方优先、空间不足翻转上方，四边夹紧 */
    function position(select) {
        var r = select.getBoundingClientRect();
        var pr = pop.getBoundingClientRect();
        pop.style.minWidth = Math.max(r.width, 140) + 'px';
        pop.style.maxWidth = Math.min(340, window.innerWidth - 16) + 'px';
        var top = r.bottom + 4;
        if (top + pr.height > window.innerHeight - 4) top = Math.max(4, r.top - pr.height - 4);
        pop.style.left = Math.max(4, Math.min(r.left, window.innerWidth - pr.width - 4)) + 'px';
        pop.style.top = top + 'px';
    }

    /** 将当前高亮项滚入弹层可视区（直接设 scrollTop，不影响用户滚动判定） */
    function scrollToCurrent() {
        if (!pop) return;
        var cur = pop.querySelector('.csd-opt.current');
        if (!cur) return;
        lastCodeScroll = Date.now();
        var listH = pop.clientHeight;
        var oTop = cur.offsetTop, oH = cur.offsetHeight;
        if (oTop < pop.scrollTop) pop.scrollTop = oTop;
        else if (oTop + oH > pop.scrollTop + listH) pop.scrollTop = oTop + oH - listH;
    }

    /** 选择并写回 select（派发 change 保证既有业务逻辑生效） */
    function pick(select, idx) {
        if (!select || !select.options[idx] || select.options[idx].disabled) return;
        select.value = select.options[idx].value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    }

    /** 键盘导航（在非禁用项之间移动高亮） */
    function nav(dir) {
        if (!pop || !target) return;
        var rows = pop.querySelectorAll('.csd-opt:not(.disabled)');
        if (!rows.length) return;
        var idx = 0;
        Array.prototype.forEach.call(rows, function (r, i) { if (r.classList.contains('current')) idx = i; });
        idx = (idx + dir + rows.length) % rows.length;
        rows.forEach(function (r) { r.classList.remove('current'); });
        rows[idx].classList.add('current');
        scrollToCurrent();
    }

    function close() {
        if (pop) { pop.remove(); pop = null; }
        target = null;
    }

    /* ==================== 全局事件委托 ==================== */
    document.addEventListener('mousedown', function (e) {
        var sel = e.target && e.target.closest ? e.target.closest('select') : null;
        if (sel) {
            if (sel.disabled) return;
            // 已打开同一下拉 → 切换收起
            if (target === sel && pop) { close(); return; }
            e.preventDefault();   // 阻止原生下拉弹层
            open(sel);
            return;
        }
        // 点击弹层外部 → 关闭
        if (pop && !pop.contains(e.target)) close();
    }, true);

    document.addEventListener('keydown', function (e) {
        if (pop) {
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); nav(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); nav(-1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var cur = pop.querySelector('.csd-opt.current');
                if (cur) pick(target, parseInt(cur.getAttribute('data-i'), 10));
            }
            return;
        }
        // 聚焦的 select 用键盘打开自定义弹层（阻止原生弹出）
        var el = document.activeElement;
        if (el && el.tagName === 'SELECT' && !el.disabled) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                open(el);
                if (e.key === 'ArrowDown') nav(1);
                else if (e.key === 'ArrowUp') nav(-1);
            }
        }
    });

    document.addEventListener('scroll', function (e) {
        if (!pop) return;
        var t = e.target;
        if (t === pop || (pop && pop.contains(t))) return;   // 弹层内部滚动不关闭
        if (Date.now() - lastCodeScroll < 50) return;        // 代码定位滚动忽略
        close();
    }, true);

    return { open: open, close: close };
})();
