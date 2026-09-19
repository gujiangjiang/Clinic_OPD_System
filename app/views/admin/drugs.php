<?php
/**
 * admin/drugs.php — 药品信息管理
 * 说明：药品名称、通用名称、企业名称及缩写（处方打印显示）、
 * 包装单位、规格/含量、剂型、单次使用剂量、用药频次、使用途径、
 * 数量、单价、是否处方药、是否限制类药品、备注；
 * 途径选中时自动按【给药途径设置】勾选【需护士站处理】。
 * 新增药品需在审核中心通过后方可开方。
 */
Router::title('药品信息');
$__isAdmin = Auth::user() && Auth::user()['role'] === 'admin';
$__isPharmacy = Auth::user() && Auth::user()['role'] === 'pharmacy';
$__canManage = $__isAdmin || $__isPharmacy;
?>
<div class="page-head">
    <div><div class="page-title">💊 药品信息</div><div class="page-desc">药品档案管理<?php echo $__canManage ? '' : '（新增药品需审核通过后可用）'; ?></div></div>
    <div class="flex gap-8">
        <span id="drugImportBtns" class="flex gap-8"></span>
        <button class="btn btn-primary btn-sm" onclick="openDrugForm(0)">＋ 新增药品</button>
    </div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="drugSearch" placeholder="🔍 快速搜索药品 / 通用名 / 厂家" style="width:220px">
        <span class="fs-13 text-muted" id="drugCountDiv"></span>
        <span class="flex gap-4" id="drugCatTabs" style="flex-wrap:wrap"></span>
    </div>
</div>
<div class="card" id="drugList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var DRUG_CAT = '';
var DRUG_ROLE = document.body.getAttribute('data-role') || '';
var IS_ADMIN = DRUG_ROLE === 'admin';
// 药房可维护药品档案（编辑走审核；导入按钮仅管理员）
var IS_DRUG_MANAGER = DRUG_ROLE === 'admin' || DRUG_ROLE === 'pharmacy';
if (!IS_ADMIN) {
    var ib = document.getElementById('drugImportBtns'); if (ib) ib.style.display = 'none';
}
/* v8.17.3 统一分页无限滚动：服务端 page/size/kw/cat 过滤，滚动到底自动加载 */
var DRUG_PAGED = null;
var DRUG_STATE = { kw: '', cat: '' };
function drugListUrl(p, size, st) {
    return '/api/admin?action=drug_list&page=' + p + '&size=' + size +
        '&kw=' + encodeURIComponent(st.kw) + '&cat=' + encodeURIComponent(st.cat);
}
function initDrugPaged() {
    var box = document.getElementById('drugList');
    if (!box) return;
    if (DRUG_PAGED) { DRUG_PAGED.reset(); return; }
    box.innerHTML = '<table class="table" id="drugTable"><tbody></tbody></table>';
    DRUG_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'drugTable',
        state: DRUG_STATE,
        url: drugListUrl,
        countEl: 'drugCountDiv',
        catsEl: 'drugCatTabs',
        kwEl: 'drugSearch',
    });
}
Clinic.importer._reloads['drug'] = loadDrugList;
Clinic.importer.attach('drug', 'drugImportBtns', '药品');
function loadDrugList() { initDrugPaged(); }
initDrugPaged();

function openDrugForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'drug_form', id: id || 0 }, { title: id ? '编辑药品' : '新增药品' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
        // 途径 → 需护士站处理 自动勾选
        var routeMap = (e.detail && e.detail.route_nurse) || {};
        var nurseChk = document.getElementById('f_nurse');
        nurseChk.setAttribute('data-cur', (e.detail && e.detail.is_nurse) || 0);
        window.__routeMap = routeMap;
        // 规格编辑器单位候选（历史已用去重，datalist 下拉/可输入）
        window.__doseUnits = (e.detail && e.detail.dose_units) || [];
        window.__packUnits = (e.detail && e.detail.pack_units) || [];
        // 拆零零售面板联动（f_allow_split 开关 + 包装/拆零单价自动换算）
        if (typeof bindSplitBox === 'function') bindSplitBox();
        // 3.6.1 库存录入单位切换（默认包装单位）+ 警戒库存换算
        if (typeof bindQtyUnit === 'function') bindQtyUnit();
        window.syncNurse = function () {
            var route = document.getElementById('f_route').value;
            if (routeMap[route] === 1) {
                nurseChk.checked = true;
            }
        };

        mask.querySelector('.modal-foot').innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;width:100%">' +
            '<button type="button" id="enabledToggle" class="btn btn-sm btn-success" onclick="toggleItemEnabled()">✅ 启用</button>' +
            '<span><button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="drugSave">保存</button></span></div>';
        initEnabledToggle(id > 0);
        document.getElementById('drugSave').addEventListener('click', function () {
            // 皮试必填校验：勾选"需要皮试"必须关联皮试处置项目
            var skinTestChk = document.getElementById('f_skin_test');
            var skinItemVal = parseInt(document.getElementById('f_skin_item') ? document.getElementById('f_skin_item').value : '0', 10) || 0;
            if (skinTestChk && skinTestChk.checked && !skinItemVal) {
                Clinic.toast.warning('勾选了【需要皮试药品】，请先选择关联的皮试处置项目（点击"选择/新建"）');
                return;
            }
            // 规格结构化必填校验
            var specDose = parseFloat(document.getElementById('f_spec_dose').value);
            if (!(specDose > 0)) {
                Clinic.toast.warning('请先点击【药物规格】设置规格（如 0.5g×24粒）');
                return;
            }
            var specPackUnit = document.getElementById('f_spec_pack_unit').value.trim();
            var useQty = Math.max(1, parseInt(document.getElementById('f_dose').value, 10) || 1);
            // 拆零零售开启校验：包装单位/最小单位/每包装数量(>1) 必须完整
            var allowSplit = document.getElementById('f_allow_split') ? (document.getElementById('f_allow_split').checked ? 1 : 0) : 0;
            if (allowSplit === 1) {
                var packUnitName = (document.getElementById('f_pkg').value || '').trim();
                var specPackQty = parseInt(document.getElementById('f_spec_pack_qty').value, 10) || 1;
                if (packUnitName === '') { Clinic.toast.warning('开启【允许拆零零售】须先选择包装单位（盒/瓶）'); return; }
                if (specPackUnit === '') { Clinic.toast.warning('开启【允许拆零零售】须先设置规格中的最小单位（如 支/粒/片）'); return; }
                if (specPackQty <= 1) { Clinic.toast.warning('开启【允许拆零零售】时每包装数量必须大于 1（如 10 支/盒）'); return; }
            }
            // 单次使用剂量展示串（如 2粒）：随单次数量 + 包装单位推导
            var singleDoseShow = useQty + (specPackUnit !== '' ? specPackUnit : '');
            Clinic.ajax('/api/admin', {
                action: 'drug_save',
                id: id || 0,
                name: document.getElementById('f_name').value.trim(),
                generic_name: document.getElementById('f_generic').value.trim(),
                category: document.getElementById('f_category').value,
                vendor: document.getElementById('f_vendor').value.trim(),
                vendor_short: document.getElementById('f_vendor_short').value.trim(),
                package_unit: document.getElementById('f_pkg').value,
                spec: document.getElementById('f_spec').value.trim(),
                spec_dose: specDose,
                spec_dose_unit: document.getElementById('f_spec_dose_unit').value.trim(),
                spec_pack_qty: Math.max(1, parseInt(document.getElementById('f_spec_pack_qty').value, 10) || 1),
                spec_pack_unit: specPackUnit,
                single_use_qty: useQty,
                allow_split: allowSplit,
                form: document.getElementById('f_form').value,
                single_dose: singleDoseShow,
                frequency: document.getElementById('f_freq').value,
                route: document.getElementById('f_route').value,
                price: document.getElementById('f_price').value,
                // 3.6.1 库存按当前录入单位换算为最小单位绝对值提交
                qty: (typeof getFQtyMin === 'function') ? getFQtyMin() : parseInt(document.getElementById('f_qty').value, 10) || 0,
                // 3.4 警戒库存：按包装单位录入，后端换算最小单位绝对阈值
                warn_box: parseInt(document.getElementById('f_warn_box').value, 10) || 0,
                is_rx: document.getElementById('f_rx').checked ? 1 : 0,
                is_limited: document.getElementById('f_limited').checked ? 1 : 0,
                is_nurse: document.getElementById('f_nurse').checked ? 1 : 0,
                is_skin_test: document.getElementById('f_skin_test') ? (document.getElementById('f_skin_test').checked ? 1 : 0) : 0,
                skin_test_item_id: parseInt(document.getElementById('f_skin_item') ? document.getElementById('f_skin_item').value : '0', 10) || 0,
                note: document.getElementById('f_note').value.trim(),
                enabled: document.getElementById('f_enabled').value,
            }, {
                onSuccess: function (json) {
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    loadDrugList();
                },
            });
        });
    });
}

/* 规格编辑器（openSpecEditor/seSaveSpec）已抽取为公共组件 drugform.js，全站加载，
   管理端与药房新增药品共用同一实现，避免双份维护。 */

function delDrug(id) {
    Clinic.adminItems.delItem({ confirm: '确定删除该药品？', url: '/api/admin', action: 'drug_delete', reload: loadDrugList }, id);
}

loadDrugList();

/* 驳回后点击站内消息跳回：自动打开编辑表单并回填原提交内容（?edit=ID） */
Clinic.adminItems.bindEditDeepLink(openDrugForm);
</script>
