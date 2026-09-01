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
<div class="card" style="margin-bottom:12px;position:relative">
    <input class="input" id="diagKw" placeholder="🔍 输入诊断码 / 名称 / 拼音首字母（实时检索）" autocomplete="off" oninput="diagSearchDebounced()" onfocus="showSearchDrop()">
    <div id="searchDrop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;max-height:350px;overflow-y:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:0 0 8px 8px;box-shadow:0 8px 24px var(--shadow)"></div>
</div>
<div class="flex gap-16" style="align-items:stretch">
    <div class="card" style="width:360px;flex-shrink:0;height:70vh;display:flex;flex-direction:column;padding:0;overflow:hidden" id="treeBox">
        <div class="card-title" style="padding:14px 16px 0;margin-bottom:8px">📂 分类树</div>
        <div id="icdTree" style="flex:1;overflow-y:auto;padding:0 16px 16px"><div class="fs-13 text-muted">加载中…</div></div>
    </div>
    <div class="card" style="flex:1;min-width:0;height:70vh;display:flex;flex-direction:column;padding:0;overflow:hidden" id="detailBox">
        <div id="detailTitle" style="flex-shrink:0;padding:14px 16px 0;display:none"></div>
        <div id="detailContent" style="flex:1;overflow-y:auto;padding:12px 16px 16px;display:flex;align-items:center;justify-content:center">
            <div style="text-align:center">
                <div style="font-size:48px;line-height:1;margin-bottom:12px">📖</div>
                <div class="fw-600" style="font-size:16px;color:var(--text-muted)">选择左侧类目查看诊断详情</div>
                <div class="fs-13 text-muted" style="margin-top:6px">点击左侧分类树中的类目节点，右侧将显示该类目下的所有诊断</div>
            </div>
        </div>
    </div>
</div>

<script>
/* ==================== 搜索浮层 ==================== */
var diagDebounce = null;
var diagLoading = false;
var SEARCH_HIGHLIGHT_CODE = '';   // 当前搜索高亮的诊断码

