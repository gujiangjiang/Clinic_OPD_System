<?php
/**
 * packages.php — 快速开单套餐管理（管理员 / 医生共用）
 * 说明：
 * 1. 类型下拉：检验套餐 / 检查套餐 / 处置套餐 / 处方套餐
 * 2. 个人套餐免审即用；科室/全院套餐提交管理员审核；
 *    审核期间个人可用；审核通过科室/全院可用；驳回后降级个人仍可用
 * 3. 新建/编辑弹窗：左侧名称/适用范围/科室树，右侧搜索栏+套餐内容区
 *    - 检验：搜索支持单个+组合，组合点击后展开为单个检验项目入套餐
 *    - 处方：剂量/频次/途径/子医嘱（参考开处方页面设计）
 * 4. 接口：/api/package（list/get/save/delete/catalog）
 */
Router::title('套餐管理');
$u = Auth::user();
$isAdmin = $u['role'] === 'admin';
?>
<div class="page-head">
    <div><div class="page-title">🥡 套餐管理</div><div class="page-desc">快速开单套餐：检验 / 检查 / 处置 / 处方</div></div>
    <div class="flex gap-8">
        <select class="select" id="pkgTypeSel" style="width:170px;height:34px;font-size:13px" onchange="setPkgTypeSel()">
            <option value="lab">检验套餐</option>
            <option value="imaging">检查套餐</option>
            <option value="procedure">处置套餐</option>
            <option value="prescription">处方套餐</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="openPkgForm(0)">＋ 新建套餐</button>
    </div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="pkgSearchKw" placeholder="🔍 搜索套餐名称" style="width:220px" oninput="applyPkgFilter()">
        <span class="flex gap-4" id="pkgScopeTabs" style="flex-wrap:wrap">
            <button class="btn btn-sm btn-primary" data-pscope="" onclick="setPkgScope(this,'')">全部</button>
            <button class="btn btn-sm btn-outline" data-pscope="personal" onclick="setPkgScope(this,'personal')">个人</button>
            <button class="btn btn-sm btn-outline" data-pscope="hospital" onclick="setPkgScope(this,'hospital')">全院</button>
            <button class="btn btn-sm btn-outline" data-pscope="dept" onclick="setPkgScope(this,'dept')">科室</button>
        </span>
    </div>
</div>
<div class="card" id="pkgList">
    <div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>
</div>
<div class="fs-12 text-muted mt-8" id="pkgCount">共 0 个套餐</div>

<style>
.pkg-form { display: flex; gap: 14px; }
.pkg-form .pkg-left { width: 300px; flex-shrink: 0; }
.pkg-form .pkg-right { flex: 1; min-width: 0; display: flex; flex-direction: column; }
/* 套餐编辑器：搜索框 + 下拉浮层 + 内容列表 */
.pkg-cat-box { position: relative; }
.pkg-cat-drop {
    display: none; position: absolute; top: 38px; left: 0; right: 0; z-index: 60;
    background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px;
    box-shadow: var(--shadow-lg); max-height: 260px; overflow-y: auto; padding: 4px;
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
.pkg-apply-item {
    border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; margin-bottom: 6px;
    display: flex; align-items: center; gap: 10px; cursor: pointer; background: var(--bg-card);
}
.pkg-apply-item:hover { border-color: var(--primary); }
.pkg-apply-item input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; }
.pkg-apply-item .meta { flex: 1; min-width: 0; }
.pkg-apply-item .price { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
.pkg-apply-sub { font-family: Menlo, Consolas, monospace; font-size: 12px; color: var(--text-muted); margin: 2px 0 2px 22px; }
.pkg-apply-scroll { max-height: 380px; overflow-y: auto; padding-right: 4px; }
</style>

<script>
var PKG_TYPE = 'lab';
var PKG_SCOPE = '';      // 范围筛选（空=全部）
var PKG_ITEMS = [];      // 套餐内容项目（新建/编辑弹窗内）
var PKG_LIST = null;     // 套餐列表的 infiniteList（分页滚动加载）
var PKG_CAT_LIST = null; // 套餐编辑器搜索下拉的 infiniteList
var PKG_CAT_KW = '';     // 套餐编辑器最近加载关键字
var PKG_SUB_LIST = null; // 处方套餐子医嘱内联下拉
var PKG_CAT_TOTAL = 0;
var RX_FREQS = [];   // 频次选项（处方套餐编辑下拉，随目录首页加载）
var RX_ROUTES = [];  // 途径选项（处方套餐编辑下拉，随目录首页加载）

/* HTML 转义（内联视图用，全局供套餐列表渲染等） */
function escHtml(s) { return Clinic.escHtml(s); }

var PKG_TYPE_NAMES = { lab: '检验套餐', imaging: '检查套餐', procedure: '处置套餐', prescription: '处方套餐' };
var PKG_SCOPE_NAMES = { personal: '个人', dept: '科室', hospital: '全院' };
var PKG_STATUS_NAMES = { published: '已发布', pending_review: '待审核', rejected: '已驳回' };
var PKG_STATUS_CLS = { published: 'badge-success', pending_review: 'badge-warning', rejected: 'badge-gray' };

function setPkgTypeSel() {
    PKG_TYPE = document.getElementById('pkgTypeSel').value;
    PKG_SCOPE = '';
    document.querySelectorAll('#pkgScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-pscope') === '' ? 'btn-primary' : 'btn-outline');
    });
    loadPkgList();
}

