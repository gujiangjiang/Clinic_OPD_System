<?php
Router::title('检验项目管理');
?>
<div class="page-head">
    <div><div class="page-title">🧪 检验项目管理</div><div class="page-desc">检验项目与组合管理（组合按组价整体收费，新项目需审核通过后可用）</div></div>
    <div class="flex gap-8">
        <button class="btn btn-primary btn-sm" onclick="openComboMgr()">🧩 检验组合管理</button>
        <button class="btn btn-outline btn-sm" onclick="openCatMgr()">🗂️ 分类管理</button>
        <button class="btn btn-outline btn-sm" onclick="openItemForm(0)">＋ 新增检验项目</button>
    </div>
</div>

<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="labSearch" placeholder="🔍 快速搜索检验项目" style="width:220px" oninput="applyLabFilter()">
        <span class="flex gap-4" id="labCatTabs" style="flex-wrap:wrap"></span>
    </div>
</div>

<div class="card" id="itemList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var LAB_CAT = '';
function buildLabCats() {
    var cats = [];
    document.querySelectorAll('#itemList tbody tr').forEach(function (tr) {
        var c = tr.getAttribute('data-cat') || '';
        if (c && cats.indexOf(c) === -1) cats.push(c);
    });
    var bar = document.getElementById('labCatTabs');
    bar.innerHTML = '<button class="btn btn-sm ' + (LAB_CAT === '' ? 'btn-primary' : 'btn-outline') + '" onclick="labCatFilter(this,\'\')">全部</button>' +
        cats.map(function (c) { return '<button class="btn btn-sm ' + (LAB_CAT === c ? 'btn-primary' : 'btn-outline') + '" onclick="labCatFilter(this,\'' + c + '\')">' + c + '</button>'; }).join('');
}
function labCatFilter(btn, c) { LAB_CAT = c; document.querySelectorAll('#labCatTabs .btn').forEach(function (b) { b.className = 'btn btn-sm ' + ((b.getAttribute('data-cat') || '') === c ? 'btn-primary' : 'btn-outline'); }); applyLabFilter(); }
function applyLabFilter() {
    var q = (document.getElementById('labSearch').value || '').trim().toLowerCase(); var n = 0;
    document.querySelectorAll('#itemList tbody tr').forEach(function (tr) {
        var hit = (LAB_CAT === '' || tr.getAttribute('data-cat') === LAB_CAT) && tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none'; if (hit) n++;
    });
    var cnt = document.getElementById('labCountDiv'); if (cnt) cnt.textContent = LAB_CAT === '' ? (q ? '检验项目 ' + n + ' 项' : '检验项目共 ' + n + ' 项') : '检验项目（' + LAB_CAT + '）' + (q ? n + ' 项' : '共 ' + n + ' 项');
}
function buildLabCats() {
    var cats = [];
    document.querySelectorAll('#itemList tbody tr').forEach(function (tr) {
        var c = tr.getAttribute('data-cat') || '';
        if (c && cats.indexOf(c) === -1) cats.push(c);
    });
    var bar = document.getElementById('labCatTabs');
    bar.innerHTML = '<button class="btn btn-sm ' + (LAB_CAT === '' ? 'btn-primary' : 'btn-outline') + '" data-cat="" onclick="labCatFilter(this,\'\')">全部</button>' +
        cats.map(function (c) { return '<button class="btn btn-sm ' + (LAB_CAT === c ? 'btn-primary' : 'btn-outline') + '" data-cat="' + c + '" onclick="labCatFilter(this,\'' + c + '\')">' + c + '</button>'; }).join('');
}
Clinic.importer._reloads['lab'] = loadItemList;
Clinic.importer.attach('lab', 'impBtns', '检验项目');
function loadItemList() { Clinic.get('/api/admin?action=item_list&type=lab', null, { onSuccess: function (j) { document.getElementById('itemList').innerHTML = j.data.html; buildLabCats(); applyLabFilter(); } }); }

