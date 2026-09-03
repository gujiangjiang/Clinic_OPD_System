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
}
