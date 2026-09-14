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
    <div class="card" style="padding-bottom:4px">
        <div class="flex gap-8" style="flex-wrap:wrap;margin-bottom:12px">
            <input class="input" id="qcRefKw" placeholder="🔍 检索：门诊流水号 / 患者编号 / 申请单号" style="flex:1;min-width:220px"
                onkeydown="if(event.key==='Enter')loadRefs(1)">
            <button class="btn btn-primary btn-sm" onclick="loadRefs(1)">查询</button>
            <span class="fs-12 text-muted" style="align-self:center" id="qcRefTotal"></span>
        </div>
        <div id="qcRefTable"><div class="fs-13 text-muted text-center" style="padding:24px">加载中…</div></div>
        <div class="qc-ref-scroll-status" id="qcRefMore" onclick="loadRefs(curRefPage + 1)" title="点击也可加载下一页">滚动加载更多</div>
    </div>
</div>
<div id="qcMore" style="display:none"><div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">📊</div>更多查询子项规划中，敬请期待</div></div></div>

<script>
var curRefPage = 0;
var refLoading = false;   // 加载锁：防止滚动触发重复请求
var refDone = false;      // 是否已加载完全部

function esc2(s) { return Clinic.escHtml(s == null ? '' : String(s)); }

function loadRefs(page) {
    if (refLoading) return;
    refLoading = true;
    var more = document.getElementById('qcRefMore');
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
            // 滚动加载状态提示：还有更多可继续滚动加载；已全部加载显示完成提示
            var hasMore = !!(d.has_more && d.has_more !== '0' && page * 20 < d.total);
            refDone = !hasMore;
            if (hasMore) { more.textContent = '↓ 继续向下滚动加载更多'; more.style.display = ''; }
            else if (page > 1) { more.textContent = '已加载全部引用'; more.style.display = ''; }
            else { more.style.display = 'none'; }
            refLoading = false;
            // 加载完成后主动判定一次：内容不满一屏（无滚动条）时自动续加载下一页
            if (window.__refScrollStop) window.__refScrollStop.check();
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
    if (tab === 'refs' && !window.__refsLoaded) {
        window.__refsLoaded = 1;
        loadRefs(1);
    }
}

/* 无限滚动：通用工具 Clinic.infiniteScroll——自动识别滚动容器（.content 主内容区），
   滚动接近列表底部自动加载下一页；refDone=true（已全部加载）后回调返回 false 停止监听 */
window.__refScrollStop = null;
function bindRefScroll() {
    if (window.__refScrollStop) return;
    var sentinel = document.getElementById('qcRefMore');
    if (!sentinel || !window.Clinic || !Clinic.infiniteScroll) return;
    window.__refScrollStop = Clinic.infiniteScroll({
        el: sentinel,
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
