<?php
/**
 * ============================================================
 * admin/diagnosis.php v2.2.0 — 诊断管理（树形浏览）
 * ============================================================
 * 说明：ICD-10 完整标准编码库树形浏览：
 *   左侧三级树：章(chapter) → 节(section) → 类目(category)
 *   右侧详情：选中类目 → 亚目列表（徽章）+ 诊断二级列表（子诊断可展开）
 * 左侧树复用 window.treeToggle 全局函数（app.js），与 depttree 同源交互。
 * ============================================================ */
Router::title('诊断管理');
?>
<div class="page-head">
    <div><div class="page-title">📖 诊断管理</div><div class="page-desc">ICD10 标准编码库 · 四级分类树：章→节→类目→亚目→诊断</div></div>
</div>
<div class="card" style="margin-bottom:12px">
    <input class="input" id="diagKw" placeholder="🔍 输入诊断码 / 名称 / 拼音首字母（实时检索）" autocomplete="off" oninput="diagSearchDebounced()">
</div>
<div class="flex gap-16" style="align-items:flex-start">
    <div class="card" style="width:360px;flex-shrink:0;max-height:70vh;overflow-y:auto" id="treeBox">
        <div class="card-title mb-8">📂 分类树</div>
        <div id="icdTree"><div class="fs-13 text-muted">加载中…</div></div>
    </div>
    <div class="card" style="flex:1;min-width:0;max-height:70vh;display:flex;flex-direction:column;padding:0;overflow:hidden" id="detailBox">
        <div id="detailTitle" style="flex-shrink:0;padding:14px 16px 0">选择左侧类目查看详情</div>
        <div id="detailContent" style="flex:1;overflow-y:auto;padding:12px 16px 16px"></div>
    </div>
</div>

