/**
 * admin_items.js v1.0.0 — 管理端项目列表公共组件
 * ============================================================
 * 说明：检验项目管理（labitems）、检查项目管理（examitems）、
 * 药品信息（drugs）三个列表页共用同一套「分类 tab + 关键字过滤 +
 * 计数」基础设施（此前三页各自实现，仅 id 前缀/文案不同）。
 * 差异经 cfg 注入；openItemForm/openDrugForm（字段采集差异大）保留各页。
 * 依赖：ajax.js / modal.js / toast.js
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.adminItems = {

    /**
     * 分类子 tab 动态构建（按列表数据生成）
     * @param {object} cfg { listId, tabsId, current, tabFn }
     *   listId  列表容器 id（tbody tr 含 data-cat）
     *   tabsId  tab 容器 id
     *   current 当前选中分类
     *   tabFn   点击 tab 的全局函数名（接收 (btn, cat)）
     */
    buildCats: function (cfg) {
        var cats = [];
        document.querySelectorAll('#' + cfg.listId + ' tbody tr').forEach(function (tr) {
            var c = tr.getAttribute('data-cat') || '';
            if (c && cats.indexOf(c) === -1) cats.push(c);
        });
        var bar = document.getElementById(cfg.tabsId);
        bar.innerHTML = '<button class="btn btn-sm ' + (cfg.current === '' ? 'btn-primary' : 'btn-outline') + '" data-cat="" onclick="' + cfg.tabFn + '(this,\'\')">全部</button>' +
            cats.map(function (c) {
                return '<button class="btn btn-sm ' + (cfg.current === c ? 'btn-primary' : 'btn-outline') + '" data-cat="' + c + '" onclick="' + cfg.tabFn + '(this,\'' + c + '\')">' + c + '</button>';
            }).join('');
    },

    /**
     * 分类过滤：高亮 tab + 重新应用搜索过滤
     * @param {object} cfg { tabsId, setCat, apply }
     */
    filterByCat: function (cfg, c) {
        cfg.setCat(c);
        document.querySelectorAll('#' + cfg.tabsId + ' .btn').forEach(function (b) {
            b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === c ? 'btn-primary' : 'btn-outline');
        });
        cfg.apply();
    },

    /**
     * 关键字 + 分类组合过滤：行显隐 + 计数动态更新
     * @param {object} cfg { listId, countId, getCat, getQuery, countText }
     *   countText(cat, q, n) 各页计数文案格式
     */
    filterRows: function (cfg) {
        var q = (cfg.getQuery() || '').trim().toLowerCase();
        var n = 0;
        document.querySelectorAll('#' + cfg.listId + ' tbody tr').forEach(function (tr) {
            var hit = (cfg.getCat() === '' || tr.getAttribute('data-cat') === cfg.getCat()) &&
                tr.textContent.toLowerCase().indexOf(q) !== -1;
            tr.style.display = hit ? '' : 'none';
            if (hit) n++;
        });
        var cnt = document.getElementById(cfg.countId);
        if (cnt) cnt.textContent = cfg.countText(cfg.getCat(), q, n);
    },

    /**
     * 删除项目（确认 + ajax + 刷新）
     * @param {object} cfg { confirm, action, url, params, reload }
     */
    delItem: function (cfg, id) {
        Clinic.modal.confirm(cfg.confirm, function () {
            var data = { action: cfg.action, id: id };
            if (cfg.params) Object.keys(cfg.params).forEach(function (k) { data[k] = cfg.params[k]; });
            Clinic.ajax(cfg.url, data, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    if (cfg.reload) cfg.reload();
                },
            });
        });
    },

    /**
     * 分类管理弹窗（检验/检查共用；按 type 拉取/新增/删除分类）
     * @param {object} opts { type, title, placeholder }
     */
    catManager: function (opts) {
        // 分类变更回调（onChanged）：页面传入项目列表刷新函数（如 loadItemList），
        // 增/改/删分类后实时刷新底层检验/检查项目列表，无需手动刷新页面
        var onChanged = opts.onChanged || function () {};
        var loadCats = function () {
            Clinic.get('/api/admin?action=cat_list&type=' + opts.type, null, {
                onSuccess: function (json) {
                    var list = json.data.list || [];
                    var box = document.getElementById('catBox');
                    box.innerHTML = list.length
                        ? list.map(function (c) {
                            return '<div class="cat-mgr-row" data-id="' + c.id + '" data-name="' + Clinic.escHtml(c.name) + '">' +
                                '<span class="cat-mgr-name">' + Clinic.escHtml(c.name) + '</span>' +
                                '<span class="cat-mgr-actions">' +
                                '<button type="button" class="btn btn-outline btn-sm" onclick="renCat(' + c.id + ')">✏️ 重命名</button>' +
                                '<button type="button" class="btn btn-danger btn-sm" onclick="delCat(' + c.id + ')">🗑️ 删除</button>' +
                                '</span></div>';
                        }).join('')
                        : '<div class="cat-mgr-empty">暂无分类，请输入名称添加</div>';
                },
            });
        };
        Clinic.modal.open(
            '<div class="flex gap-8 mb-8">' +
            '<input class="input" id="catName" placeholder="' + opts.placeholder + '" style="flex:1" onkeydown="if(event.key===\'Enter\')addCat()">' +
            '<button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div>' +
            '<div id="catBox" class="cat-mgr-box"></div>',
            {
                title: opts.title,
                size: 'modal-sm',
                buttons: [{ text: '关闭', cls: 'btn-outline' }],
            }
        );
        loadCats();
        window.addCat = function () {
            var name = document.getElementById('catName').value.trim();
            if (!name) { Clinic.toast.warning('请输入分类名称'); return; }
            Clinic.ajax('/api/admin', { action: 'cat_add', type: opts.type, name: name }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    document.getElementById('catName').value = '';
                    loadCats();
                    onChanged();   // 实时刷新项目列表
                },
            });
        };
        // 重命名：行内编辑（当前名称回填），保存后后端同步更名该分类下全部项目
        window.renCat = function (id) {
            var row = document.querySelector('.cat-mgr-row[data-id="' + id + '"]');
            if (!row) return;
            var old = row.getAttribute('data-name') || '';
            Clinic.modal.prompt({
                title: '重命名分类',
                label: '新分类名称（该分类下所有项目将同步更名）',
                value: old,
                okText: '保存',
                onOk: function (name) {
                    if (!name.trim()) { Clinic.toast.warning('请输入分类名称'); return; }
                    Clinic.ajax('/api/admin', { action: 'cat_rename', id: id, name: name.trim() }, {
                        onSuccess: function (json) {
                            Clinic.toast.success(json.msg);
                            loadCats();
                            onChanged();   // 实时刷新项目列表
                        },
                    });
                },
            });
        };
        // 删除：二次确认，删除后该分类下全部项目转未分类
        window.delCat = function (id) {
            var row = document.querySelector('.cat-mgr-row[data-id="' + id + '"]');
            var name = row ? (row.getAttribute('data-name') || '') : '';
            Clinic.modal.confirm('确定删除分类「' + name + '」吗？删除后该分类下的检验/检查项目将自动转为未分类。', function () {
                Clinic.ajax('/api/admin', { action: 'cat_delete', id: id }, {
                    onSuccess: function (json) {
                        Clinic.toast.success(json.msg);
                        loadCats();
                        onChanged();   // 实时刷新项目列表
                    },
                });
            });
        };
    },

    /** 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
    bindEditDeepLink: function (openFn) {
        var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1];
        if (m) openFn(parseInt(m, 10));
    },

    /**
     * 统一分页无限滚动列表（v8.17.3，参考查询中心-影像引用查询实现）：
     * 服务端按 page/size/kw/cat 过滤，返回行 HTML 片段；滚动到底自动加载下一页。
     * @param {object} cfg {
     *   tableEl  table 元素 id（thead 由服务端首页响应提供，tbody 由本组件填充）
     *   url     函数 (p, size, state) => 接口地址（state={kw,cat}）
     *   state   { kw:'', cat:'' } 当前搜索/分类（修改后 reset()）
     *   catsEl  可选：分类 tab 容器 id（服务端首页响应 cats 数组构建）
     *   kwEl    可选：搜索输入框 id（input 防抖 reset）
     *   countEl 可选：计数元素 id
     *   onState 可选：state 变化回调（返回 false 阻止 reset）
     * }
     * @return {{reset: function, stop: function, list: object}}
     */
    pagedTable: function (cfg) {
        var LIST = null;
        var state = cfg.state || { kw: '', cat: '' };
        var tableEl = document.getElementById(cfg.tableEl);
        var thead = '';
        var hasCats = false;
        // 注册表：分类 tab 全局委托（pagedCat）按 tableId 定位
        Clinic.adminItems.__paged = Clinic.adminItems.__paged || {};
        Clinic.adminItems.__pagedCfg = Clinic.adminItems.__pagedCfg || {};
        Clinic.adminItems.__paged[cfg.tableEl] = { reset: function () { hasCats = false; init(); } };
        Clinic.adminItems.__pagedCfg[cfg.tableEl] = cfg;
        var buildUrl = function (p, size) {
            if (typeof cfg.url === 'function') return cfg.url(p, size, state);
            return cfg.url;
        };
        // 行渲染：不再拼接 thead（thead 由 append 插入 table 开头，行进入 tbody，
        // 保证 table 结构正确，sticky 表头可吸顶、内容不会从表头上方穿出）
        var renderRows = function (list) {
            return list.join('');
        };
        function init() {
            if (LIST) LIST.stop();
            LIST = Clinic.infiniteList({
                el: tableEl,
                pageSize: 20,
                threshold: 60,
                emptyHtml: '<tbody><tr><td colspan="99" style="text-align:center;color:var(--text-muted);padding:24px">暂无数据</td></tr></tbody>',
                url: buildUrl,
                render: function (list, isFirst, data) {
                    if (data && data.thead) thead = data.thead;
                    return renderRows(list);
                },
                // 表格追加：首屏 thead 插到 table 开头，行追加到 tbody（保证合法 DOM 结构）。
                // 服务端每页都返回 thead，这里必须检测 table 已存在 thead，否则滚动加载
                // 下一页时会重复堆叠多个 thead（回到顶部时抬头全部堆在顶部）。
                append: function (el, html) {
                    if (thead !== '' && !el.querySelector('thead')) {
                        el.insertAdjacentHTML('afterbegin', thead);
                        thead = '';
                    }
                    var tb = el.querySelector('tbody');
                    if (!tb) { tb = document.createElement('tbody'); el.appendChild(tb); }
                    tb.insertAdjacentHTML('beforeend', html);
                },
                onSuccess: function (json) {
                    var d = json.data || {};
                    if (d.thead) thead = d.thead;
                    if (cfg.countEl) {
                        var c = document.getElementById(cfg.countEl);
                        if (c) c.textContent = d.count_text || ('共 ' + (d.total || 0) + ' 项');
                    }
                    // 首页响应构建分类 tab
                    if (d.cats && cfg.catsEl && !hasCats) {
                        hasCats = true;
                        var bar = document.getElementById(cfg.catsEl);
                        if (bar) {
                            bar.innerHTML = '<button class="btn btn-sm ' + (state.cat === '' ? 'btn-primary' : 'btn-outline') + '" data-cat="" onclick="' +
                                (cfg.onCat || 'Clinic.adminItems.pagedCat') + '(this,\'\',' + JSON.stringify(cfg.tableEl) + ')">全部</button>' +
                                d.cats.map(function (c) {
                                    return '<button class="btn btn-sm ' + (state.cat === c ? 'btn-primary' : 'btn-outline') + '" data-cat="' + c + '" onclick="' +
                                        (cfg.onCat || 'Clinic.adminItems.pagedCat') + '(this,\'' + c + '\',' + JSON.stringify(cfg.tableEl) + ')">' + c + '</button>';
                                }).join('');
                        }
                    }
                },
            });
        }
        // 搜索输入防抖联动
        var kwEl = cfg.kwEl ? document.getElementById(cfg.kwEl) : null;
        if (kwEl) {
            kwEl.addEventListener('input', function () {
                clearTimeout(kwEl.__t);
                kwEl.__t = setTimeout(function () {
                    state.kw = (kwEl.value || '').trim();
                    if (cfg.onState && cfg.onState(state) === false) return;
                    hasCats = false;
                    init();
                }, 250);
            });
        }
        init();
        return {
            reset: function () { hasCats = false; init(); },
            stop: function () { if (LIST) LIST.stop(); },
            list: LIST,
        };
    },

    /** 分页列表分类 tab 点击（全局委托）：更新高亮 + 重置列表 */
    pagedCat: function (btn, cat, tableId) {
        var cfg = Clinic.adminItems.__pagedCfg ? Clinic.adminItems.__pagedCfg[tableId] : null;
        if (!cfg) return;
        cfg.state.cat = cat;
        var bar = btn.parentNode;
        if (bar) bar.querySelectorAll('.btn').forEach(function (b) {
            b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === cat ? 'btn-primary' : 'btn-outline');
        });
        var paged = Clinic.adminItems.__paged ? Clinic.adminItems.__paged[tableId] : null;
        if (paged) paged.reset();
    },
};
