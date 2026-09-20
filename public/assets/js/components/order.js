/**
 * ============================================================
 * order.js v1.0.1 — 开单组件（检验/检查/处置/处方）
 * ============================================================
 * 说明：医生开单统一弹窗（三栏：左目录+搜索 / 中已选 / 右流程）：
 * 1. 项目列表滚动加载（检查/检验/处置/药品），减少首次加载压力
 * 2. 搜索项目（检验/检查/处置/药品），多选或单选
 * 3. 互斥规则：所有类型同一项目仅可添加一次（数量在已选列表手动修改）；
 *    检验组合与所含单项互斥（双向）、不同组合共享成员仅提醒不算重复；
 *    处置/处方数量可手动修改（如处置 ×2、药品 ×2）
 * 4. 检验既往已开具（含未缴费）二次确认后再加入（复查场景）
 * 5. 实时显示开单总费用
 * 6. 处方：数量不得超库存、剂度/频次/途径自动同步药品设置、
 *    支持子医嘱成组（所有药品均可，不限给药途径）、护士站执行选项自动勾选
 * 7. 右侧纵向流程图（开单-缴费-登记-执行-完成）
 * 8. 提交后自动弹出申请单/处方打印
 * 依赖：ajax.js、modal.js、toast.js、print.js、emr.js、infinite.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

/**
 * 处方条目 → 通用展示行（成组医嘱树形格式，病历正文/已开项目卡片/
 * 开单详情弹窗统一调用本方法，保证全系统同一套组医嘱展示规则）：
 * · 组内主药行（首条）：名称　剂量　频次　途径　×数量；
 * · 子药行：├─ / └─（树形连接符）+ 名称 + 剂量（临床必填）；
 *   频次/途径/数量组内一致仅主药行显示一次；
 * · 非成组药品（group_no=0）各自独立一行全要素显示。
 * @param {Array} items 处方 order_items（含 group_no/is_parent）
 * @returns {string[]} 展示行数组
 */
Clinic.orderRxLines = function (items) {
    var lines = [];
    var fullLine = function (it) {
        var p = [];
        if (it.single_dose) p.push(it.single_dose);
        if (it.frequency) p.push(it.frequency);
        if (it.route) p.push(it.route);
        // 数量带开立销售单位（2盒 / 2支），病历正文与处方笺口径一致
        var u = it.unit || '';
        return it.item_name + (p.length ? '\u3000' + p.join('\u3000') : '') + '\u3000\u00D7' + it.quantity + u;
    };
    var i = 0;
    while (i < items.length) {
        var it = items[i];
        var g = it.group_no || 0;
        if (!g) { lines.push(fullLine(it)); i++; continue; }
        var arr = [it];
        var j = i + 1;
        while (j < items.length && (items[j].group_no || 0) === g) { arr.push(items[j]); j++; }
        arr.forEach(function (x, idx) {
            if (idx === 0) { lines.push(fullLine(x)); return; }
            var head = (idx === arr.length - 1 ? '\u2514\u2500 ' : '\u251C\u2500 ') + x.item_name;
            if (x.single_dose) head += '\u3000' + x.single_dose;
            lines.push(head);
        });
        i = j;
    }
    return lines;
};

