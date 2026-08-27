/**
 * depttree.js v1.0.0 — 科室三级树组件（可复用）
 * 封装全选/分组/搜索/折叠/展开逻辑，与 admin_user.php 的科室树
 * 样式一致（复用 send-grp / tree-box 等 CSS 类），数据源：
 * GET /api/template?action=depts（返回临床科室列表，doctor 可访问）。
 *
 * 用法：
 *   Clinic.deptTree.build('#container', { selected: [1,2,3] })
 *   Clinic.deptTree.getSelected()  // 返回选中科室 id 数组
 */
Clinic.deptTree = (function () {

    var SELECTED = [];
    var CONTAINER = null;

    /* 折叠/展开：复用全局 window.treeToggle（app.js 定义，全站加载） */

    /* 全选/全不选 */
    function deptToggleAll(checked) {
        document.querySelectorAll(CONTAINER + ' .deptChk').forEach(function (c) { c.checked = checked; });
        document.querySelectorAll(CONTAINER + ' .deptGrpChk').forEach(function (c) { c.checked = checked; });
        syncGroups();
    }

    /* 按组全选/全不选 */
    function deptToggleGroup(type, checked) {
        document.querySelectorAll(CONTAINER + ' .deptChk[data-type="' + type + '"]').forEach(function (c) { c.checked = checked; });
        syncGroups();
    }

    /* 同步全选/分组选中态 */
    function syncGroups() {
        var all = document.querySelectorAll(CONTAINER + ' .deptChk');
        var allChecked = true;
        all.forEach(function (c) { if (!c.checked) allChecked = false; });
        var allEl = document.querySelector(CONTAINER + ' #dtAll');
        if (allEl) allEl.checked = allChecked;
        // 同步各分组
        var groups = {};
        all.forEach(function (c) {
            var t = c.getAttribute('data-type');
            if (!groups[t]) groups[t] = { total: 0, checked: 0 };
            groups[t].total++;
            if (c.checked) groups[t].checked++;
        });
        Object.keys(groups).forEach(function (t) {
            var grpEl = document.querySelector(CONTAINER + ' .deptGrpChk[data-type="' + t + '"]');
            if (grpEl) grpEl.checked = groups[t].checked > 0 && groups[t].checked === groups[t].total;
        });
        // 更新选中列表
        SELECTED = [];
        all.forEach(function (c) { if (c.checked) SELECTED.push(parseInt(c.value, 10)); });
    }

    /** 搜索定位（复用 tree-box 搜索样式） */
    function initSearch(inputId, resId, treeId) {
        var input = document.getElementById(inputId);
        var res = document.getElementById(resId);
        var tree = document.getElementById(treeId);
        if (!input || !res || !tree) return;
        var timer = null;
        input.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            if (timer) clearTimeout(timer);
            if (q === '') { res.innerHTML = ''; res.style.display = 'none'; return; }
            timer = setTimeout(function () {
                var hits = [];
                tree.querySelectorAll('.deptChk').forEach(function (el) {
                    var lab = el.closest('label') || el;
                    var txt = lab.textContent.trim();
                    if (txt.toLowerCase().indexOf(q) !== -1) hits.push({ label: lab, text: txt, id: el.value });
                });
                res.innerHTML = hits.length
                    ? hits.map(function (h) {
                        return '<div class="tree-search-item" data-id="' + h.id + '">' + h.text.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
                    }).join('')
                    : '<div class="fs-12 text-muted" style="padding:6px 10px">无匹配科室</div>';
                res.style.display = '';
                res.querySelectorAll('.tree-search-item').forEach(function (el) {
                    el.addEventListener('click', function () {
                        res.style.display = 'none';
                        input.value = '';
                        var id = el.getAttribute('data-id');
                        tree.querySelectorAll('.deptChk[value="' + id + '"]').forEach(function (cb) {
                            cb.checked = true;
                            var anc = cb.closest('.send-grp-children');
                            if (anc) {
                                anc.style.display = '';
                                var tbtn = tree.querySelector('.tree-toggle[data-toggle="' + anc.id + '"]');
                                if (tbtn) tbtn.textContent = '−';
                            }
                        });
                        syncGroups();
                    });
                });
            }, 150);
        });
    }

    /**
     * 构建三级树
     * @param {string|HTMLElement} container CSS 选择器或元素
     * @param {object} opts { selected: [], depts: [] }
     *   depts 可选，不传则自动 fetch /api/template?action=depts
     */
    function build(container, opts) {
        opts = opts || {};
        CONTAINER = typeof container === 'string' ? container : null;
        var el = typeof container === 'string' ? document.querySelector(container) : container;
        if (!el) return;
        SELECTED = opts.selected || [];

        function render(depts) {
            var byType = {};
            depts.forEach(function (d) {
                var t = d.type === 'emergency' ? 'emergency' : 'clinic';
                if (!byType[t]) byType[t] = { label: t === 'emergency' ? '急诊' : '门诊', items: [] };
                byType[t].items.push(d);
            });
            var types = ['clinic', 'emergency'];
            var treeId = 'dtTree_' + Math.random().toString(36).substr(2, 6);
            var childrenHtml = '';
            types.forEach(function (t) {
                var g = byType[t];
                if (!g || !g.items.length) return;
                childrenHtml +=
                    '<div class="send-grp">' +
                    '  <div class="send-grp-head-row">' +
                    '    <button type="button" class="tree-toggle" data-toggle="dtGrp_' + t + '">−</button>' +
                    '    <label class="send-grp-head"><input type="checkbox" class="deptGrpChk" data-type="' + t + '" onchange="Clinic.deptTree.toggleGroup(\'' + t + '\', this.checked)"> <b>' + g.label + '</b></label>' +
                    '  </div>' +
                    '  <div class="send-grp-children send-tree-level-3" id="dtGrp_' + t + '">' +
                    g.items.map(function (d) {
                        var sel = SELECTED.indexOf(d.id) !== -1 ? ' checked' : '';
                        return '<label class="send-user" style="font-size:13px;padding:4px 8px">' +
                            '<input type="checkbox" class="deptChk" data-type="' + t + '" value="' + d.id + '"' + sel + ' onchange="Clinic.deptTree.sync()"> ' + d.name + '</label>';
                    }).join('') +
                    '  </div>' +
                    '</div>';
            });
            el.innerHTML =
                '<div class="tree-box">' +
                '  <input class="input tree-box-search" id="dtSearch" placeholder="🔍 搜索科室，可定位到列表" autocomplete="off">' +
                '  <div class="tree-search-res" id="dtRes" style="display:none"></div>' +
                '  <div class="send-tree" id="' + treeId + '">' +
                '    <div class="send-grp">' +
                '      <div class="send-grp-head-row">' +
                '        <button type="button" class="tree-toggle" data-toggle="dtL2_' + treeId + '">−</button>' +
                '        <label class="send-grp-head"><input type="checkbox" id="dtAll" checked onchange="Clinic.deptTree.toggleAll(this.checked)"> <b>全院（全部科室）</b></label>' +
                '      </div>' +
                '      <div class="send-grp-children send-tree-level-2" id="dtL2_' + treeId + '">' + childrenHtml + '</div>' +
                '    </div>' +
                '  </div>' +
                '</div>';
            // 绑定搜索
            initSearch('dtSearch', 'dtRes', treeId);
            // 折叠/展开
            el.querySelectorAll('.tree-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () { window.treeToggle(btn); });
            });
            syncGroups();
        }

        if (opts.depts) {
            render(opts.depts);
        } else {
            Clinic.get('/api/template?action=depts', null, {
                onSuccess: function (j) { render(j.data.list || []); },
            });
        }
    }

    /** 获取当前选中科室 id 列表 */
    function getSelected() {
        return SELECTED.slice();
    }

    return {
        build: build,
        getSelected: getSelected,
        sync: syncGroups,
        toggleAll: deptToggleAll,
        toggleGroup: deptToggleGroup,
    };
})();