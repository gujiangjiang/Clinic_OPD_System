<?php
/**
 * packages.php — 快速开单套餐管理（管理员 / 医生共用）
 * ============================================================
 * 说明：套餐 = 快速开单预置组合（检验/检查/处置/处方），一键添加。
 * 1. 类型下拉：检验套餐 / 检查套餐 / 处置套餐 / 处方套餐
 * 2. 个人套餐免审；科室/全院套餐提交管理员审核；驳回降级个人可用
 * 3. 新建/编辑弹窗：左侧名称+适用范围，右侧搜索栏 + 套餐内容区
 * 4. 列表无限滚动分页加载（Clinic.infiniteList）
 * ============================================================
 */
Router::title('套餐管理');
$u = Auth::user();
$isAdmin = $u['role'] === 'admin';
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">🥡 套餐管理</div><div class="page-desc">快速开单套餐（检验 / 检查 / 处置 / 处方）</div></div>
    <div class="flex gap-8">
        <select class="select" id="pkgTypeSel" style="width:170px;height:34px;font-size:13px" onchange="pkgChangeType()">
            <option value="lab">检验套餐</option>
            <option value="imaging">检查套餐</option>
            <option value="procedure">处置套餐</option>
            <option value="prescription">处方套餐</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="pkgOpenForm(0)">＋ 新建套餐</button>
    </div>
</div>
<div class="card list-filter">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="pkgKw" placeholder="🔍 搜索套餐名称" style="width:220px">
        <span class="fs-13 text-muted" id="pkgCount"></span>
        <span class="flex gap-4" id="pkgScopeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-pscope="" onclick="pkgSetScope(this,'')">全部</button>
            <button class="btn btn-sm btn-outline" data-pscope="personal" onclick="pkgSetScope(this,'personal')">个人</button>
            <button class="btn btn-sm btn-outline" data-pscope="hospital" onclick="pkgSetScope(this,'hospital')">全院</button>
            <button class="btn btn-sm btn-outline" data-pscope="dept" onclick="pkgSetScope(this,'dept')">科室</button>
        </span>
    </div>
</div>
<div class="card list-card" id="pkgList">
    <div class="empty"><div class="spinner"></div></div>
</div>
</div>

<style>
/* 套餐新建/编辑模态框：固定高度，内容区内部滚动（参考开单模态框模式） */
.modal.pkg-form-modal { height: 700px; }
.modal.pkg-form-modal .modal-body { overflow: hidden; display: flex; }
.modal.pkg-form-modal .pkg-form { flex: 1; min-width: 0; min-height: 0; }
.modal.pkg-form-modal .pkg-right { min-height: 0; }
.pkg-form { display: flex; gap: 14px; }
.pkg-form .pkg-left { width: 300px; flex-shrink: 0; }
.pkg-form .pkg-right { flex: 1; min-width: 0; display: flex; flex-direction: column; }
/* 套餐编辑器：搜索框 + 下拉浮层 + 内容列表 */
.pkg-cat-box { position: relative; }
.pkg-cat-drop {
    display: none; position: absolute; top: 38px; left: 0; right: 0; z-index: 150;
    background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px;
    box-shadow: var(--shadow-lg); max-height: 280px; overflow-y: auto; padding: 4px;
}
.pkg-cat-drop.open { display: block; }
.pkg-cat-drop .dd-item { padding: 7px 10px; font-size: 13px; cursor: pointer; border-radius: 6px; white-space: nowrap; }
.pkg-cat-drop .dd-item:hover { background: var(--primary-soft, #cffafe); color: var(--primary); }
.pkg-cat-empty { padding: 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
/* 套餐内容条目 */
.pkg-item-card {
    border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; margin-bottom: 6px;
    background: var(--bg-card);
}
.pkg-item-card .head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pkg-item-card .head .info { display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; }
.pkg-item-card .head .actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pkg-sub-line { font-family: Menlo, Consolas, monospace; font-size: 12px; margin: 2px 0; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.pkg-sub-line .left { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pkg-sub-line .right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pkg-item-price { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
.pkg-mem-chip {
    display: inline-block; padding: 0 8px; border: 1px solid var(--border); border-radius: 4px;
    background: var(--bg-soft); color: var(--text-muted); font-size: 12px; line-height: 1.7; white-space: nowrap;
}
.pkg-mem-mems { display: inline-flex; flex-wrap: wrap; gap: 4px; vertical-align: middle; }
.pkg-mem-info { line-height: 1.9; }
/* 套餐应用弹窗：勾选条目 */
.pkg-apply-scroll { max-height: 380px; overflow-y: auto; padding-right: 4px; }
.pkg-apply-item {
    border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; margin-bottom: 6px;
    display: flex; align-items: center; gap: 10px; cursor: pointer; background: var(--bg-card);
}
.pkg-apply-item:hover { border-color: var(--primary); }
.pkg-apply-item input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; }
.pkg-apply-item .meta { flex: 1; min-width: 0; }
.pkg-apply-item .price { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
.pkg-apply-sub { font-family: Menlo, Consolas, monospace; font-size: 12px; color: var(--text-muted); margin: 2px 0 2px 26px; }
</style>

<script>
var PKG_TYPE = 'lab';
var PKG_SCOPE = '';          // 范围筛选（空=全部）
var PKG_STATE = { kw: '', cat: '' };   // 套餐列表分页状态（kw=搜索 / cat=scope）
var PKG_PAGED = null;        // 套餐列表 pagedTable
var PKG_ITEMS = [];          // 套餐内容（新建/编辑弹窗内）
var PKG_READONLY = false;    // 只读预览标记（预览时不渲染操作按钮/控件，仅静态展示）
var PKG_CAT_LIST = null;     // 套餐编辑器搜索下拉 infiniteList
var PKG_CAT_KW = '';
var PKG_SUB_LIST = null;     // 处方套餐子医嘱下拉 infiniteList
var RX_FREQS = [];           // 频次/途径选项（处方套餐编辑下拉，随目录首页加载）
var RX_ROUTES = [];
var PKG_SUB_BOUND = false;
var PKG_TAB_GET = null;      // 套餐编辑器搜索框内分类 tab 获取器（''=搜索中全量）
var PKG_RX_CATS = [];        // 处方分类（管理员设置，搜索框内 tab 用）
var PKG_LAB_MAP = null;      // 检验组合/成员映射（按 ID 解析组合显示，不依赖快照字段）

var PKG_TYPE_NAMES = { lab: '检验套餐', imaging: '检查套餐', procedure: '处置套餐', prescription: '处方套餐' };
var PKG_SCOPE_NAMES = { personal: '个人', dept: '科室', hospital: '全院' };
var PKG_STATUS_NAMES = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
var PKG_STATUS_CLS = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };

/* HTML 转义（内联视图用，供套餐列表/弹窗渲染） */

function pkgChangeType() {
    PKG_TYPE = document.getElementById('pkgTypeSel').value;
    PKG_STATE.kw = '';
    PKG_STATE.cat = '';
    var inp = document.getElementById('pkgKw');
    if (inp) inp.value = '';
    document.querySelectorAll('#pkgScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-pscope') === '' ? 'btn-primary' : 'btn-outline');
    });
    if (PKG_PAGED) PKG_PAGED.reset(); else pkgInitList();
}

function pkgSetScope(btn, s) {
    PKG_STATE.cat = s;
    document.querySelectorAll('#pkgScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-pscope') || '') === s ? 'btn-primary' : 'btn-outline');
    });
    if (PKG_PAGED) PKG_PAGED.reset(); else pkgInitList();
}