Clinic.order = (function () {
    /** 当前就诊ID */
    var VISIT_ID = 0;
    /** 当前开单类型 */
    var CUR_TYPE = 'lab';
    /** 提交防重入锁：请求发出至返回期间阻止重复提交（弹窗按钮 autoClose:false 双击会重复建单） */
    var SUBMITTING = false;
    /** 检验筛选：single=单个 / group=组合 */
    var LAB_FILTER = 'single';
    /** 子医嘱面板外部点击监听是否已注册 */
    var SUB_OUTER_BOUND = false;
    /** 剂量悬浮窗外部点击监听是否已注册 */
    var DOSE_OUTER_BOUND = false;
    /** 已选项目列表 */
    var SELECTED = [];
    /** 目录 id -> 名称（共享成员提醒显示用） */
    var ID_NAMES = {};
    /** 组合包含成员关系：groupId -> [memberId] */
    var GROUP_MEMBERS = {};
    /** 成员所属组合：memberId -> [groupId] */
    var MEMBER_GROUPS = {};
    /** 既往开具记录：item_id -> {name, time, order_no}（检验复查二次确认用） */
    var PREV_ITEMS = {};
    /** 频次/途径选项（管理员设置，已选列表下拉用） */
    var RX_FREQS = [];
    var RX_ROUTES = [];
    /** 待二次确认的既往项目 */
    var PENDING = null;
    /** 项目目录 / 药品下拉的 infiniteList 实例（滚动分段加载） */
    var CATALOG_LIST = null;   // 检验/检查/处置左侧目录
    var RX_LIST = null;        // 处方顶部搜索下拉
    var RX_SUB_LIST = null;    // 处方子医嘱内联下拉
    /** 处方下拉最近一次加载的关键字（焦点重显时判定是否需重置） */
    var RX_KW_LAST = '';
    /** 套餐选择器（开单弹窗快速选择套餐加入已选） */
    var PKG_PICK_LIST = null;  // 套餐列表 infiniteList 实例
    var PKG_PICK_KW = '';      // 套餐列表最近搜索关键字（焦点重显判定重置）
    var PKG_APPLY_GROUPS = []; // 套餐应用弹窗：主药+子医嘱分组（含勾选状态）
    var PKG_APPLY_LAB_MAP = null; // 套餐应用响应自带的组合/成员映射（按 ID 解析组合显示，不依赖 GROUP_MEMBERS 时序）
    /** 搜索框内分类筛选 tab 获取器（attachSearchTabs 返回；''=搜索中全量） */
    var RX_TAB_GET = null;

    /** 药品条目上下文注册表（开处方已选列表 / 处方套餐编辑器共用同一套控件渲染）：
     *  key → { list: function(){return 条目数组}, render: function(){ 重渲染 } }
     *  order.js 内部注册 'sel'（SELECTED）；packages.php 注册 'pkg'（PKG_ITEMS）。
     *  剂量悬浮窗 / 数量 / 护士 / 子医嘱 等控件全部基于 key+idx 访问条目，避免两处重复实现。 */
    var RX_CTX = {};

    /** 注册条目上下文（供剂量/子医嘱/数量等通用控件定位条目数组） */
    function rxSetCtx(key, getList, render, opts) {
        opts = opts || {};
        RX_CTX[key] = {
            list: getList, render: render,
            replaceType: opts.replaceType || '',      // 更换选择器的 type（套餐编辑器=PKG_TYPE，开单=处方）
            replaceUrlName: opts.replaceUrlName || '', // 更换选择器 url 的全局函数名（如套餐编辑器 pkgReplaceUrl；空=默认 order catalog）
        };
    }

    /** 设置频次/途径字典（套餐编辑器等外部调用方把字典写入 order.js 闭包，
     *  使共享 drugControls/qtyControls 读取到与开处方一致的下拉选项） */
    function setRxDicts(freqs, routes) {
        if (freqs) RX_FREQS = freqs;
        if (routes) RX_ROUTES = routes;
        // 字典到达后重渲染已选列表，保证下拉即时可用（若上下文中已有药品条目）
        var c = RX_CTX['sel'];
        if (c && c.render) c.render();
    }

    /** 取上下文条目：si 未传/为负 = 主药；否则为子医嘱 */
    function ctxItem(key, idx, si) {
        var c = RX_CTX[key];
        if (!c) return null;
        var arr = c.list ? c.list() : [];
        var s = arr[idx];
        if (!s) return null;
        if (si === undefined || si === null || si < 0) return s;
        return s.sub_items[si] || null;
    }

    /** 重渲染对应上下文（套餐/已选列表变化后调用） */
    function ctxRender(key) {
        var c = RX_CTX[key];
        if (c && c.render) c.render();
    }

    /**
     * 搜索框内右侧快速筛选 tab（通用：开处方/子医嘱/检验/套餐编辑器共用）。
     * 逻辑：
     * · 无搜索（输入为空）时 tab 互斥且必选一个（默认第一个或上次选中）；点 tab 过滤列表
     * · 开始搜索（输入非空）时自动取消勾选所有 tab（全量搜索），可再点 tab 在结果内筛选
     * · 清空输入框后恢复上次选中的 tab
     * @param {HTMLElement} input 搜索输入框（会被包裹进 .search-tabs-wrap）
     * @param {Array} tabs [{value,label}]
     * @param {object} state { active: 当前选中值（''=未选） }
     * @param {Function} onChange activeTab 变化回调（每次变化触发，由调用方 reset 列表）
     * @return {Function} 读取当前 active tab（''=无）的便捷方法
     */
    function attachSearchTabs(input, tabs, state, onChange) {
        if (!input || !tabs || !tabs.length) return function () { return ''; };
        var wrap = document.createElement('div');
        wrap.className = 'search-tabs-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var bar = document.createElement('div');
        bar.className = 'search-tabs';
        wrap.appendChild(bar);
        state = state || { active: tabs[0] ? tabs[0].value : '' };
        // 渲染 tab 胶囊
        function renderTabs() {
            bar.innerHTML = tabs.map(function (t) {
                var on = state.active === t.value;
                return '<span class="search-tab' + (on ? ' active' : '') + '" data-v="' + t.value + '">' + t.label + '</span>';
            }).join('');
        }
        renderTabs();
        // 阻止点击 tab 导致输入框失焦（失焦会关闭下拉，切换子tab时列表会消失）
        bar.addEventListener('mousedown', function (e) { e.preventDefault(); });
        // tab 点击：设置选中并回调（同时记录为恢复值）
        bar.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('.search-tab') : null;
            if (!el) return;
            state.active = el.getAttribute('data-v');
            state.lastActive = state.active;
            renderTabs();
            if (onChange) onChange(state.active);
        });
        // 搜索输入联动：开始搜索取消 tab，清空恢复上次选中
        input.addEventListener('input', function () {
            var q = (input.value || '').trim();
            if (q) {
                state.active = '';
                renderTabs();
            } else {
                state.active = state.lastActive || (tabs[0] ? tabs[0].value : '');
                renderTabs();
                if (onChange) onChange(state.active);
            }
        });
        // 初始化：当前选中即恢复值
        state.lastActive = state.active;
        // 暴露获取当前 active 的方法（''=搜索中全量）
        return function () { return state.active; };
    }

    /** 通用条目动作分发（开处方已选 / 处方套餐编辑器共用同一套药品控件交互）：
     *  action 支持：setField/setRoute/setNurse/changeQty/setQty/
     *              setSubField/changeSubQty/setSubQty/removeSub/removeItem
     *  args 为动作参数数组（第 0 位固定为条目下标，子医嘱动作第 1 位为子下标）
     */
    function rxCtx(key, action, args) {
        var c = RX_CTX[key];
        if (!c) return;
        var arr = c.list();
        var idx = parseInt(args[0], 10) || 0;
        var s = arr[idx];
        if (!s) return;
        var a1 = args[1], a2 = args[2], a3 = args[3];
        switch (action) {
            case 'setField':
                s[a1] = a2;
                break;
            case 'setRoute':
                s.route = a1;
                break;
            case 'setNurse':
                s.nurse_required = a1 ? 1 : 0;
                break;
            case 'changeQty': {
                var max = key === 'sel' && CUR_TYPE === 'prescription' ? qtyMaxOf(s) : 99;
                s.quantity = Math.min(max, Math.max(1, (parseInt(s.quantity, 10) || 1) + parseInt(a1, 10)));
                break;
            }
            case 'setQty': {
                var max2 = key === 'sel' && CUR_TYPE === 'prescription' ? qtyMaxOf(s) : 99;
                s.quantity = Math.min(max2, Math.max(1, parseInt(a1, 10) || 1));
                break;
            }
            case 'setUnitType': {
                // 销售单位切换（允许拆零药品：支/粒 ↔ 盒）：刷新单价/销售单位，并按单次剂量
                // 自动重算数量（始终覆盖单次用药底线），不遗留旧单位口径的错误数量
                var ut = a1 === 'min' ? 'min' : 'pack';
                if (ut === 'min' && s.allow_split !== 1) { Clinic.toast.warning('该药品不支持拆零销售，请按整包装（盒/瓶）开立！'); break; }
                s.unit_type = ut;
                applyUnit(s);
                if (s.spec_dose > 0) s.quantity = autoQty(s);
                break;
            }
            case 'removeItem':
                arr.splice(idx, 1);
                break;
            case 'setSubField': {
                var sub = s.sub_items[parseInt(a1, 10) || 0];
                if (sub) sub[a2] = a3;
                break;
            }
            case 'changeSubQty': {
                var sub2 = s.sub_items[parseInt(a1, 10) || 0];
                if (sub2) sub2.quantity = Math.min(99, Math.max(1, (parseInt(sub2.quantity, 10) || 1) + parseInt(a2, 10)));
                break;
            }
            case 'setSubQty': {
                var sub3 = s.sub_items[parseInt(a1, 10) || 0];
                if (sub3) sub3.quantity = Math.min(99, Math.max(1, parseInt(a2, 10) || 1));
                break;
            }
            case 'removeSub': {
                var sub4 = s.sub_items[parseInt(a1, 10) || 0];
                if (sub4) s.sub_items.splice(parseInt(a1, 10), 1);
                break;
            }
        }
        c.render();
    }

    /**
     * 初始化（页面加载时调用）
     */
    function init(visitId) {
        VISIT_ID = visitId;
        // 注册「开处方已选列表」条目上下文（通用控件：剂量/数量/护士/子医嘱）
        rxSetCtx('sel', function () { return SELECTED; }, renderSelected, { replaceType: 'prescription', replaceUrlName: '' });
    }

    /**
     * 打开开单弹窗
     */
    function open(type) {
        // 前置条件：病历已完善并保存（后端 order.php submit 亦有同样校验）
        if (window.Clinic.emr && typeof Clinic.emr.isRecordComplete === 'function' && !Clinic.emr.isRecordComplete()) {
            Clinic.toast.warning('请先在病历中完善主诉、现病史与初步诊断并保存，再开单');
            return;
        }
        CUR_TYPE = type;
        SELECTED = [];
        LAB_FILTER = 'single';
        RX_FREQS = [];
        RX_ROUTES = [];
        PREV_ITEMS = {};
        GROUP_MEMBERS = {};
        MEMBER_GROUPS = {};
        ID_NAMES = {};
        PENDING = null;
        // 上一弹窗残留的下拉覆盖层隐藏（复用 DOM，下次打开重定位显示）
        hideRxDrop();
        var names = { lab: '开检验', imaging: '开检查', procedure: '开处置', prescription: '开处方' };
        var prevReady = (type !== 'lab');   // 仅检验需要既往开具记录
        function tryOpen() {
            if (!prevReady) return;
            // 目录不再预加载：弹窗打开后由 infiniteList 按页加载（首屏 1 页 + 滚动续加载）
            Clinic.modal.open(renderDialog(), {
                title: names[type] || '开单',
                size: 'modal-lg order-modal',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    { text: '提交开单', cls: 'btn-success', autoClose: false, onClick: submit },
                ],
            });
            bindEvents();
            initCatalogList();
            if (type === 'prescription') initRxList();
        }
        if (type === 'lab') {
            Clinic.get('/api/order?action=prev_items&visit_id=' + VISIT_ID + '&type=lab', null, {
                onSuccess: function (j) {
                    (j.data.list || []).forEach(function (p) {
                        PREV_ITEMS[p.item_id] = p;
                    });
                    prevReady = true;
                    tryOpen();
                },
                onError: function () {
                    prevReady = true;
                    tryOpen();
                },
            });
        } else {
            tryOpen();
        }
    }

    /**
     * 构建组合/成员关系映射（互斥判断用）：由首页响应的 lab_map 全量映射构建
     * @param {object} m { names:{id:名称}, groups:{gid:[mid]}, members:{mid:[gid]} }
     */
    function buildMapsFrom(m) {
        ID_NAMES = {};
        GROUP_MEMBERS = {};
        MEMBER_GROUPS = {};
        if (!m) return;
        if (m.names) ID_NAMES = m.names;
        var g = m.groups || {};
        for (var gid in g) {
            var gi = parseInt(gid, 10);
            if (!(gi > 0)) continue;
            GROUP_MEMBERS[gi] = g[gid] || [];
            (g[gid] || []).forEach(function (mid) {
                (MEMBER_GROUPS[mid] = MEMBER_GROUPS[mid] || []).push(gi);
            });
        }
    }

    /**
     * 渲染开单对话框
     */
    function renderDialog() {
        var isDrug = CUR_TYPE === 'prescription';
        // 流程步骤（统一样式：圆形步骤节点 + 连接竖线，操作人/时间待状态推进后回填）
        var flowSteps = ['开单', '缴费', '登记', isDrug ? '药房发药' : '完成'];
        var flow = flowSteps.map(function (s, i) {
            return '<div class="flex gap-8" style="align-items:center">' +
                '<div style="width:24px;height:24px;border-radius:50%;background:var(--border);' +
                'display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0">' +
                (i + 1) + '</div>' +
                '<span class="fs-13 ' + (i === 0 ? 'fw-600' : 'text-muted') + '">' + s + '</span></div>';
        }).join('<div style="width:2px;height:16px;background:var(--border);margin-left:11px"></div>');

        // 护士站执行/处置均改为在「已选列表」逐项勾选（默认取管理员设置，医生可自由修改）
        var nurseBox = '';

        // 各开单类型的互斥规则提示
        var legend = {
            lab: '提示：组合与所含单项互斥；不同组合共享成员将提醒；既往已开具会二次确认',
            imaging: '提示：同一检查项目仅可添加一次；不同检查分类（如 CT/MR）将自动拆分为多张申请单',
            procedure: '提示：同一处置项目仅可添加一次，数量可在已选列表中手动修改',
            prescription: '提示：同一药品仅可添加一次，数量可在已选列表中手动修改',
        }[CUR_TYPE] || '';

        // 处方：独立布局——顶部搜索横条（焦点弹出药品下拉），右侧流程保留
        if (isDrug) {
            return '<div class="flex gap-16 order-flex" style="align-items:stretch">' +
                // 左：搜索横条（上）+ 已选列表（下），下拉为浮层
                '  <div style="flex:1;min-width:0;display:flex;flex-direction:column;position:relative">' +
'    <div class="flex gap-8" style="align-items:center">' +
                 '      <input type="text" class="input" id="rxKw" placeholder="🔍 点击搜索药品（名称 / 厂家简称），支持子医嘱" autocomplete="off" style="flex:1;min-width:0">' +
                 '      <button type="button" class="btn btn-outline btn-sm" id="rxPkgBtn" title="快速选择套餐一键加入" style="flex-shrink:0">🥡 套餐</button>' +
                 '    </div>' +
                 '    <div class="fs-13 text-muted mb-8 mt-8">已选 <strong id="selCount">0</strong> 项</div>' +
                 '    <div id="selList" style="flex:1;min-height:0;overflow-y:auto;padding-right:4px"></div>' +
                 '  </div>' +
                // 右：流程闭环追踪（保留）
                '  <div style="width:140px;border-left:1px solid var(--border);padding-left:16px;flex-shrink:0;display:flex;flex-direction:column;overflow-y:auto">' +
                '    <div class="fw-600 fs-13 mb-8">流程</div>' + flow +
                '    <div class="mt-16" style="background:var(--bg-soft);border-radius:8px;padding:10px">' +
                '      <div class="fs-12 text-muted">开单总费用</div>' +
                '      <div class="fs-18 fw-700" style="color:var(--danger)" id="orderTotal">¥0.00</div>' +
                '    </div>' +
                '  </div>' +
                '</div>';
        }

        return '<div class="flex gap-16 order-flex" style="align-items:stretch">' +
            // 左：项目显示与搜索（较窄）
            '  <div style="width:240px;flex-shrink:0;display:flex;flex-direction:column">' +
            '    <div class="flex gap-8" style="align-items:center">' +
            '      <input type="text" class="input" id="orderKw" placeholder="搜索' +
            (isDrug ? '药品名称/厂家简称' : '项目名称') + '" autocomplete="off" style="flex:1;min-width:0">' +
            '      <button type="button" class="btn btn-outline btn-sm" id="orderPkgBtn" title="快速选择套餐一键加入" style="flex-shrink:0">🥡 套餐</button>' +
            '    </div>' +
            (CUR_TYPE === 'lab' ? labFilterBar() : '') +
            '    <div class="order-catalog" id="orderCatalog" style="flex:1;min-height:0;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-top:8px">' +
            '<div class="text-center" style="padding:24px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>' +
            '    <div class="fs-12 text-muted mt-8" style="line-height:1.6">' + legend + '</div>' +
            '  </div>' +
            // 中：已选项目（大块）
            '  <div style="flex:1;min-width:0;display:flex;flex-direction:column">' +
            '    <div id="prevConfirm" style="display:none;background:var(--warning-soft);border:1px solid var(--warning);border-radius:8px;padding:10px;font-size:13px;margin-bottom:8px"></div>' +
            '    <div class="fs-13 text-muted mb-8">已选 <strong id="selCount">0</strong> 项</div>' +
            '    <div id="selList" style="flex:1;min-height:0;overflow-y:auto;padding-right:4px"></div>' +
            '  </div>' +
            // 右：流程闭环追踪（保留）
            '  <div style="width:140px;border-left:1px solid var(--border);padding-left:16px;flex-shrink:0;display:flex;flex-direction:column;overflow-y:auto">' +
            '    <div class="fw-600 fs-13 mb-8">流程</div>' + flow +
            '    <div class="mt-16" style="background:var(--bg-soft);border-radius:8px;padding:10px">' +
            '      <div class="fs-12 text-muted">开单总费用</div>' +
            '      <div class="fs-18 fw-700" style="color:var(--danger)" id="orderTotal">¥0.00</div>' +
            '    </div>' + nurseBox +
            '  </div>' +
            '</div>';
    }

    /**
     * 检验筛选徽章：单个 / 组合
     */
    function labFilterBar() {
        var opts = [['single', '单个'], ['group', '组合']];
        return '<div id="labFilterBar" class="flex gap-4" style="margin-top:8px">' +
            opts.map(function (o) {
                return '<span class="qp-chip' + (LAB_FILTER === o[0] ? ' active' : '') + '" data-f="' + o[0] + '" style="padding:2px 12px;font-size:12px">' + o[1] + '</span>';
            }).join('') + '</div>';
    }

    /** 检验筛选徽章 UI 与「搜索中取消勾选」联动：搜索时全部取消，清空后恢复 LAB_FILTER 选中 */
    function updateLabFilterUI() {
        var isSearching = ((document.getElementById('orderKw') || {}).value || '').trim() !== '';
        document.querySelectorAll('#labFilterBar .qp-chip').forEach(function (c) {
            var on = !isSearching && LAB_FILTER === c.getAttribute('data-f');
            c.classList.toggle('active', on);
        });
    }

    /** 目录条目 HTML（分页渲染每行；已选项目置灰标识） */
    function catalogItemHtml(it) {
        var sel = SELECTED.some(function (s) { return s.id === it.id; });
        var info = '';
        if (it.is_group) {
            // 检验组合：显示组内成员，按组价整体收费
            info = '<div class="fs-12 text-muted">🧩 组合项目 ｜ 含：' + Clinic.escHtml(it.members || it.spec || '') +
                '（按组价整体收费）</div>';
        }
        return '<div class="dd-item' + (sel ? ' dd-sel' : '') + '" data-id="' + it.id + '" ' +
            'data-price="' + (it.price || 0) + '" data-name="' + Clinic.escHtml(it.name || '') + '"' +
            ' data-spec="' + Clinic.escHtml(it.spec || '') + '" data-unit="' + Clinic.escHtml(it.unit || '') + '"' +
            ' data-company="' + Clinic.escHtml(it.company_short || '') + '"' +
            ' data-dose="' + Clinic.escHtml(it.single_dose || '') + '"' +
            ' data-freq="' + Clinic.escHtml(it.frequency || '') + '"' +
            ' data-route="' + Clinic.escHtml(it.route || '') + '"' +
            ' data-route-nurse="' + (it.route_nurse_required || 0) + '"' +
            ' data-stock="' + (it.stock || 0) + '"' +
            ' data-nurse-req="' + (it.nurse_required || 0) + '"' +
            ' data-need-skin-test="' + (it.is_skin_test || 0) + '"' +
            ' data-is-group="' + (it.is_group ? 1 : 0) + '"' +
            ' data-members="' + Clinic.escHtml(it.member_ids || '') + '"' +
            '>' +
            '<div class="flex-between">' +
            '  <div><span class="fw-600">' + Clinic.escHtml(it.name || '') + '</span>' +
            (it.is_group ? ' <span class="badge badge-primary fs-12">组合</span>' : '') +
            (it.category_name ? ' <span class="badge badge-gray fs-12">' + Clinic.escHtml(it.category_name) + '</span>' : '') +
            '</div>' +
            '  <div class="text-right">' +
            '    <div class="fw-600" style="color:var(--primary)">¥' + parseFloat(it.price || 0).toFixed(2) + '</div>' +
            '  </div></div>' + info +
            '</div>';
    }

    /** 目录分页接口地址（搜索关键字 / 检验筛选实时参与拼接；搜索时忽略单个/组合筛选） */
    function catalogUrl(p, size) {
        var kw = encodeURIComponent((document.getElementById('orderKw') || {}).value || '');
        // 搜索时自动取消单个/组合筛选（全量搜索）；清空后恢复筛选
        var isSearching = (document.getElementById('orderKw') || {}).value ? ((document.getElementById('orderKw') || {}).value || '').trim() !== '' : false;
        var f = CUR_TYPE === 'lab' ? (isSearching ? '' : LAB_FILTER) : '';
        return '/api/order?action=catalog&type=' + CUR_TYPE + '&page=' + p + '&size=' + size + '&kw=' + kw + '&f=' + f;
    }

    /** 重置目录列表到第一页（搜索关键字 / 单个组合切换时调用） */
    function catalogReset() {
        if (CATALOG_LIST) CATALOG_LIST.reset();
        else initCatalogList();
    }

    /** 初始化目录无限滚动列表（检验/检查/处置左侧，滚动到底部续加载） */
    function initCatalogList() {
        var box = document.getElementById('orderCatalog');
        if (!box) return;
        if (CATALOG_LIST) CATALOG_LIST.stop();
        CATALOG_LIST = Clinic.infiniteList({
            el: box,
            pageSize: 20,
            threshold: 40,
            emptyHtml: '<div class="dd-empty" style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">暂无可选项目，请先联系管理员添加</div>',
            url: catalogUrl,
            render: function (list, isFirst) {
                return list.map(catalogItemHtml).join('');
            },
            onSuccess: function (json) {
                // 首页响应携带组合映射 + 联动字典（后续分页不再重复携带）
                var d = json.data || {};
                if (d.lab_map) buildMapsFrom(d.lab_map);
                if (d.link_dicts) {
                    RX_FREQS = d.link_dicts.frequencies || [];
                    RX_ROUTES = d.link_dicts.routes || [];
                }
            },
            onError: function () {
                // 首次加载失败：展示空态提示，避免停留加载态
                if (box.querySelector('.spinner')) {
                    box.innerHTML = '<div class="dd-empty" style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">加载失败，请重试</div>';
                }
            },
        });
    }

    /* ============ 处方：搜索下拉（顶部主药 + 子医嘱共用） ============ */

    /** 药品下拉条目：第一行 名称+厂商+金额；第二行 频次/途径（仅主药）/分类/规格/库存 */
    function rxItemHtml(it, showRx) {
        var vendor = it.company_short
            ? '<span class="fs-12 text-muted" style="margin-left:6px;flex-shrink:0">' + Clinic.escHtml(it.company_short) + '</span>'
            : '';
        var parts = [];
        if (it.category_name) parts.push(it.category_name);
        if (it.spec) parts.push('规格 ' + it.spec);
        if (showRx) {
            if (it.frequency) parts.push('频次 ' + it.frequency);
            if (it.route) parts.push('途径 ' + it.route);
        }
        parts.push('库存 ' + (it.allow_split ? stockText(it, 'min') : stockText(it, 'pack')));
        return '<div class="rx-drop-item" data-id="' + it.id + '" ' +
            'data-price="' + (it.price || 0) + '" data-name="' + (it.name || '').replace(/"/g, '&quot;') + '"' +
            ' data-spec="' + (it.spec || '') + '" data-unit="' + (it.unit || '') + '"' +
            ' data-company="' + (it.company_short || '') + '"' +
            ' data-dose="' + (it.single_dose || '') + '"' +
            ' data-freq="' + (it.frequency || '') + '"' +
            ' data-route="' + (it.route || '') + '"' +
            ' data-route-nurse="' + (it.route_nurse_required || 0) + '"' +
            ' data-stock="' + (it.stock || 0) + '"' +
            ' data-nurse-req="' + (it.nurse_required || 0) + '"' +
            ' data-need-skin-test="' + (it.is_skin_test || 0) + '"' +
' data-spec-dose="' + (it.spec_dose || 0) + '"' +
             ' data-spec-dose-unit="' + (it.spec_dose_unit || '') + '"' +
             ' data-spec-pack-qty="' + (it.spec_pack_qty || 1) + '"' +
             ' data-spec-pack-unit="' + (it.spec_pack_unit || '') + '"' +
             ' data-single-use-qty="' + (it.single_use_qty || 1) + '"' +
             ' data-allow-split="' + (it.allow_split || 0) + '"' +
             ' data-is-group="0">' +
            '<div class="flex-between">' +
            '  <div class="fw-600 fs-13 ellipsis" style="display:flex;align-items:baseline;min-width:0">' +
            Clinic.escHtml(it.name || '') + vendor + '</div>' +
            '  <div class="fw-600 fs-13" style="color:var(--primary);flex-shrink:0">¥' + parseFloat(it.price || 0).toFixed(2) + '</div>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-2" style="line-height:1.5">' + parts.join(' ｜ ') + '</div>' +
            '</div>';
    }

    /** 处方药品下拉分页接口地址（搜索关键字 + 分类 tab 实时参与拼接） */
    function rxUrl(p, size) {
        var kw = encodeURIComponent((document.getElementById('rxKw') || {}).value || '');
        var cat = RX_TAB_GET ? RX_TAB_GET() : '';
        return '/api/order?action=catalog&type=prescription&page=' + p + '&size=' + size + '&kw=' + kw + '&cat=' + encodeURIComponent(cat);
    }

    /** 重置药品下拉到第一页（关键字输入 / 焦点重显时调用） */
    function rxReset() {
        if (RX_LIST) RX_LIST.reset();
        else initRxList();
    }

    /** 初始化顶部药品下拉无限滚动列表（聚焦弹出即加载，滚动续加载）
     * 下拉为 document.body 上的 fixed 覆盖层（避开模态框 transform 造成的定位/滚动干扰） */
    function ensureRxDrop() {
        var box = document.getElementById('rxDrop');
        if (box) return box;
        box = document.createElement('div');
        box.id = 'rxDrop';
        box.style.cssText = 'display:none;position:fixed;top:44px;left:0;right:0;z-index:1500;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);max-height:300px;overflow-y:auto';
        document.body.appendChild(box);
        box.addEventListener('mousedown', function (e) {
            var el = e.target.closest ? e.target.closest('.rx-drop-item') : null;
            if (el) { e.preventDefault(); pickRx(el); }
        });
        return box;
    }

    function initRxList() {
        // 每次打开重置搜索 tab 句柄（输入框为每次新建，旧句柄指向已移除元素）
        RX_TAB_GET = null;
        var box = ensureRxDrop();
        if (!box) return;
        if (RX_LIST) RX_LIST.stop();
        RX_LIST = Clinic.infiniteList({
            el: box,
            pageSize: 15,
            threshold: 40,
            emptyHtml: '<div class="rx-drop-empty">未找到相关药品</div>',
            url: rxUrl,
            render: function (list, isFirst) {
                return list.map(function (it) { return rxItemHtml(it, true); }).join('');
            },
            onSuccess: function (json) {
                RX_KW_LAST = ((document.getElementById('rxKw') || {}).value || '').trim().toLowerCase();
                // 目录首页响应携带频次/途径选项字典（处方已选列表下拉用）
                var d = json.data || {};
                if (d.link_dicts) {
                    RX_FREQS = d.link_dicts.frequencies || [];
                    RX_ROUTES = d.link_dicts.routes || [];
                    // 首次拿到分类后，在搜索框内右侧附加分类筛选 tab（开处方搜索框）
                    var rxk = document.getElementById('rxKw');
                    if (rxk && !RX_TAB_GET && d.link_dicts.categories && d.link_dicts.categories.length) {
                        RX_TAB_GET = attachSearchTabs(rxk,
                            [].concat([{ value: '', label: '全部' }], d.link_dicts.categories.map(function (c) { return { value: c, label: c }; })),
                            null,
                            function () { rxReset(); }
                        );
                    }
                    // 字典到达后重渲染已选列表，保证频次/途径下拉即时可用
                    renderSelected();
                }
            },
            onError: function () {
                var b = document.getElementById('rxDrop');
                if (b && !b.querySelector('.rx-drop-item')) {
                    b.innerHTML = '<div class="rx-drop-empty">加载失败，请重试</div>';
                }
            },
        });
    }

    function showRxDrop() {
        var box = ensureRxDrop();
        if (!box) return;
        var kw = document.getElementById('rxKw');
        if (kw) {
            // 按输入框实际位置定位（fixed 覆盖层：滚动事件直达下拉容器，不受模态框干扰）
            var r = kw.getBoundingClientRect();
            box.style.left = r.left + 'px';
            box.style.top = (r.bottom + 2) + 'px';
            box.style.width = r.width + 'px';
        }
        box.style.display = '';
    }

    function hideRxDrop() {
        var box = document.getElementById('rxDrop');
        if (box) box.style.display = 'none';
    }

    /** 顶部下拉选中药品：清空搜索、收起下拉、加入已选列表 */
    function pickRx(el) {
        var it = itemFromEl(el);
        var kw = document.getElementById('rxKw');
        if (kw) { kw.value = ''; kw.blur(); }
        hideRxDrop();
        handleAdd(it, el);
    }

    /* ============ 套餐快速选择（搜索 + 勾选一键加入已选列表） ============ */

    /** 套餐列表分页接口地址（搜索关键字实时参与拼接，按当前开单类型过滤） */
    function pkgPickUrl(p, size) {
        var kw = encodeURIComponent(PKG_PICK_KW);
        return '/api/package?action=list&type=' + CUR_TYPE + '&page=' + p + '&size=' + size + '&kw=' + kw;
    }

    /** 套餐列表行 HTML */
    function pkgPickRowHtml(p) {
        var scopeMap = { personal: '个人', dept: '科室', hospital: '全院' };
        var statusMap = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
        var scopeCls = { personal: 'badge-gray', dept: 'badge-primary', hospital: 'badge-primary' };
        var statusCls = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };
        return '<div class="dd-item pkg-pick-item" data-id="' + p.id + '" ' +
            'data-title="' + (p.title || '').replace(/"/g, '&quot;') + '" style="cursor:pointer;padding:8px 10px;border-bottom:1px solid var(--border)">' +
            '<div class="flex-between">' +
            '  <span class="fw-600 fs-13 ellipsis" style="min-width:0">' + Clinic.escHtml(p.title || '') + '</span>' +
            '  <span class="fw-600 fs-13" style="color:var(--primary);flex-shrink:0">¥' + parseFloat(p.total_price || 0).toFixed(2) + '</span>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-2">' +
            '<span class="badge ' + (scopeCls[p.scope] || 'badge-gray') + '">' + (scopeMap[p.scope] || p.scope) + '</span> ' +
            '<span class="badge ' + (statusCls[p.status] || 'badge-gray') + '">' + (statusMap[p.status] || p.status) + '</span>' +
            ' ｜ 共 ' + (p.item_count || 0) + ' 项' +
            (p.dept_names && p.dept_names.length ? ' ｜ 适用：' + Clinic.escHtml(p.dept_names.join('、')) : '') +
            '</div></div>';
    }

    /** 打开套餐选择器：搜索栏 + 无限滚动套餐列表 */
    function openPkgPicker() {
        var typeLabel = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' }[CUR_TYPE] || '开单';
        PKG_PICK_KW = '';
        PKG_PICK_LIST = null;
        var html =
            '<div style="display:flex;flex-direction:column;gap:8px">' +
            '  <input type="text" class="input" id="pkgPickKw" placeholder="🔍 搜索' + typeLabel + '套餐名称" autocomplete="off">' +
            '  <div id="pkgPickList" style="max-height:420px;min-height:160px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:4px"></div>' +
            '  <div class="fs-12 text-muted">点击套餐后弹出项目勾选，确认后一键加入已选列表</div>' +
            '</div>';
        Clinic.modal.open(html, {
            title: '🥡 选择' + typeLabel + '套餐',
            size: 'modal-md',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        });
        var kw = document.getElementById('pkgPickKw');
        kw.addEventListener('input', function () {
            clearTimeout(kw.__t);
            kw.__t = setTimeout(function () {
                PKG_PICK_KW = (kw.value || '').trim().toLowerCase();
                if (PKG_PICK_LIST) PKG_PICK_LIST.reset();
                else initPkgPickList();
            }, 300);
        });
        // 点击套餐行：关闭选择器，打开项目勾选弹窗
        var listBox = document.getElementById('pkgPickList');
        listBox.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('.pkg-pick-item') : null;
            if (el) {
                var pid = parseInt(el.getAttribute('data-id'), 10);
                var ptitle = el.getAttribute('data-title') || '';
                Clinic.modal.close();
                openPkgApply(pid, ptitle);
            }
        });
        initPkgPickList();
    }

    /** 初始化套餐列表无限滚动（搜索输入 / 打开时调用） */
    function initPkgPickList() {
        var box = document.getElementById('pkgPickList');
        if (!box) return;
        if (PKG_PICK_LIST) PKG_PICK_LIST.stop();
        PKG_PICK_LIST = Clinic.infiniteList({
            el: box,
            pageSize: 20,
            threshold: 40,
            emptyHtml: '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">暂无可用套餐，可到「套餐管理」创建</div>',
            url: pkgPickUrl,
            render: function (list) { return list.map(pkgPickRowHtml).join(''); },
            onError: function () {
                if (box && !box.querySelector('.dd-item')) {
                    box.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">加载失败，请重试</div>';
                }
            },
        });
    }

    /** 套餐项目分组：sub_of=0 为主药，其余挂到对应主药下（成组医嘱整体勾选）。
     * 携带失效标记：主药 valid=0 则整组失效（改名/删除/缺货/信息变更）。 */
    function pkgBuildGroups(items) {
        var groups = [];
        (items || []).forEach(function (it) {
            if ((it.sub_of || 0) === 0) groups.push({ main: it, subs: [], checked: it.valid !== 0, valid: it.valid !== 0, reason: it.invalid_reason || '' });
        });
        (items || []).forEach(function (it) {
            var s = parseInt(it.sub_of, 10) || 0;
            if (s > 0 && groups[s - 1]) {
                groups[s - 1].subs.push(it);
                if (it.valid === 0) { groups[s - 1].valid = false; if (!groups[s - 1].reason) groups[s - 1].reason = it.invalid_reason || ''; }
            }
        });
        return groups;
    }

    /** 渲染套餐应用弹窗：每个项目一个复选框（处方组合 = 主药+子医嘱树形）；
     * 失效项目：删除线 + 灰色 + 复选框禁用，全选/确认均不纳入 */
    function renderPkgApply() {
        var box = document.getElementById('pkgApplyList');
        if (!box) return;
        var isDrug = CUR_TYPE === 'prescription';
        box.innerHTML = PKG_APPLY_GROUPS.map(function (g, i) {
            var m = g.main || {};
            var invalid = !g.valid;
            // 组合徽标紧跟名称显示（在 meta 行内，不换行）；成员标签单独放下方
            var badgeHtml = (!isDrug && m.is_group) ? ' <span class="badge badge-primary fs-12" style="flex-shrink:0">组合</span>' : '';
            // 失效样式：主行灰字+删除线；复选框禁用
            var nameHtml = '<span class="fw-600 fs-13' + (invalid ? ' pkg-invalid' : '') + '">' + Clinic.escHtml(m.item_name || '') + '</span>';
            var meta = nameHtml + badgeHtml +
                (m.spec && !isDrug && !m.is_group ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(m.spec) + '</span>' : '') +
                (isDrug ? ' <span class="fs-12 text-muted">' +
                    [m.single_dose, m.frequency, m.route].filter(function (x) { return x; }).join(' ') + '</span>' : '') +
                (m.quantity > 1 ? ' <span class="badge badge-primary fs-12">×' + m.quantity + '</span>' : '') +
                ((isDrug && m.is_skin_test) ? ' <span class="badge badge-danger fs-12">需皮试</span>' : '') +
                (invalid ? ' <span class="badge badge-gray fs-12" title="' + Clinic.escHtml(g.reason || '') + '">已失效</span>' : '');
            // 成员标签：按组合 ID 解析（GROUP_MEMBERS 优先，套餐响应 lab_map 兜底）
            var groupChips = '';
            if (!isDrug && m.is_group) {
                var mids = GROUP_MEMBERS[m.item_id] || [];
                var lm = PKG_APPLY_LAB_MAP || {};
                if (!mids.length && lm.groups && lm.groups[m.item_id]) mids = lm.groups[m.item_id];
                if (mids.length) {
                    var nameOf = function (mid) {
                        return (lm.names && lm.names[mid]) || ID_NAMES[mid] || ('检验项目#' + mid);
                    };
                    groupChips = '<div class="fs-12 text-muted" style="margin:4px 0 0 24px;line-height:1.8">含：' +
                        mids.map(function (mid) {
                            return '<span style="display:inline-block;padding:0 7px;border:1px solid var(--border);border-radius:4px;background:var(--bg-soft);color:var(--text-muted);font-size:12px;line-height:1.7;white-space:nowrap;margin:0 3px 2px 0">' +
                                Clinic.escHtml(nameOf(mid)) + '</span>';
                        }).join('') + '</div>';
                }
            }
            var html = '<div style="border:1px solid ' + (invalid ? 'var(--border)' : 'var(--border)') + ';border-radius:8px;padding:8px 10px;margin-bottom:6px;' +
                (invalid ? 'background:var(--bg-soft);cursor:not-allowed' : 'cursor:pointer;background:var(--bg-card)') + '" ' +
                (invalid ? '' : 'onclick="var cb=this.querySelector(\'.pkg-apply-cb\');cb.checked=!cb.checked;Clinic.order.setPkgApplyCheck(' + i + ',cb.checked)"') +
                '>' +
                '<div class="flex-between" style="align-items:center">' +
                '  <input type="checkbox" class="pkg-apply-cb" data-i="' + i + '"' + (g.checked ? ' checked' : '') +
                (invalid ? ' disabled' : '') +
                ' style="width:16px;height:16px;accent-color:var(--primary);flex-shrink:0" ' +
                'onclick="event.stopPropagation()" onchange="Clinic.order.setPkgApplyCheck(' + i + ',this.checked)">' +
                '  <span class="meta" style="flex:1;min-width:0;padding:0 8px;display:flex;align-items:center;flex-wrap:wrap">' + meta + '</span>' +
                '  <span style="font-size:12px;color:var(--text-muted);flex-shrink:0">¥' + ((parseFloat(m.price) || 0) * (m.quantity || 1)).toFixed(2) + '</span>' +
                '</div>' +
                groupChips +
                (invalid && g.reason ? '<div class="fs-12" style="color:var(--danger);margin:4px 0 0 24px">' + Clinic.escHtml(g.reason) + '</div>' : '') +
                (g.subs.length ? g.subs.map(function (s, si) {
                    var branch = si === g.subs.length - 1 ? '└─' : '├─';
                    var sInvalid = s.valid === 0;
                    return '<div style="font-family:Menlo,Consolas,monospace;font-size:12px;color:var(--text-muted);margin:2px 0 2px 24px">' +
                        branch + ' ' + (sInvalid ? '<span class="pkg-invalid">' : '') + Clinic.escHtml(s.item_name || '') +
                        (s.single_dose ? ' ｜ ' + Clinic.escHtml(s.single_dose) : '') +
                        (sInvalid ? '</span>' : '') +
                        ' ｜ ¥' + ((parseFloat(s.price) || 0) * (s.quantity || 1)).toFixed(2) + '</div>';
                }).join('') : '') +
                '</div>';
            return html;
        }).join('') || '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">套餐暂无项目</div>';
    }

    /** 套餐应用弹窗：加载套餐内容并按项目分组勾选 */
    function openPkgApply(id, title) {
        Clinic.ajax('/api/package', { action: 'get', id: id, for_apply: 1 }, {
            loading: true,
            onSuccess: function (j) {
                var p = j.data && j.data.package;
                if (!p) return;
                PKG_APPLY_GROUPS = pkgBuildGroups(p.items || []);
                PKG_APPLY_LAB_MAP = (j.data && j.data.lab_map) || null;
                var invalidCnt = PKG_APPLY_GROUPS.filter(function (g) { return !g.valid; }).length;
                var html =
                    '<div class="fs-12 text-muted mb-8">套餐共 ' + (p.items || []).length + ' 项' +
                    (invalidCnt ? '，其中 <span style="color:var(--danger)">' + invalidCnt + ' 项已失效</span>（改名/删除/缺货/信息变更，灰色不可勾选）' : '') +
                    '（处方成组医嘱按「主药+子医嘱」整体勾选）：</div>' +
                    '<div id="pkgApplyList" style="max-height:400px;overflow-y:auto;padding-right:4px"></div>';
                var pkgMask = Clinic.modal.open(html, {
                    title: '添加套餐：' + Clinic.escHtml(p.title || title || ''),
                    size: 'modal-md',
                });
                // 底部按钮：全选靠左对齐，取消/确认靠右（不放在 buttons 数组里，以便自定义布局）
                if (pkgMask) {
                    var foot = pkgMask.querySelector('.modal-foot');
                    if (foot) {
                        foot.innerHTML =
                            '<div style="display:flex;align-items:center;justify-content:space-between;width:100%">' +
                            '  <button type="button" class="btn btn-outline" style="padding:8px 16px" onclick="Clinic.order.pkgApplyToggleAll()">☑️ 全选</button>' +
                            '  <div class="flex gap-10" style="align-items:center">' +
                            '    <button type="button" class="btn btn-outline" style="padding:8px 16px" onclick="Clinic.modal.close()">取消</button>' +
                            '    <button type="button" class="btn btn-primary" style="padding:8px 16px" onclick="Clinic.order.pkgApplyConfirm()">确认添加</button>' +
                            '  </div>' +
                            '</div>';
                    }
                }
                renderPkgApply();
            },
        });
    }

    /** 全选 / 全不选切换：仅有效项参与全选；失效项始终不勾选 */
    function pkgApplyToggleAll() {
        var validGroups = PKG_APPLY_GROUPS.filter(function (g) { return g.valid; });
        var all = validGroups.length && validGroups.every(function (g) { return g.checked; });
        PKG_APPLY_GROUPS.forEach(function (g) {
            if (g.valid) g.checked = !all;
            else g.checked = false;
        });
        renderPkgApply();
    }

    /** 单项勾选（复选框 onchange）：失效项不允许勾选 */
    function setPkgApplyCheck(i, checked) {
        var g = PKG_APPLY_GROUPS[i];
        if (g && g.valid) g.checked = !!checked;
    }

    /** 套餐项目 → 开单 SELECTED 条目结构（结构化药品剂量 = 数量×单剂量值，与数量自洽） */
    function pkgToOrderItem(it, isSub) {
        var sd = parseFloat(it.spec_dose) || 0;
        // 套餐固化的开立单位（pack/min，保存时随套餐落库）；缺省按拆零标记回退；
        // 不允许拆零的药品强制按包装单位（前端不可选最小单位，后端同步硬校验）
        var unitType = (parseInt(it.allow_split, 10) === 1 && it.unit_type === 'min') ? 'min' : 'pack';
        var base = {
            id: parseInt(it.item_id, 10) || 0,
            name: it.item_name || '',
            price: parseFloat(it.price) || 0,
            // 包装单价（整盒售价）：套餐固化 pack_price 优先；min 单位时 price 为拆零价 → 反推包装价
            pack_price: parseFloat(it.pack_price) > 0
                ? parseFloat(it.pack_price)
                : (parseFloat(it.price) || 0) * (unitType === 'min' ? Math.max(1, parseInt(it.spec_pack_qty, 10) || 1) : 1),
            quantity: Math.max(1, parseInt(it.quantity, 10) || 1),
            spec: it.spec || '',
            unit: it.unit || '',
            company_short: it.company_short || '',
            frequency: isSub ? '' : (it.frequency || ''),
            route: isSub ? '' : (it.route || ''),
            route_nurse: it.route_nurse_required || it.nurse_required || 0,
            stock: parseInt(it.stock, 10) || 0,
            nurse_required: isSub ? 0 : (it.nurse_required || 0),
            is_group: parseInt(it.is_group, 10) === 1,
            member_ids: it.member_ids || '',
            members: it.members || it.spec || '',
            sub_items: [],
            spec_pack_unit: it.spec_pack_unit || '',
            // v8.17 拆零销售：允许拆零标记 / 包装单位 / 最小单位 / 开立单位
            allow_split: parseInt(it.allow_split, 10) === 1 ? 1 : 0,
            pack_unit: it.pack_unit || it.unit || '',
            min_unit: it.min_unit || it.spec_pack_unit || '',
            unit_type: unitType,
            is_skin_test: parseInt(it.is_skin_test, 10) === 1 ? 1 : 0,
            skin_test: '',
        };
        if (CUR_TYPE === 'prescription' && sd > 0) {
            base.spec_dose = sd;
            // 单次剂量：优先取套餐固化的 single_dose 文本数值（如 0.6g → 0.6）；
            // 数量按开立单位自动调整到覆盖单次剂量所需（自动校正套餐导入时的错误盒数/支数）
            var sdn = parseFloat(String(it.single_dose || '').replace(/[^\d.]/g, ''));
            base.dose = (sdn > 0) ? sdn : Math.round(base.quantity * sd * (unitType === 'min' ? 1 : Math.max(1, parseInt(it.spec_pack_qty, 10) || 1)) * 100) / 100;
            base.dose_unit = it.spec_dose_unit || '';
            base.quantity = autoQty(base);
        } else {
            base.spec_dose = 0;
            base.dose = it.single_dose || '';
            base.dose_unit = '';
        }
        applyUnit(base);
        return base;
    }

    /** 确认添加：勾选项目去重后逐项加入 SELECTED（皮试药品逐项二次确认） */
    function pkgApplyConfirm() {
        var groups = PKG_APPLY_GROUPS.filter(function (g) { return g.valid && g.checked && g.main; });
        if (!groups.length) { Clinic.toast.warning('请至少勾选一个项目'); return; }
        var queue = [];
        var skipped = [];
        groups.forEach(function (g) {
            var m = g.main;
            var dupIds = [m.item_id].concat((g.subs || []).map(function (s) { return s.item_id; }));
            var conflict = SELECTED.some(function (s) {
                if (dupIds.indexOf(s.id) !== -1) return true;
                return (s.sub_items || []).some(function (sub) { return dupIds.indexOf(sub.id) !== -1; });
            });
            if (conflict) { skipped.push(m.item_name); return; }
            var item = pkgToOrderItem(m);
            item.sub_items = (g.subs || []).map(function (s) { return pkgToOrderItem(s, true); });
            queue.push(item);
        });
        if (skipped.length) Clinic.toast.warning('以下项目已在已选列表中，已跳过：' + skipped.join('、'));
        // 处方套餐：数量超出库存 → 正常加入但提示库存不足（提交处方时另有强制数量校验）
        if (CUR_TYPE === 'prescription') {
            var lowStock = queue.filter(function (q) {
                return q.stock > 0 && q.quantity > q.stock;
            }).map(function (q) { return q.name + '（需求' + q.quantity + '，库存' + q.stock + '）'; });
            var subLow = [];
            queue.forEach(function (q) {
                (q.sub_items || []).forEach(function (sub) {
                    if (sub.stock > 0 && sub.quantity > sub.stock) subLow.push(sub.name);
                });
            });
            var allLow = lowStock.concat(subLow);
            if (allLow.length) {
                setTimeout(function () { Clinic.toast.warning('以下药品库存不足，已加入但请在提交前调整数量：' + allLow.join('、')); }, 400);
            }
        }
        if (!queue.length) { renderSelected(); return; }
        Clinic.modal.close();
        pkgApplyPushQueue(queue, 0);
    }

    /** 递归入列：需皮试药品逐个弹出确认（复用开方皮试确认），完成后刷新已选 */
    function pkgApplyPushQueue(queue, idx) {
        if (idx >= queue.length) { renderSelected(); return; }
        var it = queue[idx];
        if (CUR_TYPE === 'prescription' && it.is_skin_test === 1) {
            mustConfirmSkinTest(it.name, function (choice) {
                if (choice === 'cancel') { pkgApplyPushQueue(queue, idx + 1); return; }
                it.skin_test = choice;
                SELECTED.push(it);
                pkgApplyPushQueue(queue, idx + 1);
            });
            return;
        }
        SELECTED.push(it);
        pkgApplyPushQueue(queue, idx + 1);
    }

    /* ============ 子医嘱：跟随鼠标的内联搜索下拉（替换原模态框选择） ============ */

    /* ============ 通用项目选择器（子医嘱 / 更换 共用：搜索+无限滚动，数据跟随 type 切换） ============ */

    var PICKER_BOUND = false;   // 面板全局监听是否已注册
    var PICKER_LIST = null;     // 选择器列表 infiniteList

    /** HTML 属性转义（JSON 字符串嵌入 data-it 用） */
    function escHtmlAttr(s) {
        return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /** 通用选择器条目 HTML（按类型渲染：prescription 药品 / lab / imaging / procedure） */
    function pickerItemHtml(it, type) {
        var name = '<span class="fw-600">' + Clinic.escHtml(it.name || '') + '</span>';
        var extra = '';
        if (type === 'prescription') {
            extra = (it.company_short ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(it.company_short) + '</span>' : '') +
                ' <span class="fs-12 text-muted">¥' + parseFloat(it.price || 0).toFixed(2) + '</span>' +
                // 3.5 库存单位联动：允许拆零→最小单位展示（含整包装折算）；否则包装单位
                ' <span class="fs-12 text-muted">库存' + (it.allow_split ? stockText(it, 'min') : stockText(it, 'pack')) + '</span>';
        } else {
            if (type === 'lab' && it.is_group) {
                name += ' <span class="badge badge-primary fs-12">组合</span>';
                extra = ' <span class="fs-12 text-muted">含：' + Clinic.escHtml(it.members || it.spec || '') + '</span>';
            }
            if (it.category_name) extra += ' <span class="badge badge-gray fs-12">' + Clinic.escHtml(it.category_name) + '</span>';
            extra += ' <span class="fs-12 text-muted">¥' + parseFloat(it.price || 0).toFixed(2) + '</span>';
        }
        return '<div class="rx-drop-item" data-it="' + escHtmlAttr(JSON.stringify(it)) + '">' +
            name + extra + '</div>';
    }

    /** 通用项目选择器悬浮窗（body fixed 覆盖层）：
     * opts = { btn, type, url(p,size), placeholder, onPick(it) } */
    function openItemPicker(opts) {
        opts = opts || {};
        if (!opts.btn || !opts.type) return;
        var rect = opts.btn.getBoundingClientRect();
        var panel = document.getElementById('rxItemPicker');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'rxItemPicker';
            panel.style.cssText = 'position:fixed;z-index:3200;width:380px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);overflow:hidden';
            document.body.appendChild(panel);
            if (!PICKER_BOUND) {
                PICKER_BOUND = true;
                document.addEventListener('mousedown', function (e) {
                    var p = document.getElementById('rxItemPicker');
                    if (p && p.style.display !== 'none' && !p.contains(e.target)) p.style.display = 'none';
                }, true);
                document.addEventListener('mousedown', function (e) {
                    var el = e.target.closest ? e.target.closest('.rx-drop-item') : null;
                    if (!el) return;
                    var p = document.getElementById('rxItemPicker');
                    if (!p || p.style.display === 'none' || !p.contains(e.target)) return;
                    e.preventDefault();
                    var it = JSON.parse(el.getAttribute('data-it') || '{}');
                    p.style.display = 'none';
                    // 使用面板最新回调（面板复用，闭包 opts 可能来自首次调用导致串台）
                    if (p.__onPick) p.__onPick(it);
                }, true);
            }
        }
        // 每次打开更新回调（面板复用，条目点击委托读取最新回调）
        panel.__onPick = opts.onPick || null;
        panel.innerHTML =
            '<div style="padding:8px 10px;border-bottom:1px solid var(--border)">' +
            '<input type="text" class="input" id="pickerKw" placeholder="' + (opts.placeholder || '🔍 搜索项目') + '" autocomplete="off" style="min-height:30px;padding:5px 10px">' +
            '</div>' +
            '<div id="pickerList" style="max-height:260px;overflow-y:auto"><div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>';
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 388)) + 'px';
        panel.style.top = (rect.bottom + 4) + 'px';
        panel.style.display = 'block';
        var listBox = document.getElementById('pickerList');
        if (PICKER_LIST) PICKER_LIST.stop();
        PICKER_LIST = Clinic.infiniteList({
            el: listBox,
            pageSize: 15,
            threshold: 40,
            emptyHtml: '<div class="rx-drop-empty">未找到相关项目</div>',
            url: opts.url,
            render: function (list, isFirst) {
                return list.map(function (it) { return pickerItemHtml(it, opts.type); }).join('');
            },
            onError: function () {
                if (listBox && !listBox.querySelector('.rx-drop-item')) listBox.innerHTML = '<div class="rx-drop-empty">加载失败，请重试</div>';
            },
        });
        var kw = document.getElementById('pickerKw');
        kw.addEventListener('input', function () {
            clearTimeout(kw.__t);
            kw.__t = setTimeout(function () { if (PICKER_LIST) PICKER_LIST.reset(); }, 300);
        });
        kw.addEventListener('blur', function () { setTimeout(function () { var p = document.getElementById('rxItemPicker'); if (p) p.style.display = 'none'; }, 120); });
        kw.focus();
    }

    function closeItemPicker() {
        var panel = document.getElementById('rxItemPicker');
        if (panel) panel.style.display = 'none';
    }

    /** 子医嘱搜索（处方类型）：打开通用选择器，选中后追加为该条目的子医嘱 */
    function openSubDrop(key, idx, btn) {
        // 兼容旧调用 openSubDrop(idx, btn)
        if (typeof key === 'number') { btn = idx; idx = key; key = 'sel'; }
        openItemPicker({
            btn: btn,
            type: 'prescription',
            placeholder: '🔍 搜索子医嘱药品（名称 / 厂家）',
            url: function (p, size) {
                var kw = encodeURIComponent((document.getElementById('pickerKw') || {}).value || '');
                return '/api/order?action=catalog&type=prescription&page=' + p + '&size=' + size + '&kw=' + kw;
            },
            onPick: function (it) {
                var arr = RX_CTX[key] ? RX_CTX[key].list() : [];
                var s = arr[idx];
                if (!s) return;
                if (s.id && it.id === s.id) { Clinic.toast.warning('不能添加与主药相同的药品作为子医嘱'); return; }
                if (arr.some(function (m) { return m.id === it.id; })) { Clinic.toast.warning('该药品已是主医嘱，不能重复添加为子医嘱'); return; }
                if (s.sub_items.some(function (sub) { return sub.id === it.id; })) { Clinic.toast.warning('该子医嘱已存在'); return; }
                var sub = itemFromPick(it);
                sub.sub_of = (typeof s.sub_of !== 'undefined' ? s.sub_of : 0);
                s.sub_items.push(sub);
                ctxRender(key);
            },
        });
    }

    /** 选择器条目 → 可编辑对象（结构化剂量默认） */
    function itemFromPick(it) {
        var item = {
            id: parseInt(it.id, 10) || 0,
            name: it.name || '',
            price: parseFloat(it.price) || 0,
            pack_price: parseFloat(it.price) || 0,
            spec: it.spec || '',
            unit: it.unit || '',
            company_short: it.company_short || '',
            dose: it.single_dose || '',
            frequency: it.frequency || '',
            route: it.route || '',
            route_nurse: parseInt(it.route_nurse_required, 10) || 0,
            stock: parseInt(it.stock, 10) || 0,
            nurse_required: parseInt(it.nurse_required, 10) || 0,
            is_skin_test: parseInt(it.is_skin_test, 10) || 0,
            skin_test_item_id: parseInt(it.skin_test_item_id, 10) || 0,
            spec_dose: parseFloat(it.spec_dose) || 0,
            spec_dose_unit: it.spec_dose_unit || '',
            spec_pack_qty: parseInt(it.spec_pack_qty, 10) || 1,
            spec_pack_unit: it.spec_pack_unit || '',
            single_use_qty: parseFloat(it.single_use_qty) || 1,
            // v8.17 拆零销售：允许拆零标记 / 包装单位 / 最小单位；默认单位（拆零→最小单位，否则包装单位）
            allow_split: parseInt(it.allow_split, 10) === 1 ? 1 : 0,
            pack_unit: it.pack_unit || it.unit || '',
            min_unit: it.min_unit || it.spec_pack_unit || '',
            unit_type: (parseInt(it.allow_split, 10) === 1) ? 'min' : 'pack',
            quantity: 1,
            sub_items: [],
            valid: 1,
        };
        var o = initDoseFields(it, item);
        applyUnit(o);
        return o;
    }

    /** 选择器条目 → 套餐编辑器可编辑对象（item_id/item_name 结构 + 兼容共享控件字段） */
    function pkgFromPick(it, qty) {
        var o = {
            item_id: parseInt(it.id, 10) || 0,
            item_name: it.name || '',
            price: parseFloat(it.price) || 0,
            pack_price: parseFloat(it.price) || 0,
            spec: it.spec || '',
            unit: it.unit || '',
            company_short: it.company_short || '',
            single_dose: it.single_dose || '',
            frequency: it.frequency || '',
            route: it.route || '',
            route_nurse_required: parseInt(it.route_nurse_required, 10) || 0,
            stock: parseInt(it.stock, 10) || 0,
            nurse_required: parseInt(it.nurse_required, 10) || 0,
            is_skin_test: parseInt(it.is_skin_test, 10) || 0,
            skin_test_item_id: parseInt(it.skin_test_item_id, 10) || 0,
            spec_dose: parseFloat(it.spec_dose) || 0,
            spec_dose_unit: it.spec_dose_unit || '',
            spec_pack_qty: parseInt(it.spec_pack_qty, 10) || 1,
            spec_pack_unit: it.spec_pack_unit || '',
            single_use_qty: parseFloat(it.single_use_qty) || 1,
            // v8.17 拆零销售：默认单位（拆零→最小单位，否则包装单位）
            allow_split: parseInt(it.allow_split, 10) === 1 ? 1 : 0,
            pack_unit: it.pack_unit || it.unit || '',
            min_unit: it.min_unit || it.spec_pack_unit || '',
            unit_type: (parseInt(it.allow_split, 10) === 1) ? 'min' : 'pack',
            quantity: Math.max(1, parseInt(qty, 10) || 1),
            sub_items: [],
            is_group: it.is_group ? 1 : 0,
            members: it.members || it.spec || '',
            member_ids: it.member_ids || '',
            member_items: it.member_items || [],
            valid: 1,
            invalid_reason: '',
        };
        // 兼容共享药品控件（order.js drugControls/doseDisplay 读取 id/name/dose/dose_unit）
        o.id = o.item_id;
        o.name = o.item_name;
        if (o.spec_dose > 0) {
            // 结构化剂量：默认 1 份（single_use_qty）→ 剂量自动计算、数量自动调整为至少 1 份所需
            var uq = Math.max(1, o.single_use_qty);
            o.dose = Math.round(uq * o.spec_dose * 100) / 100;
            o.dose_unit = o.spec_dose_unit;
            o.single_dose = o.dose + (o.dose_unit || '');
            o.quantity = Math.max(1, Math.ceil(uq / (o.unit_type === 'min' ? 1 : Math.max(1, o.spec_pack_qty))));
        } else {
            o.dose = it.dose || it.single_dose || '';
            o.dose_unit = '';
            o.quantity = Math.max(1, parseInt(qty, 10) || 1);
        }
        applyUnit(o);
        return o;
    }

    /** 更换当前条目：打开通用选择器（type 跟随当前开单/套餐类型），选中后确认替换 */
    function openReplace(key, idx, btn, type, url) {
        // 兼容旧调用 openReplace(idx, btn)
        if (typeof key === 'number') { url = type; type = btn; btn = idx; idx = key; key = 'sel'; }
        var arr = RX_CTX[key] ? RX_CTX[key].list() : [];
        var cur = arr[idx];
        if (!cur) return;
        var oldName = cur.name || cur.item_name || '';
        var t = type || CUR_TYPE || 'prescription';
        openItemPicker({
            btn: btn,
            type: t,
            placeholder: '🔍 搜索' + ({ lab: '检验', imaging: '检查', procedure: '处置', prescription: '药品' }[t] || '项目') + '（可输入名称搜索）',
            url: url || function (p, size) {
                var kw = encodeURIComponent((document.getElementById('pickerKw') || {}).value || '');
                return '/api/order?action=catalog&type=' + t + '&page=' + p + '&size=' + size + '&kw=' + kw;
            },
            onPick: function (it) {
                if (!it || !it.id) return;
                if (it.id === cur.id) { Clinic.toast.warning('更换为相同项目，无需操作'); return; }
                Clinic.modal.confirm('确定要将「' + Clinic.escHtml(oldName) + '」更换为「' + Clinic.escHtml(it.name || '') + '」？', function () {
                    var arr2 = RX_CTX[key] ? RX_CTX[key].list() : [];
                    var s = arr2[idx];
                    if (!s) return;
                    // 同单去重：新项已存在于其他条目（或组内）→ 拦截
                    var dup = arr2.some(function (m, mi) {
                        if (mi === idx) return false;
                        if (m.id === it.id) return true;
                        return (m.sub_items || []).some(function (sub) { return sub.id === it.id; });
                    });
                    if (dup) { Clinic.toast.warning('【' + it.name + '】已在列表中，不能重复'); return; }
                    // 按上下文生成正确结构：套餐编辑器='pkg' 用 item_id/item_name，开单='sel' 用 id/name
                    // 更换后数量默认 1（结构化剂量按单份自动调整），不沿用旧数量
                    var newItem;
                    if (key === 'pkg') {
                        newItem = pkgFromPick(it, 1);
                    } else {
                        newItem = pkgToOrderItem({
                            item_id: it.id, item_name: it.name, price: it.price, spec: it.spec || '',
                            unit: it.unit || '', company_short: it.company_short || '',
                            single_dose: it.single_dose || '', frequency: it.frequency || '', route: it.route || '',
                            spec_dose: it.spec_dose || 0, spec_dose_unit: it.spec_dose_unit || '',
                            spec_pack_qty: it.spec_pack_qty || 1, spec_pack_unit: it.spec_pack_unit || '',
                            single_use_qty: it.single_use_qty || 1, is_group: it.is_group ? 1 : 0,
                            member_ids: it.member_ids || '', members: it.members || it.spec || '',
                            quantity: 1, sub_of: 0, is_skin_test: it.is_skin_test || 0,
                        }, false);
                    }
                    // 保留原条目子医嘱（若有）与护士设置；处方沿用原频次/途径便于微调
                    newItem.sub_items = s.sub_items || [];
                    newItem.nurse_required = s.nurse_required || 0;
                    if (t === 'prescription') { newItem.frequency = newItem.frequency || s.frequency; newItem.route = newItem.route || s.route; }
                    arr2[idx] = newItem;
                    ctxRender(key);
                }, { title: '更换项目', okText: '确认更换' });
            },
        });
    }

    function closeSubDrop() {
        closeItemPicker();
    }

    /* ============ 剂量迷你悬浮窗（结构化规格：固定单位 + 快速选择 + 自动数量） ============ */

    /** 打开剂量悬浮窗（通用：基于条目上下文 key，开处方已选='sel'，处方套餐编辑器='pkg'） */
    function openDosePop(key, idx, btn, si) {
        // 兼容旧调用 openDosePop(idx, btn[, si])：首参为数字时按 'sel' 上下文处理
        if (typeof key === 'number') {
            if (typeof btn === 'number') { si = btn; }
            btn = idx; idx = key; key = 'sel';
        }
        var o = ctxItem(key, idx, si);
        if (!o) return;
        var rect = btn.getBoundingClientRect();
        var panel = document.getElementById('rxDosePop');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'rxDosePop';
            panel.style.cssText = 'position:fixed;z-index:3100;width:224px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);overflow:hidden';
            document.body.appendChild(panel);
            if (!DOSE_OUTER_BOUND) {
                DOSE_OUTER_BOUND = true;
                document.addEventListener('mousedown', function (e) {
                    var p = document.getElementById('rxDosePop');
                    if (p && p.style.display !== 'none' && !p.contains(e.target)) p.style.display = 'none';
                }, true);
            }
        }
        panel.innerHTML =
            '<div style="padding:10px">' +
            '  <div class="fs-13 fw-600 mb-6">单次剂量</div>' +
            '  <div class="flex gap-4" style="align-items:center">' +
            '    <input class="input" type="number" step="any" min="0" id="rxDoseVal" style="width:84px;padding:4px 8px;min-height:28px" value="' + (o.dose === '' || o.dose == null ? '' : o.dose) + '">' +
            '    <span class="fs-13 fw-600" style="color:var(--primary)">' + Clinic.escHtml(o.dose_unit || '') + '</span>' +
            '  </div>' +
            '  <div class="fs-12 text-muted mt-4 mb-4">快速选择（单位：' + Clinic.escHtml(o.spec_pack_unit || '') + '）</div>' +
            '  <div class="flex gap-4" style="flex-wrap:wrap">' +
            [0.125, 0.25, 0.5, 1, 1.5, 2, 3, 4, 5].map(function (c) {
                return '<button type="button" class="btn btn-outline btn-sm" style="padding:2px 10px" ' +
                    'onclick="Clinic.order.doseQuick(\'' + key + '\',' + idx + ',' + c + ',' + (si === undefined ? 'null' : si) + ')">' + c + '</button>';
            }).join('') +
            '  </div>' +
            '  <div class="fs-12 text-success mt-4" id="rxDoseHint"></div>' +
            '  <div class="flex gap-8 mt-4">' +
            '    <button type="button" class="btn btn-outline btn-sm" style="flex:1" onclick="Clinic.order.closeDosePop()">取消</button>' +
            '    <button type="button" class="btn btn-primary btn-sm" style="flex:1" onclick="Clinic.order.applyDose(\'' + key + '\',' + idx + ',' + (si === undefined ? 'null' : si) + ')">确定</button>' +
            '  </div>' +
            '</div>';
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 232)) + 'px';
        panel.style.top = (rect.bottom + 4) + 'px';
        panel.style.display = 'block';
        panel.__key = key;
        panel.__idx = idx;
        panel.__si = (si === undefined ? null : si);
        var val = document.getElementById('rxDoseVal');
        val.addEventListener('input', function () {
            var o2 = ctxItem(key, idx, panel.__si);
            var hint = document.getElementById('rxDoseHint');
            var v = parseFloat(val.value);
            if (hint && o2 && v > 0 && o2.spec_dose > 0) {
                // 覆盖单次剂量所需数量（按开立单位：盒/支/粒）实时提示
                var cap = o2.unit_type === 'min'
                    ? parseFloat(o2.spec_dose)
                    : (parseFloat(o2.spec_dose) * Math.max(1, parseInt(o2.spec_pack_qty, 10) || 1));
                hint.textContent = '需 ' + Math.max(1, Math.ceil(v / cap)) + ' ' + (o2.sale_unit || (o2.spec_pack_unit || ''));
            } else if (hint) { hint.textContent = ''; }
        });
        val.focus();
        val.select();
    }

    /** 快速选择：剂量 = 数量×单剂量值，数量 = 按开立单位覆盖所需（通用上下文） */
    function doseQuick(key, idx, count, si) {
        // 兼容旧调用 doseQuick(idx, count[, si])
        if (typeof key === 'number') {
            if (typeof count === 'number') { si = idx; }
            count = idx; idx = key; key = 'sel';
        }
        var o = ctxItem(key, idx, si);
        if (!o || !(o.spec_dose > 0)) return;
        // 快速选择以最小单位计数（如 粒/支）：剂量 = count × 单剂量值；数量按开立单位自动换算
        o.dose = Math.round(count * o.spec_dose * 100) / 100;
        o.quantity = autoQty(o);
        closeDosePop();
        ctxRender(key);
    }

    /** 确定：读取输入值，数量 = 剂量/单位容量 向上取整（通用上下文，按开立单位换算） */
    function applyDose(key, idx, si) {
        // 兼容旧调用 applyDose(idx[, si])
        if (typeof key === 'number') { if (typeof idx === 'number') { si = idx; } idx = key; key = 'sel'; }
        var o = ctxItem(key, idx, si);
        if (!o) return;
        var val = parseFloat((document.getElementById('rxDoseVal') || {}).value);
        if (!(val > 0)) { Clinic.toast.warning('请填写剂量'); return; }
        o.dose = Math.round(val * 100) / 100;
        if (o.spec_dose > 0) {
            o.quantity = autoQty(o);
        }
        closeDosePop();
        ctxRender(key);
    }

    function closeDosePop() {
        var p = document.getElementById('rxDosePop');
        if (p) p.style.display = 'none';
    }

    /**
     * 绑定弹窗事件（搜索、选择）
     */
    function bindEvents() {
        // 处方：顶部搜索横条 → 焦点弹出药品下拉（下拉为无限滚动分页加载）
        var rxk = document.getElementById('rxKw');
        if (rxk) {
            rxk.addEventListener('focus', function () {
                // 关键字变化后重显时重置回第一页（避免显示旧筛选结果）
                var k = (rxk.value || '').trim().toLowerCase();
                if (k !== RX_KW_LAST) rxReset();
                showRxDrop();
            });
            rxk.addEventListener('input', function () {
                clearTimeout(rxk.__t);
                rxk.__t = setTimeout(function () { rxReset(); }, 300);
                showRxDrop();
            });
            rxk.addEventListener('blur', function () { setTimeout(hideRxDrop, 120); });
        }
        // 目录搜索：关键字变化 → 服务端分页重新检索（防抖 300ms）；检验筛选徽章同步取消/恢复
        var kw = document.getElementById('orderKw');
        if (kw) {
            kw.addEventListener('input', function () {
                clearTimeout(kw.__t);
                kw.__t = setTimeout(function () { catalogReset(); }, 300);
                updateLabFilterUI();
            });
            updateLabFilterUI();
        }
        // 目录条目点击（委托：条目随滚动分页动态生成）
        var catBox = document.getElementById('orderCatalog');
        if (catBox) {
            catBox.addEventListener('click', function (e) {
                var el = e.target.closest ? e.target.closest('.dd-item') : null;
                if (el) handleAdd(itemFromEl(el), el);
            });
        }
        // 药品下拉条目点击：已迁移到 ensureRxDrop 创建时一次性委托（body 覆盖层）
        // 此处不再重复绑定，避免覆盖层复用时叠加多次监听
        // 检验筛选徽章（单个/组合）：切换后重新分页检索
        document.querySelectorAll('#labFilterBar .qp-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                LAB_FILTER = chip.getAttribute('data-f');
                document.querySelectorAll('#labFilterBar .qp-chip').forEach(function (c) {
                    c.classList.toggle('active', c === chip);
                });
                catalogReset();
            });
        });
        // 套餐按钮（检验/检查/处置 与 处方 顶部）：打开套餐选择器
        var opb = document.getElementById('orderPkgBtn');
        if (opb) opb.addEventListener('click', openPkgPicker);
        var rpb = document.getElementById('rxPkgBtn');
        if (rpb) rpb.addEventListener('click', openPkgPicker);
    }

    /**
     * 从目录元素读取项目信息
     */
    function itemFromEl(el) {
        return {
            id: parseInt(el.getAttribute('data-id'), 10),
            name: el.getAttribute('data-name'),
            price: parseFloat(el.getAttribute('data-price')) || 0,
            spec: el.getAttribute('data-spec'),
            unit: el.getAttribute('data-unit'),
            company_short: el.getAttribute('data-company'),
            dose: el.getAttribute('data-dose'),
            freq: el.getAttribute('data-freq'),
            route: el.getAttribute('data-route'),
            route_nurse: parseInt(el.getAttribute('data-route-nurse')) || 0,
            stock: parseInt(el.getAttribute('data-stock')) || 0,
            nurse_req: parseInt(el.getAttribute('data-nurse-req')) || 0,
            is_skin_test: parseInt(el.getAttribute('data-need-skin-test'), 10) || 0,
            is_group: parseInt(el.getAttribute('data-is-group')) === 1,
            spec_dose: parseFloat(el.getAttribute('data-spec-dose')) || 0,
            spec_dose_unit: el.getAttribute('data-spec-dose-unit') || '',
            spec_pack_qty: parseInt(el.getAttribute('data-spec-pack-qty')) || 1,
            spec_pack_unit: el.getAttribute('data-spec-pack-unit') || '',
            single_use_qty: parseFloat(el.getAttribute('data-single-use-qty')) || 1,
            allow_split: parseInt(el.getAttribute('data-allow-split')) === 1 ? 1 : 0,
        };
    }

    /**
     * 选择项目（按类型执行互斥规则）
     * 检验/检查：同单不允许重复（组合与所含单项互斥）；处置：重复自动累加数量；
     * 处方：不受互斥限制；检验：既往已开具的进行二次确认（复查场景）
     */
    function handleAdd(it, el) {
        // 处置 / 处方：同一项目仅可添加一次，数量在已选列表中手动修改
        if (CUR_TYPE === 'procedure' || CUR_TYPE === 'prescription') {
            if (isSelected(it.id)) {
                Clinic.toast.warning('【' + it.name + '】已选择，数量可在已选列表中手动修改');
                return;
            }
            pushItem(it, el);
            return;
        }
        // 检验 / 检查：同一项目不允许重复
        if (isSelected(it.id)) {
            Clinic.toast.warning('【' + it.name + '】已选择，同一开单内不允许重复开具');
            return;
        }
        if (CUR_TYPE === 'lab') {
            // 组合与所含单项互斥（双向）
            var conflict = findLabConflict(it);
            if (conflict) {
                Clinic.toast.warning(conflict);
                return;
            }
            // 不同组合共享成员：不算重复，但给出提醒
            var shared = findSharedMembers(it);
            if (shared.length) {
                Clinic.toast.info('提醒：组合【' + it.name + '】与已选组合共享检验项目：' +
                    shared.join('、') + '（不算重复，请确认是否需要）');
            }
            // 既往已开具：二次确认后再加入（复查场景）
            maybeConfirmPrev(it, el);
            return;
        }
        pushItem(it, el);
    }

    /**
     * 检验互斥检查：组合与所含单项（双向）
     * @returns {string} 冲突提示；无冲突返回空串
     */
    function findLabConflict(it) {
        if (it.is_group) {
            // 已选单项中是否有本组合包含的成员
            var members = GROUP_MEMBERS[it.id] || [];
            for (var i = 0; i < SELECTED.length; i++) {
                var s = SELECTED[i];
                if (s.is_group || members.indexOf(s.id) === -1) continue;
                return '单项【' + s.name + '】已包含在组合【' + it.name + '】中，请勿重复开具';
            }
            return '';
        }
        // 单项是否已包含在已选组合中
        var groups = MEMBER_GROUPS[it.id] || [];
        for (var j = 0; j < SELECTED.length; j++) {
            var g = SELECTED[j];
            if (!g.is_group || groups.indexOf(g.id) === -1) continue;
            return '单项【' + it.name + '】已包含在已选组合【' + g.name + '】中，请勿重复开具';
        }
        return '';
    }

    /**
     * 不同组合共享的成员（不算重复，仅提醒）
     * @returns {string[]} 共享成员名称列表
     */
    function findSharedMembers(it) {
        if (!it.is_group) return [];
        var members = GROUP_MEMBERS[it.id] || [];
        var shared = [];
        SELECTED.forEach(function (s) {
            if (!s.is_group) return;
            (GROUP_MEMBERS[s.id] || []).forEach(function (mid) {
                if (members.indexOf(mid) !== -1 && shared.indexOf(mid) === -1) shared.push(mid);
            });
        });
        return shared.map(function (mid) { return ID_NAMES[mid] || ('检验项目#' + mid); });
    }

    /**
     * 结构化剂量初始化：dose=单次数量×单剂量值（显示如 1g/100ml），
     * quantity=按开立单位覆盖单次剂量所需数量（向上取整，最小1）；无结构化规格则回退文本剂量。
     * v8.17：开立单位（pack/min）参与数量换算——盒=覆盖单次剂量所需整盒数，支=所需最小单位数。
     */
    function initDoseFields(it, item) {
        var sd = parseFloat(it.spec_dose) || 0;
        var uq = Math.max(1, parseFloat(it.single_use_qty) || 1);
        item.dose_unit = it.spec_dose_unit || '';
        item.spec_dose = sd;
        // v8.17.3：从目录条目完整复制包装规格字段——此前缺 spec_pack_qty 导致
        // 主药 autoQty 按 pack_size=1 计算（健胃消食片 2.4g→3盒而非 1盒），
        // 缺 pack_price 导致切换单位后单价以已改 price 反推失效（庆大 1支=1盒价）
        item.spec_pack_qty = parseInt(it.spec_pack_qty, 10) || 1;
        item.spec_pack_unit = it.spec_pack_unit || '';
        item.pack_unit = it.pack_unit || it.unit || '';
        item.min_unit = it.min_unit || it.spec_pack_unit || '';
        item.allow_split = parseInt(it.allow_split, 10) === 1 ? 1 : 0;
        item.single_use_qty = parseFloat(it.single_use_qty) || 1;
        item.pack_price = (parseFloat(it.pack_price) > 0 ? parseFloat(it.pack_price) : 0) || (parseFloat(it.price) || 0);
        item.stock = parseInt(it.stock, 10) > 0 ? parseInt(it.stock, 10) : (parseInt(item.stock, 10) || 0);
        if (item.unit_type !== 'min' && item.unit_type !== 'pack') {
            item.unit_type = item.allow_split === 1 ? 'min' : 'pack';
        }
        if (sd > 0) {
            item.dose = Math.round(uq * sd * 100) / 100;
            item.quantity = autoQty(item);
        } else {
            item.dose = it.dose || '';
            item.quantity = 1;
        }
        applyUnit(item);
        return item;
    }

    /** 开立销售单位联动：单价（包装价/拆零单价）与销售单位名称随 unit_type 刷新 */
    function applyUnit(o) {
        if (!o) return;
        var ps = parseInt(o.spec_pack_qty, 10) || 1;
        var pk = parseFloat(o.pack_price) || parseFloat(o.price) || 0;
        o.unit_type = o.unit_type === 'min' ? 'min' : 'pack';
        // 拆零单价 = 包装单价 ÷ 每包装数量（保留 4 位小数；结算总价按金融四舍五入）
        o.unit_price = o.unit_type === 'min' ? (pk / ps) : pk;
        o.price = o.unit_price;
        o.sale_unit = o.unit_type === 'min'
            ? (o.min_unit || o.spec_pack_unit || '个')
            : (o.pack_unit || o.unit || '盒');
    }

    /**
     * 按开立单位自动计算数量：数量 = ceil(单次剂量 / 单位容量)；
     * · pack：单位容量 = PackCap = spec_dose × spec_pack_qty（一盒能否覆盖单次用药）；
     * · min ：单位容量 = MinCap  = spec_dose（一支/粒的规格量）。
     * 无结构化剂量时保持原数量。
     */
    function autoQty(o) {
        if (!o || !(parseFloat(o.spec_dose) > 0)) return Math.max(1, parseInt(o.quantity, 10) || 1);
        var d = parseFloat(o.dose);
        if (!(d > 0)) return Math.max(1, parseInt(o.quantity, 10) || 1);
        var cap = (o.unit_type === 'min')
            ? parseFloat(o.spec_dose)
            : (parseFloat(o.spec_dose) * Math.max(1, parseInt(o.spec_pack_qty, 10) || 1));
        return Math.max(1, Math.ceil(d / cap));
    }

    /** 当前条目库存上限（按开立单位折算最小单位库存）：盒 → floor(库存/pack_size)；支 → 库存 */
    function qtyMaxOf(o) {
        if (!o) return 99;
        var stock = parseInt(o.stock, 10) || 0;
        if (stock <= 0) return 99;
        var factor = o.unit_type === 'min' ? 1 : Math.max(1, parseInt(o.spec_pack_qty, 10) || 1);
        return Math.max(1, Math.floor(stock / factor));
    }

    /** 数值展示（去多余零）：0.6 / 1.5 / 3 */
    function roundNum(v) {
        return Math.round(parseFloat(v) * 10000) / 10000;
    }

    /**
     * 库存展示串（3.5 动态单位联动）：
     * · min（最小单位）：X 支，pack_size>1 时附带整包装折算「X盒Y支」；
     * · pack（包装单位）：floor(库存/pack_size) 盒（不支持拆零药品搜索候选项默认展示）。
     * @param {object} it       目录条目（需含 stock/pack_size/pack_unit/min_unit）
     * @param {string} unitType pack=包装单位 / min=最小单位
     * @returns {string}
     */
    function stockText(it, unitType) {
        it = it || {};
        var min = parseInt(it.stock, 10) || 0;
        var ps = parseInt(it.pack_size, 10) || parseInt(it.spec_pack_qty, 10) || 1;
        var packUnit = it.pack_unit || it.unit || '盒';
        var minUnit = it.min_unit || it.spec_pack_unit || '';
        if (unitType === 'min') {
            var s = min + (minUnit ? ' ' + minUnit : '');
            if (ps > 1) {
                var packs = Math.floor(min / ps);
                var rem = min % ps;
                s += '（' + packs + packUnit + (rem ? rem + minUnit : '') + '）';
            }
            return s;
        }
        return Math.floor(min / ps) + ' ' + packUnit;
    }

    /** 剂量展示串：1g / 110ml / 2（无单位时仅数值） */
    function doseDisplay(o) {
        var v = (o.dose === '' || o.dose == null) ? '' : String(o.dose);
        return v + (o.dose_unit || '');
    }

    /**
     * 加入已选列表
     */
    function pushItem(it, el) {
        // 皮试药品阻断式确认（仅处方）：需皮试在开方时强制医生选择处置方案
        if (CUR_TYPE === 'prescription' && it.is_skin_test === 1) {
            var curName = it.name;
            var skinEl = el;
            mustConfirmSkinTest(curName, function (choice) {
                if (choice === 'cancel') {
                    if (skinEl) skinEl.style.opacity = '';
                    return;
                }
                var item = initDoseFields(it, {
                    id: it.id,
                    name: it.name,
                    price: it.price,
                    spec: it.spec,
                    unit: it.unit,
                    company_short: it.company_short,
                    dose: it.dose,
                    frequency: it.freq,
                    route: it.route,
                    route_nurse: it.route_nurse,
                    stock: it.stock,
                    nurse_required: it.nurse_req,
                    is_group: !!it.is_group,
                    quantity: 1,
                    sub_items: [],
                    skin_test: choice,   // 'yes' 或 'no'
                });
                SELECTED.push(item);
                if (skinEl) skinEl.style.opacity = '.5';
                renderSelected();
            });
            return;
        }
        SELECTED.push(initDoseFields(it, {
            id: it.id,
            name: it.name,
            price: it.price,
            spec: it.spec,
            unit: it.unit,
            company_short: it.company_short,
            dose: it.dose,
            frequency: it.freq,
            route: it.route,
            route_nurse: it.route_nurse,
            stock: it.stock,
            nurse_required: it.nurse_req,
            is_group: !!it.is_group,
            quantity: 1,
            sub_items: [],
        }));
        if (el) el.style.opacity = '.5';
        renderSelected();
    }

    /**
     * 皮试药品阻断式确认弹窗（与后端 submit 硬校验一致）
     * @param {string} drugName 药品名
     * @param {Function} cb 回调：'yes' 需要皮试 / 'no' 免试 / 'cancel' 取消
     */
    function mustConfirmSkinTest(drugName, cb) {
        Clinic.modal.open(
            '<div class="fs-14" style="line-height:1.9">「<strong>' + drugName + '</strong>」属于<b>需皮试药品</b>，请选择本次处置方案：</div>' +
            '<div class="mt-12 flex flex-col gap-8">' +
            '<button type="button" class="btn btn-danger btn-block" id="skinYes">需要皮试</button>' +
            '<button type="button" class="btn btn-outline btn-block" id="skinNo">无需皮试 / 免试</button>' +
            '</div>',
            {
                title: '⚠️ 皮试确认',
                size: 'modal-sm',
                buttons: [{ text: '取消添加', cls: 'btn-outline' }],
                onClose: function () { if (typeof cb === 'function') cb('cancel'); },
            }
        );
        document.getElementById('skinYes').addEventListener('click', function () {
            Clinic.modal.close();
            if (typeof cb === 'function') cb('yes');
        });
        document.getElementById('skinNo').addEventListener('click', function () {
            Clinic.modal.close();
            if (typeof cb === 'function') cb('no');
        });
    }

    /**
     * 检验既往开具二次确认（含未缴费）：在开单弹窗内展示确认条
     */
    function maybeConfirmPrev(it, el) {
        var prev = PREV_ITEMS[it.id];
        if (!prev) {
            pushItem(it, el);
            return;
        }
        PENDING = { it: it, el: el };
        var bar = document.getElementById('prevConfirm');
        bar.style.display = 'block';
        bar.innerHTML =
            '⚠️ 该患者曾在 <strong>' + prev.time + '</strong> 开具过「' + it.name +
            '」（单号 ' + prev.order_no + '，含未缴费记录），是否再次开具？（如为复查可再次开具）' +
            '<div class="flex gap-8 mt-4">' +
            '  <button type="button" class="btn btn-primary btn-sm" onclick="Clinic.order.confirmPrev(1)">再次开具</button>' +
            '  <button type="button" class="btn btn-outline btn-sm" onclick="Clinic.order.confirmPrev(0)">取消</button>' +
            '</div>';
        if (el) el.style.outline = '2px solid var(--warning)';
    }

    /**
     * 既往二次确认结果
     * @param {number} ok 1 再次开具 / 0 取消
     */
    function confirmPrev(ok) {
        var bar = document.getElementById('prevConfirm');
        if (bar) { bar.style.display = 'none'; bar.innerHTML = ''; }
        if (PENDING && PENDING.el) PENDING.el.style.outline = '';
        if (ok === 1 && PENDING) pushItem(PENDING.it, PENDING.el);
        PENDING = null;
    }

    /**
     * 是否已选
     */
    function isSelected(id) {
        return SELECTED.some(function (s) { return s.id === id; });
    }

    /**
     * 渲染已选列表
     */
    function renderSelected() {
        var box = document.getElementById('selList');
        if (!box) return;
        var isDrug = CUR_TYPE === 'prescription';
        document.getElementById('selCount').textContent = SELECTED.length;
        updateTotal();

        box.innerHTML = SELECTED.map(function (s, i) {
            // 组合项目：所含成员改为「标签换行」展示，避免挤占头部价格/操作区
            var groupInfo = '';
            if (s.is_group) {
                var mids = GROUP_MEMBERS[s.id] || [];
                // 兜底：GROUP_MEMBERS 未就绪时用套餐项自带 member_ids 解析（ID 权威，不依赖快照文本）
                if (!mids.length && s.member_ids) {
                    mids = String(s.member_ids).split(',').map(Number).filter(function (n) { return n > 0; });
                }
                var memHtml = mids.length
                    ? mids.map(function (mid) {
                        return '<span class="order-grp-mem">' + Clinic.escHtml(ID_NAMES[mid] || ('项目#' + mid)) + '</span>';
                    }).join('')
                    : Clinic.escHtml(s.spec || '');
                groupInfo = '<div class="fs-12 text-muted mt-2 order-grp-info">🧩 组合项目（按组价整体收费），含：' +
                    '<span class="order-grp-mems">' + memHtml + '</span></div>';
            }
            var head =
                '<div class="flex-between">' +
                '  <div class="flex gap-8" style="align-items:center;min-width:0">' +
                '    ' + Clinic.ellipsis(s.name || '', 160, 'fw-600 fs-13') +
                (s.is_group ? '<span class="badge badge-primary fs-12" style="flex-shrink:0">组合</span>' : '') +
                (!s.is_group && s.spec ? Clinic.ellipsis(s.spec, 150, 'fs-12 text-muted') : '') +
                (s.skin_test ? '<span class="badge ' + (s.skin_test === 'yes' ? 'badge-danger' : 'badge-gray') + ' fs-12">' +
                    (s.skin_test === 'yes' ? '需要皮试' : '免试') + '</span>' : '') +
                (s.company_short ? Clinic.ellipsis(s.company_short, 70, 'fs-12 text-muted') : '') +
                (isDrug && s.sale_unit ? '<span class="fs-12 text-muted" style="flex-shrink:0">' + s.quantity + ' ' + s.sale_unit + '</span>' : '') +
                (s.quantity > 1 && !(isDrug && s.sale_unit) ? '<span class="badge badge-primary fs-12">×' + s.quantity + '</span>' : '') +
                '    <span class="fs-12 text-muted" style="flex-shrink:0;margin-left:auto">¥' + (s.price * s.quantity).toFixed(2) + '</span>' +
                '  </div>' +
                '  <div class="flex gap-8" style="align-items:center;flex-shrink:0">' +
                (isDrug || CUR_TYPE === 'procedure' ? qtyControls('sel', s, i) : '') +
                (CUR_TYPE === 'procedure' || isDrug ? nurseToggle('sel', s, i) : '') +
                (isDrug ? '' : '<button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                    'onclick="Clinic.order.openReplace(\'sel\',' + i + ',this)" title="快速更换为其他项目">更换</button>') +
                '    <button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                'onclick="Clinic.order.removeItem(' + i + ')">✕</button>' +
                '  </div>' +
                '</div>';
            var extra = isDrug ? drugControls('sel', s, i) : '';
            return '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px">' +
                head + groupInfo + extra + '</div>';
        }).join('') || '<div class="text-muted fs-13 text-center">尚未选择项目</div>';
    }

    /**
     * 更新总费用（主药 + 子医嘱均计费；每行按金融四舍五入，与后端结算口径一致）
     */
    function updateTotal() {
        var total = SELECTED.reduce(function (sum, s) {
            var t = sum + Math.round(s.price * s.quantity * 100) / 100;
            (s.sub_items || []).forEach(function (sub) {
                t += Math.round((sub.price || 0) * (sub.quantity || 1) * 100) / 100;
            });
            return t;
        }, 0);
        document.getElementById('orderTotal').textContent = '¥' + total.toFixed(2);
    }

    /**
     * 数量控制（通用上下文：开处方已选='sel'，处方套餐编辑器='pkg'）
     * v8.17：数量框右侧紧邻【销售单位下拉】——允许拆零药品可选 支/粒（min）或 盒（pack），
     * 默认最小单位；不支持拆零药品仅展示包装单位（单选项，不可切换）。
     */
    function qtyControls(key, s, i) {
        var isDrug = key === 'sel' && CUR_TYPE === 'prescription';
        var dis = s.valid === 0;   // 失效项目：数量控件禁用
        var maxQty = isDrug ? qtyMaxOf(s) : 99;
        var unitSel = '';
        if (isDrug) {
            var packName = s.pack_unit || s.unit || '盒';
            var minName = s.min_unit || s.spec_pack_unit || '';
            if (s.allow_split === 1 && minName !== '') {
                unitSel = '<select class="select" style="width:64px;padding:3px 4px;min-height:28px;font-size:12px"' + (dis ? ' disabled' : '') + ' ' +
                    'onchange="Clinic.order.rxCtx(\'' + key + '\',\'setUnitType\',[' + i + ',this.value])">' +
                    '<option value="min"' + (s.unit_type === 'min' ? ' selected' : '') + '>' + minName + '</option>' +
                    '<option value="pack"' + (s.unit_type === 'pack' ? ' selected' : '') + '>' + packName + '</option></select>';
            } else {
                unitSel = '<select class="select" style="width:64px;padding:3px 4px;min-height:28px;font-size:12px" disabled>' +
                    '<option value="pack" selected>' + packName + '</option></select>';
            }
        }
        // 库存展示按开立单位联动（3.5）：min → X 支（含整包装折算）；pack → X 盒（含拆零余量）
        var stockTxt = '';
        if (isDrug) {
            var stockMax = qtyMaxOf(s);
            var minStock = parseInt(s.stock, 10) || 0;
            var psStock = Math.max(1, parseInt(s.spec_pack_qty, 10) || 1);
            var packNameStock = s.pack_unit || s.unit || '盒';
            var minNameStock = s.min_unit || s.spec_pack_unit || '';
            if (s.unit_type === 'min') {
                stockTxt = '库存' + minStock + minNameStock +
                    (psStock > 1 ? '（' + Math.floor(minStock / psStock) + packNameStock + ((minStock % psStock) ? (minStock % psStock) + minNameStock : '') + '）' : '');
            } else {
                stockTxt = '库存' + stockMax + packNameStock +
                    (psStock > 1 && (minStock % psStock) ? '（余' + (minStock % psStock) + minNameStock + '）' : '');
            }
            stockTxt = '<span class="fs-12 text-muted" title="库存（按当前销售单位）">' + stockTxt + '</span>';
        }
        return '<div class="flex gap-4" style="align-items:center">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px"' + (dis ? ' disabled' : '') + ' ' +
            'onclick="Clinic.order.rxCtx(\'' + key + '\',\'changeQty\',[' + i + ',-1])">−</button>' +
            '<input type="number" class="input" style="width:52px;padding:3px 6px;min-height:28px;text-align:center" ' +
            'value="' + s.quantity + '" min="1" max="' + maxQty + '"' + (dis ? ' disabled' : '') + ' ' +
            'onchange="Clinic.order.rxCtx(\'' + key + '\',\'setQty\',[' + i + ',this.value])">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px"' + (dis ? ' disabled' : '') + ' ' +
            'onclick="Clinic.order.rxCtx(\'' + key + '\',\'changeQty\',[' + i + ',1])">＋</button>' +
            unitSel +
            stockTxt + '</div>';
    }

    /** 护士站处置逐项勾选（通用上下文：处方/处置；失效项目禁用） */
    function nurseToggle(key, s, i) {
        var dis = s.valid === 0;
        return '<label style="display:inline-flex;align-items:center;gap:3px;font-size:12px;cursor:' + (dis ? 'not-allowed' : 'pointer') + ';color:var(--text-muted);user-select:none" title="缴费后护士站显示待执行；取消勾选则不显示">' +
            '<input type="checkbox" style="width:14px;height:14px;accent-color:var(--primary)"' +
            (s.nurse_required ? ' checked' : '') + (dis ? ' disabled' : '') +
            ' onchange="Clinic.order.rxCtx(\'' + key + '\',\'setNurse\',[' + i + ',this.checked])"> 护士</label>';
    }

    /**
     * 药品剂量/频次/途径（自动同步，可修改；通用上下文）
     * 成组医嘱：所有药品均可添加子医嘱（不限给药途径）
     */
    function drugControls(key, s, i) {
        var dis = s.valid === 0;   // 失效项目：剂量/频次/途径/子医嘱全部禁用
        var freqOpts = RX_FREQS.map(function (f) {
            return '<option value="' + f + '"' + (f === s.frequency ? ' selected' : '') + '>' + f + '</option>';
        }).join('');
        var routeOpts = RX_ROUTES.map(function (r) {
            return '<option value="' + r + '"' + (r === s.route ? ' selected' : '') + '>' + r + '</option>';
        }).join('');
        // 当前值不在选项列表（管理员新增未刷新等）时，补一个选中项兜底
        if (s.frequency && freqOpts && RX_FREQS.indexOf(s.frequency) === -1) {
            freqOpts = '<option value="' + s.frequency + '" selected>' + s.frequency + '</option>' + freqOpts;
        }
        if (s.route && routeOpts && RX_ROUTES.indexOf(s.route) === -1) {
            routeOpts = '<option value="' + s.route + '" selected>' + s.route + '</option>' + routeOpts;
        }
        // 频次/途径下拉：带搜索 + 清空 X，占位「用药频次/使用途径」为空值且不进候选列表；
        // 药品数据含频次/途径自动回填（selected），缺失则回退占位；提交时必填（剂量/频次/途径）
        // 词典为空且无当前值时回退文本输入（无可选项）
        var freqSel = freqOpts
            ? '<select class="select" data-csd-search="1" style="width:128px;padding:4px 8px;min-height:28px;font-size:13px"' + (dis ? ' disabled' : '') + ' ' +
              'onchange="Clinic.order.rxCtx(\'' + key + '\',\'setField\',[' + i + ',\'frequency\',this.value])">' +
              '<option value="">用药频次</option>' + freqOpts + '</select>'
            : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px"' + (dis ? ' disabled' : '') + ' ' +
              'value="' + (s.frequency || '') + '" placeholder="频次" onchange="Clinic.order.rxCtx(\'' + key + '\',\'setField\',[' + i + ',\'frequency\',this.value])">';
        var routeSel = routeOpts
            ? '<select class="select" data-csd-search="1" style="width:128px;padding:4px 8px;min-height:28px;font-size:13px"' + (dis ? ' disabled' : '') + ' ' +
              'onchange="Clinic.order.rxCtx(\'' + key + '\',\'setRoute\',[' + i + ',this.value])">' +
              '<option value="">使用途径</option>' + routeOpts + '</select>'
            : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px"' + (dis ? ' disabled' : '') + ' ' +
              'value="' + (s.route || '') + '" placeholder="途径" onchange="Clinic.order.rxCtx(\'' + key + '\',\'setRoute\',[' + i + ',this.value])">';
        // 剂量：结构化规格 → 只读可点击按钮（弹迷你悬浮窗）；否则回退文本输入
        var doseArea = (s.spec_dose > 0)
            ? '<button type="button" class="btn btn-outline btn-sm" style="min-height:28px;font-weight:600"' + (dis ? ' disabled' : '') + ' ' +
              'onclick="Clinic.order.openDosePop(\'' + key + '\',' + i + ',this)" title="点击设置剂量（自动计算数量）">' + Clinic.escHtml(doseDisplay(s)) + ' ▾</button>'
            : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px"' + (dis ? ' disabled' : '') + ' ' +
              'value="' + (s.dose || '') + '" placeholder="剂量" onchange="Clinic.order.rxCtx(\'' + key + '\',\'setField\',[' + i + ',\'dose\',this.value])">';
        return '<div class="flex gap-8 mt-4" style="flex-wrap:wrap">' +
            doseArea +
            freqSel +
            routeSel +
            (dis ? '' : '<button type="button" class="btn btn-outline btn-sm" ' +
            'onclick="Clinic.order.openSubDrop(\'' + key + '\',' + i + ',this)">＋ 子医嘱</button>') +
            (dis ? '' : drugReplaceBtn(key, i)) +
            '</div>' +
            (s.sub_items.length ? subList(key, s, i) : '');
    }

    /** 处方条目【更换】按钮：与子医嘱同一行、靠右对齐（不单独占行） */
    function drugReplaceBtn(key, i) {
        var ctx = RX_CTX[key] || {};
        var t = ctx.replaceType || 'prescription';
        var urlRef = ctx.replaceUrlName || '';
        return '<button type="button" class="btn btn-outline btn-sm" style="margin-left:auto" ' +
            'onclick="Clinic.order.openReplace(\'' + key + '\',' + i + ',this,\'' + t + '\',' + (urlRef ? urlRef : 'null') + ')" title="快速更换为其他药品">更换</button>';
    }

    /**
     * 子医嘱列表（成组医嘱树状连线：┌ 首个 / ├ 中间 / └ 末尾；通用上下文）
     */
    function subList(key, s, i) {
        var n = s.sub_items.length;
        var dis = s.valid === 0;   // 失效项目：子医嘱控件一并禁用
        return '<div style="margin:6px 0 0 20px;border-left:2px solid var(--warning);padding-left:10px">' +
            '<div class="fs-12 text-muted mb-4">成组医嘱（并入上方主药，途径频次随主药；剂量/数量可独立调整并计费）</div>' +
            s.sub_items.map(function (sub, si) {
                // 子医嘱连接符：非末行 ├ / 末行 └（单个即 └）
                var branch = si === n - 1 ? '└' : '├';
                // 剂量：结构化规格 → 只读可点击按钮；否则文本输入
                var subDose = (sub.spec_dose > 0)
                    ? '<button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px;min-height:22px;font-weight:600"' + (dis ? ' disabled' : '') + ' ' +
                      'onclick="Clinic.order.openDosePop(\'' + key + '\',' + i + ',this,' + si + ')" title="点击设置剂量（自动计算数量）">' + Clinic.escHtml(doseDisplay(sub)) + ' ▾</button>'
                    : '<input type="text" class="input" style="width:70px;padding:2px 6px;min-height:22px;font-size:12px"' + (dis ? ' disabled' : '') + ' ' +
                      'value="' + (sub.dose || '') + '" placeholder="剂量" onchange="Clinic.order.rxCtx(\'' + key + '\',\'setSubField\',[' + i + ',' + si + ',\'dose\',this.value])">';
                return '<div class="flex-between fs-13" style="padding:2px 0;align-items:center">' +
                    '<span style="min-width:0;flex:1;font-family:Menlo,Consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                    branch + ' ' + sub.name +
                    (sub.spec ? ' <span class="text-muted">' + sub.spec + '</span>' : '') +
                    ' ｜ ' + subDose +
                    '</span>' +
                    '<span class="flex gap-4" style="align-items:center;flex-shrink:0;margin-left:8px">' +
                    '<span class="fs-12 text-muted">¥' + ((sub.price || 0) * (sub.quantity || 1)).toFixed(2) + '</span>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px"' + (dis ? ' disabled' : '') + ' ' +
                    'onclick="Clinic.order.rxCtx(\'' + key + '\',\'changeSubQty\',[' + i + ',' + si + ',-1])">−</button>' +
                    '<input type="number" class="input" style="width:46px;padding:2px 4px;min-height:22px;text-align:center;font-size:12px"' + (dis ? ' disabled' : '') + ' ' +
                    'value="' + (sub.quantity || 1) + '" min="1" max="99" ' +
                    'onchange="Clinic.order.rxCtx(\'' + key + '\',\'setSubQty\',[' + i + ',' + si + ',this.value])">' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px"' + (dis ? ' disabled' : '') + ' ' +
                    'onclick="Clinic.order.rxCtx(\'' + key + '\',\'changeSubQty\',[' + i + ',' + si + ',1])">＋</button>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px"' + (dis ? ' disabled' : '') + ' ' +
                    'onclick="Clinic.order.rxCtx(\'' + key + '\',\'removeSub\',[' + i + ',' + si + '])">✕</button>' +
                    '</span>' +
                    '</div>';
            }).join('') + '</div>';
    }

    /**
     * 提交开单
     */
    function submit() {
        if (SUBMITTING) return;
        if (!SELECTED.length) {
            Clinic.toast.warning('请至少选择一个项目');
            return;
        }
        // 处方库存上限 + 单次剂量覆盖性 + 剂量/频次/途径必填校验（仅药品；检验/检查/处置无库存且无剂量要素）
        if (CUR_TYPE === 'prescription') {
            for (var i = 0; i < SELECTED.length; i++) {
                var s = SELECTED[i];
                // 不支持拆零的药品选择最小单位：前端拦截（后端 submit 亦有硬校验）
                if (s.unit_type === 'min' && s.allow_split !== 1) {
                    Clinic.toast.warning('【' + s.name + '】不支持拆零销售，请按整包装（盒/瓶）开立！');
                    return;
                }
                // 库存按开立单位折算最小单位比对（盒 → 数量×pack_size）
                var factor = s.unit_type === 'min' ? 1 : Math.max(1, parseInt(s.spec_pack_qty, 10) || 1);
                var needMin = (parseInt(s.quantity, 10) || 1) * factor;
                if (needMin > (s.stock || 0)) {
                    Clinic.toast.warning('【' + s.name + '】数量超过库存（当前库存可开 ' + qtyMaxOf(s) + ' ' + (s.sale_unit || '') + '）');
                    return;
                }
                if (!(s.dose || '').toString().trim()) { Clinic.toast.warning('请填写【' + s.name + '】剂量（必填）'); return; }
                if (!(s.frequency || '').trim()) { Clinic.toast.warning('请选择【' + s.name + '】用药频次（必填）'); return; }
                if (!(s.route || '').trim()) { Clinic.toast.warning('请选择【' + s.name + '】使用途径（必填）'); return; }
                // 单次剂量覆盖性校验：开药总量（数量 × 单位容量）必须 ≥ 单次剂量，防开空/开漏
                var sdoseN = parseFloat(s.spec_dose) || 0;
                var doseN = parseFloat(s.dose);
                if (sdoseN > 0 && doseN > 0) {
                    var unitCap = s.unit_type === 'min' ? sdoseN : sdoseN * factor;
                    var haveAmt = (parseInt(s.quantity, 10) || 1) * unitCap;
                    var needQ = Math.max(1, Math.ceil(doseN / unitCap));
                    if (haveAmt + 1e-9 < doseN) {
                        Clinic.toast.warning('【' + s.name + '】开立数量无法满足单次剂量要求（当前 ' + s.quantity + ' ' + (s.sale_unit || '') +
                            ' 仅 ' + roundNum(haveAmt) + (s.dose_unit || '') + '，单次需 ' + roundNum(doseN) + (s.dose_unit || '') +
                            '），至少需要 ' + needQ + ' ' + (s.sale_unit || '') + '！');
                        return;
                    }
                }
                for (var si = 0; si < (s.sub_items || []).length; si++) {
                    if (!((s.sub_items[si].dose || '').toString().trim())) {
                        Clinic.toast.warning('请填写子医嘱【' + s.sub_items[si].name + '】剂量（必填）');
                        return;
                    }
                    if (s.sub_items[si].unit_type === 'min' && s.sub_items[si].allow_split !== 1) {
                        Clinic.toast.warning('【' + s.sub_items[si].name + '】不支持拆零销售，请按整包装（盒/瓶）开立！');
                        return;
                    }
                }
            }
        }
        var flat = [];
        var skinChoices = [];
        SELECTED.forEach(function (s, idx) {
            flat.push({
                item_id: s.id, item_name: s.name, price: s.price, quantity: s.quantity,
                spec: s.spec, unit: s.unit, company_short: s.company_short,
                dose: s.dose, dose_unit: s.dose_unit || '', frequency: s.frequency, route: s.route,
                notes: '', sub_of: 0, sort: idx,
                // v8.17 开立销售单位（pack/min）：后端据此核算单价、校验拆零权限与库存单位抵扣
                unit_type: s.unit_type || 'pack',
                is_nurse: ((CUR_TYPE === 'procedure' || CUR_TYPE === 'prescription') && s.nurse_required) ? 1 : 0,
            });
            // 皮试判定结果（主药行；子药下标为 null 表示非皮试主药）
            skinChoices.push(s.skin_test || '');
            (s.sub_items || []).forEach(function (sub, si) {
                flat.push({
                    item_id: sub.id || 0, item_name: sub.name, price: sub.price || 0,
                    quantity: sub.quantity || 1,
                    dose: sub.dose, dose_unit: sub.dose_unit || '', frequency: '', route: '',
                    spec: sub.spec || '', unit: sub.unit || '',
                    company_short: sub.company_short || '', notes: '',
                    sub_of: idx + 1, sort: si,
                    unit_type: sub.unit_type || 'pack',
                });
                skinChoices.push('');
            });
        });

        SUBMITTING = true;
        Clinic.ajax('/api/order', {
            action: 'submit',
            visit_id: VISIT_ID,
            order_type: CUR_TYPE,
            nurse_required: 0,
            items: JSON.stringify(flat),
            skin_choices: JSON.stringify(skinChoices),
            // 开单与病历强关联：记录当前所在病历（首诊/续写/会诊），
            // 开单科室随病历固化（取病历书写科室——会诊病历=目标科室 B，
            // 而非就诊当前科室 A），展示/打印不跨病历串显示
            record_id: (Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.record) ? (Clinic.emr._ctx.DATA.record.record_id || 0) : 0,
            dept_id: (Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.record) ? (Clinic.emr._ctx.DATA.record.dept_id || 0) : 0,
        }, {
            loading: true,
            onSuccess: function (j) {
                SUBMITTING = false;
                var msg = j.msg || '开单成功';
                Clinic.toast.success(msg + '，总费用 ¥' + parseFloat(j.data.total).toFixed(2));
                Clinic.modal.close();
                // 申请单/处置单/处方单统一 A5 病历纸样式；检查按分类拆分后一次打印多张
                var ids = j.data.order_ids && j.data.order_ids.length ? j.data.order_ids : [j.data.order_id];
                var q = ids.length > 1 ? 'order_ids=' + ids.join(',') : 'order_id=' + ids[0];
                Clinic.print.load('/api/print?action=order&' + q, null, 'a5');
                Clinic.emr.loadOrders(VISIT_ID);
            },
            onError: function () { SUBMITTING = false; },
        });
    }

    /* ============ 对外操作接口 ============ */

    /** 移除已选项目（主药含子药时级联确认） */
    function removeItem(i) {
        var s = SELECTED[i];
        if (s.sub_items && s.sub_items.length) {
            Clinic.modal.confirm(
                '删除主药【' + Clinic.escHtml(s.name || '') + '】将同时移除所有关联子医嘱（' + s.sub_items.length + '项），是否确认？',
                function () { doRemove(i); },
                { title: '确认删除成组医嘱', okText: '确认删除' }
            );
        } else {
            doRemove(i);
        }
    }
    function doRemove(i) {
        SELECTED.splice(i, 1);
        renderSelected();
    }

    /** 修改数量 */
    function changeQty(i, delta) {
        var s = SELECTED[i];
        var max = CUR_TYPE === 'prescription' ? qtyMaxOf(s) : 99;
        s.quantity = Math.min(max, Math.max(1, s.quantity + delta));
        if (CUR_TYPE === 'prescription' && s.quantity >= max) {
            Clinic.toast.warning('数量不能超过库存（按 ' + (s.sale_unit || '') + ' 计）');
        }
        renderSelected();
    }

    /** 设置数量 */
    function setQty(i, val) {
        var s = SELECTED[i];
        var max = CUR_TYPE === 'prescription' ? qtyMaxOf(s) : 99;
        var v = Math.min(max, Math.max(1, parseInt(val, 10) || 1));
        if (CUR_TYPE === 'prescription' && parseInt(val, 10) > max) Clinic.toast.warning('数量不能超过库存（按 ' + (s.sale_unit || '') + ' 计）');
        s.quantity = v;
        renderSelected();
    }

    /** 设置字段（剂量/频次） */
    function setField(i, field, val) {
        SELECTED[i][field] = val;
        updateTotal();
    }

    /** 设置途径 */
    function setRoute(i, val) {
        SELECTED[i].route = val;
        renderSelected();
    }

    /** 设置护士站处置（逐项，仅处置） */
    function setNurse(i, checked) {
        if (!SELECTED[i]) return;
        SELECTED[i].nurse_required = checked ? 1 : 0;
    }

    /** 设置子医嘱字段（剂量） */
    function setSubField(i, si, field, val) {
        var s = SELECTED[i];
        if (s && s.sub_items[si]) s.sub_items[si][field] = val;
    }

    /** 子医嘱数量增减 */
    function changeSubQty(i, si, delta) {
        var s = SELECTED[i];
        if (!s || !s.sub_items[si]) return;
        var q = Math.min(99, Math.max(1, (s.sub_items[si].quantity || 1) + delta));
        s.sub_items[si].quantity = q;
        renderSelected();
    }

    /** 子医嘱数量设置 */
    function setSubQty(i, si, val) {
        var s = SELECTED[i];
        if (!s || !s.sub_items[si]) return;
        s.sub_items[si].quantity = Math.min(99, Math.max(1, parseInt(val, 10) || 1));
        renderSelected();
    }

    /** 移除子医嘱 */
    function removeSub(i, si) {
        SELECTED[i].sub_items.splice(si, 1);
        renderSelected();
    }

    return {
        init: init, open: open, renderSelected: renderSelected,
        removeItem: removeItem, changeQty: changeQty, setQty: setQty,
        setField: setField, setRoute: setRoute, removeSub: removeSub,
        setSubField: setSubField, changeSubQty: changeSubQty, setSubQty: setSubQty,
        setNurse: setNurse, openSubDrop: openSubDrop, closeSubDrop: closeSubDrop,
openDosePop: openDosePop, doseQuick: doseQuick, applyDose: applyDose, closeDosePop: closeDosePop,
        confirmPrev: confirmPrev, setPkgApplyCheck: setPkgApplyCheck,
        pkgApplyToggleAll: pkgApplyToggleAll, pkgApplyConfirm: pkgApplyConfirm,
        // 通用条目上下文 + 共享控件（开处方已选 / 处方套餐编辑器共用）
        rxSetCtx: rxSetCtx, rxCtx: rxCtx, setRxDicts: setRxDicts,
        qtyControls: qtyControls, nurseToggle: nurseToggle, drugControls: drugControls, subList: subList,
        doseDisplay: doseDisplay, attachSearchTabs: attachSearchTabs,
        applyUnit: applyUnit, autoQty: autoQty, qtyMaxOf: qtyMaxOf, stockText: stockText,
        openItemPicker: openItemPicker, openReplace: openReplace, closeItemPicker: closeItemPicker,
    };
})();
