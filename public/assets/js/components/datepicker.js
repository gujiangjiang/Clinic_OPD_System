/**
 * ============================================================
 * datepicker.js v1.0.0 — 通用日期选择组件
 * ============================================================
 * 说明：轻量日历弹层，用于出生日期等场景，拒绝手动输入避免格式错误。
 *
 * Clinic.datePicker.open(input, opts):
 *   input            只读文本框（点击弹出日历）
 *   opts.maxToday    不可选择未来日期（出生日期场景）
 *   opts.onChange(v) 选定后回调（v = 'YYYY-MM-DD'）
 *
 * 交互：点击输入框弹出 → 点选日期回填；支持 年/月 翻转、
 *       「今天」快捷键、「清除」按钮；点击空白处 / Esc 关闭。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.datePicker = (function () {

    let pop = null;      // 当前弹层元素
    let curInput = null; // 绑定的输入框
    let opts = null;
    let viewYear = 0, viewMonth = 0; // 当前浏览的年月（month: 1-12）
    let selDate = '';    // 已选值 YYYY-MM-DD

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function fmt(y, m, d) { return y + '-' + pad2(m) + '-' + pad2(d); }

    /** 解析 YYYY-MM-DD 为 [y,m,d]，非法返回 null */
    function parse(s) {
        const m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s || '');
        if (!m) return null;
        return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
    }

    function close() {
        if (pop) { pop.remove(); pop = null; curInput = null; }
        document.removeEventListener('mousedown', outsideHandler, true);
        document.removeEventListener('keydown', escHandler, true);
    }

    function escHandler(e) { if (e.key === 'Escape') close(); }

    function outsideHandler(e) {
        if (pop && !pop.contains(e.target) && e.target !== curInput) close();
    }

    /** 渲染日历主体（viewYear/viewMonth → HTML） */
    function render() {
        if (!pop) return;
        const today = new Date();
        const todayStr = fmt(today.getFullYear(), today.getMonth() + 1, today.getDate());
        const firstDay = new Date(viewYear, viewMonth - 1, 1);
        // 周一为一周起始
        let lead = firstDay.getDay() - 1; if (lead < 0) lead = 6;
        const daysInMonth = new Date(viewYear, viewMonth, 0).getDate();

        let cells = '';
        for (let i = 0; i < lead; i++) cells += '<span class="date-cell blank"></span>';
        for (let d = 1; d <= daysInMonth; d++) {
            const val = fmt(viewYear, viewMonth, d);
            const isFuture = opts.maxToday && val > todayStr;
            const cls = 'date-cell' +
                (val === selDate ? ' selected' : '') +
                (val === todayStr ? ' today' : '') +
                (isFuture ? ' disabled' : '');
            cells += '<span class="' + cls + '" data-v="' + val + '">' + d + '</span>';
        }

        pop.querySelector('.date-pop-main').innerHTML =
            '<div class="date-head">' +
            '  <button type="button" class="date-nav" data-nav="prev-year" title="上一年">«</button>' +
            '  <button type="button" class="date-nav" data-nav="prev-month" title="上一月">‹</button>' +
            '  <div class="date-title">' + viewYear + ' 年 ' + viewMonth + ' 月</div>' +
            '  <button type="button" class="date-nav" data-nav="next-month" title="下一月">›</button>' +
            '  <button type="button" class="date-nav" data-nav="next-year" title="下一年">»</button>' +
            '</div>' +
            '<div class="date-week">' +
            '  <span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span>' +
            '</div>' +
            '<div class="date-grid">' + cells + '</div>';
    }

    /** 定位：默认输入框正下方，靠近视口底部时改为上方 */
    function position() {
        const r = curInput.getBoundingClientRect();
        pop.style.visibility = 'hidden';
        pop.style.display = '';
        const h = pop.offsetHeight, w = pop.offsetWidth;
        let top = r.bottom + window.scrollY + 6;
        if (r.bottom + h + 12 > window.innerHeight + window.scrollY) {
            top = r.top + window.scrollY - h - 6;
        }
        let left = r.left + window.scrollX;
        if (left + w > window.scrollX + document.documentElement.clientWidth - 8) {
            left = window.scrollX + document.documentElement.clientWidth - w - 8;
        }
        pop.style.top = top + 'px';
        pop.style.left = Math.max(8, left) + 'px';
        pop.style.visibility = '';
    }

    /**
     * 打开日期选择弹层
     * @param {HTMLElement} input 只读输入框
     * @param {object} o { maxToday, onChange }
     */
    function open(input, o) {
        if (!input || input.disabled) return;
        close();
        curInput = input;
        opts = o || {};
        selDate = input.value || '';

        const init = parse(selDate) ||
            (() => { const t = new Date(); return [t.getFullYear(), t.getMonth() + 1, t.getDate()]; })();
        viewYear = init[0]; viewMonth = init[1];

        pop = document.createElement('div');
        pop.className = 'date-pop';
        pop.innerHTML = '<div class="date-pop-main"></div>' +
            '<div class="date-foot">' +
            '  <button type="button" class="btn btn-outline btn-sm" data-act="clear">清除</button>' +
            '  <button type="button" class="btn btn-outline btn-sm" data-act="today">今天</button>' +
            '</div>';
        document.body.appendChild(pop);

        render();
        position();

        /* 头部导航 */
        pop.addEventListener('click', function (e) {
            const nav = e.target.closest ? e.target.closest('.date-nav') : null;
            if (nav) {
                const act = nav.getAttribute('data-nav');
                if (act === 'prev-year') viewYear--;
                if (act === 'next-year') viewYear++;
                if (act === 'prev-month') { viewMonth--; if (viewMonth < 1) { viewMonth = 12; viewYear--; } }
                if (act === 'next-month') { viewMonth++; if (viewMonth > 12) { viewMonth = 1; viewYear++; } }
                render();
                return;
            }
            const cell = e.target.closest ? e.target.closest('.date-cell:not(.blank):not(.disabled)') : null;
            if (cell) {
                selDate = cell.getAttribute('data-v');
                input.value = selDate;
                close();
                if (opts.onChange) opts.onChange(selDate);
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            const actBtn = e.target.closest ? e.target.closest('[data-act]') : null;
            if (actBtn) {
                const act = actBtn.getAttribute('data-act');
                if (act === 'clear') {
                    selDate = ''; input.value = '';
                    if (opts.onChange) opts.onChange('');
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    close();
                }
                if (act === 'today') {
                    const t = new Date();
                    selDate = fmt(t.getFullYear(), t.getMonth() + 1, t.getDate());
                    input.value = selDate;
                    close();
                    if (opts.onChange) opts.onChange(selDate);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });

        document.addEventListener('mousedown', outsideHandler, true);
        document.addEventListener('keydown', escHandler, true);
    }

    return { open: open };
})();
