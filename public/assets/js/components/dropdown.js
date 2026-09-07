/**
 * dropdown.js v2.0.0 — 全站自定义下拉菜单组件
 * ============================================================
 * 说明：原生 <select> 弹出的选项列表在 Chrome/Safari 为系统原生样式，
 * 无法用 CSS 主题化。本组件通过「文档级事件委托 + 自定义弹层」为全站所有
 * <select> 提供与整体 UI 一致（含明暗主题）的下拉面板：
 *   1. 点击 / 键盘聚焦 <select> 打开自定义弹层（阻止原生弹层，含双击）
 *   2. 弹层顶部按 <select> 的 data 属性开关显示 搜索栏 / 清空 X：
 *      · data-csd-search="1"   顶部搜索栏，按选项文本实时过滤
 *      · data-csd-clear="1"    搜索栏右侧清空 X，点击后 value 置空（可显示空白）
 *      （两个开关独立，按接口决定是否需要——如性别无需搜索、必填项不置清空）
 *   3. 弹层选项：悬停/键盘高亮、当前值 ✓ 标注、禁用项置灰、视口内夹紧
 *   4. 选择后写回 <select>.value 并派发 change（既有业务 change 处理器全部生效）
 *   5. 外部点击 / Esc / 滚动（弹层外）关闭；再次点击同一下拉 = 切换收起
 *   6. 颜色全部走 CSS 变量（--bg-card/--border/--primary/--text-*），自动适配明暗主题
 * 依赖：无（纯原生，页面全局加载一次）
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.dropdown = (function () {

    var pop = null;      // 当前弹层元素
    var target = null;   // 触发的 <select>
    var lastCodeScroll = 0;   // 代码滚动时间戳（选项定位用，避免误关闭）
    var searchBox = null;     // 弹层内搜索输入框

    /** 构建弹层选项 HTML：默认隐藏空值占位符（请选择/请填写/单位 等占位选项不进候选列表；
     *  个别确实需要把空值当真实选项的下拉可用 data-csd-keepempty="1" 保留） */
    function rowsHtml(select) {
        var keepEmpty = select.getAttribute('data-csd-keepempty') === '1';
        var rows = [];
        Array.prototype.forEach.call(select.options, function (o, i) {
            if (!keepEmpty && o.value === '') return;
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
        select.classList.add('csd-open');   // 打开态样式（悬浮/点击反馈）
        pop = document.createElement('div');
        pop.className = 'csd-pop';
        pop.setAttribute('role', 'listbox');

        var showSearch = select.getAttribute('data-csd-search') === '1';
        var showClear = select.getAttribute('data-csd-clear') === '1';
        if (showSearch || showClear) {
            var head = document.createElement('div');
            head.className = 'csd-head';
            if (showSearch) {
                searchBox = document.createElement('input');
                searchBox.className = 'csd-search';
                searchBox.type = 'text';
                searchBox.placeholder = '搜索…';
                searchBox.addEventListener('input', filterRows);
                searchBox.addEventListener('mousedown', function (e) { e.stopPropagation(); });
                head.appendChild(searchBox);
            }
            if (showClear) {
                var cx = document.createElement('span');
                cx.className = 'csd-clear';
                cx.title = '清除选择';
                cx.textContent = '✕';
                cx.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearSel();
                });
                head.appendChild(cx);
            }
            pop.appendChild(head);
        }
        pop.insertAdjacentHTML('beforeend', '<div class="csd-list">' + rowsHtml(select) + '</div>');

        // 弹层选项点击选择
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
        scrollToCurrent();
        // 聚焦：优先搜索框（便于直接输入过滤），否则 select（键盘可操作）
        if (searchBox) { try { searchBox.focus({ preventScroll: true }); } catch (e) { searchBox.focus(); } }
        else { try { select.focus({ preventScroll: true }); } catch (e) { select.focus(); } }
    }

    /** 搜索过滤：按选项文本实时显隐 */
    function filterRows() {
        if (!pop || !searchBox) return;
        var kw = searchBox.value.trim().toLowerCase();
        var list = pop.querySelector('.csd-list');
        var matched = 0;
        Array.prototype.forEach.call(list.querySelectorAll('.csd-opt'), function (o) {
            var hit = kw === '' || (o.textContent || '').toLowerCase().indexOf(kw) !== -1;
            o.style.display = hit ? '' : 'none';
            if (hit) matched++;
        });
        var empty = list.querySelector('.csd-empty');
        if (empty) {
            empty.style.display = kw !== '' && matched === 0 ? '' : 'none';
        }
        if (matched > 0) {
            var first = visibleOpts()[0];
            list.querySelectorAll('.csd-opt.current').forEach(function (r) { r.classList.remove('current'); });
            if (first) first.classList.add('current');
        }
    }

    /** 弹层内当前可见且可选的选项 */
    function visibleOpts() {
        var list = pop ? pop.querySelector('.csd-list') : null;
        if (!list) return [];
        return Array.prototype.filter.call(list.querySelectorAll('.csd-opt'), function (o) {
            return !o.classList.contains('disabled') && o.style.display !== 'none';
        });
    }

    /** 定位弹层：fixed 视口坐标，下方优先、空间不足翻转上方，四边夹紧 */
    function position(select) {
        var r = select.getBoundingClientRect();
        var pr = pop.getBoundingClientRect();
        pop.style.minWidth = Math.max(r.width, 160) + 'px';
        pop.style.maxWidth = Math.min(340, window.innerWidth - 16) + 'px';
        var top = r.bottom + 4;
        if (top + pr.height > window.innerHeight - 4) top = Math.max(4, r.top - pr.height - 4);
        pop.style.left = Math.max(4, Math.min(r.left, window.innerWidth - pr.width - 4)) + 'px';
        pop.style.top = top + 'px';
    }

    /** 将当前高亮项滚入弹层可视区（直接设 scrollTop，不影响用户滚动判定） */
    function scrollToCurrent() {
        if (!pop) return;
        var list = pop.querySelector('.csd-list');
        var cur = list ? list.querySelector('.csd-opt.current') : null;
        if (!cur) return;
        lastCodeScroll = Date.now();
        var listH = list.clientHeight;
        var oTop = cur.offsetTop, oH = cur.offsetHeight;
        if (oTop < list.scrollTop) list.scrollTop = oTop;
        else if (oTop + oH > list.scrollTop + listH) list.scrollTop = oTop + oH - listH;
    }

    /** 选择并写回 select（派发 change 保证既有业务逻辑生效） */
    function pick(select, idx) {
        if (!select || !select.options[idx] || select.options[idx].disabled) return;
        select.value = select.options[idx].value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    }

    /** 清空选择（data-csd-clear 的 X 触发）：value 置空 → 关闭态显示空白 */
    function clearSel() {
        if (!target) return;
        target.value = '';
        target.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    }

    /** 键盘导航（在可见非禁用项之间移动高亮） */
    function nav(dir) {
        if (!pop || !target) return;
        var rows = visibleOpts();
        if (!rows.length) return;
        var idx = 0;
        rows.forEach(function (r, i) { if (r.classList.contains('current')) idx = i; });
        idx = (idx + dir + rows.length) % rows.length;
        rows.forEach(function (r) { r.classList.remove('current'); });
        rows[idx].classList.add('current');
        scrollToCurrent();
    }

    function close() {
        if (target) target.classList.remove('csd-open');
        if (pop) { pop.remove(); pop = null; }
        target = null;
        searchBox = null;
    }

    /* ==================== 全局事件委托 ==================== */
    document.addEventListener('mousedown', function (e) {
        var sel = e.target && e.target.closest ? e.target.closest('select') : null;
        if (sel) {
            if (sel.disabled) return;
            // 已打开同一下拉 → 切换收起（必须同样 preventDefault，否则第二次 mousedown
            // 的默认动作会触发浏览器原生下拉弹出——这正是双击弹原生的根因）
            if (target === sel && pop) { e.preventDefault(); close(); return; }
            e.preventDefault();   // 阻止原生下拉弹层
            open(sel);
            return;
        }
        // 点击弹层外部 → 关闭
        if (pop && !pop.contains(e.target)) close();
    }, true);

    // 双击/单击仍阻止原生弹层（部分浏览器双击会弹出原生下拉）
    ['click', 'dblclick'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var sel = e.target && e.target.closest ? e.target.closest('select') : null;
            if (sel) e.preventDefault();
        }, true);
    });

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
