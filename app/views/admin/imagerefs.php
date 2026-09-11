<?php
/**
 * ============================================================
 * admin/imagerefs.php — 影像引用查询（管理端，只存引用架构视图）
 * ============================================================
 * 说明（第13项）：
 *   平台只存影像引用（Study/Series UID 与元数据），本页为管理员/影像科
 *   提供引用台账检索：按 门诊流水号 / 患者编号 / 申请单号 检索（三单匹配键），
 *   列表展示引用核心信息（study_uid / 检查类型 / 区域存储 / 张数 / 登记人）；
 *   已配置 Web 阅片器 URL 模板时提供【调阅影像】直链（新窗口打开阅片器）。
 * 数据接口：/api/imaging?action=refs_list（admin/imaging 角色门禁）。
 * ============================================================ */
Router::title('影像引用查询');
?>
<div class="page-head">
    <div><div class="page-title">🩻 影像引用查询</div>
    <div class="page-desc">只存引用架构台账：平台仅保存 Study/Series UID 与元数据，影像本体由区域影像存储（region）与 Web 阅片器负责；引用与申请单/就诊以门诊流水号三单硬绑定</div></div>
</div>

<div class="card">
    <div class="flex gap-8" style="flex-wrap:wrap;margin-bottom:12px">
        <input class="input" id="refKw" placeholder="🔍 检索：门诊流水号 / 患者编号 / 申请单号" style="flex:1;min-width:220px"
            onkeydown="if(event.key==='Enter')loadRefs(1)">
        <button class="btn btn-primary btn-sm" onclick="loadRefs(1)">查询</button>
        <span class="fs-12 text-muted" style="align-self:center" id="refTotal"></span>
    </div>
    <div id="refTable"><div class="fs-13 text-muted text-center" style="padding:24px">加载中…</div></div>
    <div style="text-align:center;margin-top:12px">
        <button class="btn btn-outline btn-sm" id="refMore" style="display:none" onclick="loadRefs(curRefPage + 1)">加载更多</button>
    </div>
</div>

<script>
var curRefPage = 0;

function esc2(s) { return Clinic.escHtml(s == null ? '' : String(s)); }

function loadRefs(page) {
    var kw = document.getElementById('refKw').value.trim();
    Clinic.get('/api/imaging?action=refs_list&kw=' + encodeURIComponent(kw) + '&page=' + page, null, {
        onSuccess: function (json) {
            var d = json.data || {};
            curRefPage = page;
            var box = document.getElementById('refTable');
            if (page <= 1) box.innerHTML = '';
            var list = d.list || [];
            document.getElementById('refTotal').textContent = '共 ' + d.total + ' 条引用';
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
            var more = document.getElementById('refMore');
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

loadRefs(1);
</script>