function setPkgScope(btn, s) {
    PKG_SCOPE = s;
    document.querySelectorAll('#pkgScopeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-pscope') || '') === s ? 'btn-primary' : 'btn-outline');
    });
    loadPkgList();
}

function applyPkgFilter() {
    clearTimeout(window.__pkgKwT);
    window.__pkgKwT = setTimeout(loadPkgList, 300);
}

function pkgListUrl(p, size) {
    var kw = encodeURIComponent((document.getElementById('pkgSearchKw') || {}).value || '');
    return '/api/package?action=list&type=' + PKG_TYPE + '&scope=' + (PKG_SCOPE || '') + '&page=' + p + '&size=' + size + '&kw=' + kw;
}

function loadPkgList() {
    if (PKG_LIST) PKG_LIST.reset();
    else initPkgList();
}

function initPkgList() {
    var box = document.getElementById('pkgList');
    if (!box) return;
    PKG_LIST = Clinic.infiniteList({
        el: box,
        pageSize: 20,
        threshold: 40,
        totalEl: document.getElementById('pkgCount'),
        emptyHtml: '<div class="empty"><div class="empty-ico">🥡</div>暂无套餐，点击右上角「新建套餐」创建</div>',
        url: pkgListUrl,
        render: pkgRowHtml,
        // 后续页仅返回 tr 行：追加到已有表格 tbody（保证表格样式统一）
        append: function (el, html) {
            var tb = el.querySelector('table tbody');
            if (tb) tb.insertAdjacentHTML('beforeend', html);
            else el.insertAdjacentHTML('beforeend', html);
        },
        onError: function () {
            var b = document.getElementById('pkgList');
            if (b && !b.querySelector('tr')) b.innerHTML = '<div class="empty">加载失败，请重试</div>';
        },
    });
}

function pkgRowHtml(list, isFirst) {
    var rows = list.map(pkgRow).join('');
    if (!isFirst) return rows;
    return '<div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>套餐名称</th><th>类型</th><th>适用范围</th><th>项目</th><th>创建人</th><th>审核状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div>';
}

function pkgRow(t) {
    // 待审核套餐：适用范围展示目标范围（全院/科室），但标注当前仅个人可用
    var scopeBadge = '<span class="badge badge-primary">' + (PKG_SCOPE_NAMES[t.scope] || t.scope) + '</span>';
    if (t.status === 'pending_review') {
        scopeBadge += ' <span class="fs-12 text-muted">（待审核·暂仅个人可用）</span>';
    }
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
            actions += '<button class="btn btn-outline btn-sm" onclick="openPkgForm(' + t.id + ')">编辑</button>';
            actions += '<button class="btn btn-outline btn-sm" onclick="delPkg(' + t.id + ')">删除</button>';
        } else {
            actions = '<span class="fs-12 text-muted">他人套餐</span>';
        }
    }
    return '<tr>' +
        '<td class="fw-600">' + escHtml(t.title) + '</td>' +
        '<td><span class="badge badge-gray">' + (PKG_TYPE_NAMES[t.type] || t.type) + '</span></td>' +
        '<td>' + scopeBadge + ' ' + deptText + '</td>' +
        '<td class="fs-12 text-muted">' + (t.item_count || 0) + ' 项 ｜ ¥' + (t.total_price || 0).toFixed(2) + '</td>' +
        '<td>' + escHtml(t.creator_name) + '</td>' +
        '<td>' + statusBadge + '</td>' +
        '<td><div class="flex gap-4">' + actions + '</div></td></tr>';
}

