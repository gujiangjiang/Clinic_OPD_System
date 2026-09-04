/**
 * ============================================================
 * order.js v1.0.0 — 开单组件（检验/检查/处置/处方）
 * ============================================================
 * 说明：医生开单统一弹窗（三栏：左目录+搜索 / 中已选 / 右流程）：
 * 1. 搜索项目（检验/检查/处置/药品），多选或单选
 * 2. 互斥规则：所有类型同一项目仅可添加一次（数量在已选列表手动修改）；
 *    检验组合与所含单项互斥（双向）、不同组合共享成员仅提醒不算重复；
 *    处置/处方数量可手动修改（如处置 ×2、药品 ×2）
 * 3. 检验既往已开具（含未缴费）二次确认后再加入（复查场景）
 * 4. 实时显示开单总费用
 * 5. 处方：数量不得超库存、剂量/频次/途径自动同步药品设置、
 *    支持子医嘱成组（所有药品均可，不限给药途径）、护士站执行选项自动勾选
 * 6. 右侧纵向流程图（开单-缴费-登记-执行-完成）
 * 7. 提交后自动弹出申请单/处方打印
 * 依赖：ajax.js、modal.js、toast.js、print.js、emr.js
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
        return it.item_name + (p.length ? '\u3000' + p.join('\u3000') : '') + '\u3000\u00D7' + it.quantity;
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
    /** 检验筛选：single=单个 / group=组合 */
    var LAB_FILTER = 'single';
    /** 子医嘱面板外部点击监听是否已注册 */
    var SUB_OUTER_BOUND = false;
    /** 剂量悬浮窗外部点击监听是否已注册 */
    var DOSE_OUTER_BOUND = false;
    /** 已选项目列表 */
    var SELECTED = [];
    /** 项目目录缓存 */
    var CATALOG = [];
    /** 既往开具记录：item_id -> {name, time, order_no}（检验复查二次确认用） */
    var PREV_ITEMS = {};
    /** 组合包含成员关系：groupId -> [memberId] */
    var GROUP_MEMBERS = {};
    /** 成员所属组合：memberId -> [groupId] */
    var MEMBER_GROUPS = {};
    /** 目录 id -> 名称（共享成员提醒显示用） */
    var ID_NAMES = {};
    /** 频次/途径选项（管理员设置，已选列表下拉用） */
    var RX_FREQS = [];
    var RX_ROUTES = [];
    /** 待二次确认的既往项目 */
    var PENDING = null;

    /**
     * 初始化（页面加载时调用）
     */
    function init(visitId) {
        VISIT_ID = visitId;
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
        var names = { lab: '开检验', imaging: '开检查', procedure: '开处置', prescription: '开处方' };
        var catalogReady = false;
        var prevReady = (type !== 'lab');   // 仅检验需要既往开具记录
        function tryOpen() {
            if (!catalogReady || !prevReady) return;
            Clinic.modal.open(renderDialog(), {
                title: names[type] || '开单',
                size: 'modal-lg order-modal',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    { text: '提交开单', cls: 'btn-success', autoClose: false, onClick: submit },
                ],
            });
            bindEvents();
        }
        Clinic.get('/api/order?action=catalog&type=' + type, null, {
            onSuccess: function (j) {
                CATALOG = j.data.list;
                if (j.data.link_dicts) {
                    RX_FREQS = j.data.link_dicts.frequencies || [];
                    RX_ROUTES = j.data.link_dicts.routes || [];
                }
                buildMaps();
                catalogReady = true;
                tryOpen();
            },
            onError: function () {
                // 目录加载失败：置空目录并放行（弹窗内显示空态），避免点击开单按钮毫无反应
                CATALOG = [];
                catalogReady = true;
                tryOpen();
            },
        });
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
        }
    }

    /**
     * 构建组合/成员关系映射（互斥判断用）
     */
    function buildMaps() {
        CATALOG.forEach(function (it) {
            ID_NAMES[it.id] = it.name;
            if (it.is_group && it.member_ids) {
                var ids = String(it.member_ids).split(',').map(Number).filter(function (n) { return n > 0; });
                GROUP_MEMBERS[it.id] = ids;
                ids.forEach(function (mid) {
                    (MEMBER_GROUPS[mid] = MEMBER_GROUPS[mid] || []).push(it.id);
                });
            }
        });
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

        var rows = CATALOG.filter(function (it) {
            // 处方：库存为 0 的药品不显示（缺货不可开具）
            return !(isDrug && (it.stock || 0) <= 0);
        }).map(function (it) {
            var info = '';
            if (isDrug) {
                var parts = [];
                if (it.single_dose) parts.push('剂量 ' + it.single_dose);
                if (it.frequency) parts.push('频次 ' + it.frequency);
                if (it.route) parts.push('途径 ' + it.route);
                info = parts.length ? '<div class="fs-12 text-muted">' + parts.join(' ｜ ') + '</div>' : '';
            } else if (it.is_group) {
                // 检验组合：显示组内成员，按组价整体收费
                info = '<div class="fs-12 text-muted">🧩 组合项目 ｜ 含：' + (it.members || it.spec || '') +
                    '（按组价整体收费）</div>';
            }
            return '<div class="dd-item" data-id="' + it.id + '" ' +
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
                (isDrug && it.company_short ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(it.company_short) + '</span>' : '') +
                (it.category_name ? ' <span class="badge badge-gray fs-12">' + Clinic.escHtml(it.category_name) + '</span>' : '') +
                '</div>' +
                '  <div class="text-right">' +
                '    <div class="fw-600" style="color:var(--primary)">¥' + parseFloat(it.price || 0).toFixed(2) + '</div>' +
                (isDrug ? '<div class="fs-12 ' + (it.stock > 0 ? 'text-success' : 'text-danger') + '">库存：' +
                    (it.stock || 0) + '</div>' : '') +
                '  </div></div>' + info +
                '</div>';
        }).join('') || '<div class="dd-empty">暂无可选项目，请先联系管理员添加</div>';

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
                '    <input type="text" class="input" id="rxKw" placeholder="🔍 点击搜索药品（名称 / 厂家简称），支持子医嘱" autocomplete="off" style="flex-shrink:0">' +
                '    <div id="rxDrop" style="display:none;position:absolute;top:44px;left:0;right:0;z-index:40;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);max-height:300px;overflow-y:auto"></div>' +
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
            '    <input type="text" class="input" id="orderKw" placeholder="搜索' +
            (isDrug ? '药品名称/厂家简称' : '项目名称') + '" autocomplete="off">' +
            (CUR_TYPE === 'lab' ? labFilterBar() : '') +
            '    <div class="order-catalog" style="flex:1;min-height:0;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-top:8px">' +
            rows + '</div>' +
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

    /** 统一应用目录筛选：搜索关键字 + 检验筛选（单个/组合） */
    function applyCatalogFilter() {
        var kw = ((document.getElementById('orderKw') || {}).value || '').toLowerCase();
        document.querySelectorAll('.order-catalog .dd-item').forEach(function (el) {
            var matchKw = !kw || el.textContent.toLowerCase().indexOf(kw) !== -1;
            var matchF = true;
            if (CUR_TYPE === 'lab') {
                var isGroup = el.getAttribute('data-is-group') === '1';
                matchF = LAB_FILTER === 'group' ? isGroup : !isGroup;
            }
            el.style.display = (matchKw && matchF) ? '' : 'none';
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
        parts.push('库存 ' + (it.stock || 0));
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
            ' data-is-group="0">' +
            '<div class="flex-between">' +
            '  <div class="fw-600 fs-13 ellipsis" style="display:flex;align-items:baseline;min-width:0">' +
            Clinic.escHtml(it.name || '') + vendor + '</div>' +
            '  <div class="fw-600 fs-13" style="color:var(--primary);flex-shrink:0">¥' + parseFloat(it.price || 0).toFixed(2) + '</div>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-2" style="line-height:1.5">' + parts.join(' ｜ ') + '</div>' +
            '</div>';
    }

    /** 渲染顶部药品下拉（kw 为空 → 完整列表） */
    function renderRxDrop(kw) {
        var box = document.getElementById('rxDrop');
        if (!box) return;
        var k = (kw || '').trim().toLowerCase();
        var list = CATALOG.filter(function (it) {
            if (!k) return true;
            return (it.name || '').toLowerCase().indexOf(k) !== -1 ||
                (it.company_short || '').toLowerCase().indexOf(k) !== -1;
        });
        box.innerHTML = list.length ? list.map(function (it) { return rxItemHtml(it, true); }).join('') : '<div class="rx-drop-empty">未找到相关药品</div>';
        box.querySelectorAll('.rx-drop-item').forEach(function (el) {
            el.addEventListener('mousedown', function (e) {
                e.preventDefault();   // 阻止输入框失焦，避免下拉先被关闭
                pickRx(el);
            });
        });
    }

    function showRxDrop() {
        var box = document.getElementById('rxDrop');
        if (box) box.style.display = '';
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

    /* ============ 子医嘱：跟随鼠标的内联搜索下拉（替换原模态框选择） ============ */

    /** 打开子医嘱搜索面板（跟随 子医嘱 按钮下方） */
    function openSubDrop(idx, btn) {
        var rect = btn.getBoundingClientRect();
        var panel = document.getElementById('rxSubDrop');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'rxSubDrop';
            panel.style.cssText = 'position:fixed;z-index:3000;width:380px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);overflow:hidden';
            document.body.appendChild(panel);
            // 全局：点击面板外关闭（一次性注册）
            if (!SUB_OUTER_BOUND) {
                SUB_OUTER_BOUND = true;
                document.addEventListener('mousedown', function (e) {
                    var p = document.getElementById('rxSubDrop');
                    if (p && p.style.display !== 'none' && !p.contains(e.target)) closeSubDrop();
                }, true);
            }
        }
        panel.innerHTML =
            '<div style="padding:8px 10px;border-bottom:1px solid var(--border)">' +
            '<input type="text" class="input" id="rxSubKw" placeholder="🔍 搜索子医嘱药品（名称 / 厂家）" autocomplete="off" style="min-height:30px;padding:5px 10px">' +
            '</div>' +
            '<div id="rxSubList" style="max-height:220px;overflow-y:auto"></div>';
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 388)) + 'px';
        panel.style.top = (rect.bottom + 4) + 'px';
        panel.style.display = 'block';
        renderRxSubList(idx, '');
        var subKw = document.getElementById('rxSubKw');
        subKw.addEventListener('input', function () { renderRxSubList(idx, subKw.value); });
        subKw.addEventListener('blur', function () { setTimeout(closeSubDrop, 120); });
        subKw.focus();
    }

    /** 渲染子医嘱列表（与顶部下拉同一数据源/逻辑） */
    function renderRxSubList(idx, kw) {
        var box = document.getElementById('rxSubList');
        if (!box) return;
        var k = (kw || '').trim().toLowerCase();
        var list = CATALOG.filter(function (it) {
            if (!k) return true;
            return (it.name || '').toLowerCase().indexOf(k) !== -1 ||
                (it.company_short || '').toLowerCase().indexOf(k) !== -1;
        });
        box.innerHTML = list.length ? list.map(function (it) { return rxItemHtml(it, false); }).join('') : '<div class="rx-drop-empty">未找到相关药品</div>';
        box.querySelectorAll('.rx-drop-item').forEach(function (el) {
            el.addEventListener('mousedown', function (e) {
                e.preventDefault();
                pickSub(idx, el);
            });
        });
    }

    function closeSubDrop() {
        var panel = document.getElementById('rxSubDrop');
        if (panel) panel.style.display = 'none';
    }

    /* ============ 剂量迷你悬浮窗（结构化规格：固定单位 + 快速选择 + 自动数量） ============ */

    /** 取目标对象：si 未传/为负 = 主药；否则为子医嘱 */
    function doseTarget(idx, si) {
        var s = SELECTED[idx];
        if (!s) return null;
        if (si === undefined || si === null || si < 0) return s;
        return s.sub_items[si] || null;
    }

    function openDosePop(idx, btn, si) {
        var o = doseTarget(idx, si);
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
                    'onclick="Clinic.order.doseQuick(' + idx + ',' + c + ',' + (si === undefined ? 'null' : si) + ')">' + c + '</button>';
            }).join('') +
            '  </div>' +
            '  <div class="fs-12 text-success mt-4" id="rxDoseHint"></div>' +
            '  <div class="flex gap-8 mt-4">' +
            '    <button type="button" class="btn btn-outline btn-sm" style="flex:1" onclick="Clinic.order.closeDosePop()">取消</button>' +
            '    <button type="button" class="btn btn-primary btn-sm" style="flex:1" onclick="Clinic.order.applyDose(' + idx + ',' + (si === undefined ? 'null' : si) + ')">确定</button>' +
            '  </div>' +
            '</div>';
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 232)) + 'px';
        panel.style.top = (rect.bottom + 4) + 'px';
        panel.style.display = 'block';
        panel.__idx = idx;
        panel.__si = (si === undefined ? null : si);
        var val = document.getElementById('rxDoseVal');
        val.addEventListener('input', function () {
            var o2 = doseTarget(idx, panel.__si);
            var hint = document.getElementById('rxDoseHint');
            var v = parseFloat(val.value);
            if (hint && o2 && v > 0 && o2.spec_dose > 0) {
                hint.textContent = '需 ' + Math.max(1, Math.ceil(v / o2.spec_dose)) + ' ' + (o2.spec_pack_unit || '');
            } else if (hint) { hint.textContent = ''; }
        });
        val.focus();
        val.select();
    }

    /** 快速选择：剂量 = 数量×单剂量值，数量 = 该数量向上取整，立即应用 */
    function doseQuick(idx, count, si) {
        var o = doseTarget(idx, si);
        if (!o || !(o.spec_dose > 0)) return;
        o.dose = Math.round(count * o.spec_dose * 100) / 100;
        o.quantity = Math.max(1, Math.ceil(count));
        closeDosePop();
        renderSelected();
    }

    /** 确定：读取输入值，数量 = 剂量/单剂量值 向上取整 */
    function applyDose(idx, si) {
        var o = doseTarget(idx, si);
        if (!o) return;
        var val = parseFloat((document.getElementById('rxDoseVal') || {}).value);
        if (!(val > 0)) { Clinic.toast.warning('请填写剂量'); return; }
        o.dose = Math.round(val * 100) / 100;
        if (o.spec_dose > 0) {
            o.quantity = Math.max(1, Math.ceil(val / o.spec_dose));
        }
        closeDosePop();
        renderSelected();
    }

    function closeDosePop() {
        var p = document.getElementById('rxDosePop');
        if (p) p.style.display = 'none';
    }

    /** 子医嘱下拉选中：追加到对应主药的 sub_items */
    function pickSub(idx, el) {
        var it = itemFromEl(el);
        var s = SELECTED[idx];
        if (!s) return;
        if (s.id && it.id === s.id) {
            Clinic.toast.warning('不能添加与主药相同的药品作为子医嘱');
            closeSubDrop();
            return;
        }
        if (SELECTED.some(function (m) { return m.id === it.id; })) {
            Clinic.toast.warning('该药品已是主医嘱，不能重复添加为子医嘱');
            closeSubDrop();
            return;
        }
        if (s.sub_items.some(function (sub) { return sub.id === it.id; })) {
            Clinic.toast.warning('该子医嘱已存在');
            closeSubDrop();
            return;
        }
        var sub = initDoseFields(it, {
            id: it.id, name: it.name, price: it.price, quantity: 1,
            dose: it.dose, frequency: '', route: '',
            spec: it.spec, unit: it.unit, company_short: it.company_short,
        });
        s.sub_items.push(sub);
        closeSubDrop();
        renderSelected();
    }

    /**
     * 绑定弹窗事件（搜索、选择）
     */
    function bindEvents() {
        // 处方：顶部搜索横条 → 焦点弹出药品下拉；输入即筛选
        var rxk = document.getElementById('rxKw');
        if (rxk) {
            rxk.addEventListener('focus', function () {
                renderRxDrop(rxk.value);
                showRxDrop();
            });
            rxk.addEventListener('input', function () {
                renderRxDrop(rxk.value);
                showRxDrop();
            });
            rxk.addEventListener('blur', function () { setTimeout(hideRxDrop, 120); });
        }
        var kw = document.getElementById('orderKw');
        if (kw) kw.addEventListener('input', applyCatalogFilter);
        document.querySelectorAll('.order-catalog .dd-item').forEach(function (el) {
            el.addEventListener('click', function () {
                handleAdd(itemFromEl(el), el);
            });
        });
        document.querySelectorAll('#labFilterBar .qp-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                LAB_FILTER = chip.getAttribute('data-f');
                document.querySelectorAll('#labFilterBar .qp-chip').forEach(function (c) {
                    c.classList.toggle('active', c === chip);
                });
                applyCatalogFilter();
            });
        });
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
     * quantity=单次数量（向上取整，最小1）；无结构化规格则回退文本剂量。
     */
    function initDoseFields(it, item) {
        var sd = parseFloat(it.spec_dose) || 0;
        var uq = Math.max(1, parseFloat(it.single_use_qty) || 1);
        item.dose_unit = it.spec_dose_unit || '';
        item.spec_dose = sd;
        item.spec_pack_unit = it.spec_pack_unit || '';
        if (sd > 0) {
            item.dose = Math.round(uq * sd * 100) / 100;
            item.quantity = Math.max(1, Math.ceil(uq));
        } else {
            item.dose = it.dose || '';
            item.quantity = 1;
        }
        return item;
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
            // 组合项目补充显示所含成员
            var groupInfo = (s.is_group && s.spec)
                ? '<div class="fs-12 text-muted mt-2">🧩 组合项目，含：' + s.spec + '</div>' : '';
            var head =
                '<div class="flex-between">' +
                '  <div class="flex gap-8" style="align-items:center;min-width:0">' +
                '    <span class="fw-600 fs-13 ellipsis">' + s.name + '</span>' +
                (s.spec ? '<span class="fs-12 text-muted" style="flex-shrink:0">' + s.spec + '</span>' : '') +
                (s.skin_test ? '<span class="badge ' + (s.skin_test === 'yes' ? 'badge-danger' : 'badge-gray') + ' fs-12">' +
                    (s.skin_test === 'yes' ? '需要皮试' : '免试') + '</span>' : '') +
                (s.company_short ? '<span class="fs-12 text-muted">' + s.company_short + '</span>' : '') +
                (s.quantity > 1 ? '<span class="badge badge-primary fs-12">×' + s.quantity + '</span>' : '') +
                '    <span class="fs-12 text-muted">¥' + (s.price * s.quantity).toFixed(2) + '</span>' +
                '  </div>' +
                '  <div class="flex gap-8" style="align-items:center;flex-shrink:0">' +
                (isDrug || CUR_TYPE === 'procedure' ? qtyControls(s, i) : '') +
                (CUR_TYPE === 'procedure' || isDrug ? nurseToggle(s, i) : '') +
                '    <button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                'onclick="Clinic.order.removeItem(' + i + ')">✕</button>' +
                '  </div>' +
                '</div>';
            var extra = isDrug ? drugControls(s, i) : '';
            return '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px">' +
                head + groupInfo + extra + '</div>';
        }).join('') || '<div class="text-muted fs-13 text-center">尚未选择项目</div>';
    }

    /**
     * 更新总费用（主药 + 子医嘱均计费）
     */
    function updateTotal() {
        var total = SELECTED.reduce(function (sum, s) {
            var t = sum + s.price * s.quantity;
            (s.sub_items || []).forEach(function (sub) {
                t += (sub.price || 0) * (sub.quantity || 1);
            });
            return t;
        }, 0);
        document.getElementById('orderTotal').textContent = '¥' + total.toFixed(2);
    }

    /**
     * 数量控制
     */
    function qtyControls(s, i) {
        var isDrug = CUR_TYPE === 'prescription';
        return '<div class="flex gap-4" style="align-items:center">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
            'onclick="Clinic.order.changeQty(' + i + ',-1)">−</button>' +
            '<input type="number" class="input" style="width:52px;padding:3px 6px;min-height:28px;text-align:center" ' +
            'value="' + s.quantity + '" min="1" max="' + (isDrug ? (s.stock || 99) : 99) + '" ' +
            'onchange="Clinic.order.setQty(' + i + ',this.value)">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
            'onclick="Clinic.order.changeQty(' + i + ',1)">＋</button>' +
            (isDrug ? '<span class="fs-12 text-muted">库存' + (s.stock || 0) + '</span>' : '') + '</div>';
    }

    /** 护士站处置逐项勾选（仅处置，默认取管理员设置） */
    function nurseToggle(s, i) {
        return '<label style="display:inline-flex;align-items:center;gap:3px;font-size:12px;cursor:pointer;color:var(--text-muted);user-select:none" title="缴费后护士站显示待执行；取消勾选则不显示">' +
            '<input type="checkbox" style="width:14px;height:14px;accent-color:var(--primary)"' +
            (s.nurse_required ? ' checked' : '') +
            ' onchange="Clinic.order.setNurse(' + i + ',this.checked)"> 护士</label>';
    }

    /**
     * 药品剂量/频次/途径（自动同步，可修改）
     * 成组医嘱：所有药品均可添加子医嘱（不限给药途径）
     */
    function drugControls(s, i) {
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
        // 剂量：结构化规格 → 只读可点击按钮（弹迷你悬浮窗）；否则回退文本输入
        var doseArea = (s.spec_dose > 0)
            ? '<button type="button" class="btn btn-outline btn-sm" style="min-height:28px;font-weight:600" ' +
              'onclick="Clinic.order.openDosePop(' + i + ',this)" title="点击设置剂量（自动计算数量）">' + Clinic.escHtml(doseDisplay(s)) + ' ▾</button>'
            : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
              'value="' + (s.dose || '') + '" placeholder="剂量" onchange="Clinic.order.setField(' + i + ',\'dose\',this.value)">';
        return '<div class="flex gap-8 mt-4" style="flex-wrap:wrap">' +
            doseArea +
            (freqOpts ? '<select class="select" style="width:112px;padding:4px 8px;min-height:28px;font-size:13px" ' +
                'onchange="Clinic.order.setField(' + i + ',\'frequency\',this.value)">' + freqOpts + '</select>'
                : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
                'value="' + (s.frequency || '') + '" placeholder="频次" onchange="Clinic.order.setField(' + i + ',\'frequency\',this.value)">') +
            (routeOpts ? '<select class="select" style="width:112px;padding:4px 8px;min-height:28px;font-size:13px" ' +
                'onchange="Clinic.order.setRoute(' + i + ',this.value)">' + routeOpts + '</select>'
                : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
                'value="' + (s.route || '') + '" placeholder="途径" onchange="Clinic.order.setRoute(' + i + ',this.value)">') +
            '<button type="button" class="btn btn-outline btn-sm" ' +
            'onclick="Clinic.order.openSubDrop(' + i + ',this)">＋ 子医嘱</button>' +
            '</div>' +
            (s.sub_items.length ? subList(s, i) : '');
    }

    /**
     * 子医嘱列表（成组医嘱树状连线：┌ 首个 / ├ 中间 / └ 末尾）
     */
    function subList(s, i) {
        var n = s.sub_items.length;
        return '<div style="margin:6px 0 0 20px;border-left:2px solid var(--warning);padding-left:10px">' +
            '<div class="fs-12 text-muted mb-4">成组医嘱（并入上方主药，途径频次随主药；剂量/数量可独立调整并计费）</div>' +
            s.sub_items.map(function (sub, si) {
                // 子医嘱连接符：非末行 ├ / 末行 └（单个即 └）
                var branch = si === n - 1 ? '└' : '├';
                // 剂量：结构化规格 → 只读可点击按钮；否则文本输入
                var subDose = (sub.spec_dose > 0)
                    ? '<button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px;min-height:22px;font-weight:600" ' +
                      'onclick="Clinic.order.openDosePop(' + i + ',this,' + si + ')" title="点击设置剂量（自动计算数量）">' + Clinic.escHtml(doseDisplay(sub)) + ' ▾</button>'
                    : '<input type="text" class="input" style="width:70px;padding:2px 6px;min-height:22px;font-size:12px" ' +
                      'value="' + (sub.dose || '') + '" placeholder="剂量" onchange="Clinic.order.setSubField(' + i + ',' + si + ',\'dose\',this.value)">';
                return '<div class="flex-between fs-13" style="padding:2px 0;align-items:center">' +
                    '<span style="min-width:0;flex:1;font-family:Menlo,Consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                    branch + ' ' + sub.name +
                    (sub.spec ? ' <span class="text-muted">' + sub.spec + '</span>' : '') +
                    ' ｜ ' + subDose +
                    '</span>' +
                    '<span class="flex gap-4" style="align-items:center;flex-shrink:0;margin-left:8px">' +
                    '<span class="fs-12 text-muted">¥' + ((sub.price || 0) * (sub.quantity || 1)).toFixed(2) + '</span>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px" ' +
                    'onclick="Clinic.order.changeSubQty(' + i + ',' + si + ',-1)">−</button>' +
                    '<input type="number" class="input" style="width:46px;padding:2px 4px;min-height:22px;text-align:center;font-size:12px" ' +
                    'value="' + (sub.quantity || 1) + '" min="1" max="99" ' +
                    'onchange="Clinic.order.setSubQty(' + i + ',' + si + ',this.value)">' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px" ' +
                    'onclick="Clinic.order.changeSubQty(' + i + ',' + si + ',1)">＋</button>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
                    'onclick="Clinic.order.removeSub(' + i + ',' + si + ')">✕</button>' +
                    '</span>' +
                    '</div>';
            }).join('') + '</div>';
    }

    /**
     * 提交开单
     */
    function submit() {
        if (!SELECTED.length) {
            Clinic.toast.warning('请至少选择一个项目');
            return;
        }
        // 处方库存上限校验（仅药品有库存概念；检验/检查/处置项目无库存，不做校验）
        if (CUR_TYPE === 'prescription') {
            for (var i = 0; i < SELECTED.length; i++) {
                if (SELECTED[i].quantity > (SELECTED[i].stock || 0)) {
                    Clinic.toast.warning('【' + SELECTED[i].name + '】数量超过库存');
                    return;
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
                });
                skinChoices.push('');
            });
        });

        Clinic.ajax('/api/order', {
            action: 'submit',
            visit_id: VISIT_ID,
            order_type: CUR_TYPE,
            nurse_required: 0,
            items: JSON.stringify(flat),
            skin_choices: JSON.stringify(skinChoices),
            // 开单与病历强关联：记录当前所在病历（首诊/续写/会诊），
            // 开单科室随病历固化，展示/打印不跨病历串显示
            record_id: (Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.record) ? (Clinic.emr._ctx.DATA.record.record_id || 0) : 0,
            dept_id: (Clinic.emr && Clinic.emr._ctx && Clinic.emr._ctx.DATA && Clinic.emr._ctx.DATA.visit) ? (Clinic.emr._ctx.DATA.visit.current_dept_id || 0) : 0,
        }, {
            loading: true,
            onSuccess: function (j) {
                var msg = j.msg || '开单成功';
                Clinic.toast.success(msg + '，总费用 ¥' + parseFloat(j.data.total).toFixed(2));
                Clinic.modal.close();
                // 申请单/处置单/处方单统一 A5 病历纸样式；检查按分类拆分后一次打印多张
                var ids = j.data.order_ids && j.data.order_ids.length ? j.data.order_ids : [j.data.order_id];
                var q = ids.length > 1 ? 'order_ids=' + ids.join(',') : 'order_id=' + ids[0];
                Clinic.print.load('/api/print?action=order&' + q, null, 'a5');
                Clinic.emr.loadOrders(VISIT_ID);
            },
        });
    }

    /* ============ 对外操作接口 ============ */

    /** 移除已选项目（主药含子药时级联确认） */
    function removeItem(i) {
        var s = SELECTED[i];
        if (s.sub_items && s.sub_items.length) {
            Clinic.modal.confirm(
                '删除主药【' + s.name + '】将同时移除所有关联子医嘱（' + s.sub_items.length + '项），是否确认？',
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
        var max = CUR_TYPE === 'prescription' ? (s.stock || 99) : 99;
        s.quantity = Math.min(max, Math.max(1, s.quantity + delta));
        if (CUR_TYPE === 'prescription' && s.quantity >= (s.stock || 99)) {
            Clinic.toast.warning('数量不能超过库存');
        }
        renderSelected();
    }

    /** 设置数量 */
    function setQty(i, val) {
        var s = SELECTED[i];
        var max = CUR_TYPE === 'prescription' ? (s.stock || 99) : 99;
        var v = Math.min(max, Math.max(1, parseInt(val, 10) || 1));
        if (CUR_TYPE === 'prescription' && parseInt(val, 10) > (s.stock || 99)) Clinic.toast.warning('数量不能超过库存');
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
        confirmPrev: confirmPrev,
    };
})();