function pkgResetSearch() {
    if (PKG_PAGED) PKG_PAGED.reset();
    else pkgInitList();
}

function pkgListUrl(p, size, st) {
    return '/api/package?action=list&type=' + PKG_TYPE + '&page=' + p + '&size=' + size +
        '&kw=' + encodeURIComponent(st.kw) + '&scope=' + encodeURIComponent(st.cat);
}

function pkgItemRow(t) {
    var scopeBadge = '<span class="badge badge-primary">' + (PKG_SCOPE_NAMES[t.scope] || t.scope) + '</span>';
    if (t.status === 'pending_review') scopeBadge += ' <span class="fs-12 text-muted">（待审核·暂仅个人可用）</span>';
    var statusBadge = '<span class="badge ' + (PKG_STATUS_CLS[t.status] || 'badge-gray') + '">' + (PKG_STATUS_NAMES[t.status] || t.status) + '</span>';
    var deptText = t.dept_names && t.dept_names.length ? '（' + t.dept_names.join('、') + '）' : '';
    var actions = '';
    if (t.status === 'pending_review') {
        if (<?php echo $isAdmin ? 'true' : 'false'; ?>) {
            actions = '<a class="btn btn-outline btn-sm" href="/admin/review">去审核中心审核</a>';
        } else {
            actions = '<span class="fs-12 text-muted">待审核·不可编辑</span>';
        }
    } else {
        var canManage = <?php echo $isAdmin ? 'true' : 'false'; ?> || t.creator_id === <?php echo (int)$u['id']; ?>;
        if (canManage) {
            actions = '<button class="btn btn-outline btn-sm" onclick="pkgOpenForm(' + t.id + ')">编辑</button>' +
                '<button class="btn btn-outline btn-sm" onclick="pkgDel(' + t.id + ')">删除</button>';
        } else {
            // 他人套餐：复用编辑模态框只读预览
            actions = '<button class="btn btn-outline btn-sm" onclick="previewPkg(' + t.id + ')">预览</button>';
        }
    }
    return '<tr>' +
        '<td class="fw-600">' + escHtml(t.title) + '</td>' +
        '<td>' + scopeBadge + ' ' + deptText + '</td>' +
        '<td class="fs-12 text-muted">' + (t.item_count || 0) + ' 项 ｜ ' + Clinic.money(parseFloat(t.total_price || 0)) + '</td>' +
        '<td>' + escHtml(t.creator_name) + '</td>' +
        '<td>' + statusBadge + '</td>' +
        '<td><div class="flex gap-4">' + actions + '</div></td></tr>';
}

function pkgInitList() {
    var box = document.getElementById('pkgList');
    if (!box) return;
    if (PKG_PAGED) { PKG_PAGED.reset(); return; }
    box.innerHTML = '<div class="table-wrap"><table class="table" id="pkgTable"><tbody></tbody></table></div>';
    PKG_PAGED = Clinic.adminItems.pagedTable({
        tableEl: 'pkgTable',
        state: PKG_STATE,
        url: pkgListUrl,
        countEl: 'pkgCount',
        kwEl: 'pkgKw',
        render: function (list, isFirst, data) { return list.map(pkgItemRow).join(''); },
    });
}

/* ==================== 新建/编辑套餐弹窗 ==================== */
function pkgOpenForm(id) {
    var isEdit = id && id > 0;
    if (PKG_CAT_LIST) { PKG_CAT_LIST.stop(); PKG_CAT_LIST = null; }
    if (PKG_SUB_LIST) { PKG_SUB_LIST.stop(); PKG_SUB_LIST = null; }
    PKG_TAB_GET = null;   // 每次打开重建搜索框内的筛选 tab
    PKG_ITEMS = [];
    RX_FREQS = [];
    RX_ROUTES = [];
    PKG_LAB_MAP = null;   // 每次打开重建组合映射
    var title = isEdit ? '编辑套餐' : '新建套餐';
    if (!isEdit) {
        var html = '<div id="pkgFormBox"></div>';
        var mask = Clinic.modal.open(html, { title: title, size: 'modal-xl pkg-form-modal' });
        pkgBuildForm(mask, null);
    } else {
        var mask = Clinic.modal.load('/api/package?action=get&id=' + id, null, { title: title, size: 'modal-xl pkg-form-modal' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
            if (e.detail && e.detail.lab_map) PKG_LAB_MAP = e.detail.lab_map;
            if (e.detail && e.detail.package) pkgBuildForm(mask, e.detail.package);
        });
    }
}