/* ==================== 新建/编辑套餐弹窗 ==================== */
function openPkgForm(id) {
    var isEdit = id && id > 0;
    var title = isEdit ? '编辑套餐' : '新建套餐';
    if (!isEdit) {
        PKG_ITEMS = [];
        var html = '<div id="pkgFormContent"></div>';
        var mask = Clinic.modal.open(html, { title: title, size: 'modal-xl' });
        buildPkgForm(mask, null);
    } else {
        PKG_ITEMS = [];
        var mask = Clinic.modal.load('/api/package?action=get&id=' + id, null, { title: title, size: 'modal-xl' });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
            if (e.detail && e.detail.package) {
                var p = e.detail.package;
                PKG_TYPE = p.type;
                var sel = document.getElementById('pkgTypeSel');
                if (sel) sel.value = p.type;
                PKG_ITEMS = (p.items || []).map(function (it) {
                    var x = clonePkgItem(it);
                    x.sub_items = (it.sub_items || []).map(clonePkgItem);
                    return x;
                });
                buildPkgForm(mask, p);
            }
        });
    }
}

/** 克隆一个套餐项目为可编辑对象（补齐默认字段） */
function clonePkgItem(it) {
    it = it || {};
    return {
        item_id: parseInt(it.item_id, 10) || 0,
        item_name: it.item_name || '',
        price: parseFloat(it.price) || 0,
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
        quantity: Math.max(1, parseInt(it.quantity, 10) || 1),
        sub_items: [],
    };
}

/** 从下拉条目构建可编辑对象（默认剂量：单次数量×单剂量值，数量向上取整） */
function pkgItemFromDrop(it) {
    var o = clonePkgItem(it);
    var sd = parseFloat(it.spec_dose) || 0;
    var uq = Math.max(1, parseFloat(it.single_use_qty) || 1);
    if (sd > 0) {
        o.single_dose = Math.round(uq * sd * 100) / 100 + (it.spec_dose_unit || '');
        o.quantity = Math.max(1, Math.ceil(uq));
    } else {
        o.single_dose = it.single_dose || '';
        o.quantity = 1;
    }
    return o;
}

function buildPkgForm(mask, pkg) {
    var isDrug = PKG_TYPE === 'prescription';
    var html =
        '<div class="pkg-form">' +
        '  <div class="pkg-left">' +
        '    <div class="form-group"><label class="form-label">套餐名称 <span class="req">*</span></label>' +
        '      <input class="input" id="pfTitle" value="' + escHtml(pkg ? pkg.title : '') + '" placeholder="如：急诊检验套餐 / 感冒常用药套餐">' +
        '    </div>' +
        '    <div class="form-group"><label class="form-label">适用范围</label>' +
        '      <select class="select" id="pfScope" onchange="onPkgScopeChange()">' +
        '        <option value="personal"' + (pkg && pkg.scope === 'personal' ? ' selected' : '') + (isAdmin ? ' disabled' : '') + '>个人</option>' +
        '        <option value="dept"' + (pkg && pkg.scope === 'dept' ? ' selected' : '') + '>科室</option>' +
        '        <option value="hospital"' + (pkg && pkg.scope === 'hospital' ? ' selected' : '') + '>全院</option>' +
        '      </select></div>' +
        '    <div class="form-group" id="pfDeptWrap" style="display:none"><label class="form-label">选择科室（多选）</label>' +
        '      <div id="pfDeptTree"></div></div>' +
        '  </div>' +
        '  <div class="pkg-right">' +
        '    <div class="pkg-cat-box">' +
        '      <input type="text" class="input" id="pkgCatKw" placeholder="🔍 搜索' + (isDrug ? '药品（名称/厂家简称）' : '项目名称') + '，点击加入套餐" autocomplete="off">' +
        '      <div class="pkg-cat-drop" id="pkgCatDrop"></div>' +
        '    </div>' +
        '    <div class="fs-12 text-muted mt-8 mb-4">套餐内容 <strong id="pkgItemCount">0</strong> 项 ｜ 合计 <strong id="pkgItemTotal">¥0.00</strong></div>' +
        '    <div id="pkgItems" style="flex:1;min-height:0;overflow-y:auto;padding-right:4px"><div class="text-muted fs-13 text-center" style="padding:30px">尚未添加项目</div></div>' +
        '  </div>' +
        '</div>';
    mask.querySelector('.modal-body').innerHTML = html;
    var treeBox = document.getElementById('pfDeptTree');
    if (treeBox) {
        Clinic.deptTree.build(treeBox, { selected: (pkg && pkg.dept_ids) || [] });
    }
    // 搜索下拉：分页滚动加载（Clinic.infiniteList）
    initPkgCatList();
    var catKw = document.getElementById('pkgCatKw');
    if (catKw) {
        catKw.addEventListener('focus', function () {
            var k = (catKw.value || '').trim().toLowerCase();
            if (k !== PKG_CAT_KW) pkgCatReset();
            showPkgCatDrop();
        });
        catKw.addEventListener('input', function () {
            clearTimeout(catKw.__t);
            catKw.__t = setTimeout(function () { pkgCatReset(); }, 300);
            showPkgCatDrop();
        });
        catKw.addEventListener('blur', function () { setTimeout(hidePkgCatDrop, 150); });
    }
    // 下拉条目点击（委托：条目随滚动动态生成）
    var drop = document.getElementById('pkgCatDrop');
    if (drop) {
        drop.addEventListener('mousedown', function (e) {
            var el = e.target.closest ? e.target.closest('.dd-item') : null;
            if (el) {
                e.preventDefault();
                addPkgCatItem(JSON.parse(el.getAttribute('data-item') || '{}'));
            }
        });
    }
    window.__pkgDirty = false;
    onPkgScopeChange();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" id="pkgSaveBtn">保存</button>';
    document.getElementById('pkgSaveBtn').addEventListener('click', function () { savePkgForm(pkg ? pkg.id : 0, pkg ? pkg.status : ''); });
}