/* ==================== 检验组合管理器（两列模态框） ==================== */
var COMBOS = [];
var CUR_COMBO = null;
var COMBO_CANDS = [];

function openComboMgr() {
    Clinic.get('/api/admin?action=lab_groups', null, { onSuccess: function (j) {
        COMBOS = j.data.list || [];
        Clinic.get('/api/admin?action=lab_group_candidates', null, { onSuccess: function (j2) {
            COMBO_CANDS = j2.data.list || [];
            renderComboMgr();
        } });
    } });
}
function renderComboMgr() {
    var leftList = COMBOS.map(function (c) {
        return '<div class="combo-item" onclick="selectCombo(' + c.id + ')" id="comboRow_' + c.id + '">' +
            c.name + ' <span class="text-muted fs-12">（' + (c.member_count || 0) + ' 项）</span></div>';
    }).join('') || '<div class="text-muted fs-13" style="padding:10px">暂无检验组合</div>';
    var html =
        '<div class="combo-mgr">' +
        '  <div class="combo-left">' +
        '    <button class="btn btn-primary btn-sm btn-block" onclick="newComboPop(event)">＋ 新增检验组合</button>' +
        '    <input class="input mt-8" id="comboSearch" placeholder="🔍 搜索组合" autocomplete="off" oninput="filterCombos()">' +
        '    <div class="combo-list mt-8" id="comboList">' + leftList + '</div>' +
        '  </div>' +
        '  <div class="combo-right" id="comboRight">' +
        '    <div class="text-muted" style="padding:20px;text-align:center">选择一个检验组合<br><span class="fs-12">左侧列表点击组合即可查看与编辑</span></div>' +
        '  </div>' +
        '</div>';
    Clinic.modal.open(html, { title: '🧩 检验组合管理', size: 'modal-xl' });
}
function filterCombos() {
    var q = document.getElementById('comboSearch').value.trim().toLowerCase();
    document.querySelectorAll('#comboList .combo-item').forEach(function (ei) {
        ei.style.display = ei.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
}
function selectCombo(id) {
    document.querySelectorAll('.combo-item').forEach(function (ci) { ci.classList.remove('active'); });
    var el = document.getElementById('comboRow_' + id);
    if (el) el.classList.add('active');
    Clinic.get('/api/admin?action=lab_group_get&id=' + id, null, { onSuccess: function (j) {
        CUR_COMBO = j.data.group;
        var members = j.data.members || [];
        var memberRows = members.length ? members.map(function (m) {
            return '<div class="combo-member-row">' +
                '<span class="fs-13 fw-600">' + m.name + '</span>' +
                ' <span class="text-muted">¥' + m.price + ' ｜' + (m.unit || '—') + '</span>' +
                '<span class="combo-member-del" onclick="removeFromCombo(' + m.id + ')">✕</span></div>';
        }).join('') : '<div class="text-muted fs-12" style="padding:8px">暂无成员，点击上方「＋ 添加项目」加入</div>';
        document.getElementById('comboRight').innerHTML =
            '<div class="combo-right-head">' +
            '  <div class="form-row"><div class="form-group"><label>组合名称</label><input class="input" id="cgName" value="' + jsE(CUR_COMBO.name) + '"></div>' +
            '  <div class="form-group"><label>组合价格</label><input class="input" type="number" step="0.01" id="cgPrice" value="' + parseFloat(CUR_COMBO.price).toFixed(2) + '"></div>' +
            '  <div class="form-group"><label>分类</label><input class="input" id="cgCat" value="' + jsE(CUR_COMBO.category || '') + '"></div></div>' +
            '  <div class="flex gap-4 mt-4"><button class="btn btn-primary btn-sm" onclick="saveComboInfo()">💾 保存组合</button>' +
            '  <button class="btn btn-danger btn-sm" onclick="delCombo(' + CUR_COMBO.id + ')">🗑 删除组合</button></div>' +
            '</div>' +
            '<div class="combo-right-body">' +
            '  <button class="btn btn-sm btn-outline" onclick="showAddItemPop()">＋ 添加项目</button>' +
            '  <div class="combo-members mt-8">' + memberRows + '</div>' +
            '</div>';
    } });
}
function saveComboInfo() {
    var name = document.getElementById('cgName').value.trim();
    var price = parseFloat(document.getElementById('cgPrice').value) || 0;
    var cat = document.getElementById('cgCat').value.trim();
    if (!name) { Clinic.toast.warning('请填写组合名称'); return; }
    Clinic.ajax('/api/admin', { action: 'lab_group_save', id: CUR_COMBO.id, name: name, category: cat, price: price, member_ids: '' }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            // 刷新左侧组合列表（同步名称/计数）
            Clinic.get('/api/admin?action=lab_groups', null, { onSuccess: function (j2) {
                COMBOS = j2.data.list || [];
                var list = document.getElementById('comboList');
                list.innerHTML = COMBOS.map(function (c) { return '<div class="combo-item" onclick="selectCombo(' + c.id + ')" id="comboRow_' + c.id + '">' + c.name + ' <span class="text-muted fs-12">（' + (c.member_count || 0) + ' 项）</span></div>'; }).join('');
                selectCombo(CUR_COMBO.id);
            } });
        },
    });
}
function newComboPop(ev) {
    closeComboPop();
    var pop = document.createElement('div');
    pop.id = 'newComboPop'; pop.className = 'finish-pop'; pop.style.cssText = 'width:280px;position:fixed;z-index:3200';
    pop.innerHTML = '<div class="fs-13 fw-700 mb-8">新增检验组合</div>' +
        '<div class="form-group"><label>组合名称</label><input class="input" id="ncName" placeholder="如：肝功能十项"></div>' +
        '<div class="form-row"><div class="form-group"><label>组合价格</label><input class="input" type="number" step="0.01" id="ncPrice"></div>' +
        '<div class="form-group"><label>分类</label><input class="input" id="ncCat" placeholder="如：生化检验"></div></div>' +
        '<div class="flex gap-8"><button class="btn btn-outline btn-sm" style="flex:1" onclick="closeComboPop()">取消</button>' +
        '<button class="btn btn-primary btn-sm" style="flex:1" onclick="doNewCombo()">创建</button></div>';
    document.body.appendChild(pop);
    var cx = ev && typeof ev.clientX === 'number' ? ev.clientX : window.innerWidth / 2 - 140;
    var cy = ev && typeof ev.clientY === 'number' ? ev.clientY : 120;
    pop.style.top = Math.min(Math.max(8, cy + 12), window.innerHeight - 240) + 'px';
    pop.style.left = Math.min(Math.max(8, cx + 12), window.innerWidth - 292) + 'px';
    // 点击外部关闭
    setTimeout(function () {
        var handler = function (e) { if (!pop.contains(e.target)) closeComboPop(); };
        pop._outside = handler;
        document.addEventListener('mousedown', handler);
        try { document.getElementById('ncName').focus(); } catch (e2) {}
    }, 0);
}
function closeComboPop() {
    var p = document.getElementById('newComboPop');
    if (p) { if (p._outside) { document.removeEventListener('mousedown', p._outside); } p.remove(); }
}
function doNewCombo() {
    var name = document.getElementById('ncName').value.trim(); var price = parseFloat(document.getElementById('ncPrice').value) || 0;
    var cat = document.getElementById('ncCat').value.trim();
    if (!name) { Clinic.toast.warning('请填写组合名称'); return; }
    Clinic.ajax('/api/admin', { action: 'lab_group_save', id: 0, name: name, category: cat, price: price, member_ids: '' }, {
        onSuccess: function (j) {
            closeComboPop();
            Clinic.toast.success(j.msg);
            Clinic.get('/api/admin?action=lab_groups', null, { onSuccess: function (j2) {
                COMBOS = j2.data.list || [];
                var list = document.getElementById('comboList');
                list.innerHTML = COMBOS.map(function (c) { return '<div class="combo-item" onclick="selectCombo(' + c.id + ')" id="comboRow_' + c.id + '">' + c.name + ' <span class="text-muted fs-12">（' + (c.member_count || 0) + ' 项）</span></div>'; }).join('');
                selectCombo(j.data.id);
            } });
        },
    });
}
function delCombo(id) {
    Clinic.modal.confirm('确定删除该检验组合？组内项目将还原为独立项目。', function () {
        Clinic.ajax('/api/admin', { action: 'lab_group_delete', id: id }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                Clinic.get('/api/admin?action=lab_groups', null, { onSuccess: function (j2) {
                    COMBOS = j2.data.list || [];
                    document.getElementById('comboList').innerHTML = COMBOS.map(function (c) { return '<div class="combo-item" onclick="selectCombo(' + c.id + ')" id="comboRow_' + c.id + '">' + c.name + ' <span class="text-muted fs-12">（' + (c.member_count || 0) + ' 项）</span></div>'; }).join('');
                    document.getElementById('comboRight').innerHTML = '<div class="text-muted" style="padding:20px;text-align:center">选择一个检验组合</div>';
                } });
            },
        });
    });
}
/* 添加项目弹出：搜索 + 选择列表 */
var ADD_POP_CLOSE = null;
function showAddItemPop() {
    closeAddItemPop();
    var candidates = COMBO_CANDS.filter(function (c) {
        var used = false;
        document.querySelectorAll('.combo-member-row').forEach(function (r) { if (r.textContent.indexOf(c.name) !== -1) used = true; });
        return !used;
    });
    var pop = document.createElement('div');
    pop.id = 'addItemPop'; pop.className = 'finish-pop'; pop.style.cssText = 'width:320px;position:fixed;z-index:3200;max-height:360px;overflow-y:auto';
    pop.innerHTML =
        '<div class="fs-13 fw-700 mb-8">添加项目到组合</div>' +
        '<input class="input" id="aiSearch" placeholder="🔍 搜索项目" autocomplete="off" oninput="filterAICands()">' +
        '<div class="mt-8" id="aiList">' + (candidates.length ? candidates.map(function (c) {
            return '<div class="combo-cand-item" onclick="addToCombo(' + c.id + ',\'' + jsE(c.name) + '\')">' + c.name + ' <span class="text-muted fs-12">¥' + parseFloat(c.price).toFixed(2) + ' ｜' + c.category + '</span></div>';
        }).join('') : '<div class="text-muted fs-12" style="padding:8px">无可用单独项目（所有项目已加入组合或不存在）</div>') + '</div>';
    document.body.appendChild(pop);
    var btn = document.querySelector('.combo-right-body .btn-outline');
    var rect = btn.getBoundingClientRect();
    pop.style.top = Math.min(rect.bottom + 8, window.innerHeight - 380) + 'px';
    pop.style.left = Math.max(8, rect.left) + 'px';
    ADD_POP_CLOSE = function (e) { if (!pop.contains(e.target)) closeAddItemPop(); };
    setTimeout(function () { document.addEventListener('mousedown', ADD_POP_CLOSE); try { document.getElementById('aiSearch').focus(); } catch (e) {} }, 50);
}
function filterAICands() {
    var q = document.getElementById('aiSearch').value.trim().toLowerCase();
    document.querySelectorAll('#aiList .combo-cand-item').forEach(function (ei) { ei.style.display = ei.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
}
function closeAddItemPop() { var p = document.getElementById('addItemPop'); if (p) p.remove(); if (ADD_POP_CLOSE) { document.removeEventListener('mousedown', ADD_POP_CLOSE); ADD_POP_CLOSE = null; } }
function addToCombo(itemId, name) {
    Clinic.ajax('/api/admin', { action: 'lab_group_add_item', group_id: CUR_COMBO.id, item_id: itemId }, {
        onSuccess: function (j) {
            Clinic.toast.success('已加入：' + name);
            closeAddItemPop();
            selectCombo(CUR_COMBO.id);  // refresh right panel
        },
    });
}
function removeFromCombo(itemId) {
    Clinic.modal.confirm('确定从该组合中移除该项目？', function () {
        Clinic.ajax('/api/admin', { action: 'lab_group_remove_item', group_id: CUR_COMBO.id, item_id: itemId }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                selectCombo(CUR_COMBO.id);
            },
        });
    });
}
function jsE(s) { return String(s || '').replace(/&/g, '&amp;').replace(/'/g, '\\\'').replace(/"/g, '&quot;'); }

/* 分类管理（与之前相同） */
function openCatMgr() {
    var loadCats = function () {
        Clinic.get('/api/admin?action=cat_list&type=lab', null, { onSuccess: function (json) {
            var list = json.data.list || [];
            document.getElementById('catBox').innerHTML = list.map(function (c) { return '<span class="badge badge-gray" style="margin:0 6px 6px 0;padding:5px 12px">' + c.name + ' <a href="javascript:void(0)" style="color:var(--danger)" onclick="delCat(' + c.id + ')">✕</a></span>'; }).join('') || '<span class="text-muted fs-13">暂无分类</span>';
        } });
    };
    Clinic.modal.open('<div class="flex gap-8 mb-8"><input class="input" id="catName" placeholder="新增检验分类名称（如：血液检验）" style="flex:1"><button class="btn btn-primary btn-sm" onclick="addCat()">添加</button></div><div id="catBox"></div>', { title: '检验分类管理', size: 'modal-sm', buttons: [{ text: '关闭', cls: 'btn-outline' }] });
    loadCats();
    window.addCat = function () { var name = document.getElementById('catName').value.trim(); if (!name) { Clinic.toast.warning('请输入分类名称'); return; } Clinic.ajax('/api/admin', { action: 'cat_add', type: 'lab', name: name }, { onSuccess: function (json) { Clinic.toast.success(json.msg); document.getElementById('catName').value = ''; loadCats(); } }); };
    window.delCat = function (id) { Clinic.ajax('/api/admin', { action: 'cat_delete', id: id }, { onSuccess: function () { loadCats(); } }); };
}
function openItemForm(id) { /* same as before, reused for single item edit */
    var mask = Clinic.modal.load('/api/admin', { action: 'item_form', type: 'lab', id: id || 0 }, { title: id ? '编辑检验项目' : '新增检验项目' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
        mask.querySelector('.modal-foot').innerHTML = '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button><button type="button" class="btn btn-primary" id="itemSave">保存</button>';
        document.getElementById('itemSave').addEventListener('click', function () {
            Clinic.ajax('/api/admin', { action: 'item_save', type: 'lab', id: id || 0, name: document.getElementById('f_name').value.trim(), category: document.getElementById('f_category').value, price: document.getElementById('f_price').value, unit: document.getElementById('f_unit') ? document.getElementById('f_unit').value : '', normal_range: document.getElementById('f_normal') ? document.getElementById('f_normal').value : '', critical_low: document.getElementById('f_clow') ? document.getElementById('f_clow').value : '', critical_high: document.getElementById('f_chigh') ? document.getElementById('f_chigh').value : '', description: document.getElementById('f_desc').value }, {
                onSuccess: function (json) { Clinic.toast.success(json.msg); Clinic.modal.close(); loadItemList(); },
            });
        });
    });
}
function delItem(type, id) {
    Clinic.modal.confirm('确定删除该检验项目？', function () { Clinic.ajax('/api/admin', { action: 'item_delete', type: type, id: id }, { onSuccess: function (json) { Clinic.toast.success(json.msg); loadItemList(); } }); });
}
loadItemList();
(function () { var m = (location.search.match(/[?&]edit=(\d+)/) || [])[1]; if (m) openItemForm(parseInt(m, 10)); })();
</script>