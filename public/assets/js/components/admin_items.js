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
        var loadCats = function () {
            Clinic.get('/api/admin?action=cat_list&type=' + opts.type, null, {
                onSuccess: function (json) {
                    var list = json.data.list || [];
                    document.getElementById('catBox').innerHTML = list.map(function (c) {
                        return '<span class="badge badge-gray" style="margin:0 6px 6px 0;padding:5px 12px">' + c.name +
                            ' <a href="javascript:void(0)" style="color:var(--danger)" onclick="delCat(' + c.id + ')">✕</a></span>';
                    }).join('') || '<span class="text-muted fs-13">暂无分类</span>';
                },
            });
        };
        Clinic.modal.open(
            '<div class="flex gap-8 mb-8">' +
            '<input class="input" id="catName" placeholder="' + opts.placeholder + '" style="flex:1">' +
            '<button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div>' +
            '<div id="catBox"></div>',
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
                },
            });
        };
        window.delCat = function (id) {
            Clinic.ajax('/api/admin', { action: 'cat_delete', id: id }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    loadCats();
                },
            });
        };
    },

    /** 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
    bindEditDeepLink: function (openFn) {
        var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1];
        if (m) openFn(parseInt(m, 10));
    },
};
