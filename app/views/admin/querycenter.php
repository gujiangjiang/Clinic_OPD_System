<?php
/**
 * admin/querycenter.php — 管理员查询中心
 * 说明：全院数据查询入口。目前包含「危急值查询」——按医生端列表呈现
 * 全院危急值记录（发起科室/发起时间/接收医生/处理情况/处理时长），
 * 点击 → 只读详情查看完整处理过程；后续可在此扩展更多查询子项。
 */
Router::title('查询中心');
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">🔍 查询中心</div><div class="page-desc">全院业务数据查询与溯源（危急值 / 影像引用 / 更多子项）</div></div>
</div>

<div class="flex gap-8 mb-12" id="qcTabsBar">
    <button type="button" class="btn btn-primary btn-sm" data-qc-tab="critical" onclick="qcTab('critical')">🚨 危急值查询</button>
    <button type="button" class="btn btn-outline btn-sm" data-qc-tab="refs" onclick="qcTab('refs')">🩻 影像引用查询</button>
    <button type="button" class="btn btn-outline btn-sm" data-qc-tab="more" onclick="qcTab('more')">更多子项（规划中）</button>
</div>

<div class="qc-body">
<div id="qcCritical"></div>
<div id="qcRefs" style="display:none">
    <!-- 搜索工具条：与打印中心/科室管理等页面统一（筛选卡片 + 列表卡片分离，卡片间 16px 间距） -->
    <div class="card list-filter">
        <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
            <input type="text" class="input input-date" id="qcRefFrom" readonly placeholder="开始日期" 
                onclick="Clinic.datePicker.open(this,{maxToday:false,peer:'qcRefTo',maxSpan:183})">
            <span class="text-muted">至</span>
            <input type="text" class="input input-date" id="qcRefTo" readonly placeholder="结束日期" 
                onclick="Clinic.datePicker.open(this,{maxToday:true,peer:'qcRefFrom',maxSpan:183})">
            <input class="input" id="qcRefKw" placeholder="🔍 检索：门诊流水号 / 患者编号 / 申请单号" style="flex:1;min-width:220px"
                onkeydown="if(event.key==='Enter')searchRefs()">
            <button class="btn btn-primary btn-sm" onclick="searchRefs()">查询</button>
            <button class="btn btn-outline btn-sm" onclick="resetRefs()">重置</button>
            <span class="fs-12 text-muted" id="qcRefTotal"></span>
        </div>
    </div>
    <!-- 列表卡片（独立滚动容器：外层占满剩余高度 + 列表 flex:1 内部滚动） -->
    <div class="card qc-ref-list-card">
        <div class="qc-ref-list" id="qcRefTable"><div class="fs-13 text-muted text-center" style="padding:24px">加载中…</div></div>
    </div>
</div>
<div id="qcMore" style="display:none"><div class="card qc-more-card"><div class="empty qc-more-empty"><div class="empty-ico">📊</div>更多查询子项规划中，敬请期待</div></div></div>
</div>
</div>

<script>

var refList = null;   // 当前渲染实例的无限列表句柄（SPA 重跑脚本时重置）

/** 渲染影像引用行（isFirst=true 首次含表头；后续页仅返回 tr 行，由 append 插入已有 tbody） */
function refRowHtml(list, isFirst) {
    var rows = list.map(function (r) {
        return '<tr>' +
            '<td class="fs-12">' + escHtml(r.created_at ? r.created_at.substr(0, 16) : '') + '</td>' +
            '<td class="fw-600 fs-13">' + escHtml(r.patient_name) + ' <span class="fs-12 text-muted fw-400">' + escHtml(r.gender) + '/' + escHtml(r.age_fmt || '') + '</span></td>' +
            '<td class="fs-12">' + escHtml(r.flow_no) + '</td>' +
            '<td class="fs-12">' + escHtml(r.order_no || '—') + '</td>' +
            '<td>' + escHtml(r.item_name || '—') + '</td>' +
            '<td><span class="badge badge-gray" style="font-size:11px">' + escHtml(r.modality || 'OT') + '</span></td>' +
            '<td class="fs-12" style="font-family:monospace;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escHtml(r.study_uid) + '">' + escHtml(r.study_uid) + '</td>' +
            '<td class="fs-12">' + escHtml(r.region) + '</td>' +
            '<td class="fs-12">' + escHtml(r.created_by || '') + '</td>' +
            '<td>' + (window.__refViewerTpl
                ? '<button class="btn btn-outline btn-sm" onclick="openRefViewer(\'' + escHtml(r.study_uid) + '\')">🔍 调阅</button>'
                : '<span class="fs-12 text-muted">—</span>') + '</td>' +
            '</tr>';
    }).join('');
    if (!isFirst) return rows;   // 后续页：仅返回行，由 append 插入已有表格 tbody
    return '<table class="table"><thead><tr>' +
        '<th>登记时间</th><th>患者</th><th>流水号</th><th>申请单号</th><th>检查项目</th>' +
        '<th>类型</th><th>Study UID</th><th>区域</th><th>登记人</th><th>调阅</th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>';
}

