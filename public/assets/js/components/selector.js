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
                    '  <span>' + Clinic.escHtml(o.label || '') + '</span>' +
                    (o.sub ? '<span class="text-muted fs-12">' + Clinic.escHtml(o.sub) + '</span>' : '') +
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

/* ============================================================
 * UniversalSelector — 通用「检索 + 选择 + 快捷创建」模态框
 * ============================================================
 * 面向多场景复用（关联皮试处置 / 绑定途径处置等）的解耦组件：
 * 只负责 UI 交互，数据源与权限由调用方通过配置声明。
 *
 * Clinic.universalSelector.open({
 *   title: '选择关联处置项目',            // 模态框标题
 *   searchAction: 'disposal_search',      // 数据源 action（GET，参数 kw）
 *   allowCreate: true,                    // false 时彻底隐藏“+ 新建”入口
 *   createForm: function(){ return html } // 快建表单 HTML（allowCreate=true 时必传）
 *   createCollect: function(){ return {name:..., fee:..., creation_source:...} },
 *   createAction: 'disposal_quick_create',// 快建提交 action（POST）
 *   createContext: '在维护药品[青霉素]时快捷创建', // 审计追溯文案
 *   onSelect: function(item){}            // item = {id, name, fee, ...}
 * });
 * ============================================================ */
Clinic.universalSelector = (function () {

    /** 当前配置（快建提交时使用） */
    let CFG = null;

    /** 当前用户是否管理员（快建文案用；open 时刷新，供 showCreateForm 使用） */
    let IS_ADMIN = false;

    /** 渲染结果列表 */
    function renderList(box, rows) {
        if (!rows || !rows.length) {
            box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未找到匹配项目</div>';
            return;
        }
        box.innerHTML = rows.map(function (r) {
            return '<div class="us-item" data-id="' + r.id + '" data-name="' + Clinic.escHtml(r.name || '') + '"' +
                ' data-fee="' + (r.fee || 0) + '" style="padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer">' +
                '<div class="flex-between"><span class="fw-600">' + Clinic.escHtml(r.name || '') + '</span>' +
                '<span class="fs-12 text-muted">¥' + Number(r.fee || 0).toFixed(2) + '</span></div></div>';
        }).join('');
        box.querySelectorAll('.us-item').forEach(function (el) {
            el.addEventListener('click', function () {
                const item = { id: el.getAttribute('data-id'), name: el.getAttribute('data-name'), fee: parseFloat(el.getAttribute('data-fee')) || 0 };
                Clinic.modal.close();
                if (CFG && typeof CFG.onSelect === 'function') CFG.onSelect(item);
            });
        });
    }

    /** 检索 */
    function doSearch(box, kw) {
        Clinic.get('/api/admin?action=' + CFG.searchAction + '&kw=' + encodeURIComponent(kw || ''), null, {
            onSuccess: function (json) { renderList(box, json.data.list || []); },
        });
    }

    /**
     * 打开通用选择模态框
     * @param {object} cfg 配置对象（见文件头注释）
     */
    function open(cfg) {
        CFG = cfg || {};
        IS_ADMIN = document.body.getAttribute('data-role') === 'admin';
        const canCreate = !!CFG.allowCreate;
        const createBtn = canCreate
            ? '<button type="button" class="btn btn-outline btn-sm" id="usCreate">+ 新建项目</button>'
            : '';
        const html =
            '<div class="form-group"><input class="input" id="usKw" placeholder="输入名称检索…"></div>' +
            '<div id="usList" style="max-height:320px;overflow-y:auto"></div>' +
            '<div class="flex-between mt-8"><span class="fs-12 text-muted">' +
            (canCreate ? '找不到？可直接就地新建' + (IS_ADMIN ? '。' : '（非管理员提交需审核）。') : '') + '</span>' + createBtn + '</div>';

        Clinic.modal.open(html, {
            title: CFG.title || '选择项目',
            size: 'modal-md',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        });

        const box = document.getElementById('usList');
        doSearch(box, '');
        document.getElementById('usKw').addEventListener('input', function () {
            doSearch(box, this.value.trim());
        });

        if (canCreate) {
            document.getElementById('usCreate').addEventListener('click', function () {
                showCreateForm();
            });
        }
    }

    /** 快建表单视图 */
    function showCreateForm() {
        const kw = document.getElementById('usKw');
        const createBtn = document.getElementById('usCreate');
        // 进入新建模式：隐藏搜索框与「新建项目」按钮，聚焦表单
        if (kw) kw.style.display = 'none';
        if (createBtn) createBtn.style.display = 'none';
        const formHtml =
            '<div id="usCreateBox" style="background:var(--bg-soft);border-radius:10px;padding:14px">' +
            '<div class="form-group"><label class="form-label">项目名称 <span class="req">*</span></label>' +
            '<input class="input" id="usc_name" placeholder="如：青霉素皮试"></div>' +
            '<div class="form-group"><label class="form-label">费用（元）</label>' +
            '<input class="input" type="number" step="0.01" min="0" id="usc_fee" value="0"></div>' +
            '<div class="flex gap-8">' +
            '<button type="button" class="btn btn-primary btn-sm" id="usc_submit">提交</button>' +
            '<button type="button" class="btn btn-outline btn-sm" id="usc_back">返回检索</button></div>' +
            '<div class="fs-12 text-warning mt-8">' + (IS_ADMIN ? '提交后将记录创建来源（' + String(CFG.createContext || '快捷创建') + '）。' : '提交后将记录创建来源（' + String(CFG.createContext || '快捷创建') + '），非管理员需管理员审核。') + '</div></div>';
        document.getElementById('usList').innerHTML = formHtml;
        document.getElementById('usc_back').addEventListener('click', function () {
            // 返回检索：恢复搜索框与新建按钮
            if (kw) kw.style.display = '';
            if (createBtn) createBtn.style.display = '';
            doSearch(document.getElementById('usList'), kw ? kw.value.trim() : '');
        });
        document.getElementById('usc_submit').addEventListener('click', function () {
            const name = document.getElementById('usc_name').value.trim();
            if (!name) { Clinic.toast.warning('请填写项目名称'); return; }
            const payload = CFG.createCollect ? CFG.createCollect() : {};
            payload.name = name;
            payload.fee = parseFloat(document.getElementById('usc_fee').value) || 0;
            payload.creation_source = CFG.createContext || '快捷创建';
            payload.action = CFG.createAction;
            Clinic.ajax('/api/admin', payload, {
                onSuccess: function (json) {
                    const d = json.data || {};
                    if (d.pending) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        return;   // 待审核项不直接回填选择
                    }
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    if (typeof CFG.onSelect === 'function') {
                        CFG.onSelect({ id: d.id, name: d.name, fee: d.fee });
                    }
                },
            });
        });
    }

    return { open: open };
})();
