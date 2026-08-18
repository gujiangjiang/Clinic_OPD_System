/**
 * ============================================================
 * selector.js v1.0.0 — 搜索式下拉选择器
 * ============================================================
 * 说明：替代原生下拉的增强组件：
 * 1. 点击输入框弹出选项面板
 * 2. 输入关键字实时过滤（匹配名称/编码/拼音）
 * 3. 支持键盘上下键选择、回车确认
 * 4. 兼容 Chrome / Safari
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.selector = (function () {
    /** 当前打开的面板 */
    let panel = null;
    let activeItem = 0;

    /**
     * 绑定搜索下拉
     * @param {HTMLInputElement} input  输入框
     * @param {Array}            options 选项数组 [{label, value, sub}]
     * @param {Function}         onSelect 选择回调(value, option)
     * @param {object}           opts { minChars, placeholder }
     */
    function bind(input, options, onSelect, opts) {
        opts = opts || {};
        let all = options || [];

        /**
         * 过滤选项
         */
        function filter(keyword) {
            if (!keyword) return all;
            const kw = keyword.toLowerCase();
            return all.filter(function (o) {
                const text = ((o.label || '') + ' ' + (o.sub || '') + ' ' + (o.pinyin || '')).toLowerCase();
                return text.indexOf(kw) !== -1;
            });
        }

        /**
         * 打开面板
         */
        function openPanel(list) {
            closePanel();
            panel = document.createElement('div');
            panel.className = 'dropdown-panel';
            const rect = input.getBoundingClientRect();
            panel.style.top = (rect.bottom + 4) + 'px';
            panel.style.left = rect.left + 'px';
            panel.style.width = Math.max(rect.width, 200) + 'px';
            render(list);
            document.body.appendChild(panel);
            document.addEventListener('click', outsideHandler);
            document.addEventListener('keydown', keyHandler);
        }

        /**
         * 渲染选项列表
         */
        function render(list) {
            if (!panel) return;
            if (!list.length) {
                panel.innerHTML = '<div class="dd-empty">' + (opts.emptyText || '无匹配选项') + '</div>';
                return;
            }
            panel.innerHTML = '';
            activeItem = 0;
            list.forEach(function (o, i) {
                const div = document.createElement('div');
                div.className = 'dd-item' + (i === 0 ? ' active' : '');
                div.innerHTML =
                    '<div class="flex-between">' +
                    '  <span>' + (o.label || '') + '</span>' +
                    (o.sub ? '<span class="text-muted fs-12">' + o.sub + '</span>' : '') +
                    '</div>';
                div.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    pick(o);
                });
                div.addEventListener('mouseenter', function () {
                    setActive(i, list);
                });
                panel.appendChild(div);
            });
        }

        /**
         * 高亮指定项
         */
        function setActive(idx, list) {
            activeItem = idx;
            const items = panel.querySelectorAll('.dd-item');
            items.forEach(function (el, i) {
                el.classList.toggle('active', i === idx);
            });
            const el = items[idx];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }

        /**
         * 选中选项
         */
        function pick(o) {
            input.value = o.label || '';
            closePanel();
            if (onSelect) onSelect(o.value, o);
        }

        /**
         * 键盘事件：上下选择 / 回车确认 / Esc 关闭
         */
        function keyHandler(e) {
            if (!panel) return;
            const list = filter(input.value);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeItem + 1, list.length - 1), list);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeItem - 1, 0), list);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const list2 = filter(input.value);
                if (list2[activeItem]) pick(list2[activeItem]);
            } else if (e.key === 'Escape') {
                closePanel();
            }
        }

        /**
         * 点击外部关闭
         */
        function outsideHandler(e) {
            if (!panel || panel.contains(e.target) || input.contains(e.target)) return;
            closePanel();
        }

        /**
         * 关闭面板
         */
        function closePanel() {
            if (panel) {
                panel.remove();
                panel = null;
                document.removeEventListener('click', outsideHandler);
                document.removeEventListener('keydown', keyHandler);
            }
        }

        // 输入事件：过滤并重新渲染
        input.addEventListener('input', function () {
            if (document.activeElement === input) {
                const list = filter(input.value);
                if (!panel) {
                    openPanel(list);
                } else {
                    render(list);
                }
            }
        });
        input.addEventListener('focus', function () {
            const list = filter(input.value);
            openPanel(list);
        });
        input.addEventListener('blur', function () {
            // 延迟关闭，避免 mousedown 未触发
            setTimeout(closePanel, 150);
        });

        // 返回更新选项的方法（供外部动态刷新）
        return {
            /**
             * 更新选项列表；若下拉面板当前已打开，立即按输入框当前值
             * 重新过滤渲染，保证 AJAX 搜索返回后结果立刻显示
             * （此前仅更新数据不重绘，快速输入多字时面板一直停留在
             * 「无匹配选项」，表现为 ICD 搜索越精确越搜不到）。
             */
            setOptions: function (newOpts) {
                all = newOpts || [];
                if (panel && document.activeElement === input) {
                    render(filter(input.value));
                }
            },
            close: closePanel,
        };
    }

    return { bind: bind };
})();