/* 初始化影像引用列表（统一动态加载封装 Clinic.infiniteList） */
function initRefList() {
    var listEl = document.getElementById('qcRefTable');
    if (!listEl || refList || !window.Clinic || !Clinic.infiniteList) return;
    refList = Clinic.infiniteList({
        el: listEl,
        pageSize: 20,   // 影像引用每页 20 条
        threshold: 40,
        totalEl: document.getElementById('qcRefTotal'),
        emptyHtml: '<div class="empty" style="padding:30px 0"><div class="empty-ico">🩻</div>暂无影像引用（报告出具后自动登记）</div>',
        // url 用函数（每次加载读取当前检索值）：关键字 + 日期范围均为动态条件；
        // 后端按 ir.id DESC 倒序返回（最新登记在最上面）
        url: function (p, size) {
            return '/api/imaging?action=refs_list&page=' + p + '&size=' + size +
                '&kw=' + encodeURIComponent((document.getElementById('qcRefKw') || {}).value || '') +
                '&from=' + encodeURIComponent((document.getElementById('qcRefFrom') || {}).value || '') +
                '&to=' + encodeURIComponent((document.getElementById('qcRefTo') || {}).value || '');
        },
        render: refRowHtml,
        // 后续页仅返回 tr 行：追加到已有表格的 tbody（保证表格样式统一）
        append: function (el, html) {
            var tb = el.querySelector('table tbody');
            if (tb) tb.insertAdjacentHTML('beforeend', html);
            else el.insertAdjacentHTML('beforeend', html);
        },
    });
}

/* 搜索：重置列表到第一页（检索值由 url 函数在加载时读取） */
function searchRefs() {
    if (refList) refList.reset();
    else initRefList();
}

/* 重置：清空日期范围与关键字回到全部列表 */
function resetRefs() {
    var f = document.getElementById('qcRefFrom');
    var t = document.getElementById('qcRefTo');
    var kw = document.getElementById('qcRefKw');
    if (f) f.value = '';
    if (t) t.value = '';
    if (kw) kw.value = '';
    if (refList) refList.reset();
    else initRefList();
}

/* 阅片器直链（模板服务端注入，JS 端 {study_uid} 替换后新窗口打开） */
function openRefViewer(studyUid) {
    var url = String(window.__refViewerTpl || '').replace('{study_uid}', encodeURIComponent(studyUid));
    if (url) window.open(url, '_blank', 'noopener');
}

window.__refViewerTpl = <?php echo json_encode(trim((string)setting('pacs_viewer_url', ''))); ?>;

function qcTab(tab) {
    // 子 Tab 按钮选中态切换（btn-primary 选中 / btn-outline 未选中）
    document.querySelectorAll('#qcTabsBar [data-qc-tab]').forEach(function (b) {
        var on = b.getAttribute('data-qc-tab') === tab;
        b.classList.toggle('btn-primary', on);
        b.classList.toggle('btn-outline', !on);
    });
    document.getElementById('qcCritical').style.display = tab === 'critical' ? '' : 'none';
    document.getElementById('qcRefs').style.display = tab === 'refs' ? '' : 'none';
    document.getElementById('qcMore').style.display = tab === 'more' ? '' : 'none';
    if (tab === 'refs') initRefList();   // 首次进入初始化；SPA 重进 refList 为 null 重新绑定
}

document.addEventListener('DOMContentLoaded', function () {
    Clinic.critical.initListPage({
        role: 'admin',
        container: 'qcCritical',
        listBody: 'critListBody',
        totalEl: 'critTotal',
        footEl: 'critMore',
        defaultFrom: '<?php echo date('Y-m-d', strtotime('-2 days')); ?>',
        defaultTo: '<?php echo today_str(); ?>',
    });
});
</script>