function diagSearchDebounced() {
    if (diagDebounce) clearTimeout(diagDebounce);
    diagDebounce = setTimeout(function () { showSearchDrop(); }, 300);
}
function showSearchDrop() {
    var kw = document.getElementById('diagKw').value.trim();
    var drop = document.getElementById('searchDrop');
    if (!kw) { drop.style.display = 'none'; return; }
    if (diagLoading) return;
    diagLoading = true;
    Clinic.get('/api/icd10?action=list&kw=' + encodeURIComponent(kw) + '&limit=50', null, {
        onSuccess: function (json) {
            diagLoading = false;
            var list = json.data.list || [];
            var total = json.data.total || 0;
            if (!list.length) {
                drop.innerHTML = '<div class="fs-12 text-muted" style="padding:10px 14px">未检索到匹配诊断</div>';
                drop.style.display = '';
                return;
            }
            drop.innerHTML = '<div class="fs-12 text-muted" style="padding:6px 14px;border-bottom:1px solid var(--border)">检索到 ' + total + ' 条诊断</div>' +
                list.map(function (d) {
                    var chain = [];
                    if (d.chapter_name) chain.push(d.chapter_name);
                    if (d.section_name) chain.push(d.section_name);
                    if (d.category_name) chain.push(d.category_name);
                    if (d.subcategory_name) chain.push(d.subcategory_name);
                    return '<div class="dd-item" style="padding:7px 14px;cursor:pointer;border-bottom:1px solid var(--border)" ' +
                        'onclick="onSearchPick(\'' + d.category_code + '\',\'' + (d.category_name || '').replace(/'/g, "\\'") + '\',\'' + d.section_code_range + '\',\'' + d.chapter_code_range + '\',\'' + d.icd10_code + '\',\'' + (d.subcategory_code || '').replace(/'/g, "\\'") + '\')">' +
                        '<div class="fw-600" style="font-size:13px"><span style="font-family:monospace">' + d.icd10_code + '</span> ' + d.diagnosis_name + '</div>' +
                        '<div class="fs-12 text-muted ellipsis">' + chain.join(' → ') + '</div></div>';
                }).join('');
            drop.style.display = '';
        },
        onError: function () { diagLoading = false; },
    });
}
/* 点击搜索结果：关闭浮层 → 展开树到类目 → 右侧显示详情 → 高亮 */
function onSearchPick(catCode, catName, secCode, chCode, diagCode, subCode) {
    SEARCH_HIGHLIGHT_CODE = diagCode;
    document.getElementById('searchDrop').style.display = 'none';
    document.getElementById('diagKw').value = '';
    // 展开左侧树到目标类目（类目节点点击会自动触发 showCategoryDetail 并高亮）
    expandTreeToCategory(chCode, secCode, catCode, catName);
}
/* 点击页面其他区域关闭搜索浮层 */
document.addEventListener('click', function (e) {
    var drop = document.getElementById('searchDrop');
    var input = document.getElementById('diagKw');
    if (drop && drop.style.display !== 'none' && !e.target.closest('#searchDrop') && e.target !== input) {
        drop.style.display = 'none';
    }
});

/* ==================== 展开树到指定类目（异步懒加载） ==================== */
function expandTreeToCategory(chapter, section, category, catName, callback) {
    expandChapter(chapter, function () {
        expandSection(section, function () {
            // 找到类目节点并点击
            var catItems = document.querySelectorAll('#icdTree .dd-item');
            var clicked = false;
            for (var i = 0; i < catItems.length; i++) {
                var item = catItems[i];
                if (item.textContent.trim().indexOf(category) === 0) {
                    // 清除左侧树之前的高亮
                    document.querySelectorAll('#icdTree .tree-cat-highlight').forEach(function (el) {
                        el.classList.remove('tree-cat-highlight');
                        el.style.background = '';
                    });
                    // 高亮左侧树类目 + 滚动到可见（仅滚动树容器，避免整页跳动）
                    item.style.background = 'var(--primary-soft, #e6f0ff)';
                    item.classList.add('tree-cat-highlight');
                    item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    item.click();
                    clicked = true;
                    break;
                }
            }
            // 未找到类目（树未加载出该节点）→ 直接显示右侧详情兜底
            if (!clicked) showCategoryDetail(category, catName);
            if (callback) callback();
        });
    });
}
function expandChapter(chapter, callback) {
    var chId = 'ch_' + chapter.replace(/[^A-Z0-9]/g, '_');
    var container = document.getElementById(chId);
    if (!container || container.style.display !== 'none' && container.getAttribute('data-loaded')) {
        if (callback) callback(); return;
    }
    if (container.style.display !== 'none') { waitLoaded(container, callback); return; }
    var row = container.closest('.send-grp');
    var btn = row ? row.querySelector('.tree-toggle') : null;
    if (!btn) { if (callback) callback(); return; }
    toggleChapter(btn);
    waitLoaded(container, callback);
}
function expandSection(section, callback) {
    var secId = 'sec_' + section.replace(/[^A-Z0-9]/g, '_');
    var container = document.getElementById(secId);
    if (!container || container.style.display !== 'none' && container.getAttribute('data-loaded')) {
        if (callback) callback(); return;
    }
    if (container.style.display !== 'none') { waitLoaded(container, callback); return; }
    var row = container.closest('.send-grp');
    var btn = row ? row.querySelector('.tree-toggle') : null;
    if (!btn) { if (callback) callback(); return; }
    toggleSection(btn);
    waitLoaded(container, callback);
}
function waitLoaded(container, callback) {
    if (container.getAttribute('data-loaded')) { if (callback) callback(); return; }
    var timer = setInterval(function () {
        if (container.getAttribute('data-loaded')) {
            clearInterval(timer);
            if (callback) callback();
        }
    }, 50);
    setTimeout(function () { clearInterval(timer); if (callback) callback(); }, 8000);
}

/* ==================== 高亮 + 展开子诊断 ==================== */
function highlightAndExpand(diagCode, subCode) {
    // 清除之前的高亮
    document.querySelectorAll('#diagListWrap .diag-highlight').forEach(function (el) {
        el.classList.remove('diag-highlight');
        el.style.background = '';
    });
    var items = document.querySelectorAll('#diagListWrap .dd-item');
    var found = null;
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (item.textContent.indexOf(diagCode) !== -1) {
            found = item;
            break;
        }
    }
    if (!found) return;
    found.style.background = 'var(--primary-soft, #e6f0ff)';
    found.classList.add('diag-highlight');
    // 如果是子诊断（父诊断含 + 号），展开父诊断
    var parent = found.closest('[id^="sub_"]');
    if (parent && parent.style.display === 'none') {
        var parentRow = parent.previousElementSibling;
        if (parentRow && parentRow.onclick) parentRow.click();
    }
    found.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ==================== 左侧三级树（复用 window.treeToggle） ==================== */
function loadTree() {
    Clinic.get('/api/icd10?action=tree&level=chapters', null, {
        onSuccess: function (j) {
            var list = j.data.list || [];
            if (!list.length) {
                document.getElementById('icdTree').innerHTML = '<div class="empty" style="padding:20px 0"><div class="empty-ico" style="font-size:32px">📂</div>暂无诊断数据</div>';
                return;
            }
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
            target.setAttribute('data-loaded', '1');
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
    var code = head.querySelector('span').textContent.trim().split(' ')[0];
    Clinic.get('/api/icd10?action=tree&level=categories&parent=' + encodeURIComponent(code), null, {
        onSuccess: function (j) {
            target.innerHTML = (j.data.list || []).map(function (cat) {
                var cid = 'cat_' + cat.code.replace(/[^A-Z0-9]/g, '_');
                return '<div class="dd-item" style="font-size:12px;padding:4px 8px;cursor:pointer;margin-left:12px;border-radius:4px" onclick="pickTreeCategory(this,\'' + cat.code + '\',\'' + (cat.name || '').replace(/'/g, "\\'") + '\')">' +
                    '<span class="fw-600">' + cat.code + '</span>  ' + cat.name + '</div>';
            }).join('');
            target.setAttribute('data-loaded', '1');
        },
    });
}

/* 点击树类目：清除旧高亮 → 高亮当前项 → 显示右侧详情 */
function pickTreeCategory(el, code, name) {
    document.querySelectorAll('#icdTree .tree-cat-highlight').forEach(function (x) {
        x.classList.remove('tree-cat-highlight');
        x.style.background = '';
    });
    el.style.background = 'var(--primary-soft, #e6f0ff)';
    el.classList.add('tree-cat-highlight');
    showCategoryDetail(code, name);
}

/* ==================== 右侧详情（类目两行标题 + 诊断二级列表） ==================== */
var CURRENT_CATEGORY = '';

function showCategoryDetail(code, name) {
    CURRENT_CATEGORY = code;
    document.getElementById('diagKw').value = '';
    // 类目标题：编码 + 名称整体放入徽章，醒目
    document.getElementById('detailTitle').style.display = '';
    document.getElementById('detailTitle').innerHTML =
        '<div style="display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);padding-bottom:10px">' +
        '<span class="badge badge-primary" style="font-family:monospace;font-weight:700;font-size:15px;padding:6px 14px">' + code + ' ' + name + '</span>' +
        '</div>';
    var content = document.getElementById('detailContent');
    // 从居中引导态切换为普通内容区
    content.style.display = 'block';
    content.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
    // 加载诊断明细
    Clinic.get('/api/icd10?action=list&category=' + encodeURIComponent(code) + '&limit=500', null, {
        onSuccess: function (j2) {
            var diags = j2.data.list || [];
            content.innerHTML = '<div class="fs-13 fw-600 mb-8">诊断明细（' + diags.length + '）</div>' +
                '<div id="diagListWrap">' + buildDiagListHtml(diags) + '</div>';
            // 若来自搜索定位（存在待高亮诊断码），渲染完成后执行高亮 + 展开子诊断
            if (SEARCH_HIGHLIGHT_CODE) {
                highlightAndExpand(SEARCH_HIGHLIGHT_CODE);
                SEARCH_HIGHLIGHT_CODE = '';
            }
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