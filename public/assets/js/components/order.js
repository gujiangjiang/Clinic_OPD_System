/**
 * ============================================================
 * order.js v1.0.0 — 开单组件（检验/检查/处置/处方）
 * ============================================================
 * 说明：医生开单统一弹窗：
 * 1. 搜索项目（检验/检查/处置/药品），多选或单选
 * 2. 同一项目在同一次开单中仅可选择一次
 * 3. 实时显示开单总费用
 * 4. 处方：数量不得超库存、剂量/频次/途径自动同步药品设置、
 *    支持子处方（静脉输液）、护士站执行选项自动勾选
 * 5. 右侧纵向流程图（开单-缴费-登记-执行-完成）
 * 6. 提交后自动弹出申请单/处方打印
 * 依赖：ajax.js、modal.js、toast.js、print.js、emr.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.order = (function () {
    /** 当前就诊ID */
    var VISIT_ID = 0;
    /** 当前开单类型 */
    var CUR_TYPE = 'lab';
    /** 已选项目列表 */
    var SELECTED = [];
    /** 项目目录缓存 */
    var CATALOG = [];

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
        CUR_TYPE = type;
        SELECTED = [];
        var names = { lab: '开检验', imaging: '开检查', procedure: '开处置', prescription: '开处方' };
        Clinic.get('/api/order?action=catalog&type=' + type, null, {
            onSuccess: function (j) {
                CATALOG = j.data.list;
                Clinic.modal.open(renderDialog(), {
                    title: names[type] || '开单',
                    size: 'modal-lg',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        { text: '提交开单', cls: 'btn-success', autoClose: false, onClick: submit },
                    ],
                });
                bindEvents();
            },
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
                '>' +
                '<div class="flex-between">' +
                '  <div><span class="fw-600">' + (it.name || '') + '</span>' +
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

        return '<div class="flex gap-16">' +
            '  <div style="flex:1;min-width:0">' +
            '    <div class="flex gap-8 mb-8">' +
            '      <input type="text" class="input" id="orderKw" placeholder="搜索' +
            (isDrug ? '药品名称/厂家简称' : '项目名称') + '" style="flex:1" autocomplete="off">' +
            '    </div>' +
            '    <div class="order-catalog" style="max-height:360px;overflow-y:auto;border:1px solid var(--border);border-radius:8px">' +
            rows + '</div>' +
            '    <div class="fs-13 text-muted mt-8">已选 <strong id="selCount">0</strong> 项（同一项目仅可选一次）</div>' +
            '    <div id="selList" class="mt-8"></div>' +
            '  </div>' +
            '  <div style="width:150px;border-left:1px solid var(--border);padding-left:16px;flex-shrink:0">' +
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
                var id = parseInt(el.getAttribute('data-id'), 10);
                if (isSelected(id)) {
                    Clinic.toast.warning('该项目已选择，同一项目仅可选一次');
                    return;
                }
                SELECTED.push({
                    id: id,
                    name: el.getAttribute('data-name'),
                    price: parseFloat(el.getAttribute('data-price')) || 0,
                    spec: el.getAttribute('data-spec'),
                    unit_name: el.getAttribute('data-unit'),
                    company_short: el.getAttribute('data-company'),
                    dose: el.getAttribute('data-dose'),
                    frequency: el.getAttribute('data-freq'),
                    route: el.getAttribute('data-route'),
                    route_nurse: parseInt(el.getAttribute('data-route-nurse')) || 0,
                    stock: parseInt(el.getAttribute('data-stock')) || 0,
                    nurse_required: parseInt(el.getAttribute('data-nurse-req')) || 0,
                    quantity: 1,
                    sub_items: [],
                });
                var nc = document.getElementById('nurseReq');
                if (nc && CUR_TYPE === 'prescription') {
                    var last = SELECTED[SELECTED.length - 1];
                    if (last.route_nurse === 1) nc.checked = true;
                }
                el.style.opacity = '.5';
                renderSelected();
            });
        });
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
            var head =
                '<div class="flex-between">' +
                '  <div class="flex gap-8" style="align-items:center;min-width:0">' +
                '    <span class="fw-600 fs-13 ellipsis">' + s.name + '</span>' +
                (s.company_short ? '<span class="fs-12 text-muted">' + s.company_short + '</span>' : '') +
                '    <span class="fs-12 text-muted">¥' + s.price.toFixed(2) + '</span>' +
                '  </div>' +
                '  <div class="flex gap-8" style="align-items:center;flex-shrink:0">' +
                (isDrug ? qtyControls(s, i) : '') +
                '    <button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                'onclick="Clinic.order.removeItem(' + i + ')">✕</button>' +
                '  </div>' +
                '</div>';
            var extra = isDrug ? drugControls(s, i) : '';
            return '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px">' +
                head + extra + '</div>';
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
        return '<div class="flex gap-4" style="align-items:center">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
            'onclick="Clinic.order.changeQty(' + i + ',-1)">−</button>' +
            '<input type="number" class="input" style="width:52px;padding:3px 6px;min-height:28px;text-align:center" ' +
            'value="' + s.quantity + '" min="1" max="' + (s.stock || 99) + '" ' +
            'onchange="Clinic.order.setQty(' + i + ',this.value)">' +
            '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" ' +
            'onclick="Clinic.order.changeQty(' + i + ',1)">＋</button>' +
            '<span class="fs-12 text-muted">库存' + (s.stock || 0) + '</span></div>';
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
     * 子处方列表
     */
    function subList(s, i) {
        return '<div style="margin:6px 0 0 20px;border-left:3px solid var(--warning);padding-left:12px">' +
            '<div class="fs-12 text-muted mb-4">子处方（并入上方静脉输液，剂量单独显示，频次途径合并）</div>' +
            s.sub_items.map(function (sub, si) {
                return '<div class="flex-between fs-13" style="padding:2px 0">' +
                    '<span>' + sub.name + ' ｜ 剂量：' + (sub.dose || '—') + '</span>' +
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
        // 处方库存上限校验
        for (var i = 0; i < SELECTED.length; i++) {
            if (SELECTED[i].quantity > SELECTED[i].stock) {
                Clinic.toast.warning('【' + SELECTED[i].name + '】数量超过库存');
                return;
            }
        }
        var nc = document.getElementById('nurseReq');
        var flat = [];
        SELECTED.forEach(function (s, idx) {
            flat.push({
                item_id: s.id, item_name: s.name, price: s.price, quantity: s.quantity,
                spec: s.spec, unit_name: s.unit_name, company_short: s.company_short,
                dose: s.dose, frequency: s.frequency, route: s.route,
                notes: '', sub_of: 0, sort: idx,
            });
            (s.sub_items || []).forEach(function (sub, si) {
                flat.push({
                    item_id: 0, item_name: sub.name, price: 0, quantity: 1,
                    dose: sub.dose, frequency: '', route: '',
                    spec: '', unit_name: '', company_short: '', notes: '',
                    sub_of: idx + 1, sort: si,
                });
            });
        });

        Clinic.ajax('/api/order', {
            action: 'submit',
            visit_id: VISIT_ID,
            order_type: CUR_TYPE,
            nurse_required: nc && nc.checked ? 1 : 0,
            items: JSON.stringify(flat),
        }, {
            loading: true,
            onSuccess: function (j) {
                Clinic.toast.success('开单成功，总费用 ¥' + parseFloat(j.data.total).toFixed(2));
                Clinic.modal.close();
                Clinic.print.load('/api/order?action=print&order_id=' + j.data.order_id, null);
                Clinic.emr.loadOrders(VISIT_ID);
            },
        });
    }

    /* ============ 对外操作接口 ============ */

    /** 移除已选项目 */
    function removeItem(i) {
        SELECTED.splice(i, 1);
        // 恢复目录项样式
        var id = SELECTED.length ? null : null;
        renderSelected();
    }

    /** 修改数量 */
    function changeQty(i, delta) {
        var s = SELECTED[i];
        s.quantity = Math.min(s.stock || 99, Math.max(1, s.quantity + delta));
        if (s.quantity >= (s.stock || 99)) {
            Clinic.toast.warning('数量不能超过库存');
        }
        renderSelected();
    }

    /** 设置数量 */
    function setQty(i, val) {
        var s = SELECTED[i];
        var v = Math.min(s.stock || 99, Math.max(1, parseInt(val, 10) || 1));
        if (parseInt(val, 10) > (s.stock || 99)) Clinic.toast.warning('数量不能超过库存');
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
    };
})();
