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

<div class="flex gap-8 mb-12" id="qcTabsBar">
    <button type="button" class="btn btn-primary btn-sm" data-qc-tab="critical" onclick="qcTab('critical')">🚨 危急值查询</button>
    <button type="button" class="btn btn-outline btn-sm" data-qc-tab="refs" onclick="qcTab('refs')">🩻 影像引用查询</button>
    <button type="button" class="btn btn-outline btn-sm" data-qc-tab="more" onclick="qcTab('more')">更多子项（规划中）</button>
</div>

<div id="qcCritical"></div>
<div id="qcRefs" style="display:none">
    <div class="card qc-ref-card">
        <div class="flex gap-8" style="flex-wrap:wrap;padding:14px 14px 10px">
            <input class="input" id="qcRefKw" placeholder="🔍 检索：门诊流水号 / 患者编号 / 申请单号" style="flex:1;min-width:220px"
                onkeydown="if(event.key==='Enter')loadRefs(1)">
            <button class="btn btn-primary btn-sm" onclick="loadRefs(1)">查询</button>
            <span class="fs-12 text-muted" style="align-self:center" id="qcRefTotal"></span>
        </div>
        <!-- 列表独立滚动容器（与统一打印中心 .pc-list 同构：外层定高 + 列表 flex:1 内部滚动） -->
        <div class="qc-ref-list" id="qcRefTable"><div class="fs-13 text-muted text-center" style="padding:24px">加载中…</div></div>
    </div>
</div>
<div id="qcMore" style="display:none"><div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">📊</div>更多查询子项规划中，敬请期待</div></div></div>

<script>
var curRefPage = 0;
var refLoading = false;   // 加载锁：防止滚动触发重复请求
var refDone = false;      // 是否已加载完全部
// 脚本级状态（SPA 局部刷新重新执行脚本时自动重置，避免旧 window 级标志残留）：
var refsLoaded = false;   // 本渲染实例是否已加载过第一页
var refScrollStop = null; // 无限滚动监听句柄

function esc2(s) { return Clinic.escHtml(s == null ? '' : String(s)); }

function loadRefs(page) {
    if (refLoading) return;
    refLoading = true;
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
                // 表格不使用 .table-wrap（其 overflow:auto 会抢占滚动事件，导致
                // 无限滚动监听失效）——滚动统一交给 .qc-ref-list 容器
                box.innerHTML = '<table class="table"><thead><tr>' +
                    '<th>登记时间</th><th>患者</th><th>流水号</th><th>申请单号</th><th>检查项目</th>' +
                    '<th>类型</th><th>Study UID</th><th>区域</th><th>登记人</th><th>调阅</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table>';
            } else {
                tbl.querySelector('tbody').insertAdjacentHTML('beforeend', rows);
            }
            var hasMore = !!(d.has_more && d.has_more !== '0' && page * 20 < d.total);
            refDone = !hasMoreThor;
            refLoading = false;
        },
        onError: function () { refLoading = false; },
    });
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
    if (tab === 'refs' && !refsLoaded) {
        refsLoaded = true;
        loadRefs(1);
    }
}

/* 无限滚动：列表容器 .qc-ref-list 内部滚动（与打印中心 .pc-list 同构），
   滚动到底自动加载下一页；refDone=true（已全部加载）后回调返回 false 停止监听 */
function bindRefScroll() {
    if (refScrollStop) return;
    var list = document.getElementById('qcRefTable');
    if (!list || !window.Clinic || !Clinic.infiniteScroll) return;
    refScrollStop = Clinic.infiniteScroll({
        el: list,
        threshold: 40,
        onNearBottom: function () {
            if (refDone) return false;              // 已加载完：停止监听
            loadRefs(curRefPage + 1);               // 滚动接近底部 → 加载下一页
        },
    });
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
    bindRefScroll();
});
</script>