/** 从目录条目构建套餐项目对象（含处方剂量结构） */
function pkgItemFrom(it) {
    var item = {
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
        // v8.17 拆零销售：允许拆零标记 / 包装单位 / 最小单位；默认单位（拆零→最小单位，否则包装单位）
        allow_split: parseInt(it.allow_split, 10) === 1 ? 1 : 0,
        pack_unit: it.pack_unit || it.unit || '',
        min_unit: it.min_unit || it.spec_pack_unit || '',
        unit_type: (parseInt(it.allow_split, 10) === 1) ? 'min' : 'pack',
        quantity: 1,
        sub_items: [],
        // 检验组合字段（组合保持组合显示）
        is_group: it.is_group ? 1 : 0,
        members: it.members || it.spec || '',
        member_ids: it.member_ids || '',
        member_items: it.member_items || [],
    };
    // 兼容共享药品控件（order.js drugControls/doseDisplay 读取 id/name/dose/dose_unit）
    item.id = item.item_id;
    item.name = item.item_name;
    item.dose = it.dose || it.single_dose || '';
    item.dose_unit = it.spec_dose_unit || '';
    // 新增项目默认有效
    item.valid = 1;
    item.invalid_reason = '';
    // 处方结构化剂量：剂量 = 单次数量×单剂量值，数量按开立单位覆盖所需自动换算
    if (PKG_TYPE === 'prescription' && item.spec_dose > 0) {
        var uq = Math.max(1, item.single_use_qty);
        item.dose = Math.round(uq * item.spec_dose * 100) / 100;
        item.dose_unit = item.spec_dose_unit;
        item.single_dose = item.dose + (item.dose_unit || '');
        if (Clinic.order && Clinic.order.applyUnit) Clinic.order.applyUnit(item);
        item.quantity = (Clinic.order && Clinic.order.autoQty) ? Clinic.order.autoQty(item) : Math.max(1, Math.ceil(uq));
    }
    return item;
}

/** 解析后端已保存的套餐项目为可编辑对象（单个扁平条目 → 可编辑对象） */
function pkgItemFromSaved(it) {
    it = it || {};
    var savedUnitType = it.unit_type === 'min' ? 'min' : 'pack';
    var obj = {
        item_id: parseInt(it.item_id, 10) || 0,
        item_name: it.item_name || '',
        price: parseFloat(it.price) || 0,
        // 包装单价：套餐固化 pack_price 优先；min 单位时 price 为拆零价 → 反推包装价
        pack_price: parseFloat(it.pack_price) > 0
            ? parseFloat(it.pack_price)
            : (parseFloat(it.price) || 0) * (savedUnitType === 'min' ? Math.max(1, parseInt(it.spec_pack_qty, 10) || 1) : 1),
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
        // v8.17 拆零销售：套餐保存时固化开立单位/拆零标记/包装单位，编辑回填保持单位口径
        allow_split: parseInt(it.allow_split, 10) === 1 ? 1 : 0,
        pack_unit: it.pack_unit || it.unit || '',
        min_unit: it.min_unit || it.spec_pack_unit || '',
        unit_type: it.unit_type === 'min' ? 'min' : 'pack',
        quantity: Math.max(1, parseInt(it.quantity, 10) || 1),
        sub_items: [],
        // 检验组合字段（保存时保留组合实体）
        is_group: parseInt(it.is_group, 10) || 0,
        members: it.members || '',
        member_ids: it.member_ids || '',
        member_items: it.member_items || [],
    };
    // 兼容共享药品控件（order.js drugControls/doseDisplay 读取 id/name/dose/dose_unit）
    obj.id = obj.item_id;
    obj.name = obj.item_name;
    // 失效标记（后端 get 返回；编辑回填时据此禁用控件/显示删除线）
    obj.valid = (it.valid === undefined || it.valid === null) ? 1 : (parseInt(it.valid, 10) === 1 ? 1 : 0);
    obj.invalid_reason = it.invalid_reason || '';
    // 剂量展示：结构化 → dose 数值 + unit；否则回退 single_dose 文本
    if (obj.spec_dose > 0) {
        obj.dose = Math.round(obj.quantity * obj.spec_dose * (obj.unit_type === 'min' ? 1 : Math.max(1, obj.spec_pack_qty)) * 100) / 100;
        obj.dose_unit = obj.spec_dose_unit;
    } else {
        obj.dose = obj.single_dose || '';
        obj.dose_unit = '';
    }
    if (Clinic.order && Clinic.order.applyUnit) Clinic.order.applyUnit(obj);
    return obj;
}

/** 后端保存的套餐项目为扁平数组（主药 sub_of=0 / 子医嘱 sub_of=主药序号）。
 * 解析回可编辑对象：主药 + 归并子医嘱到 sub_items */
function pkgItemsFromSaved(items) {
    var mains = [];
    (items || []).forEach(function (it) {
        var s = parseInt(it.sub_of, 10) || 0;
        if (s === 0) mains.push(pkgItemFromSaved(it));
    });
    (items || []).forEach(function (it) {
        var s = parseInt(it.sub_of, 10) || 0;
        if (s > 0 && mains[s - 1]) mains[s - 1].sub_items.push(pkgItemFromSaved(it));
    });
    return mains;
}