<script>
/* ==================== 搜索 ==================== */
var diagDebounce = null;
var diagOffset = 0;
var diagTotal = 0;
var diagLoading = false;
function diagSearchDebounced() {
    if (diagDebounce) clearTimeout(diagDebounce);
    diagDebounce = setTimeout(function () { diagOffset = 0; showSearchResults(); }, 300);
}
function showSearchResults() {
    var kw = document.getElementById('diagKw').value.trim();
    if (!kw) { loadTree(); document.getElementById('detailContent').innerHTML = ''; return; }
    if (diagLoading) return;
    diagLoading = true;
    Clinic.get('/api/icd10?action=list&kw=' + encodeURIComponent(kw) + '&offset=' + diagOffset + '&limit=50', null, {
        onSuccess: function (json) {
            diagLoading = false;
            var list = json.data.list || [];
            diagTotal = json.data.total || 0;
            document.getElementById('icdTree').innerHTML = '<div class="fs-12 text-muted mb-8">检索结果：' + diagTotal + ' 条</div>' +
                list.map(function (d) {
                    var chain = [];
                    if (d.chapter_name) chain.push(d.chapter_name);
                    if (d.section_name) chain.push(d.section_name);
                    if (d.category_name) chain.push(d.category_name);
                    if (d.subcategory_name) chain.push(d.subcategory_name);
                    return '<div class="dd-item" style="font-size:13px;padding:6px 8px;border-bottom:1px solid var(--border);cursor:pointer" onclick="showCategoryDetail(\'' + d.category_code + '\',\'' + (d.category_name || '').replace(/'/g, "\\'") + '\')">' +
                        '<div><span class="fw-600" style="font-family:monospace">' + d.icd10_code + '</span> ' + d.diagnosis_name + '</div>' +
                        '<div class="fs-12 text-muted ellipsis">' + chain.join(' → ') + '</div></div>';
                }).join('') +
                (diagOffset + list.length < diagTotal
                    ? '<div class="text-center" style="padding:8px"><button class="btn btn-outline btn-sm" onclick="loadMoreSearch()">加载更多</button></div>'
                    : '');
            diagOffset += list.length;
            document.getElementById('detailTitle').textContent = '搜索：' + kw;
            document.getElementById('detailContent').innerHTML = '';
        },
        onError: function () { diagLoading = false; },
    });
}
function loadMoreSearch() { showSearchResults(); }

/* ==================== 左侧三级树（复用 window.treeToggle） ==================== */
function loadTree() {
    Clinic.get('/api/icd10?action=tree&level=chapters', null, {
        onSuccess: function (j) {
            var list = j.data.list || [];
            document.getElementById('icdTree').innerHTML = list.map(function (ch) {
                var id = 'ch_' + ch.code.replace(/[^A-Z0-9]/g, '_');
                return '<div class="send-grp">' +
                    '  <div class="send-grp-head-row">' +
                    '    <button type="button" class="tree-toggle" data-toggle="' + id + '" onclick="toggleChapter(this)">+</button>' +
                    '    <b class="fs-13" style="cursor:pointer" onclick="toggleChapter(this)">' + ch.code + '  ' + ch.name + '</b>' +
                    '  </div>' +
                    '  <div class="send-grp-children" id="' + id + '" style="display:none">' +
                    '    <div class="fs-12 text-muted" style="padding:4px 8px">加载中…</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
        },
    });
}
function toggleChapter(el) {
    var head = el.closest('.send-grp-head-row');
    var btn = head.querySelector('.tree-toggle');
    var target = document.getElementById(btn.getAttribute('data-toggle'));
    if (!target) return;
    // 已展开则折叠；未展开则展开并懒加载子节点
    if (target.style.display !== 'none') { window.treeToggle(btn); return; }
    window.treeToggle(btn);
    if (target.getAttribute('data-loaded')) return;
    target.setAttribute('data-loaded', '1');
    var code = head.querySelector('b').textContent.trim().split(' ')[0];
    Clinic.get('/api/icd10?action=tree&level=sections&parent=' + encodeURIComponent(code), null, {
        onSuccess: function (j) {
            target.innerHTML = (j.data.list || []).map(function (sec) {
                var sid = 'sec_' + sec.code.replace(/[^A-Z0-9]/g, '_');
                return '<div class="send-grp" style="margin-left:12px">' +
                    '  <div class="send-grp-head-row">' +
                    '    <button type="button" class="tree-toggle" data-toggle="' + sid + '" onclick="toggleSection(this)">+</button>' +
                    '    <span class="fs-12" style="cursor:pointer" onclick="toggleSection(this)">' + sec.code + '  ' + sec.name + '</span>' +
                    '  </div>' +
                    '  <div class="send-grp-children" id="' + sid + '" style="display:none">' +
                    '    <div class="fs-12 text-muted" style="padding:4px 8px">加载中…</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
        },
    });
}
function toggleSection(el) {
    var head = el.closest('.send-grp-head-row');
    var btn = head.querySelector('.tree-toggle');
    var target = document.getElementById(btn.getAttribute('data-toggle'));
    if (!target) return;
    if (target.style.display !== 'none') { window.treeToggle(btn); return; }
    window.treeToggle(btn);
    if (target.getAttribute('data-loaded')) return;
    target.setAttribute('data-loaded', '1');
    var code = head.querySelector('span').textContent.trim().split(' ')[0];
    Clinic.get('/api/icd10?action=tree&level=categories&parent=' + encodeURIComponent(code), null, {
        onSuccess: function (j) {
            target.innerHTML = (j.data.list || []).map(function (cat) {
                var cid = 'cat_' + cat.code.replace(/[^A-Z0-9]/g, '_');
                return '<div class="dd-item" style="font-size:12px;padding:4px 8px;cursor:pointer;margin-left:12px;border-radius:4px" onclick="showCategoryDetail(\'' + cat.code + '\',\'' + (cat.name || '').replace(/'/g, "\\'") + '\')">' +
                    '<span class="fw-600">' + cat.code + '</span>  ' + cat.name + '</div>';
            }).join('');
        },
    });
}

/* ==================== 右侧详情（类目两行标题 + 诊断二级列表） ==================== */
var CURRENT_CATEGORY = '';

function showCategoryDetail(code, name) {
    CURRENT_CATEGORY = code;
    document.getElementById('diagKw').value = '';
    // 类目标题：编码徽章 + 名称加粗（可折行）
    document.getElementById('detailTitle').innerHTML =
        '<div style="display:flex;align-items:flex-start;gap:10px;border-bottom:1px solid var(--border);padding-bottom:10px">' +
        '<span class="badge badge-primary" style="font-family:monospace;font-weight:700;font-size:14px;padding:4px 12px;flex-shrink:0;margin-top:2px">' + code + '</span>' +
        '<div style="font-weight:700;font-size:15px;line-height:1.5">' + name + '</div>' +
        '</div>';
    var content = document.getElementById('detailContent');
    content.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
    // 加载诊断明细
    Clinic.get('/api/icd10?action=list&category=' + encodeURIComponent(code) + '&limit=500', null, {
        onSuccess: function (j2) {
            var diags = j2.data.list || [];
            content.innerHTML = '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                '<div id="diagListWrap">' + buildDiagListHtml(diags) + '</div>';
        },
    });
}

/* 构建诊断二级列表（父诊断 + 子诊断可展开；所有行统一预留 + 号位对齐） */
function buildDiagListHtml(diags) {
    // 分组：同一基码（去掉 x 扩展）下多条则首条为父诊断，其余为子诊断
    var groups = {};
    diags.forEach(function (d) {
        var code = d.icd10_code;
        var base = code.replace(/x\d{3}.*$/, '');
        if (!groups[base]) groups[base] = [];
        groups[base].push(d);
    });
    var html = '';
    var keys = Object.keys(groups).sort();
    keys.forEach(function (base) {
        var items = groups[base];
        var first = items[0];
        var hasSub = items.length > 1;
        // 统一 16px 折叠位：有子诊断显示 +，无子诊断显示空白（保持上下对齐）
        var toggleCell = hasSub
            ? '<span class="tree-toggle" style="width:16px;flex-shrink:0;margin-right:6px;user-select:none;border:none;font-weight:700">+</span>'
            : '<span style="width:16px;flex-shrink:0;margin-right:6px;display:inline-block">&nbsp;</span>';
        var wrapId = 'sub_' + base.replace(/[^A-Z0-9.]/g, '_');
        if (hasSub) {
            html += '<div class="dd-item" style="display:flex;align-items:center;padding:5px 8px;cursor:pointer;border-bottom:1px solid var(--border)" onclick="toggleSubDiags(this, \'' + wrapId + '\')">' +
                toggleCell +
                '<span class="fw-600" style="font-family:monospace;font-size:13px">' + first.icd10_code + '</span>' +
                ' <span class="fs-13">' + first.diagnosis_name + '</span>' +
                '<span class="fs-12 text-muted" style="margin-left:8px">（' + items.length + '）</span></div>' +
                '<div id="' + wrapId + '" style="display:none;padding-left:30px">' +
                items.slice(1).map(function (it) {
                    return '<div class="dd-item" style="display:flex;align-items:center;padding:3px 8px;font-size:12px;border-bottom:1px dashed var(--border)">' +
                        '<span style="width:16px;flex-shrink:0;margin-right:6px;display:inline-block">&nbsp;</span>' +
                        '<span class="fw-600" style="font-family:monospace">' + it.icd10_code + '</span> ' + it.diagnosis_name +
                        '<span class="fs-12 text-muted" style="margin-left:6px">' + (it.pinyin || '') + '</span></div>';
                }).join('') +
                '</div>';
        } else {
            html += '<div class="dd-item" style="display:flex;align-items:center;padding:5px 8px;border-bottom:1px solid var(--border)">' +
                toggleCell +
                '<span class="fw-600" style="font-family:monospace;font-size:13px">' + first.icd10_code + '</span> ' +
                '<span class="fs-13">' + first.diagnosis_name + '</span>' +
                '<span class="fs-12 text-muted" style="margin-left:6px">' + (first.pinyin || '') + '</span></div>';
        }
    });
    return html || '<div class="fs-12 text-muted">无诊断数据</div>';
}

/* 子诊断折叠展开 */
function toggleSubDiags(el, wrapId) {
    var wrap = document.getElementById(wrapId);
    if (!wrap) return;
    var toggle = el.querySelector('.tree-toggle');
    if (wrap.style.display !== 'none') {
        wrap.style.display = 'none';
        if (toggle) toggle.textContent = '+';
    } else {
        wrap.style.display = '';
        if (toggle) toggle.textContent = '−';
    }
}

/* ==================== 初始化 ==================== */
loadTree();
</script>