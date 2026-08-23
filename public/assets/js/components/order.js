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
 *    支持子处方（静脉输液）、护士站执行选项自动勾选
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
        if (it.frequency_name) p.push(it.frequency_name);
        if (it.route_name) p.push(it.route_name);
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
                size: 'modal-lg',
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
                buildMaps();
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
        var flowSteps = ['开单', '缴费', '登记', CUR_TYPE === 'prescription' ? '药房发药' : '完成'];
        var flow = flowSteps.map(function (s, i) {
            return '<div class="flex gap-8" style="align-items:center">' +
                '<div style="width:24px;height:24px;border-radius:50%;background:var(--border);' +
                'display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0">' +
                (i + 1) + '</div>' +
                '<span class="fs-13 ' + (i === 0 ? 'fw-600' : 'text-muted') + '">' + s + '</span></div>';
        }).join('<div style="width:2px;height:16px;background:var(--border);margin-left:11px"></div>');

        var rows = CATALOG.map(function (it) {
            var info = '';
            if (isDrug) {
                var parts = [];
                if (it.single_dose) parts.push('剂量 ' + it.single_dose);
                if (it.frequency_name) parts.push('频次 ' + it.frequency_name);
                if (it.route_name) parts.push('途径 ' + it.route_name);
                info = parts.length ? '<div class="fs-12 text-muted">' + parts.join(' ｜ ') + '</div>' : '';
            } else if (it.is_group) {
                // 检验组合：显示组内成员，按组价整体收费
                info = '<div class="fs-12 text-muted">🧩 组合项目 ｜ 含：' + (it.members || it.spec || '') +
                    '（按组价整体收费）</div>';
            }
            return '<div class="dd-item" data-id="' + it.id + '" ' +
                'data-price="' + (it.price || 0) + '" data-name="' + (it.name || '').replace(/"/g, '&quot;') + '"' +
                ' data-spec="' + (it.spec || '') + '" data-unit="' + (it.unit_name || it.unit || '') + '"' +
                ' data-company="' + (it.company_short || '') + '"' +
                ' data-dose="' + (it.single_dose || '') + '"' +
                ' data-freq="' + (it.frequency_name || '') + '"' +
                ' data-route="' + (it.route_name || '') + '"' +
                ' data-route-nurse="' + (it.route_nurse_required || 0) + '"' +
                ' data-stock="' + (it.stock || 0) + '"' +
                ' data-nurse-req="' + (it.nurse_required || 0) + '"' +
                ' data-is-group="' + (it.is_group ? 1 : 0) + '"' +
                ' data-members="' + (it.member_ids || '') + '"' +
                '>' +
                '<div class="flex-between">' +
                '  <div><span class="fw-600">' + (it.name || '') + '</span>' +
                (it.is_group ? ' <span class="badge badge-primary fs-12">组合</span>' : '') +
                (isDrug && it.company_short ? ' <span class="fs-12 text-muted">' + it.company_short + '</span>' : '') +
                (it.category_name ? ' <span class="badge badge-gray fs-12">' + it.category_name + '</span>' : '') +
                '</div>' +
                '  <div class="text-right">' +
                '    <div class="fw-600" style="color:var(--primary)">¥' + parseFloat(it.price || 0).toFixed(2) + '</div>' +
                (isDrug ? '<div class="fs-12 ' + (it.stock > 0 ? 'text-success' : 'text-danger') + '">库存：' +
                    (it.stock || 0) + '</div>' : '') +
                '  </div></div>' + info +
                '</div>';
        }).join('') || '<div class="dd-empty">暂无可选项目，请先联系管理员添加</div>';

        var nurseBox = (CUR_TYPE === 'procedure' || CUR_TYPE === 'prescription')
            ? '<div class="mt-12" style="background:var(--warning-soft);border-radius:8px;padding:10px">' +
              '<label style="display:flex;align-items:center;gap:6px;font-size:13px">' +
              '<input type="checkbox" id="nurseReq"> ' +
              (CUR_TYPE === 'prescription' ? '护士站执行' : '护士站处置') + '</label>' +
              '<div class="fs-12 text-muted mt-4">' +
              (CUR_TYPE === 'prescription'
                  ? '静脉输液等途径将自动勾选，可手动取消（取消时需提醒患者注意）'
                  : '勾选后缴费完显示待执行，护士执行完才显示已执行') + '</div></div>'
            : '';

        // 各开单类型的互斥规则提示
        var legend = {
            lab: '提示：组合与所含单项互斥；不同组合共享成员将提醒；既往已开具会二次确认',
            imaging: '提示：同一检查项目仅可添加一次；不同检查分类（如 CT/MR）将自动拆分为多张申请单',
            procedure: '提示：同一处置项目仅可添加一次，数量可在已选列表中手动修改',
            prescription: '提示：同一药品仅可添加一次，数量可在已选列表中手动修改',
        }[CUR_TYPE] || '';

        return '<div class="flex gap-16" style="align-items:stretch">' +
            // 左：项目显示与搜索（较窄）
            '  <div style="width:240px;flex-shrink:0;display:flex;flex-direction:column">' +
            '    <input type="text" class="input" id="orderKw" placeholder="搜索' +
            (isDrug ? '药品名称/厂家简称' : '项目名称') + '" autocomplete="off">' +
            '    <div class="order-catalog" style="flex:1;max-height:400px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-top:8px">' +
            rows + '</div>' +
            '    <div class="fs-12 text-muted mt-8" style="line-height:1.6">' + legend + '</div>' +
            '  </div>' +
            // 中：已选项目（大块）
            '  <div style="flex:1;min-width:0">' +
            '    <div id="prevConfirm" style="display:none;background:var(--warning-soft);border:1px solid var(--warning);border-radius:8px;padding:10px;font-size:13px;margin-bottom:8px"></div>' +
            '    <div class="fs-13 text-muted mb-8">已选 <strong id="selCount">0</strong> 项</div>' +
            '    <div id="selList" style="max-height:400px;overflow-y:auto;padding-right:4px"></div>' +
            '  </div>' +
            // 右：流程闭环追踪（保留）
            '  <div style="width:140px;border-left:1px solid var(--border);padding-left:16px;flex-shrink:0">' +
            '    <div class="fw-600 fs-13 mb-8">流程</div>' + flow +
            '    <div class="mt-16" style="background:var(--bg-soft);border-radius:8px;padding:10px">' +
            '      <div class="fs-12 text-muted">开单总费用</div>' +
            '      <div class="fs-18 fw-700" style="color:var(--danger)" id="orderTotal">¥0.00</div>' +
            '    </div>' + nurseBox +
            '  </div>' +
            '</div>';
    }

    /**
     * 绑定弹窗事件（搜索、选择）
     */
    function bindEvents() {
        var kw = document.getElementById('orderKw');
        kw.addEventListener('input', function () {
            var v = kw.value.toLowerCase();
            document.querySelectorAll('.order-catalog .dd-item').forEach(function (el) {
                el.style.display = el.textContent.toLowerCase().indexOf(v) !== -1 ? '' : 'none';
            });
        });
        document.querySelectorAll('.order-catalog .dd-item').forEach(function (el) {
            el.addEventListener('click', function () {
                handleAdd(itemFromEl(el), el);
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
            unit_name: el.getAttribute('data-unit'),
            company_short: el.getAttribute('data-company'),
            dose: el.getAttribute('data-dose'),
            freq: el.getAttribute('data-freq'),
            route: el.getAttribute('data-route'),
            route_nurse: parseInt(el.getAttribute('data-route-nurse')) || 0,
            stock: parseInt(el.getAttribute('data-stock')) || 0,
            nurse_req: parseInt(el.getAttribute('data-nurse-req')) || 0,
            is_group: parseInt(el.getAttribute('data-is-group')) === 1,
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
     * 加入已选列表
     */
    function pushItem(it, el) {
        // 皮试药品阻断式确认（仅处方）：需皮试在开方时强制医生选择处置方案
        if (CUR_TYPE === 'prescription' && it.need_skin_test === 1) {
            var curName = it.name;
            var skinEl = el;
            mustConfirmSkinTest(curName, function (choice) {
                if (choice === 'cancel') {
                    if (skinEl) skinEl.style.opacity = '';
                    return;
                }
                SELECTED.push({
                    id: it.id,
                    name: it.name,
                    price: it.price,
                    spec: it.spec,
                    unit_name: it.unit_name,
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
                var nc = document.getElementById('nurseReq');
                if (nc && CUR_TYPE === 'prescription') {
                    var last = SELECTED[SELECTED.length - 1];
                    if (last.route_nurse === 1) nc.checked = true;
                }
                if (skinEl) skinEl.style.opacity = '.5';
                renderSelected();
            });
            return;
        }
        SELECTED.push({
            id: it.id,
            name: it.name,
            price: it.price,
            spec: it.spec,
            unit_name: it.unit_name,
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
        });
        var nc = document.getElementById('nurseReq');
        if (nc && CUR_TYPE === 'prescription') {
            var last = SELECTED[SELECTED.length - 1];
            if (last.route_nurse === 1) nc.checked = true;
        }
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
                (s.skin_test ? '<span class="badge ' + (s.skin_test === 'yes' ? 'badge-danger' : 'badge-gray') + ' fs-12">' +
                    (s.skin_test === 'yes' ? '需要皮试' : '免试') + '</span>' : '') +
                (s.company_short ? '<span class="fs-12 text-muted">' + s.company_short + '</span>' : '') +
                (s.quantity > 1 ? '<span class="badge badge-primary fs-12">×' + s.quantity + '</span>' : '') +
                '    <span class="fs-12 text-muted">¥' + (s.price * s.quantity).toFixed(2) + '</span>' +
                '  </div>' +
                '  <div class="flex gap-8" style="align-items:center;flex-shrink:0">' +
                (isDrug || CUR_TYPE === 'procedure' ? qtyControls(s, i) : '') +
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
     * 更新总费用
     */
    function updateTotal() {
        var total = SELECTED.reduce(function (sum, s) {
            return sum + s.price * s.quantity;
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

    /**
     * 药品剂量/频次/途径（自动同步，可修改）
     */
    function drugControls(s, i) {
        var isIV = s.route && s.route.indexOf('静脉') !== -1;
        return '<div class="flex gap-8 mt-4" style="flex-wrap:wrap">' +
            '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
            'value="' + (s.dose || '') + '" placeholder="剂量" onchange="Clinic.order.setField(' + i + ',\'dose\',this.value)">' +
            '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
            'value="' + (s.frequency || '') + '" placeholder="频次" onchange="Clinic.order.setField(' + i + ',\'frequency\',this.value)">' +
            '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" ' +
            'value="' + (s.route || '') + '" placeholder="途径" onchange="Clinic.order.setRoute(' + i + ',this.value)">' +
            (isIV ? '<button type="button" class="btn btn-outline btn-sm" ' +
                'onclick="Clinic.order.addSub(' + i + ')">＋ 子处方</button>' : '') +
            '</div>' +
            (s.sub_items.length ? subList(s, i) : '');
    }

    /**
     * 子处方列表（成组医嘱树状连线：┌ 首个 / ├ 中间 / └ 末尾）
     */
    function subList(s, i) {
        var n = s.sub_items.length;
        return '<div style="margin:6px 0 0 20px;border-left:2px solid var(--warning);padding-left:10px">' +
            '<div class="fs-12 text-muted mb-4">成组医嘱（并入上方输液/注射，剂量单独显示，途径频次随主药）</div>' +
            s.sub_items.map(function (sub, si) {
                var branch = si === 0 ? '┌' : (si === n - 1 ? '└' : '├');
                return '<div class="flex-between fs-13" style="padding:2px 0;font-family:Menlo,Consolas,monospace">' +
                    '<span>' + branch + ' ' + sub.name + ' ｜ 剂量：' + (sub.dose || '—') + '</span>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
                    'onclick="Clinic.order.removeSub(' + i + ',' + si + ')">✕</button></div>';
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
        var nc = document.getElementById('nurseReq');
        var flat = [];
        var skinChoices = [];
        SELECTED.forEach(function (s, idx) {
            flat.push({
                item_id: s.id, item_name: s.name, price: s.price, quantity: s.quantity,
                spec: s.spec, unit_name: s.unit_name, company_short: s.company_short,
                dose: s.dose, frequency: s.frequency, route: s.route,
                notes: '', sub_of: 0, sort: idx,
            });
            // 皮试判定结果（主药行；子处方下标为 null 表示非皮试主药）
            skinChoices.push(s.skin_test || '');
            (s.sub_items || []).forEach(function (sub, si) {
                flat.push({
                    item_id: 0, item_name: sub.name, price: 0, quantity: 1,
                    dose: sub.dose, frequency: '', route: '',
                    spec: '', unit_name: '', company_short: '', notes: '',
                    sub_of: idx + 1, sort: si,
                });
                skinChoices.push('');
            });
        });

        Clinic.ajax('/api/order', {
            action: 'submit',
            visit_id: VISIT_ID,
            order_type: CUR_TYPE,
            nurse_required: nc && nc.checked ? 1 : 0,
            items: JSON.stringify(flat),
            skin_choices: JSON.stringify(skinChoices),
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
                '删除主药【' + s.name + '】将同时移除所有关联子处方（' + s.sub_items.length + '项），是否确认？',
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

    /** 添加子处方 */
    function addSub(idx) {
        Clinic.get('/api/order?action=catalog&type=prescription', null, {
            onSuccess: function (j) {
                var opts = j.data.list.map(function (d) {
                    return '<option value="' + d.id + '" data-dose="' + (d.single_dose || '') + '" ' +
                        'data-name="' + (d.name || '') + '">' + d.name +
                        (d.company_short ? '（' + d.company_short + '）' : '') + '</option>';
                }).join('');
                Clinic.modal.open(
                    '<div class="form-row">' +
                    '  <div class="form-group"><label class="form-label">子处方药品</label>' +
                    '    <select class="select" id="subDrug">' + opts + '</select></div>' +
                    '  <div class="form-group"><label class="form-label">剂量</label>' +
                    '    <input type="text" class="input" id="subDose" placeholder="如：2g"></div>' +
                    '</div>' +
                    '<div class="fs-12 text-muted">子处方并入上方输液关联显示，剂量单独计算，频次途径合并显示。</div>',
                    {
                        title: '添加子处方',
                        size: 'modal-sm',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '添加', cls: 'btn-primary', autoClose: false,
                                onClick: function () {
                                    var sel = document.getElementById('subDrug');
                                    var opt = sel.options[sel.selectedIndex];
                                    if (!opt.value) { Clinic.toast.warning('请选择药品'); return; }
                                    SELECTED[idx].sub_items.push({
                                        id: parseInt(opt.value, 10),
                                        name: opt.getAttribute('data-name'),
                                        dose: document.getElementById('subDose').value.trim() || opt.getAttribute('data-dose'),
                                    });
                                    Clinic.modal.close();
                                    renderSelected();
                                },
                            },
                        ],
                    }
                );
            },
        });
    }

    /** 移除子处方 */
    function removeSub(i, si) {
        SELECTED[i].sub_items.splice(si, 1);
        renderSelected();
    }

    return {
        init: init, open: open, renderSelected: renderSelected,
        removeItem: removeItem, changeQty: changeQty, setQty: setQty,
        setField: setField, setRoute: setRoute, addSub: addSub, removeSub: removeSub,
        confirmPrev: confirmPrev,
    };
})();