function pkgBuildForm(mask, pkg, readonly) {
    PKG_READONLY = !!readonly;
    // 注册套餐条目上下文（复用开处方通用控件：剂量/数量/护士/子医嘱）
    if (window.Clinic && Clinic.order && Clinic.order.rxSetCtx) {
        Clinic.order.rxSetCtx('pkg', function () { return PKG_ITEMS; }, pkgRenderItems, { replaceType: PKG_TYPE, replaceUrlName: 'pkgReplaceUrl' });
    }
    if (pkg) {
        PKG_TYPE = pkg.type;
        var ts = document.getElementById('pkgTypeSel');
        if (ts) ts.value = pkg.type;
        PKG_ITEMS = pkgItemsFromSaved(pkg.items || []);
    } else {
        PKG_ITEMS = [];
    }
    var isDrug = PKG_TYPE === 'prescription';
    var scopeHtml = '<option value="personal"' + (pkg && pkg.scope === 'personal' ? ' selected' : '') + (<?php echo $isAdmin ? 'true' : 'false'; ?> ? ' disabled' : '') + '>个人</option>' +
        '<option value="dept"' + (pkg && pkg.scope === 'dept' ? ' selected' : '') + '>科室</option>' +
        '<option value="hospital"' + (pkg && pkg.scope === 'hospital' ? ' selected' : '') + '>全院</option>';
    var html =
        '<div class="pkg-form">' +
        '  <div class="pkg-left">' +
        '    <div class="form-group"><label class="form-label">套餐名称 <span class="req">*</span></label>' +
        '      <input class="input" id="pkgTitle" value="' + escHtml(pkg ? pkg.title : '') + '" placeholder="如：急诊常规套餐 / 感冒常用药套餐">' +
        '    </div>' +
        '    <div class="form-group"><label class="form-label">适用范围</label>' +
        '      <select class="select" id="pkgScope" onchange="pkgScopeChange()">' + scopeHtml + '</select></div>' +
        '    <div class="form-group" id="pkgDeptWrap" style="display:none"><label class="form-label">选择科室（多选）</label>' +
        '      <div id="pkgDeptTree"></div></div>' +
        '    <div class="fs-12 text-muted">' + (isDrug ? '处方套餐：剂量/频次/途径必填，子医嘱并入主药成组' : '检验组合点击后自动展开为单个检验项目') + '</div>' +
        '  </div>' +
        '  <div class="pkg-right">' +
        '    <div class="pkg-cat-box">' +
        '      <input type="text" class="input" id="pkgCatKw" placeholder="🔍 搜索' + (isDrug ? '药品（名称 / 厂家简称）' : '项目名称') + '，点击加入套餐" autocomplete="off" style="flex-shrink:0">' +
        '    </div>' +
        '    <div class="fs-13 text-muted mt-8 mb-4">套餐内容 <strong id="pkgItemCount">0</strong> 项 ｜ 合计 <strong id="pkgItemTotal" style="color:var(--danger)">¥0.00</strong></div>' +
        '    <div id="pkgItems" style="flex:1;min-height:0;overflow-y:auto;padding-right:4px">' +
        '      <div class="text-muted fs-13 text-center" style="padding:30px">尚未添加项目，请在上方搜索并点击加入</div></div>' +
        '  </div>' +
        '</div>';
    mask.querySelector('.modal-body').innerHTML = html;
    // 只读预览：隐藏套餐搜索添加框（仅查看内容，无需添加项目）
    if (readonly) {
        var cbx = mask.querySelector('.pkg-cat-box');
        if (cbx) cbx.style.display = 'none';
    }
    var treeBox = document.getElementById('pkgDeptTree');
    if (treeBox) {
        Clinic.deptTree.build(treeBox, { selected: (pkg && pkg.dept_ids) || [] });
    }
    // 搜索下拉：聚焦/输入 → 分页加载（滚动续加载）
    pkgInitCatList();
    var catKw = document.getElementById('pkgCatKw');
    if (catKw) {
        catKw.addEventListener('focus', function () {
            var k = (catKw.value || '').trim().toLowerCase();
            if (k !== PKG_CAT_KW) pkgCatReset();
            pkgShowCatDrop();
        });
        // 点击已聚焦输入框：重新弹出下拉（focus 事件在已聚焦时不触发，pkgCatPick 收起后靠点击重开）
        catKw.addEventListener('click', function () {
            pkgShowCatDrop();
        });
        catKw.addEventListener('input', function () {
            clearTimeout(catKw.__t);
            catKw.__t = setTimeout(function () { pkgCatReset(); }, 300);
            pkgShowCatDrop();
        });
        catKw.addEventListener('blur', function () { setTimeout(pkgHideCatDrop, 150); });
    }
    var drop = document.getElementById('pkgCatDrop');
    if (drop) {
        // 条目点击委托已迁至 ensurePkgCatDrop 创建时一次性绑定（body 覆盖层）
    }
    pkgScopeChange();
    // 渲染套餐内容（编辑回填 / 新建空态）；处方频次/途径选项需等目录首页字典返回后再次渲染
    pkgRenderItems();
    // 只读预览：底栏仅提示，不提供保存；编辑模式提供取消/保存
    mask.querySelector('.modal-foot').innerHTML = readonly
        ? '<span class="fs-12 text-muted">🔒 只读预览 — 套餐内容不可编辑、不可保存</span>'
        : '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
          '<button type="button" class="btn btn-primary" id="pkgSaveBtn">保存</button>';
    if (!readonly) {
        document.getElementById('pkgSaveBtn').addEventListener('click', function () { pkgSave(pkg ? pkg.id : 0, pkg ? pkg.status : ''); });
    }
}

/**
 * 套餐只读预览：复用「添加/编辑套餐」同一个模态框（pkgBuildForm），
 * 打开后整框强制只读（modalReadonly 禁用所有控件/拦截交互）。
 * 供「他人套餐」预览与管理员审核统一调用。
 */
function previewPkg(id) {
    if (PKG_CAT_LIST) { PKG_CAT_LIST.stop(); PKG_CAT_LIST = null; }
    if (PKG_SUB_LIST) { PKG_SUB_LIST.stop(); PKG_SUB_LIST = null; }
    PKG_TAB_GET = null;
    PKG_ITEMS = [];
    RX_FREQS = [];
    RX_ROUTES = [];
    PKG_LAB_MAP = null;
    // 管理员可预览任意套餐（含待审核）；普通用户按可见性（for_apply）过滤
    var role = document.body.getAttribute('data-role');
    var url = role === 'admin'
        ? '/api/package?action=get&id=' + id
        : '/api/package?action=get&id=' + id + '&for_apply=1';
    Clinic.get(url, null, {
        onSuccess: function (j) {
            if (!(j.data && j.data.package)) { Clinic.toast.warning('该套餐已被删除或不可见，无法预览'); return; }
            var mask = Clinic.modal.open('<div id="pkgFormBox"></div>', { title: '预览套餐', size: 'modal-xl pkg-form-modal' });
            if (j.data.lab_map) PKG_LAB_MAP = j.data.lab_map;
            pkgBuildForm(mask, j.data.package, true);
            if (Clinic.modalReadonly) Clinic.modalReadonly(mask);
        },
        onError: function () { Clinic.toast.warning('该套餐已被删除或不可见，无法预览'); },
    });
}

function pkgScopeChange() {
    var sel = document.getElementById('pkgScope');
    var wrap = document.getElementById('pkgDeptWrap');
    if (sel && wrap) wrap.style.display = sel.value === 'dept' ? '' : 'none';
}

