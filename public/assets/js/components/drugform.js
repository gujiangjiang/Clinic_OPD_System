/**
 * ============================================================
 * drugform.js — 药品表单规格编辑器（管理端/药房共用）
 * ============================================================
 * 说明：form_drug() 渲染的药品表单在【药物规格】输入框上绑定
 * openSpecEditor()，管理端（admin/drugs.php）与药房新增药品
 * （pharmacy/dashboard.php）共用同一表单。规格编辑器此前仅在
 * 管理端内联定义，药房打开表单点击规格即报错。抽取为本公共组件，
 * 由 layout.php 全站加载（依赖 Clinic.modal，加载顺序在 modal 之后）。
 * ============================================================ */

/**
 * 打开规格结构化编辑器（二级模态框，保留药品表单在下层）
 * 依赖：window.__doseUnits / window.__packUnits（表单 modal:loaded 时注入历史单位列表）
 */
function openSpecEditor() {
    var dose = document.getElementById('f_spec_dose').value || '';
    var dunit = document.getElementById('f_spec_dose_unit').value || '';
    var pkt = document.getElementById('f_spec_pack_qty').value || '1';
    var punit = document.getElementById('f_spec_pack_unit').value || '';
    // datalist 组合框：已有单位下拉可选 + 直接输入（同检验编辑「计量单位」）
    var dl = function (id, list, cur) {
        var all = list.slice();
        if (cur && all.indexOf(cur) === -1) all.push(cur);
        return '<datalist id="' + id + '">' + all.map(function (u) { return '<option value="' + u + '">'; }).join('') + '</datalist>';
    };
    Clinic.modal.open(
        '<div class="form-row">' +
        '  <div class="form-group"><label class="form-label">单剂量值</label>' +
        '    <div class="flex gap-4"><input class="input" type="number" step="any" min="0" id="se_dose" style="width:70px" value="' + dose + '">' +
        '    <input class="input" id="se_dose_unit" list="se_dose_unit_list" style="width:80px" value="' + dunit + '" placeholder="如 g">' +
        dl('se_dose_unit_list', window.__doseUnits || [], dunit) + '</div>' +
        '  </div>' +
        '  <div class="form-group"><label class="form-label">包装数量 / 单位</label>' +
        '    <div class="flex gap-4"><input class="input" type="number" min="1" id="se_pack_qty" style="width:70px" value="' + pkt + '">' +
        '    <input class="input" id="se_pack_unit" list="se_pack_unit_list" style="width:80px" value="' + punit + '" placeholder="如 粒">' +
        dl('se_pack_unit_list', window.__packUnits || [], punit) + '</div>' +
        '  </div>' +
        '</div>' +
        '<div class="fs-12 text-muted">示例：0.35g×24粒 → 单剂量 0.35、单位 g、包装数量 24、单位 粒。</div>',
        {
            title: '💊 规格编辑',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '保存规格', cls: 'btn-primary', autoClose: false, onClick: function () { seSaveSpec(); } },
            ],
        }
    );
}

/** 保存规格：回写主表单隐藏字段与展示串 */
function seSaveSpec() {
    var dose = parseFloat(document.getElementById('se_dose').value);
    if (!(dose > 0)) { Clinic.toast.warning('请填写单剂量值'); return; }
    var dunit = document.getElementById('se_dose_unit').value.trim();
    var pkt = Math.max(1, parseInt(document.getElementById('se_pack_qty').value, 10) || 1);
    var punit = document.getElementById('se_pack_unit').value.trim();
    document.getElementById('f_spec_dose').value = dose;
    document.getElementById('f_spec_dose_unit').value = dunit;
    document.getElementById('f_spec_pack_qty').value = pkt;
    document.getElementById('f_spec_pack_unit').value = punit;
    document.getElementById('f_spec').value = dose + dunit + (punit !== '' ? '×' + pkt + punit : '');
    Clinic.modal.close();
    syncSplitBox();
    // 规格变化（每包装数量/最小单位）联动刷新库存/警戒单位显示与换算
    if (typeof renderQtyInput === 'function') renderQtyInput();
    if (typeof syncWarn === 'function') syncWarn();
}

