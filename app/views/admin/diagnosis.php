<?php
/**
 * ============================================================
 * admin/diagnosis.php v2.0.0 — 诊断管理（标准库只读版）
 * ============================================================
 * 说明：ICD-10 诊断标准编码库浏览（完整医保版，只读）。
 * 支持按诊断码/名称/拼音检索，按章/节/类目/亚目层级筛选。
 * 标准库不可由管理员新增/编辑/删除（后续改为树形浏览）。
 * ============================================================ */
Router::title('诊断管理');
?>
<div class="page-head">
    <div><div class="page-title">📖 诊断管理</div><div class="page-desc">ICD10 完整标准编码库检索（只读，含四级分类：章→节→类目→亚目）</div></div>
</div>
<div class="card" style="margin-bottom:12px">
    <input class="input" id="diagKw" placeholder="🔍 输入诊断码 / 名称 / 拼音首字母（实时检索）" autocomplete="off" oninput="diagSearchDebounced()">
</div>
<div class="card" id="diagList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var diagDebounce = null;
var diagOffset = 0;
var diagTotal = 0;
var diagLoading = false;
function diagSearchDebounced() {
    if (diagDebounce) clearTimeout(diagDebounce);
    diagDebounce = setTimeout(function () { diagOffset = 0; loadDiag(); }, 300);
}
function loadDiag() {
    var kw = document.getElementById('diagKw').value.trim();
    if (diagLoading) return;
    diagLoading = true;
    Clinic.get('/api/icd10?action=list&kw=' + encodeURIComponent(kw) + '&offset=' + diagOffset + '&limit=50', null, {
        onSuccess: function (json) {
            diagLoading = false;
            var list = json.data.list || [];
            diagTotal = json.data.total || 0;
            var box = document.getElementById('diagList');
            if (diagOffset === 0 && !list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">📖</div>未检索到诊断</div>';
                diagOffset = 0;
                return;
            }
            var rowsHtml = list.map(function (d) {
                var chain = [];
                if (d.chapter_name) chain.push(d.chapter_name);
                if (d.section_name) chain.push(d.section_name);
                if (d.category_name) chain.push(d.category_name);
                if (d.subcategory_name) chain.push(d.subcategory_name);
                return '<tr>' +
                    '<td class="fw-600" style="font-family:monospace">' + d.icd10_code + '</td>' +
                    '<td>' + d.diagnosis_name + '</td>' +
                    '<td class="fs-12 text-muted">' + (d.pinyin || '—') + '</td>' +
                    '<td class="fs-12 text-muted" style="max-width:300px">' + chain.join(' → ') + '</td>' +
                    '</tr>';
            }).join('');
            var showTotal = diagOffset + list.length;
            if (diagOffset === 0) {
                box.innerHTML = '<div class="fs-13 text-muted mb-8" id="diagCount">共 ' + diagTotal + ' 条诊断，已显示 ' + showTotal + ' 条</div>' +
                    '<div class="table-wrap"><table class="table"><thead><tr>' +
                    '<th style="width:160px">诊断码（ICD10）</th><th>诊断名称</th><th>拼音</th><th>分类归属</th>' +
                    '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>' +
                    '<div class="fs-12 text-muted" style="text-align:center;padding:10px 0" id="diagFoot">加载中…</div>';
            } else {
                box.querySelector('tbody').insertAdjacentHTML('beforeend', rowsHtml);
                document.getElementById('diagCount').textContent = '共 ' + diagTotal + ' 条诊断，已显示 ' + showTotal + ' 条';
            }
            diagOffset += list.length;
            updateDiagFooter();
        },
        onError: function () { diagLoading = false; },
    });
}
function updateDiagFooter() {
    var box = document.getElementById('diagList');
    if (!box) return;
    var foot = document.getElementById('diagFoot');
    if (!foot) return;
    if (diagOffset >= diagTotal) {
        foot.textContent = '已全部加载（' + diagTotal + ' 条）';
        return;
    }
    foot.innerHTML = '<button class="btn btn-outline btn-sm" onclick="loadDiag()">加载更多（' + (diagTotal - diagOffset) + ' 条）</button>';
}
document.getElementById('diagKw').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') loadDiag();
});
loadDiag();
</script>