/* ==================== 套餐编辑器：搜索下拉（分页加载） ==================== */
/** 套餐编辑器搜索下拉：body 上的 fixed 覆盖层（避开模态框 transform 定位/滚动干扰） */
function ensurePkgCatDrop() {
    var box = document.getElementById('pkgCatDrop');
    if (box) return box;
    box = document.createElement('div');
    box.id = 'pkgCatDrop';
    box.className = 'pkg-cat-drop';
    box.style.cssText = 'display:none;position:fixed;top:44px;left:0;right:0;z-index:3200;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);max-height:280px;overflow-y:auto;padding:4px';
    document.body.appendChild(box);
    box.addEventListener('mousedown', function (e) {
        var el = e.target.closest ? e.target.closest('.dd-item') : null;
        if (el) { e.preventDefault(); pkgCatPick(JSON.parse(el.getAttribute('data-it') || '{}')); }
    });
    return box;
}

function pkgCatUrl(p, size) {
    var kw = encodeURIComponent((document.getElementById('pkgCatKw') || {}).value || '');
    var tab = PKG_TAB_GET ? PKG_TAB_GET() : '';
    // 检验：tab=单个/组合 → f 参数；处方：tab=分类 → cat 参数
    if (PKG_TYPE === 'lab') {
        var f = (tab === 'single' || tab === 'group') ? tab : '';
        return '/api/package?action=catalog&type=lab&page=' + p + '&size=' + size + '&kw=' + kw + '&f=' + f;
    }
    if (PKG_TYPE === 'prescription') {
        return '/api/package?action=catalog&type=prescription&page=' + p + '&size=' + size + '&kw=' + kw + '&cat=' + encodeURIComponent(tab);
    }
    return '/api/package?action=catalog&type=' + PKG_TYPE + '&page=' + p + '&size=' + size + '&kw=' + kw;
}
function pkgCatReset() {
    if (PKG_CAT_LIST) PKG_CAT_LIST.reset();
    else pkgInitCatList();
}
function pkgInitCatList() {
    var box = ensurePkgCatDrop();
    if (!box || PKG_CAT_LIST) return;
    PKG_CAT_LIST = Clinic.infiniteList({
        el: box,
        pageSize: 15,
        threshold: 40,
        emptyHtml: '<div class="pkg-cat-empty">未找到相关项目</div>',
        url: pkgCatUrl,
        render: function (list, isFirst) {
            return list.map(function (it) {
                var stockHtml = '';
                // 3.5 处方套餐搜索候选项库存单位联动（允许拆零→最小单位 / 否则包装单位）
                if (PKG_TYPE === 'prescription' && window.Clinic && Clinic.order && Clinic.order.stockText) {
                    var st = Clinic.order.stockText(it, it.allow_split ? 'min' : 'pack');
                    stockHtml = ' <span class="fs-12 text-muted">库存' + st + '</span>';
                }
                return '<div class="dd-item" data-it="' + escHtml(JSON.stringify(it)) + '">' +
                    '<span class="fw-600">' + escHtml(it.name || '') + '</span>' +
                    (it.is_group ? ' <span class="badge badge-primary fs-12">组合</span>' : '') +
                    (it.category_name ? ' <span class="badge badge-gray fs-12">' + escHtml(it.category_name) + '</span>' : '') +
                    (it.company_short ? ' <span class="fs-12 text-muted">' + escHtml(it.company_short) + '</span>' : '') +
                    stockHtml +
                    ' <span class="fs-12 text-muted">' + Clinic.money(parseFloat(it.price || 0)) + '</span></div>';
            }).join('');
        },
        onSuccess: function (json) {
            PKG_CAT_KW = ((document.getElementById('pkgCatKw') || {}).value || '').trim().toLowerCase();
            var d = json.data || {};
            // 检验：组合/成员映射（按 ID 解析组合显示）
            if (d.lab_map) PKG_LAB_MAP = d.lab_map;
            if (d.link_dicts) {
                RX_FREQS = d.link_dicts.frequencies || [];
                RX_ROUTES = d.link_dicts.routes || [];
                PKG_RX_CATS = d.link_dicts.categories || [];
                // 关键：把频次/途径字典写入 order.js 闭包（共享 drugControls 读取），
                // 使套餐编辑器已添加药品的频次/途径显示为下拉而非文本输入
                if (window.Clinic && Clinic.order && Clinic.order.setRxDicts) {
                    Clinic.order.setRxDicts(d.link_dicts.frequencies || [], d.link_dicts.routes || []);
                }
                // 字典就绪后重渲染已选列表：已添加药品的频次/途径下拉即时可用
                pkgRenderItems();
                // 搜索框内附加快速筛选 tab（仅首次；分类就绪后）
                var kwEl = document.getElementById('pkgCatKw');
                if (kwEl && !PKG_TAB_GET && window.Clinic && Clinic.order && Clinic.order.attachSearchTabs) {
                    var tabs = [];
                    if (PKG_TYPE === 'lab') {
                        tabs = [{ value: '', label: '全部' }, { value: 'single', label: '单个' }, { value: 'group', label: '组合' }];
                    } else if (PKG_TYPE === 'prescription') {
                        tabs = [{ value: '', label: '全部' }].concat(PKG_RX_CATS.map(function (c) { return { value: c, label: c }; }));
                    }
                    if (tabs.length > 1) {
                        PKG_TAB_GET = Clinic.order.attachSearchTabs(kwEl, tabs, null, function () { pkgCatReset(); });
                    }
                }
            }
        },
        onError: function () {
            var b = document.getElementById('pkgCatDrop');
            if (b && !b.querySelector('.dd-item')) b.innerHTML = '<div class="pkg-cat-empty">加载失败，请重试</div>';
        },
    });
}
function pkgShowCatDrop() {
    var box = ensurePkgCatDrop();
    if (!box) return;
    var kw = document.getElementById('pkgCatKw');
    if (kw) {
        var r = kw.getBoundingClientRect();
        box.style.left = r.left + 'px';
        box.style.top = (r.bottom + 2) + 'px';
        box.style.width = r.width + 'px';
    }
    // 内联 display 覆盖 class（ensurePkgCatDrop 初始 display:none），必须直接设 inline 显示
    box.style.display = 'block';
}
function pkgHideCatDrop() {
    var box = document.getElementById('pkgCatDrop');
    if (box) { box.style.display = 'none'; box.classList.remove('open'); }
}

