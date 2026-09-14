<?php
/**
 * admin/querycenter.php — 管理员查询中心
 * 说明：全院数据查询入口。目前包含「危急值查询」——按医生端列表呈现
 * 全院危急值记录（发起科室/发起时间/接收医生/处理情况/处理时长），
 * 点击 → 只读详情查看完整处理过程；后续可在此扩展更多查询子项。
 */
Router::title('查询中心');
?>
<div class="page-head">
    <div><div class="page-title">🔍 查询中心</div><div class="page-desc">全院业务数据查询与溯源（危急值 / 影像引用 / 更多子项）</div></div>
</div>

<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" onclick="qcTab('critical')">🚨 危急值查询</button>
    <button class="btn btn-outline btn-sm" onclick="qcTab('refs')">🩻 影像引用查询</button>
    <button class="btn btn-outline btn-sm" onclick="qcTab('more')">更多子项（规划中）</button>
</div>

<div id="qcCritical"></div>
<div id="qcRefs" style="display:none">
    <div class="card">
        <div class="flex gap-8" style="flex-wrap:wrap;margin-bottom:12px">
            <input class="input" id="qcRefKw" placeholder="🔍 检索：门诊流水号 / 患者编号 / 申请单号" style="flex:1;min-width:220px"
                onkeydown="if(event.key==='Enter')loadRefs(1)">
            <button class="btn btn-primary btn-sm" onclick="loadRefs(1)">查询</button>
            <span class="fs-12 text-muted" style="align-self:center" id="qcRefTotal"></span>
        </div>
        <div id="qcRefTable"><div class="fs-13 text-muted text-center" style="padding:24px">加载中…</div></div>
        <div style="text-align:center;margin-top:12px">
            <button class="btn btn-outline btn-sm" id="qcRefMore" style="display:none" onclick="loadRefs(curRefPage + 1)">加载更多</button>
        </div>
    </div>
</div>
<div id="qcMore" style="display:none"><div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">📊</div>更多查询子项规划中，敬请期待</div></div></div>

<script>
var curRefPage = 0;

function esc2(s) { return Clinic.escHtml(s == null ? '' : String(s)); }

function loadRefs(page) {
    var kw = document.getElementById('qcRefKw').value.trim();
    Clinic.get('/api/imaging?action=refs_list&kw=' + encodeURIComponent(kw) + '&page=' + page, null, {
        onSuccess: function (json) {
            var d = json.data || {};
            curRefPage = page;
            var box = document.getElementById('qcRefTable');
            if (page <= 1) box.innerHTML = '';
            var list = d.list || [];
            document.getElementById('qcRefTotal').textContent = '共 ' + d.total + ' 条引用';
            if (!list.length && page <= 1) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">🩻</div>暂无影像引用（报告出具后自动登记）</div>';
            }
            var rows = list.map(function (r) {
                return '<tr>' +
                    '<td class="fs-12">' + esc2(r.created_at ? r.created_at.substr(0, 16) : '') + '</td>' +
                    '<td class="fw-600 fs-13">' + esc2(r.patient_name) + ' <span class="fs-12 text-muted fw-400">' + esc2(r.gender) + '/' + esc2(r.age_fmt || '') + '</span></td>' +
                    '<td class="fs-12">' + esc2(r.flow_no) + '</td>' +
                    '<td class="fs-12">' + esc2(r.order_no || '—') + '</td>' +
                    '<td>' + esc2(r.item_name || '—') + '</td>' +
                    '<td><span class="badge badge-gray" style="font-size:11px">' + esc2(r.modality || 'OT') + '</span></td>' +
                    '<td class="fs-12" style="font-family:monospace;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc2(r.study_uid) + '">' + esc2(r.study_uid) + '</td>' +
                    '<td class="fs-12">' + esc2(r.region) + '</td>' +
                    '<td class="fs-12">' + esc2(r.created_by || '') + '</td>' +
                    '<td>' + (window.__refViewerTpl
                        ? '<button class="btn btn-outline btn-sm" onclick="openRefViewer(\'' + esc2(r.study_uid) + '\')">🔍 调阅</button>'
                        : '<span class="fs-12 text-muted">—</span>') + '</td>' +
                    '</tr>';
            }).join('');
            var tbl = box.querySelector('table');
            if (!tbl) {
                box.innerHTML = '<div class="table-wrap"><table class="table"><thead><tr>' +
                    '<th>登记时间</th><th>患者</th><th>流水号</th><th>申请单号</th><th>检查项目</th>' +
                    '<th>类型</th><th>Study UID</th><th>区域</th><th>登记人</th><th>调阅</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            } else {
                tbl.querySelector('tbody').insertAdjacentHTML('beforeend', rows);
            }
            var more = document.getElementById('qcRefMore');
            more.style.display = d.has_more ? '' : 'none';
        },
    });
}

/* 阅片器直链（模板服务端注入，JS 端 {study_uid} 替换后新窗口打开） */
function openRefViewer(studyUid) {
    var url = String(window.__refViewerTpl || '').replace('{study_uid}', encodeURIComponent(studyUid));
    if (url) window.open(url, '_blank', 'noopener');
}

window.__refViewerTpl = <?php echo json_encode(trim((string)setting('pacs_viewer_url', ''))); ?>;

function qcTab(tab) {
    document.getElementById('qcCritical').style.display = tab === 'critical' ? '' : 'none';
    document.getElementById('qcRefs').style.display = tab === 'refs' ? '' : 'none';
    document.getElementById('qcMore').style.display = tab === 'more' ? '' : 'none';
    if (tab === 'refs' && !window.__refsLoaded) {
        window.__refsLoaded = 1;
        loadRefs(1);
    }
}
document.addEventListener('DOMContentLoaded', function () {
    Clinic.critical.initListPage({
        role: 'admin',
        container: 'qcCritical',
        listBody: 'critListBody',
        totalEl: 'critTotal',
        footEl: 'critMore',
        defaultFrom: '<?php echo date('Y-m-d', strtotime('-2 days')); ?>',
        defaultTo: '<?php echo date('Y-m-d'); ?>',
    });
});
</script>
