<?php
/**
 * ============================================================
 * admin/diagnosis.php v2.1.0 — 诊断管理（树形浏览）
 * ============================================================
 * 说明：ICD-10 完整标准编码库树形浏览：
 *   左侧三级树：章(chapter) → 节(section) → 类目(category)
 *   右侧详情：选中类目 → 亚目列表 + 诊断明细
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
function loadMoreSearch() {
    showSearchResults();
}

/* ==================== 树形浏览 ==================== */
function loadTree() {
    Clinic.get('/api/icd10?action=tree&level=chapters', null, {
        onSuccess: function (j) {
            var list = j.data.list || [];
            document.getElementById('icdTree').innerHTML = list.map(function (ch) {
                return '<div class="send-grp">' +
                    '  <div class="send-grp-head-row" style="cursor:pointer" onclick="toggleChapter(this)">' +
                    '    <button type="button" class="tree-toggle" data-toggle="ch_' + ch.code.replace(/[^A-Z0-9]/g,'_') + '">+</button>' +
                    '    <b class="fs-13">' + ch.code + '  ' + ch.name + '</b>' +
                    '  </div>' +
                    '  <div class="send-grp-children" id="ch_' + ch.code.replace(/[^A-Z0-9]/g,'_') + '" style="display:none">' +
                    '    <div class="fs-12 text-muted" style="padding:4px 8px">加载中…</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
        },
    });
}

function toggleChapter(btn) {
    var head = btn.closest('.send-grp-head-row');
    var toggleBtn = head.querySelector('.tree-toggle');
    var child = document.getElementById(toggleBtn.getAttribute('data-toggle'));
    if (!child) return;
    if (child.style.display !== 'none') {
        child.style.display = 'none';
        toggleBtn.textContent = '+';
        return;
    }
    child.style.display = '';
    toggleBtn.textContent = '−';
    // 如果尚未加载子节点，则加载
    if (child.getAttribute('data-loaded')) return;
    child.setAttribute('data-loaded', '1');
    // 获取章编码范围
    var codeText = head.querySelector('b').textContent.trim();
    var chapterCode = codeText.split(' ')[0];
    Clinic.get('/api/icd10?action=tree&level=sections&parent=' + encodeURIComponent(chapterCode), null, {
        onSuccess: function (j) {
            child.innerHTML = (j.data.list || []).map(function (sec) {
                return '<div class="send-grp" style="margin-left:12px">' +
                    '  <div class="send-grp-head-row" style="cursor:pointer" onclick="toggleSection(this)">' +
                    '    <button type="button" class="tree-toggle" data-toggle="sec_' + sec.code.replace(/[^A-Z0-9]/g,'_') + '">+</button>' +
                    '    <span class="fs-12">' + sec.code + '  ' + sec.name + '</span>' +
                    '  </div>' +
                    '  <div class="send-grp-children" id="sec_' + sec.code.replace(/[^A-Z0-9]/g,'_') + '" style="display:none">' +
                    '    <div class="fs-12 text-muted" style="padding:4px 8px">加载中…</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
            // 重新绑定折叠按钮
            child.querySelectorAll('.tree-toggle').forEach(function (btn) {
                btn.addEventListener('click', function (e) { e.stopPropagation(); });
            });
        },
    });
}

function toggleSection(btn) {
    var head = btn.closest('.send-grp-head-row');
    var toggleBtn = head.querySelector('.tree-toggle');
    var child = document.getElementById(toggleBtn.getAttribute('data-toggle'));
    if (!child) return;
    if (child.style.display !== 'none') {
        child.style.display = 'none';
        toggleBtn.textContent = '+';
        return;
    }
    child.style.display = '';
    toggleBtn.textContent = '−';
    if (child.getAttribute('data-loaded')) return;
    child.setAttribute('data-loaded', '1');
    var codeText = head.querySelector('span').textContent.trim();
    var sectionCode = codeText.split(' ')[0];
    Clinic.get('/api/icd10?action=tree&level=categories&parent=' + encodeURIComponent(sectionCode), null, {
        onSuccess: function (j) {
            child.innerHTML = (j.data.list || []).map(function (cat) {
                return '<div class="dd-item" style="font-size:12px;padding:4px 8px;cursor:pointer;margin-left:12px;border-radius:4px" onclick="showCategoryDetail(\'' + cat.code + '\',\'' + (cat.name || '').replace(/'/g, "\\'") + '\')">' +
                    '<span class="fw-600">' + cat.code + '</span>  ' + cat.name + '</div>';
            }).join('');
        },
    });
}

/* ==================== 右侧详情 ==================== */
var CURRENT_CATEGORY = '';

function showCategoryDetail(code, name) {
    CURRENT_CATEGORY = code;
    document.getElementById('diagKw').value = '';
    document.getElementById('detailTitle').textContent = '📂 ' + code + '  ' + name;
    var content = document.getElementById('detailContent');
    content.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
    // 加载亚目 + 诊断明细
    Clinic.get('/api/icd10?action=tree&level=subcategories&parent=' + encodeURIComponent(code), null, {
        onSuccess: function (j) {
            var subs = j.data.list || [];
            // 加载诊断明细
            Clinic.get('/api/icd10?action=list&category=' + encodeURIComponent(code) + '&limit=200', null, {
                onSuccess: function (j2) {
                    var diags = j2.data.list || [];
                    var html = '';
                    // 亚目列表
                    if (subs.length) {
                        html += '<div class="mb-12"><div class="fs-13 fw-600 mb-8">亚目（' + subs.length + '）</div>' +
                            '<div class="flex gap-4" style="flex-wrap:wrap">' +
                            subs.map(function (s) {
                                var cnt = diags.filter(function (d) { return d.subcategory_code === s.code; }).length;
                                return '<span class="badge badge-primary" style="cursor:pointer;font-size:12px;padding:4px 10px" onclick="showSubcategoryDetail(\'' + s.code + '\')">' +
                                    s.code + ' ' + s.name + (cnt ? '（' + cnt + '）' : '') + '</span>';
                            }).join('') +
                            '</div></div>';
                    }
                    // 诊断明细
                    html += '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                        '<div class="table-wrap"><table class="table"><thead><tr>' +
                        '<th style="width:160px">诊断码</th><th>诊断名称</th><th>拼音</th><th>亚目</th></tr></thead><tbody>' +
                        diags.map(function (d) {
                            return '<tr>' +
                                '<td class="fw-600" style="font-family:monospace">' + d.icd10_code + '</td>' +
                                '<td>' + d.diagnosis_name + '</td>' +
                                '<td class="fs-12 text-muted">' + (d.pinyin || '—') + '</td>' +
                                '<td class="fs-12 text-muted">' + (d.subcategory_code || '') + '</td>' +
                                '</tr>';
                        }).join('') +
                        '</tbody></table></div>';
                    content.innerHTML = html;
                },
            });
        },
    });
}

function showSubcategoryDetail(code) {
    document.getElementById('detailTitle').textContent = '亚目：' + code;
    Clinic.get('/api/icd10?action=list&subcategory=' + encodeURIComponent(code) + '&limit=200', null, {
        onSuccess: function (j) {
            var diags = j.data.list || [];
            document.getElementById('detailContent').innerHTML =
                '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                '<div class="table-wrap"><table class="table"><thead><tr>' +
                '<th style="width:160px">诊断码</th><th>诊断名称</th><th>拼音</th></tr></thead><tbody>' +
                diags.map(function (d) {
                    return '<tr><td class="fw-600" style="font-family:monospace">' + d.icd10_code + '</td>' +
                        '<td>' + d.diagnosis_name + '</td>' +
                        '<td class="fs-12 text-muted">' + (d.pinyin || '—') + '</td></tr>';
                }).join('') +
                '</tbody></table></div>';
        },
    });
}

/* ==================== 初始化 ==================== */
loadTree();
</script>