function onPkgScopeChange() {
    var sel = document.getElementById('pfScope');
    var wrap = document.getElementById('pfDeptWrap');
    if (sel && wrap) wrap.style.display = sel.value === 'dept' ? '' : 'none';
}

/* ==================== 套餐编辑器：搜索下拉（分页滚动加载） ==================== */
function pkgCatUrl(p, size) {
    var kw = encodeURIComponent((document.getElementById('pkgCatKw') || {}).value || '');
    return '/api/package?action=catalog&type=' + PKG_TYPE + '&page=' + p + '&size=' + size + '&kw=' + kw;
}

function pkgCatReset() {
    if (PKG_CAT_LIST) PKG_CAT_LIST.reset();
    else initPkgCatList();
}

function initPkgCatList() {
    var box = document.getElementById('pkgCatDrop');
    if (!box) return;
    if (PKG_CAT_LIST) PKG_CAT_LIST.stop();
    PKG_CAT_LIST = Clinic.infiniteList({
        el: box,
        pageSize: 15,
        threshold: 40,
        emptyHtml: '<div class="pkg-cat-empty">未找到相关项目</div>',
        url: pkgCatUrl,
        render: function (list, isFirst) {
            return list.map(function (it) {
                return '<div class="dd-item" data-item="' + escHtml(JSON.stringify(it).replace(/"/g, '&quot;')) + '">' +
                    '<span class="fw-600">' + escHtml(it.name || '') + '</span>' +
                    (it.is_group ? ' <span class="badge badge-primary fs-12">组合</span>' : '') +
                    (it.category_name ? ' <span class="badge badge-gray fs-12">' + escHtml(it.category_name) + '</span>' : '') +
                    ' <span class="fs-12 text-muted">¥' + parseFloat(it.price || 0).toFixed(2) + '</span></div>';
            }).join('');
        },
        onSuccess: function (json) {
            PKG_CAT_KW = ((document.getElementById('pkgCatKw') || {}).value || '').trim().toLowerCase();
            var d = json.data || {};
            if (d.link_dicts) {
                RX_FREQS = d.link_dicts.frequencies || [];
                RX_ROUTES = d.link_dicts.routes || [];
                renderPkgItems();
            }
        },
        onError: function () {
            var b = document.getElementById('pkgCatDrop');
            if (b && !b.querySelector('.dd-item')) {
                b.innerHTML = '<div class="pkg-cat-empty">加载失败，请重试</div>';
            }
        },
    });
}

function showPkgCatDrop() {
    var box = document.getElementById('pkgCatDrop');
    if (box) box.classList.add('open');
}
function hidePkgCatDrop() {
    var box = document.getElementById('pkgCatDrop');
    if (box) box.classList.remove('open');
}

/** 点击下拉条目加入套餐：组合展开为单个检验项目；药品作为主药（含剂量/频次/途径） */
function addPkgCatItem(it) {
    if (!it || !it.id) return;
    var isDrug = PKG_TYPE === 'prescription';
    if (PKG_TYPE === 'lab' && it.is_group) {
        // 组合展开为单个检验项目（套餐中不再保留组合实体）
        var members = it.member_items || [];
        if (!members.length) { Clinic.toast.warning('该组合暂无可展开的检验项目'); return; }
        var existed = [];
        members.forEach(function (m) {
            if (PKG_ITEMS.some(function (x) { return x.item_id === m.id; })) existed.push(m.name);
        });
        if (existed.length) {
            Clinic.toast.warning('已存在：' + existed.join('、') + '，未重复添加');
            return;
        }
        members.forEach(function (m) { PKG_ITEMS.push(clonePkgItem(m)); });
        renderPkgItems();
        return;
    }
    if (isDrug) {
        // 处方药品：同一药品仅可添加一次（主药或子医嘱）
        if (PKG_ITEMS.some(function (x) { return x.item_id === it.id; })) {
            Clinic.toast.warning('【' + it.name + '】已在套餐中');
            return;
        }
        var o = pkgItemFromDrop(it);
        o.sub_items = [];
        PKG_ITEMS.push(o);
        renderPkgItems();
        return;
    }
    // 检验单个 / 检查 / 处置：同一项目仅可添加一次
    if (PKG_ITEMS.some(function (x) { return x.item_id === it.id; })) {
        Clinic.toast.warning('【' + it.name + '】已在套餐中');
        return;
    }
    PKG_ITEMS.push(clonePkgItem(it));
    renderPkgItems();
}

/** 渲染套餐内容列表 */
function renderPkgItems() {
    var box = document.getElementById('pkgItems');
    if (!box) return;
    var isDrug = PKG_TYPE === 'prescription';
    var total = PKG_ITEMS.reduce(function (s, x) {
        var t = s + x.price * x.quantity;
        (x.sub_items || []).forEach(function (sub) { t += (sub.price || 0) * (sub.quantity || 1); });
        return t;
    }, 0);
    document.getElementById('pkgItemCount').textContent = PKG_ITEMS.length;
    document.getElementById('pkgItemTotal').textContent = '¥' + total.toFixed(2);

    box.innerHTML = PKG_ITEMS.map(function (s, i) {
        // 检验组合（旧数据兜底）按成员标签展示；处方药品显示剂量/频次/途径/子医嘱
        var head =
            '<div class="head">' +
            '  <div class="info">' +
            '    <span class="fw-600 fs-13">' + escHtml(s.item_name || s.name) + '</span>' +
            (s.spec && !s.sub_items.length ? '<span class="fs-12 text-muted">' + escHtml(s.spec) + '</span>' : '') +
            (s.single_dose && isDrug ? '<span class="fs-12 text-muted">' + escHtml(s.single_dose) + '</span>' : '') +
            (s.frequency && isDrug ? '<span class="fs-12 text-muted">' + escHtml(s.frequency) + '</span>' : '') +
            (s.route && isDrug ? '<span class="fs-12 text-muted">' + escHtml(s.route) + '</span>' : '') +
            (s.quantity > 1 ? '<span class="badge badge-primary fs-12">×' + s.quantity + '</span>' : '') +
            '    <span class="pkg-item-price">¥' + (s.price * s.quantity).toFixed(2) + '</span>' +
            '  </div>' +
            '  <div class="actions">' +
            (isDrug
                ? '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" onclick="pkgOpenSubDrop(' + i + ', this)">＋ 子医嘱</button>'
                : '') +
            '    <button type="button" class="btn btn-outline btn-sm" style="padding:1px 8px" onclick="pkgRemoveItem(' + i + ')">✕</button>' +
            '  </div>' +
            '</div>';
        var extra = isDrug ? pkgDrugControls(s, i) : '';
        var groupInfo = (s.is_group && s.spec)
            ? '<div class="fs-12 text-muted mt-2 pkg-mem-info">🧩 组合项目，含：<span class="pkg-mem-mems">' +
              s.spec.split('、').map(function (m) { return '<span class="pkg-mem-chip">' + escHtml(m) + '</span>'; }).join('') +
              '</span></div>' : '';
        return '<div class="pkg-item-card">' + head + groupInfo + extra + '</div>';
    }).join('') || '<div class="text-muted fs-13 text-center" style="padding:30px">尚未添加项目，请在上方搜索并点击加入</div>';
    window.__pkgDirty = true;
}

/** 处方套餐条目控制：剂量输入 + 频次/途径下拉 + 子医嘱列表 */
function pkgDrugControls(s, i) {
    var freqOpts = RX_FREQS.map(function (f) {
        return '<option value="' + f + '"' + (f === s.frequency ? ' selected' : '') + '>' + f + '</option>';
    }).join('');
    var routeOpts = RX_ROUTES.map(function (r) {
        return '<option value="' + r + '"' + (r === s.route ? ' selected' : '') + '>' + r + '</option>';
    }).join('');
    if (s.frequency && freqOpts && RX_FREQS.indexOf(s.frequency) === -1) {
        freqOpts = '<option value="' + s.frequency + '" selected>' + s.frequency + '</option>' + freqOpts;
    }
    if (s.route && routeOpts && RX_ROUTES.indexOf(s.route) === -1) {
        routeOpts = '<option value="' + s.route + '" selected>' + s.route + '</option>' + routeOpts;
    }
    var freqSel = freqOpts
        ? '<select class="select" style="width:128px;padding:4px 8px;min-height:28px;font-size:13px" onchange="pkgSetField(' + i + ',\'frequency\',this.value)">' +
          '<option value="">用药频次</option>' + freqOpts + '</select>'
        : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" value="' + (s.frequency || '') + '" placeholder="频次" onchange="pkgSetField(' + i + ',\'frequency\',this.value)">';
    var routeSel = routeOpts
        ? '<select class="select" style="width:128px;padding:4px 8px;min-height:28px;font-size:13px" onchange="pkgSetField(' + i + ',\'route\',this.value)">' +
          '<option value="">使用途径</option>' + routeOpts + '</select>'
        : '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" value="' + (s.route || '') + '" placeholder="途径" onchange="pkgSetField(' + i + ',\'route\',this.value)">';
    return '<div class="flex gap-8 mt-4" style="flex-wrap:wrap">' +
        '<input type="text" class="input" style="width:104px;padding:4px 8px;min-height:28px" value="' + (s.single_dose || '') + '" placeholder="剂量" onchange="pkgSetField(' + i + ',\'single_dose\',this.value)">' +
        freqSel + routeSel +
        '</div>' +
        (s.sub_items.length ? pkgSubList(s, i) : '');
}

/** 子医嘱列表（树形连线） */
function pkgSubList(s, i) {
    var n = s.sub_items.length;
    return '<div style="margin:6px 0 0 20px;border-left:2px solid var(--warning);padding-left:10px">' +
        '<div class="fs-12 text-muted mb-4">成组医嘱（并入上方主药，途径频次随主药；剂量/数量可独立调整并计费）</div>' +
        s.sub_items.map(function (sub, si) {
            var branch = si === n - 1 ? '└' : '├';
            return '<div class="pkg-sub-line">' +
                '<span class="left">' + branch + ' ' + escHtml(sub.item_name) +
                (sub.spec ? ' <span class="text-muted">' + escHtml(sub.spec) + '</span>' : '') +
                ' ｜ 剂量：<input class="input" style="width:70px;padding:2px 6px;min-height:22px;font-size:12px" value="' + (sub.single_dose || '') + '" onchange="pkgSetSubField(' + i + ',' + si + ',\'single_dose\',this.value)">' +
                '</span>' +
                '<span class="right">' +
                '<span class="pkg-item-price">¥' + ((sub.price || 0) * (sub.quantity || 1)).toFixed(2) + '</span>' +
                '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px" onclick="pkgChangeSubQty(' + i + ',' + si + ',-1)">−</button>' +
                '<input type="number" class="input" style="width:46px;padding:2px 4px;min-height:22px;text-align:center;font-size:12px" value="' + (sub.quantity || 1) + '" min="1" max="99" onchange="pkgSetSubQty(' + i + ',' + si + ',this.value)">' +
                '<button type="button" class="btn btn-outline btn-sm" style="padding:0 7px" onclick="pkgChangeSubQty(' + i + ',' + si + ',1)">＋</button>' +
                '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" onclick="pkgRemoveSub(' + i + ',' + si + ')">✕</button>' +
                '</span>' +
                '</div>';
        }).join('') + '</div>';
}

/* ==================== 套餐编辑器：子医嘱搜索下拉 ==================== */
function pkgOpenSubDrop(idx, btn) {
    var rect = btn.getBoundingClientRect();
    var panel = document.getElementById('pkgSubDrop');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'pkgSubDrop';
        panel.style.cssText = 'position:fixed;z-index:3200;width:380px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);overflow:hidden';
        document.body.appendChild(panel);
        if (!window.__pkgSubBound) {
            window.__pkgSubBound = true;
            document.addEventListener('mousedown', function (e) {
                var p = document.getElementById('pkgSubDrop');
                if (p && p.style.display !== 'none' && !p.contains(e.target)) p.style.display = 'none';
            }, true);
            document.addEventListener('mousedown', function (e) {
                var el = e.target.closest ? e.target.closest('.dd-item') : null;
                if (!el) return;
                var p = document.getElementById('pkgSubDrop');
                if (!p || !p.contains(e.target) || p.style.display === 'none') return;
                e.preventDefault();
                pkgPickSub(parseInt(p.getAttribute('data-sub-idx') || '0', 10), JSON.parse(el.getAttribute('data-item') || '{}'));
            }, true);
        }
    }
    panel.setAttribute('data-sub-idx', idx);
    panel.innerHTML =
        '<div style="padding:8px 10px;border-bottom:1px solid var(--border)">' +
        '<input type="text" class="input" id="pkgSubKw" placeholder="🔍 搜索子医嘱药品（名称 / 厂家）" autocomplete="off" style="min-height:30px;padding:5px 10px">' +
        '</div>' +
        '<div id="pkgSubList" style="max-height:220px;overflow-y:auto"><div class="text-center" style="padding:18px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>';
    panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 388)) + 'px';
    panel.style.top = (rect.bottom + 4) + 'px';
    panel.style.display = 'block';
    initPkgSubList(idx);
    var subKw = document.getElementById('pkgSubKw');
    subKw.addEventListener('input', function () {
        clearTimeout(subKw.__t);
        subKw.__t = setTimeout(function () {
            if (PKG_SUB_LIST) PKG_SUB_LIST.reset();
            else initPkgSubList(idx);
        }, 300);
    });
    subKw.addEventListener('blur', function () { setTimeout(function () { var p = document.getElementById('pkgSubDrop'); if (p) p.style.display = 'none'; }, 120); });
    subKw.focus();
}