/**
 * 拆零零售面板联动（开方单位下拉按 allow_split 决定选项）：
 * · 开启【允许拆零零售】→ 展示拆零参数与「包装售价 / 拆零单价」自动换算；
 * · 关闭 → 隐藏（仅整包装销售）。
 * 拆零单价 = 包装单价 ÷ 每包装最小单位数量（保留 4 位小数，结算按金融四舍五入）。
 * 药品表单为模态框 Ajax 加载（DOMContentLoaded 早于表单渲染），
 * 由各页面 modal:loaded 后调用 bindSplitBox() 完成绑定与初始渲染（幂等）。
 */
var __splitBoxBound = false;
function bindSplitBox() {
    if (__splitBoxBound) { syncSplitBox(); return; }
    __splitBoxBound = true;
    ['f_price', 'f_pkg', 'f_spec_pack_qty', 'f_spec_pack_unit', 'f_allow_split'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', syncSplitBox);
            el.addEventListener('input', syncSplitBox);
        }
    });
    syncSplitBox();
}
function syncSplitBox() {
    var chk = document.getElementById('f_allow_split');
    var box = document.getElementById('split_box');
    if (!chk || !box) return;
    var on = !!chk.checked;
    box.style.display = on ? 'block' : 'none';
    if (!on) return;
    var packUnit = (document.getElementById('f_pkg') || {}).value || '';
    var minUnit = (document.getElementById('f_spec_pack_unit') || {}).value || '';
    var pkt = parseInt((document.getElementById('f_spec_pack_qty') || {}).value, 10) || 1;
    var price = parseFloat((document.getElementById('f_price') || {}).value) || 0;
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
    set('sp_pack_unit_name', packUnit || '—');
    set('sp_min_unit_name', minUnit || '—');
    set('sp_pack_qty_name', pkt);
    // 3.6.2：金额与单位分元素展示，避免「¥16.00 / 盒 / 盒」重复单位
    set('sp_pack_price', '¥' + price.toFixed(2));
    set('sp_pack_price_unit', ' / ' + (packUnit || '盒'));
    set('sp_min_price', '¥' + (pkt > 1 ? (price / pkt).toFixed(4) : '0').replace(/\.?0+$/, ''));
    set('sp_min_price_unit', ' / ' + (minUnit || '个'));
    syncQtyHint();
    syncWarn();
}

/* ==================== 3.6.1 库存录入单位切换（默认包装单位） ==================== */

var __qtyUnitBound = false;
var __qtyDirty = false;

function bindQtyUnit() {
    var btn = document.getElementById('f_qty_unit_btn');
    var qty = document.getElementById('f_qty');
    if (!btn || !qty) return;
    if (__qtyUnitBound) { renderQtyInput(); syncWarn(); return; }
    __qtyUnitBound = true;
    btn.addEventListener('click', function () { toggleQtyUnit(); });
    qty.addEventListener('input', qtyInputChanged);
    qty.addEventListener('change', qtyInputChanged);
    var wb = document.getElementById('f_warn_box');
    if (wb) { wb.addEventListener('input', syncWarn); wb.addEventListener('change', syncWarn); }
    // 规格/包装单位变化后联动刷新单位标签与换算（幂等绑定）
    ['f_pkg', 'f_spec_pack_qty', 'f_spec_pack_unit'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('change', refreshQtyUnits); el.addEventListener('input', refreshQtyUnits); }
    });
    renderQtyInput();
    syncWarn();
}