/** 下拉点击加入套餐：组合保持组合显示（参考开检验列表样式）；重复检测；加入后收起下拉并 toast */
function pkgCatPick(it) {
    if (!it || !it.id) return;
    pkgHideCatDrop();
    if (PKG_TYPE === 'lab' && it.is_group) {
        // 组合：保持组合实体（套餐中显示组合及其成员，不再拆散为单个）
        if (PKG_ITEMS.some(function (x) { return x.is_group && x.item_id === it.id; })) {
            Clinic.toast.warning('组合【' + it.name + '】已在套餐中');
            return;
        }
        // 组合内含已在套餐中的单个检验 → 重复提醒
        var memIds = (it.member_ids || '').split(',').map(Number).filter(function (n) { return n > 0; });
        var dupMems = PKG_ITEMS.filter(function (x) { return !x.is_group && memIds.indexOf(x.item_id) !== -1; });
        if (dupMems.length) {
            Clinic.toast.warning('组合【' + it.name + '】内含已在套餐中的检验：' + dupMems.map(function (m) { return m.item_name; }).join('、'));
            return;
        }
        var g = pkgItemFrom(it);
        g.is_group = 1;
        g.members = it.members || it.spec || '';
        g.member_ids = it.member_ids || '';
        g.member_items = it.member_items || [];
        PKG_ITEMS.push(g);
        pkgRenderItems();
        Clinic.toast.success('组合【' + it.name + '】已添加到套餐列表');
        return;
    }
    if (PKG_TYPE === 'lab') {
        // 单个检验：不能与套餐内组合的成员重复
        for (var i = 0; i < PKG_ITEMS.length; i++) {
            var gi = PKG_ITEMS[i];
            if (!gi.is_group) continue;
            var mids = (gi.member_ids || '').split(',').map(Number);
            if (mids.indexOf(it.id) !== -1) {
                Clinic.toast.warning('【' + it.name + '】已包含在组合【' + gi.item_name + '】中，请勿重复添加');
                return;
            }
        }
    }
    if (PKG_ITEMS.some(function (x) { return !x.is_group && x.item_id === it.id; })) {
        Clinic.toast.warning('【' + it.name + '】已在套餐中');
        return;
    }
    PKG_ITEMS.push(pkgItemFrom(it));
    pkgRenderItems();
    Clinic.toast.success('【' + it.name + '】已添加到套餐列表');
}

/** 组合成员名称：优先按组合 ID 从 lab_map 解析（权威），其次回退快照 members/spec */
function pkgGroupMemberNames(item) {
    if (item && item.is_group) {
        var ids = [];
        if (PKG_LAB_MAP && PKG_LAB_MAP.groups && PKG_LAB_MAP.groups[item.item_id]) {
            ids = PKG_LAB_MAP.groups[item.item_id];
            return ids.map(function (mid) { return (PKG_LAB_MAP.names && PKG_LAB_MAP.names[mid]) || ('检验项目#' + mid); });
        }
        // 兼容旧数据/快照：member_ids 顿号分隔
        var midStr = item.member_ids || '';
        if (midStr) {
            ids = midStr.split(',').map(Number).filter(function (n) { return n > 0; });
            if (ids.length && PKG_LAB_MAP && PKG_LAB_MAP.names) {
                return ids.map(function (mid) { return PKG_LAB_MAP.names[mid] || ('检验项目#' + mid); });
            }
        }
        // 最后回退快照文本
        var names = (item.members || item.spec || '').split('、').filter(function (n) { return n; });
        if (names.length) return names;
    }
    return [];
}

/** 只读预览：剂量/频次/途径/护士全部保留与编辑一致的输入框/下拉/勾选样式，但全部 disabled；
 * 仅隐藏数量-+/更换/✕（由 pkgRenderItems 只读分支负责不渲染 headActions/更换/✕） */
function pkgReadonlyControls(s) {
    // 剂量：优先展示含单位的单次剂量串（如「2 粒」），结构化规格 → disabled 按钮，否则 disabled 输入框
    var doseText = s.single_dose || (s.dose ? s.dose + (s.dose_unit || '') : '');
    var doseArea = (s.spec_dose > 0)
        ? '<button type="button" class="btn btn-outline btn-sm" disabled style="min-height:28px;font-weight:600">' +
          escHtml(doseText || '—') + '</button>'
        : '<input type="text" class="input" disabled style="width:104px;padding:4px 8px;min-height:28px" value="' +
          escHtml(doseText) + '">';
    var freqSel = '<select class="select" disabled style="width:128px;padding:4px 8px;min-height:28px;font-size:13px">' +
        '<option value="">' + escHtml(s.frequency || '用药频次') + '</option></select>';
    var routeSel = '<select class="select" disabled style="width:128px;padding:4px 8px;min-height:28px;font-size:13px">' +
        '<option value="">' + escHtml(s.route || '使用途径') + '</option></select>';
    var nurseSel = '<label style="display:inline-flex;align-items:center;gap:3px;font-size:12px;color:var(--text-muted);cursor:not-allowed;user-select:none" title="缴费后护士站显示待执行">' +
        '<input type="checkbox" disabled' + (s.nurse_required ? ' checked' : '') + ' style="width:14px;height:14px;accent-color:var(--primary)"> 护士</label>';
    return '<div class="flex gap-8 mt-4" style="flex-wrap:wrap;align-items:center">' + doseArea + freqSel + routeSel + nurseSel + '</div>';
}