function initPkgSubList(idx) {
    var box = document.getElementById('pkgSubList');
    if (!box) return;
    if (PKG_SUB_LIST) PKG_SUB_LIST.stop();
    PKG_SUB_LIST = Clinic.infiniteList({
        el: box,
        pageSize: 15,
        threshold: 40,
        emptyHtml: '<div class="pkg-cat-empty">未找到相关药品</div>',
        url: function (p, size) {
            var kw = encodeURIComponent((document.getElementById('pkgSubKw') || {}).value || '');
            return '/api/package?action=catalog&type=prescription&page=' + p + '&size=' + size + '&kw=' + kw;
        },
        render: function (list, isFirst) {
            return list.map(function (it) {
                return '<div class="dd-item" data-item="' + escHtml(JSON.stringify(it).replace(/"/g, '&quot;')) + '">' +
                    '<span class="fw-600">' + escHtml(it.name || '') + '</span>' +
                    (it.company_short ? ' <span class="fs-12 text-muted">' + escHtml(it.company_short) + '</span>' : '') +
                    ' <span class="fs-12 text-muted">¥' + parseFloat(it.price || 0).toFixed(2) + '</span>' +
                    ' 库存' + (it.stock || 0) + '</div>';
            }).join('');
        },
        onError: function () {
            var b = document.getElementById('pkgSubList');
            if (b && !b.querySelector('.dd-item')) {
                b.innerHTML = '<div class="pkg-cat-empty">加载失败，请重试</div>';
            }
        },
    });
}

function pkgPickSub(idx, it) {
    var s = PKG_ITEMS[idx];
    if (!s || !it.id) return;
    if (s.item_id === it.id) { Clinic.toast.warning('不能添加与主药相同的药品作为子医嘱'); return; }
    if (PKG_ITEMS.some(function (m) { return m.item_id === it.id; })) { Clinic.toast.warning('该药品已是主医嘱，不能重复添加为子医嘱'); return; }
    if (s.sub_items.some(function (sub) { return sub.item_id === it.id; })) { Clinic.toast.warning('该子医嘱已存在'); return; }
    s.sub_items.push(pkgItemFromDrop(it));
    renderPkgItems();
    var p = document.getElementById('pkgSubDrop');
    if (p) p.style.display = 'none';
}

/* ==================== 套餐内容操作 ==================== */
function pkgRemoveItem(i) {
    var s = PKG_ITEMS[i];
    if (s && s.sub_items && s.sub_items.length) {
        Clinic.modal.confirm('删除【' + s.item_name + '】将同时移除所有关联子医嘱（' + s.sub_items.length + '项），是否确认？',
            function () { PKG_ITEMS.splice(i, 1); renderPkgItems(); },
            { title: '确认删除', okText: '确认删除' });
    } else {
        PKG_ITEMS.splice(i, 1);
        renderPkgItems();
    }
}
function pkgSetField(i, field, val) {
    if (PKG_ITEMS[i]) PKG_ITEMS[i][field] = val;
    renderPkgItems();
}
function pkgSetSubField(i, si, field, val) {
    var s = PKG_ITEMS[i];
    if (s && s.sub_items[si]) s.sub_items[si][field] = val;
}
function pkgChangeSubQty(i, si, delta) {
    var s = PKG_ITEMS[i];
    if (!s || !s.sub_items[si]) return;
    s.sub_items[si].quantity = Math.min(99, Math.max(1, (s.sub_items[si].quantity || 1) + delta));
    renderPkgItems();
}
function pkgSetSubQty(i, si, val) {
    var s = PKG_ITEMS[i];
    if (!s || !s.sub_items[si]) return;
    s.sub_items[si].quantity = Math.min(99, Math.max(1, parseInt(val, 10) || 1));
    renderPkgItems();
}
function pkgRemoveSub(i, si) {
    PKG_ITEMS[i].sub_items.splice(si, 1);
    renderPkgItems();
}

/* ==================== 保存套餐 ==================== */
function savePkgForm(id, origStatus) {
    var title = document.getElementById('pfTitle').value.trim();
    if (!title) { Clinic.toast.warning('请填写套餐名称'); return; }
    var scope = document.getElementById('pfScope').value;
    if (scope === 'dept') {
        var checked = document.querySelectorAll('#pfDeptTree .deptChk:checked');
        if (!checked.length) { Clinic.toast.warning('请选择至少一个科室'); return; }
    }
    if (!PKG_ITEMS.length) { Clinic.toast.warning('请先添加套餐项目'); return; }
    // 处方套餐校验：主药需剂量/频次/途径；子药需剂量
    if (PKG_TYPE === 'prescription') {
        for (var i = 0; i < PKG_ITEMS.length; i++) {
            var s = PKG_ITEMS[i];
            if (!(s.single_dose || '').toString().trim()) { Clinic.toast.warning('请填写【' + s.item_name + '】剂量（必填）'); return; }
            if (!(s.frequency || '').trim()) { Clinic.toast.warning('请选择【' + s.item_name + '】用药频次（必填）'); return; }
            if (!(s.route || '').trim()) { Clinic.toast.warning('请选择【' + s.item_name + '】使用途径（必填）'); return; }
            for (var si = 0; si < (s.sub_items || []).length; si++) {
                if (!((s.sub_items[si].single_dose || '').toString().trim())) {
                    Clinic.toast.warning('请填写子医嘱【' + s.sub_items[si].item_name + '】剂量（必填）');
                    return;
                }
            }
        }
    }
    var deptIds = [];
    document.querySelectorAll('#pfDeptTree .deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
    // 扁平化提交：主药 + 子医嘱（sub_of 标记）
    var flat = [];
    PKG_ITEMS.forEach(function (s, idx) {
        flat.push({
            item_id: s.item_id, item_name: s.item_name, price: s.price, quantity: s.quantity,
            spec: s.spec, unit: s.unit, company_short: s.company_short,
            single_dose: s.single_dose, frequency: s.frequency, route: s.route,
            nurse_required: s.nurse_required, is_skin_test: s.is_skin_test, skin_test_item_id: s.skin_test_item_id,
            spec_dose: s.spec_dose, spec_dose_unit: s.spec_dose_unit, spec_pack_qty: s.spec_pack_qty,
            spec_pack_unit: s.spec_pack_unit, single_use_qty: s.single_use_qty,
            sub_of: 0,
        });
        (s.sub_items || []).forEach(function (sub, si) {
            flat.push({
                item_id: sub.item_id || 0, item_name: sub.item_name, price: sub.price || 0,
                quantity: sub.quantity || 1, spec: sub.spec || '', unit: sub.unit || '',
                company_short: sub.company_short || '', single_dose: sub.single_dose || '',
                frequency: '', route: '', nurse_required: 0, is_skin_test: 0, skin_test_item_id: 0,
                spec_dose: sub.spec_dose || 0, spec_dose_unit: sub.spec_dose_unit || '',
                spec_pack_qty: sub.spec_pack_qty || 1, spec_pack_unit: sub.spec_pack_unit || '',
                single_use_qty: sub.single_use_qty || 1, sub_of: idx + 1,
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
            loadPkgList();
        },
    });
}

/* ==================== 删除套餐 ==================== */
function delPkg(id) {
    Clinic.modal.confirm('确定删除该套餐？', function () {
        Clinic.ajax('/api/package', { action: 'delete', id: id }, {
            onSuccess: function (j) { Clinic.toast.success(j.msg); loadPkgList(); },
        });
    });
}

loadPkgList();
</script>