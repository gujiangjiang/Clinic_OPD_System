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
    <div class="card" style="flex:1;min-width:0" id="detailBox">
        <div class="fs-13 text-muted" id="detailTitle">选择左侧类目查看详情</div>
        <div id="detailContent"></div>
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

/* ==================== 右侧详情（亚目徽章 + 诊断二级列表） ==================== */
var CURRENT_CATEGORY = '';

function showCategoryDetail(code, name) {
    CURRENT_CATEGORY = code;
    document.getElementById('diagKw').value = '';
    document.getElementById('detailTitle').textContent = '📂 ' + code + '  ' + name;
    var content = document.getElementById('detailContent');
    content.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
    // 加载亚目
    Clinic.get('/api/icd10?action=tree&level=subcategories&parent=' + encodeURIComponent(code), null, {
        onSuccess: function (j) {
            var subs = j.data.list || [];
            // 加载诊断明细
            Clinic.get('/api/icd10?action=list&category=' + encodeURIComponent(code) + '&limit=500', null, {
                onSuccess: function (j2) {
                    var diags = j2.data.list || [];
                    var html = '';
                    // 亚目徽章列表
                    if (subs.length) {
                        html += '<div class="mb-12"><div class="fs-13 fw-600 mb-8">亚目</div>' +
                            '<div class="flex gap-4" style="flex-wrap:wrap">' +
                            subs.map(function (s) {
                                var cnt = diags.filter(function (d) { return d.subcategory_code === s.code; }).length;
                                return '<span class="badge badge-primary" style="cursor:pointer;font-size:13px;padding:5px 12px" onclick="showSubcategoryDetail(\'' + s.code + '\')">' +
                                    '<span style="font-family:monospace;font-weight:700">' + s.code + '</span> ' + s.name + (cnt ? '（' + cnt + '）' : '') + '</span>';
                            }).join('') +
                            '</div></div>';
                    }
                    // 诊断二级列表（按父诊断分组，子诊断可展开）
                    html += '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                        '<div id="diagListWrap">' + buildDiagListHtml(diags) + '</div>';
                    content.innerHTML = html;
                },
            });
        },
    });
}

function showSubcategoryDetail(code) {
    document.getElementById('detailTitle').textContent = '亚目：' + code;
    Clinic.get('/api/icd10?action=list&subcategory=' + encodeURIComponent(code) + '&limit=500', null, {
        onSuccess: function (j) {
            var diags = j.data.list || [];
            document.getElementById('detailContent').innerHTML =
                '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                '<div id="diagListWrap">' + buildDiagListHtml(diags) + '</div>';
        },
    });
}

/* 构建诊断二级列表（父诊断 + 子诊断可展开） */
function buildDiagListHtml(diags) {
    // 分组：按基码分组（取 diagnosis_code 中第一个 . 前为主码判断依据）
    // 同一基码 prefix 下如果有多条，首条为父诊断，其余为子诊断
    // 基码规则：取诊断码中字母+数字部分到第一个 x 或扩展前
    var groups = {};
    diags.forEach(function (d) {
        var code = d.icd10_code;
        // 提取基码：A01.000x004 → A01.000
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
        if (hasSub) {
            // 父诊断（可展开）
            var wrapId = 'sub_' + base.replace(/[^A-Z0-9.]/g, '_');
            html += '<div style="border-bottom:1px solid var(--border)">' +
                '<div class="dd-item" style="display:flex;align-items:center;padding:5px 8px;cursor:pointer" onclick="toggleSubDiags(this, \'' + wrapId + '\')">' +
                '<span class="tree-toggle" style="font-size:14px;margin-right:4px;user-select:none">+</span>' +
                '<span class="fw-600" style="font-family:monospace;font-size:13px">' + first.icd10_code + '</span>' +
                ' <span class="fs-13">' + first.diagnosis_name + '</span>' +
                '<span class="fs-12 text-muted" style="margin-left:8px">（' + items.length + '）</span></div>' +
                '<div id="' + wrapId + '" style="display:none;padding-left:28px">' +
                items.slice(1).map(function (it) {
                    return '<div class="dd-item" style="padding:3px 8px;font-size:12px">' +
                        '<span class="fw-600" style="font-family:monospace">' + it.icd10_code + '</span> ' + it.diagnosis_name +
                        '<span class="fs-12 text-muted" style="margin-left:6px">' + (it.pinyin || '') + '</span></div>';
                }).join('') +
                '</div></div>';
        } else {
            // 无子诊断：直接显示
            html += '<div class="dd-item" style="padding:5px 8px;border-bottom:1px solid var(--border)">' +
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