/** 只读子医嘱：保留成组医嘱行（名称/规格 + 剂量 + 数量），控件 disabled，隐藏 -/+✕ */
function pkgReadonlySub(s) {
    return '<div class="fs-12 text-muted mt-2" style="margin:6px 0 0 20px;border-left:2px solid var(--warning);padding-left:10px">成组医嘱：' +
        s.sub_items.map(function (sub, si) {
            var branch = si === s.sub_items.length - 1 ? '└' : '├';
            var subDoseText = sub.single_dose || (sub.dose ? sub.dose + (sub.dose_unit || '') : '');
            var subDose = (sub.spec_dose > 0)
                ? '<button type="button" class="btn btn-outline btn-sm" disabled style="padding:1px 8px;min-height:22px;font-weight:600">' + escHtml(subDoseText || '—') + '</button>'
                : '<input type="text" class="input" disabled style="width:70px;padding:2px 6px;min-height:22px;font-size:12px" value="' + escHtml(subDoseText) + '">';
            var subQty = '<input type="number" class="input" disabled style="width:46px;padding:2px 4px;min-height:22px;text-align:center;font-size:12px" value="' + (sub.quantity || 1) + '" min="1">';
            return '<div class="flex-between fs-13" style="padding:2px 0;align-items:center">' +
                '<span style="min-width:0;flex:1;font-family:Menlo,Consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                branch + ' ' + escHtml(sub.name || sub.item_name || '') +
                (sub.spec ? ' <span class="text-muted">' + escHtml(sub.spec) + '</span>' : '') +
                ' ｜ ' + subDose +
                '</span>' +
                '<span class="flex gap-4" style="align-items:center;flex-shrink:0;margin-left:8px">' +
                subQty +
                '</span></div>';
        }).join('') + '</div>';
}

/* ==================== 套餐内容渲染 ==================== */
function pkgRenderItems() {
    var box = document.getElementById('pkgItems');
    if (!box) return;
    var isDrug = PKG_TYPE === 'prescription';
    var total = PKG_ITEMS.reduce(function (s, x) {
        var t = s + Math.round(x.price * x.quantity * 100) / 100;
        (x.sub_items || []).forEach(function (sub) { t += Math.round((sub.price || 0) * (sub.quantity || 1) * 100) / 100; });
        return t;
    }, 0);
    document.getElementById('pkgItemCount').textContent = PKG_ITEMS.length;
    document.getElementById('pkgItemTotal').textContent = '' + Clinic.money(total);
    box.innerHTML = PKG_ITEMS.map(function (s, i) {
        var isReadonly = PKG_READONLY;
        // 只读预览：保留与编辑一致的输入框/下拉样式（disabled），隐藏数量-+/护士/更换/✕
        var extra = '';
        var headActions = '';
        var replaceBtn = '';
        var delBtn = '';
        var subInfo = '';
        if (isDrug) {
            if (!isReadonly) {
                extra = Clinic.order.drugControls('pkg', s, i);
                headActions = Clinic.order.qtyControls('pkg', s, i) + Clinic.order.nurseToggle('pkg', s, i);
            } else {
                // 处方只读：剂量/频次/途径保持输入框/下拉样式但全部 disabled；子医嘱静态展示
                extra = pkgReadonlyControls(s);
                if (s.sub_items.length) subInfo = pkgReadonlySub(s);
            }
        }
        var dis = s.valid === 0;
        if (!isReadonly) {
            // 更换按钮：失效项（原数据变更导致不可用）必须保留，用于更换为可用项目——
            // 这是「更换」按钮的核心用途；非处方在删除左侧；处方靠右显示在头部下方
            replaceBtn = '<button type="button" class="btn btn-outline btn-sm" ' +
                'onclick="Clinic.order.openReplace(\'pkg\',' + i + ',this,\'' + PKG_TYPE + '\',pkgReplaceUrl)" title="快速更换为其他项目">更换</button>';
            delBtn = '<button type="button" class="btn btn-outline btn-sm" onclick="pkgRemoveItem(' + i + ')">✕</button>';
        }
        var head =
            '<div class="head">' +
            '  <div class="info">' +
            '    ' + (Clinic.ellipsis ? Clinic.ellipsis(s.item_name || '', 170, 'fw-600 fs-13' + (s.valid === 0 ? ' pkg-invalid' : '')) : '<span class="fw-600 fs-13">' + escHtml(s.item_name) + '</span>') +
            (s.valid === 0 ? ' <span class="badge badge-gray fs-12">已失效</span>' : '') +
            (s.is_group ? ' <span class="badge badge-primary fs-12">组合</span>' : '') +
            (!s.is_group && s.spec ? (Clinic.ellipsis ? Clinic.ellipsis(s.spec, 140, 'fs-12 text-muted') : ' <span class="fs-12 text-muted">' + escHtml(s.spec) + '</span>') : '') +
            (isDrug && s.frequency ? ' <span class="fs-12 text-muted">' + escHtml(s.frequency) + '</span>' : '') +
            (isDrug && s.route ? ' <span class="fs-12 text-muted">' + escHtml(s.route) + '</span>' : '') +
            (isDrug && s.sale_unit ? ' <span class="fs-12 text-muted">' + escHtml(s.quantity + ' ' + s.sale_unit) + '</span>' : '') +
            (s.quantity > 1 && !(isDrug && s.sale_unit) ? ' <span class="badge badge-primary fs-12">×' + s.quantity + '</span>' : '') +
            '    <span class="pkg-item-price">' + Clinic.money(((s.price || 0) * s.quantity)) + '</span>' +
            '  </div>' +
            '  <div class="actions">' +
            headActions +
            (isDrug ? '' : replaceBtn) +
            delBtn +
            '  </div>' +
            '</div>';
        var groupInfo = '';
        if (s.is_group) {
            // 组合成员标签：按组合 ID 从 lab_map 权威解析（不依赖快照字段，避免保存后缺失）
            var memNames = pkgGroupMemberNames(s);
            if (memNames.length) {
                groupInfo = '<div class="fs-12 text-muted mt-2 pkg-mem-info">🧩 组合项目（按组价整体收费），含：<span class="pkg-mem-mems">' +
                    memNames.map(function (m) { return '<span class="pkg-mem-chip">' + escHtml(m) + '</span>'; }).join('') +
                    '</span></div>';
            }
        }
        var invalidInfo = (s.valid === 0 && s.invalid_reason)
            ? '<div class="fs-12" style="color:var(--danger);margin:4px 0 0">' + escHtml(s.invalid_reason) + '</div>' : '';
        return '<div class="pkg-item-card">' + head + invalidInfo + groupInfo + extra + subInfo + '</div>';
    }).join('') || '<div class="text-muted fs-13 text-center" style="padding:30px">尚未添加项目</div>';
}

/** 套餐编辑器更换选择器的分页地址（走 /api/package catalog，兼容管理员） */
function pkgReplaceUrl(p, size) {
    var kw = encodeURIComponent((document.getElementById('pickerKw') || {}).value || '');
    return '/api/package?action=catalog&type=' + PKG_TYPE + '&page=' + p + '&size=' + size + '&kw=' + kw;
}

