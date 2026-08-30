<?php
/**
 * ============================================================
 * admin/diagnosis.php v1.2.0 — 诊断管理（只读）
 * ============================================================
 * 说明：ICD-10 诊断字典为独立只读库，本页仅提供检索与查看：
 *   诊断码（icd10_code）、诊断名称（diagnosis_name）、
 *   诊断拼音首字母（pinyin，快速检索）。
 * 病历初步诊断（ICD10 联动）直接使用本库数据。
 * ============================================================ */
Router::title('诊断管理');
?>
<div class="page-head">
    <div><div class="page-title">📖 诊断管理</div><div class="page-desc">ICD10 诊断码 / 诊断名称 / 拼音首字母检索（只读字典，病历诊断联动数据源）</div></div>
    <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span></div>
</div>
<div class="card" style="margin-bottom:12px">
    <input class="input" id="diagKw" placeholder="🔍 输入诊断码 / 名称 / 拼音首字母（实时检索）" autocomplete="off" oninput="diagSearchDebounced()">
</div>
<div class="card" id="diagList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
/* 实时检索（300ms 防抖） */
var diagDebounce = null;
var diagOffset = 0;      // 当前已加载条数（分页用）
var diagTotal = 0;       // 匹配总数
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
                return '<tr>' +
                    '<td class="fw-600" style="font-family:monospace">' + d.code + '</td>' +
                    '<td>' + d.name + '</td>' +
                    '<td class="fs-12 text-muted" style="font-family:monospace">' + (d.pinyin || '—') + '</td></tr>';
            }).join('');
            var showTotal = diagOffset + list.length;
            if (diagOffset === 0) {
                // 首页：重建列表
                box.innerHTML = '<div class="fs-13 text-muted mb-8" id="diagCount">共 ' + diagTotal + ' 条诊断，已显示 ' + showTotal + ' 条</div>' +
                    '<div class="table-wrap"><table class="table"><thead><tr>' +
                    '<th style="width:160px">诊断码（ICD10）</th><th>诊断名称</th><th>拼音首字母</th>' +
                    '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>' +
                    '<div class="fs-12 text-muted" style="text-align:center;padding:10px 0" id="diagFoot">加载中…</div>';
            } else {
                // 追加
                box.querySelector('tbody').insertAdjacentHTML('beforeend', rowsHtml);
                document.getElementById('diagCount').textContent = '共 ' + diagTotal + ' 条诊断，已显示 ' + showTotal + ' 条';
                var foot = document.getElementById('diagFoot');
                if (foot) foot.textContent = (diagOffset + list.length >= diagTotal) ? '已全部加载（' + diagTotal + ' 条）' : '加载中…';
            }
            diagOffset += list.length;
            // 更新底部状态：全部加载完 → 提示；否则显示「加载更多」按钮
            updateDiagFooter();
        },
        onError: function () { diagLoading = false; },
    });
}
/* 更新列表底部：全部加载完提示 / 加载更多按钮 */
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

/* 回车查询 */
document.getElementById('diagKw').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') loadDiag();
});

loadDiag();
</script>