function qtyPackSize() { return Math.max(1, parseInt((document.getElementById('f_spec_pack_qty') || {}).value, 10) || 1); }
function qtyPackUnit() { var p = document.getElementById('f_pkg'); var v = p ? (p.value || '').trim() : ''; return v || '盒'; }
function qtyMinUnit() { var u = (document.getElementById('f_spec_pack_unit') || {}).value || ''; return u || '支'; }
function qtyCurrentUnit() {
    var u = document.getElementById('f_qty_unit');
    return u && u.value === 'min' ? 'min' : 'pack';
}
function qtyMinValue() {
    var inp = document.getElementById('f_qty');
    var raw = inp ? inp.getAttribute('data-min-qty') : '';
    var v = parseFloat(raw);
    return isNaN(v) ? 0 : v;
}
function setQtyMinValue(v) {
    var inp = document.getElementById('f_qty');
    if (inp) inp.setAttribute('data-min-qty', Math.max(0, Math.round(v)));
}
function renderQtyInput() {
    var inp = document.getElementById('f_qty');
    var btn = document.getElementById('f_qty_unit_btn');
    if (!inp) return;
    var min = qtyMinValue();
    var ps = qtyPackSize();
    var unit = qtyCurrentUnit();
    if (unit === 'min') {
        inp.value = min;
        if (btn) btn.textContent = qtyMinUnit();
    } else {
        inp.value = ps > 1 ? Math.floor(min / ps) : min;
        if (btn) btn.textContent = qtyPackUnit();
    }
    syncQtyHint();
}
function syncQtyHint() {
    var hint = document.getElementById('f_qty_hint');
    if (!hint) return;
    var min = qtyMinValue();
    var ps = qtyPackSize();
    var unit = qtyCurrentUnit();
    if (ps > 1) {
        hint.textContent = unit === 'min'
            ? '当前按最小单位录入（' + min + ' ' + qtyMinUnit() + '）；点击单位可切换为包装单位（' + Math.floor(min / ps) + ' ' + qtyPackUnit() + '）。库存统一以最小单位存储。'
            : '当前按包装单位录入（' + Math.floor(min / ps) + ' ' + qtyPackUnit() + '）；点击单位可切换为最小单位（' + min + ' ' + qtyMinUnit() + '）。库存统一以最小单位存储。';
    } else {
        hint.textContent = '该药品每包装数量为 1，最小单位与包装单位一致，库存直接录入。';
    }
}
function toggleQtyUnit() {
    var u = document.getElementById('f_qty_unit');
    if (!u) return;
    if (qtyPackSize() <= 1) { Clinic.toast.warning('每包装数量为 1 时最小单位与包装单位一致，无需切换'); return; }
    __qtyDirty = false;   // 切换仅改变显示单位，不改变真实最小库存
    u.value = u.value === 'min' ? 'pack' : 'min';
    renderQtyInput();
}
function qtyInputChanged() {
    __qtyDirty = true;
    var inp = document.getElementById('f_qty');
    if (!inp) return;
    var v = parseFloat(inp.value);
    if (isNaN(v) || v < 0) v = 0;
    var ps = qtyPackSize();
    setQtyMinValue((qtyCurrentUnit() === 'min') ? v : v * ps);
    syncQtyHint();
}
/** 对外：保存时返回库存最小单位绝对值（未编辑保留真实值含整包装余量；编辑过按当前单位换算） */
function getFQtyMin() {
    var inp = document.getElementById('f_qty');
    if (!inp) return 0;
    if (!__qtyDirty) return qtyMinValue();
    var v = parseFloat(inp.value);
    if (isNaN(v) || v < 0) v = 0;
    var ps = qtyPackSize();
    return Math.max(0, Math.round((qtyCurrentUnit() === 'min') ? v : v * ps));
}
function refreshQtyUnits() {
    // 规格/包装单位变化：刷新单位按钮标签、换算展示（保持真实最小库存不变）
    renderQtyInput();
    syncWarn();
    if (typeof syncSplitBox === 'function') syncSplitBox();
}
/** 警戒库存换算：包装单位输入 → 最小单位绝对阈值 */
function syncWarn() {
    var wb = document.getElementById('f_warn_box');
    var wh = document.getElementById('f_warn_qty');
    var hint = document.getElementById('f_warn_hint');
    if (!wb || !wh) return;
    var v = Math.max(0, parseInt(wb.value, 10) || 0);
    var ps = qtyPackSize();
    var min = v * ps;
    wh.value = min;
    if (hint) {
        hint.textContent = ps > 1
            ? '库存 ≤ 警戒线时低库存报警。当前换算：' + v + ' ' + qtyPackUnit() + ' = ' + min + ' ' + qtyMinUnit() + '（最小单位绝对阈值）。'
            : '库存 ≤ 警戒线时低库存报警。该药品每包装数量为 1，直接按 ' + qtyPackUnit() + ' 录入。';
    }
}