/* ==================== 套餐内容操作 ==================== */
function pkgRemoveItem(i) {
    var s = PKG_ITEMS[i];
    if (s && s.sub_items && s.sub_items.length) {
        Clinic.modal.confirm('删除【' + s.item_name + '】将同时移除所有关联子医嘱（' + s.sub_items.length + '项），是否确认？',
            function () { PKG_ITEMS.splice(i, 1); pkgRenderItems(); },
            { title: '确认删除', okText: '确认删除' });
    } else {
        PKG_ITEMS.splice(i, 1);
        pkgRenderItems();
    }
}

/* ==================== 保存套餐 ==================== */
function pkgSave(id, origStatus) {
    var title = document.getElementById('pkgTitle').value.trim();
    if (!title) { Clinic.toast.warning('请填写套餐名称'); return; }
    var scope = document.getElementById('pkgScope').value;
    if (scope === 'dept') {
        var checked = document.querySelectorAll('#pkgDeptTree .deptChk:checked');
        if (!checked.length) { Clinic.toast.warning('请选择至少一个科室'); return; }
    }
    if (!PKG_ITEMS.length) { Clinic.toast.warning('请先添加套餐项目'); return; }
    if (PKG_TYPE === 'prescription') {
        for (var i = 0; i < PKG_ITEMS.length; i++) {
            var s = PKG_ITEMS[i];
            if (!pkgDoseText(s)) { Clinic.toast.warning('请填写【' + s.item_name + '】剂量（必填）'); return; }
            if (!(s.frequency || '').trim()) { Clinic.toast.warning('请选择【' + s.item_name + '】用药频次（必填）'); return; }
            if (!(s.route || '').trim()) { Clinic.toast.warning('请选择【' + s.item_name + '】使用途径（必填）'); return; }
            for (var si = 0; si < (s.sub_items || []).length; si++) {
                if (!pkgDoseText(s.sub_items[si])) {
                    Clinic.toast.warning('请填写子医嘱【' + (s.sub_items[si].item_name || s.sub_items[si].name || '未命名') + '】剂量（必填）');
                    return;
                }
            }
        }
    }
    var deptIds = [];
    document.querySelectorAll('#pkgDeptTree .deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
    // 剂量展示文本（共享控件编辑 dose/dose_unit；保存时转回 single_dose 文本）
    function pkgDoseText(it) {
        var d = (it.dose === '' || it.dose == null) ? '' : String(it.dose);
        return d + (it.dose_unit || '');
    }
    // 扁平化提交：主药 sub_of=0，子医嘱 sub_of=主药序号（1基）
    // v8.17：一并固化开立销售单位（pack/min）与销售单位文本，套餐导入处方时保持单位口径，
    // 单价为销售单位单价（盒=包装价 / 支=拆零价），提交时经后端权威核价。
    var flat = [];
    PKG_ITEMS.forEach(function (s, idx) {
        flat.push({
            item_id: s.item_id, item_name: s.item_name, price: s.price, pack_price: s.pack_price || s.price || 0, quantity: s.quantity,
            spec: s.spec, unit: s.sale_unit || s.unit || '', company_short: s.company_short,
            single_dose: pkgDoseText(s) || s.single_dose || '', frequency: s.frequency, route: s.route,
            nurse_required: s.nurse_required, is_skin_test: s.is_skin_test, skin_test_item_id: s.skin_test_item_id,
            spec_dose: s.spec_dose, spec_dose_unit: s.spec_dose_unit, spec_pack_qty: s.spec_pack_qty,
            spec_pack_unit: s.spec_pack_unit, single_use_qty: s.single_use_qty, sub_of: 0,
            unit_type: s.unit_type || 'pack', pack_unit: s.pack_unit || s.unit || '', allow_split: s.allow_split || 0,
            // 组合检验：保存组合身份（is_group/members/member_ids），后端据此保留组合实体
            is_group: s.is_group ? 1 : 0, members: s.members || s.spec || '', member_ids: s.member_ids || '',
        });
        (s.sub_items || []).forEach(function (sub) {
            flat.push({
                // 子医嘱对象由 order.js itemFromPick 构建：字段为 id/name（非 item_id/item_name），必须回退否则保存为空
                item_id: sub.item_id || sub.id || 0,
                item_name: sub.item_name || sub.name || '', price: sub.price || 0,
                quantity: sub.quantity || 1, spec: sub.spec || '', unit: sub.sale_unit || sub.unit || '',
                company_short: sub.company_short || '', single_dose: pkgDoseText(sub) || sub.single_dose || '',
                frequency: '', route: '', nurse_required: 0, is_skin_test: 0, skin_test_item_id: 0,
                spec_dose: sub.spec_dose || 0, spec_dose_unit: sub.spec_dose_unit || '',
                spec_pack_qty: sub.spec_pack_qty || 1, spec_pack_unit: sub.spec_pack_unit || '',
                single_use_qty: sub.single_use_qty || 1, sub_of: idx + 1,
                unit_type: sub.unit_type || 'pack', pack_unit: sub.pack_unit || sub.unit || '', allow_split: sub.allow_split || 0,
            });
        });
    });
    Clinic.ajax('/api/package', {
        action: 'save', id: id || 0, title: title, type: PKG_TYPE,
        scope: scope, items: JSON.stringify(flat), dept_ids: deptIds.join(','),
    }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            Clinic.modal.close();
            if (PKG_PAGED) PKG_PAGED.reset();
            else pkgInitList();
        },
    });
}

function pkgDel(id) {
    Clinic.modal.confirm('确定删除该套餐？', function () {
        Clinic.ajax('/api/package', { action: 'delete', id: id }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                if (PKG_PAGED) PKG_PAGED.reset();
                else pkgInitList();
            },
        });
    });
}

pkgInitList();

/* 审核中心跳转预览：?preview=ID 自动打开套餐只读预览（复用编辑模态框） */
(function () {
    var m = (location.search.match(/[?&]preview=(\d+)/) || [])[1];
    if (m) {
        setTimeout(function () { previewPkg(parseInt(m, 10)); }, 300);
    }
})();
</script>