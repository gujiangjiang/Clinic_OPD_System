<?php
/**
 * ============================================================
 * imaging/dashboard.php — 影像科工作台（双模式重构）
 * ============================================================
 * 说明：顶部视图切换器（localStorage 记忆用户最后选择）：
 *   【模式 B 经典双屏分屏模式】保留现有布局与右侧侧边栏，
 *      点击患者弹出影像诊断报告单页 + 模态框写报告；
 *      患者行/顶部工具栏提供【独立视窗阅片】按钮（window.open 弹出
 *      无工具栏独立窗口，用于多显示器全屏拖拽阅片；Session 失效时
 *      由 BroadcastChannel clinic_auth_sync 联动锁定）。
 *   【模式 A 一体化阅片模式】参考专业 RIS 阅片工作台三栏展开：
 *      左（检查信息 + 序列缩略图占位）/ 中（专业深色读片视窗 + 工具栏
 *      占位 + DICOMweb 挂载能力）/ 右（临床信息 / 报告撰写 / 历史报告
 *      Tab 区），并隐藏系统默认右侧大纲栏保证工作空间。
 * 数据接口：/api/deptwork（queue/patient）+ /api/imaging（register_order/
 * save_result/withdraw/history_reports/viewer_url）。
 * ============================================================ */
require APP_ROOT . '/app/includes/dept_workbench.php';
dept_workbench(array(
    'role' => 'imaging',
    'title' => '影像科工作台',
    'desc' => '检查登记、报告书写与报告管理（检查项目请到「检查管理」维护）',
    'emoji' => '🩻',
));
?>
<script>
/* ==================== 影像科工作台：双模式初始化 ==================== */
Clinic.deptwork.configure({
    role: 'imaging',
    render: renderImgWork,
    afterAction: afterImgAction,
});

function afterImgAction(orderId) {
    // 局部刷新：登记/提交报告（已知申请单）仅重建该区块保持滚动位置；
    // 撤回等无法定位申请单的场景轻量重渲染（fetchPatient 无加载遮罩，不整页刷新）
    if (orderId) refreshImgSec(orderId);
    else Clinic.deptwork.fetchPatient(function (data) { renderImgWork(data); Clinic.deptwork.refreshQueue(); });
}

function esc(s) { return Clinic.escHtml(s); }
function nl2br(s) { return Clinic.nl2br(s); }
function itemStatusName(s) {
    var map = { open: '待缴费', paid: '待登记', registered: '待出报告', done: '已完成', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}
function imgStatusBadge(s) {
    var cls = s === 'done' ? 'badge-success' : (s === 'registered' ? 'badge-warning' : 'badge-gray');
    return Clinic.deptwork.statusBadge(itemStatusName(s), cls);
}

/* ==================== 模式切换器（localStorage 记忆最后选择） ==================== */
var IMG_MODE_KEY = 'clinic_img_view_mode';
function imgModeKey() { return IMG_MODE_KEY; }
function imgReadMode() { try { return localStorage.getItem(imgModeKey()) || 'classic'; } catch (e) { return 'classic'; } }
function imgSaveMode(m) { try { localStorage.setItem(imgModeKey(), m); } catch (e) { /* 忽略 */ } }

function renderImgWork(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'imaging'; });
    var imgItems = [];
    orders.forEach(function (o) { imgItems = imgItems.concat(o.items); });
    window.__imgItems = imgItems;
    window.__imgData = data;

    // 模式 A：一体化阅片（三栏工作台，隐藏右栏大纲）
    if (imgReadMode() === 'integrated') {
        renderImgIntegrated(data);
        return;
    }

    // 模式 B：经典双屏分屏（原布局：右栏大纲 + 主区报告单页）
    renderImgSide(data);
    var head = imgHeadHtml(data);
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">🩻</div>本次就诊暂无检查项目</div></div>';
    } else {
        orders.forEach(function (o) { body += imgOrderHtml(o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
}

/* 模式切换：重建当前患者工作台（切换器注入 dept_workbench 骨架顶栏） */
function imgSwitchMode(mode) {
    imgSaveMode(mode);
    // 切换器高亮
    document.querySelectorAll('.pms-btn').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-mode') === mode);
    });
    // 一体化模式隐藏右栏大纲（CSS 由 .pacs-integrated 类驱动）
    document.querySelector('.emr-workspace-layout').classList.toggle('pacs-integrated', mode === 'integrated');
    // 模式切换后重建当前患者视图
    if (Clinic.deptwork.currentVisit()) {
        Clinic.deptwork.fetchPatient(function (data) { renderImgWork(data); });
    } else {
        Clinic.deptwork.closePatient();
    }
}

/* ==================== 模式 A：一体化阅片工作台（三栏） ==================== */
function renderImgIntegrated(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'imaging'; });
    var imgItems = [];
    orders.forEach(function (o) { imgItems = imgItems.concat(o.items); });
    window.__imgItems = imgItems;

    // 当前可撰写项目：已登记待出报告的首项（无则取已完成首项供回显）
    var cur = null;
    imgItems.forEach(function (it) { if (!cur && it.status === 'registered') cur = it; });
    if (!cur) imgItems.forEach(function (it) { if (!cur && it.status === 'done') cur = it; });
    window.__imgCurItem = cur;

    var main = document.getElementById('dwMain');
    main.innerHTML =
        '<div class="pacs-workstation">' +
        /* 左栏：检查信息 + 序列缩略图 */
        '<div class="pacs-left">' +
        '  <div class="pacs-info-card">' +
        '    <div class="pacs-info-title">🩻 检查信息</div>' +
        '    <div class="pacs-info-line"><span class="k">患者</span><span class="v">' + esc(v.name) + '（' + esc(v.gender) + ' / ' + esc(v.age_fmt || '') + '）</span></div>' +
        '    <div class="pacs-info-line"><span class="k">门诊号</span><span class="v">' + esc(v.visit_no) + '</span></div>' +
        '    <div class="pacs-info-line"><span class="k">患者ID</span><span class="v">' + esc(p.patient_id) + '</span></div>' +
        '    <div class="pacs-info-line"><span class="k">检查项目</span><span class="v">' + esc(cur ? cur.item_name : '—') + '</span></div>' +
        '    <div class="pacs-info-line"><span class="k">检查类型</span><span class="v">' + esc(imgModality(cur)) + '</span></div>' +
        '    <div class="pacs-info-line"><span class="k">开单科室</span><span class="v">' + esc(v.first_dept_name || v.dept_name || '') + '</span></div>' +
        '  </div>' +
        '  <div class="pacs-series-card">' +
        '    <div class="pacs-info-title">📋 序列 / Series <span class="fs-12 text-muted" style="font-weight:400;margin-left:auto">DICOMweb 接入后展示</span></div>' +
        '    <div class="pacs-series-list" id="pacsSeriesList">' + pacsSeriesHtml(imgItems) + '</div>' +
        '  </div>' +
        '</div>' +
        /* 中栏：读片核心视窗（专业深色） */
        '<div class="pacs-viewer">' +
        '  <div class="pacs-viewer-toolbar">' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'窗宽窗位\')">◐ 窗宽窗位</button>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'缩放\')">🔍 缩放</button>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'平移\')">✥ 平移</button>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'测量标注\')">📐 测量标注</button>' +
        '    <span class="pacs-tool-sep"></span>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'旋转\')">↻ 旋转</button>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'翻转\')">⇋ 翻转</button>' +
        '    <span class="pacs-tool-sep"></span>' +
        '    <button type="button" class="pacs-tool-btn" onclick="pacsTool(\'重置\')">↺ 重置</button>' +
        '    <span style="margin-left:auto" class="fs-12 text-muted">工具栏占位（DICOM Viewer 接入后生效）</span>' +
        '  </div>' +
        '  <div class="pacs-viewer-stage">' +
        '    <div class="pacs-viewer-mount" id="pacsViewerMount"></div>' +
        '    <div class="pacs-viewer-placeholder" id="pacsViewerPh">' +
        '      <div class="ph-ico">🩻</div>' +
        '      <div class="ph-main">专业阅片视窗</div>' +
        '      <div class="ph-sub">DICOMweb / WADO-RS 阅片器接入后，影像将自动挂载至此视窗<br>' +
        '      管理员可在【外部接口集成 → DICOM/PACS】配置 Web 阅片器 URL 模板（支持 {study_uid} 变量）</div>' +
        '    </div>' +
        '    <div class="pacs-viewer-tag" id="pacsTagL"></div>' +
        '    <div class="pacs-viewer-tag pacs-viewer-tag-r" id="pacsTagR"></div>' +
        '  </div>' +
        '</div>' +
        /* 右栏：报告撰写与参考信息 */
        '<div class="pacs-right">' +
        '  <div class="pacs-right-tabs">' +
        '    <button type="button" class="pacs-right-tab active" data-rtab="clin" onclick="imgRightTab(\'clin\')">临床信息</button>' +
        '    <button type="button" class="pacs-right-tab" data-rtab="write" onclick="imgRightTab(\'write\')">报告撰写</button>' +
        '    <button type="button" class="pacs-right-tab" data-rtab="hist" onclick="imgRightTab(\'hist\')">历史报告</button>' +
        '  </div>' +
        '  <div class="pacs-right-body">' +
        imgClinPane(data) +
        imgWritePane(cur, data) +
        imgHistPane(p) +
        '  </div>' +
        '  <div class="pacs-right-foot">' +
        '    <button type="button" class="btn btn-outline btn-sm" onclick="imgDraftSave()">💾 保存草稿</button>' +
        '    <button type="button" class="btn btn-primary btn-sm" onclick="imgPublish()">📤 提交审核</button>' +
        '    <button type="button" class="btn btn-outline btn-sm pacs-crit-btn" onclick="openImgCritSend()">🚨 标记危急值</button>' +
        '    <button type="button" class="btn btn-outline btn-sm" onclick="imgRejectBack()">↩ 退回修改</button>' +
        '  </div>' +
        '</div>' +
        '</div>';

    // 视窗左上角标签：当前患者/项目信息（专业阅片习惯）
    var tl = document.getElementById('pacsTagL');
    if (tl) tl.textContent = '> ' + (cur ? cur.item_name : '未选择检查项目') + ' ｜ ' + v.name;
    var tr = document.getElementById('pacsTagR');
    if (tr) tr.textContent = v.visit_no || '';

    // 同步独立阅片窗口（优化项12）：患者加载完成广播当前上下文
    broadcastImgContext(cur);

    // 初始化：默认打开「报告撰写」页签（撰写为高频主任务）
    imgRightTab(cur && cur.status === 'registered' ? 'write' : 'clin');
    // 报告模板下拉：接入现有影像科模板（与经典模态框同一数据源）
    loadPacsTpls();
    // 历史报告调阅（仅挂载一次；切患者时重新挂载）
    mountImgHistory(p);
}

/* 独立阅片窗口上下文广播（患者/序列变化实时同步） */
function broadcastImgContext(item) {
    if (!window.Clinic || !Clinic.authSync) return;
    var v = (window.__imgData || {}).visit || {};
    Clinic.authSync.broadcastContext({
        visit: v.code || Clinic.deptwork.currentVisit() || '',
        item: item ? item.id : '',
        label: item ? (item.item_name + ' ｜ ' + imgModality(item)) : '',
    });
}

/* 检查类型（DR / CT / US 等）：由项目名/类别推导占位 */
function imgModality(it) {
    if (!it) return '—';
    var name = (it.item_name || '') + '';
    if (/CT/i.test(name)) return 'CT';
    if (/DR|X线|X光|摄片|胸片/i.test(name)) return 'DR';
    if (/超声|US|B超/i.test(name)) return 'US';
    if (/MR|磁共振|核磁/i.test(name)) return 'MR';
    return '影像';
}

/* 序列缩略图占位（DICOMweb 接入前：尺寸 + 张数标注占位） */
function pacsSeriesHtml(items) {
    if (!items.length) {
        return '<div class="fs-12 text-muted" style="padding:6px 2px">暂无检查序列（PACS 接入后自动加载）</div>';
    }
    return items.map(function (it, i) {
        var st = it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done');
        return '<div class="pacs-series-item' + (i === 0 ? ' active' : '') + '" onclick="pacsPickSeries(this,\'' + esc(it.id) + '\')">' +
            '<div class="pacs-thumb">🩻<span class="pacs-thumb-size">512×512</span></div>' +
            '<div class="pacs-series-meta">' +
            '<div class="pacs-series-name">' + esc(it.item_name) + '</div>' +
            '<div class="pacs-series-sub">Series ' + (i + 1) + ' · <span class="dot ' + st + '"></span> ' + itemStatusName(it.status) + '</div>' +
            '</div></div>';
    }).join('');
}

function pacsPickSeries(el, itemId) {
    document.querySelectorAll('.pacs-series-item').forEach(function (x) { x.classList.remove('active'); });
    el.classList.add('active');
    var it = null;
    (window.__imgItems || []).forEach(function (x) { if (x.id === itemId) it = x; });
    var tl = document.getElementById('pacsTagL');
    if (tl && it) tl.textContent = '> ' + it.item_name + ' ｜ 序列已选中';
    // 同步独立阅片窗口 + 左下角检查信息动态更新（优化项7/12）
    window.__imgCurItem = it;
    broadcastImgContext(it);
    updateImgInfoCard(it);
}

function pacsTool(name) {
    var tl = document.getElementById('pacsTagL');
    if (tl) tl.textContent = '> 工具：' + name + '（占位交互，Viewer 接入后生效）';
}

/* ---------- 右栏页签 ---------- */
function imgRightTab(tab) {
    document.querySelectorAll('.pacs-right-tab').forEach(function (t) {
        t.classList.toggle('active', t.getAttribute('data-rtab') === tab);
    });
    document.querySelectorAll('.pacs-right-pane').forEach(function (p) {
        p.classList.toggle('active', p.getAttribute('data-pane') === tab);
    });
}

/* 临床信息页签：门诊号/姓名/性别/年龄/主诉/临床初步诊断 */
function imgClinPane(data) {
    var v = data.visit || {}, p = data.patient || {}, s = data.summary || {};
    var line = function (k, val) {
        return '<div class="pacs-info-line"><span class="k">' + k + '</span><span class="v">' + esc(val || '—') + '</span></div>';
    };
    return '<div class="pacs-right-pane" data-pane="clin">' +
        '<div class="pacs-info-card" style="border:none;padding:0 0 10px">' +
        line('门诊号', v.visit_no) + line('姓名', v.name) + line('性别', v.gender) +
        line('年龄', v.age_fmt) + line('出生日期', p.birth_date) + line('患者ID', p.patient_id) +
        '</div>' +
        '<div class="pacs-rep-label">主诉 / 现病史</div>' +
        '<div class="pacs-hist-detail open" style="margin-top:6px"><div class="hist-field-text">' + esc(s.chief_complaint || '—') +
        (s.present_illness ? '\n' + s.present_illness : '') + '</div></div>' +
        '<div class="pacs-rep-label" style="margin-top:12px">临床初步诊断</div>' +
        '<div class="pacs-hist-detail open" style="margin-top:6px"><div class="hist-field-text">' + esc(s.diagnosis || '—') + '</div></div>' +
        '</div>';
}

/* 报告撰写页签：模板下拉 + 常用语快捷插入 + 危急值按钮 + 表现/诊断双区 */
function imgWritePane(cur, data) {
    var quick = ['两肺纹理清晰，走行自然。', '心影大小、形态正常。', '膈肌光整，肋膈角锐利。', '必要时结合临床随访复查。', '所示骨质结构未见明显异常。'];
    return '<div class="pacs-right-pane" data-pane="write">' +
        '<div class="pacs-rep-block">' +
        '<div class="pacs-rep-label">报告模板' +
        '<select class="select" id="pacsTplSel" style="margin-left:auto;max-width:200px;font-size:12px" onchange="pacsTplPick(this.value)"><option value="">选择模板…</option></select>' +
        '</div>' +
        '<div class="fs-12 text-muted" id="pacsTplHint">选择模板后可覆盖或续写应用（模板列表加载中…）</div>' +
        '</div>' +
        '<div class="pacs-rep-block">' +
        '<div class="pacs-rep-label">影像学表现（描述）<span class="req">*</span></div>' +
        '<textarea class="textarea pacs-rep-textarea" id="pacsFindings" style="min-height:150px" placeholder="请填写影像学表现描述">' + esc(cur ? (cur.findings || '') : '') + '</textarea>' +
        '<div class="pacs-quickwords" id="pacsQuickFindings">' +
        quick.map(function (q) { return '<span class="pacs-quickword" onclick="pacsQuickInsert(\'pacsFindings\', this)">' + esc(q) + '</span>'; }).join('') +
        '</div></div>' +
        '<div class="pacs-rep-block">' +
        '<div class="pacs-rep-label">影像学诊断（结论）<span class="req">*</span></div>' +
        '<textarea class="textarea pacs-rep-textarea" id="pacsConclusion" style="min-height:90px" placeholder="请填写影像学诊断（检查结论）">' + esc(cur ? (cur.conclusion || '') : '') + '</textarea>' +
        '<div class="pacs-quickwords" id="pacsQuickConclusion">' +
        '<span class="pacs-quickword" onclick="pacsQuickInsert(\'pacsConclusion\', this)">目前影像学检查未见明显异常。</span>' +
        '<span class="pacs-quickword" onclick="pacsQuickInsert(\'pacsConclusion\', this)">建议随访复查。</span>' +
        '</div></div>' +
        '</div>';
}

/* 历史报告页签（挂载容器；组件化渲染见 pacshistory.js） */
function imgHistPane(p) {
    return '<div class="pacs-right-pane" data-pane="hist" id="pacsHistPane">' +
        '<div class="fs-12 text-muted" style="padding:8px 2px">' +
        (p && p.patient_id ? '患者 ' + esc(p.name || '') + '（' + esc(p.patient_id) + '）的历史影像检查' : '请先选择患者') +
        '</div></div>';
}

/* 历史报告挂载（一体化右栏）：一键复制写入当前报告撰写区 */
function mountImgHistory(p) {
    var pane = document.getElementById('pacsHistPane');
    if (!pane || !p || !p.patient_id) return;
    Clinic.pacsHistory.setPatient({ patient_id: p.patient_id });
    Clinic.pacsHistory.mount({
        container: pane,
        patientId: p.patient_id,
        emptyText: '该患者暂无历史影像报告',
        onCopy: function (kind, text) {
            if (!text) { Clinic.toast.warning('该历史报告字段为空，无可复制内容'); return; }
            var el = document.getElementById(kind === 'findings' ? 'pacsFindings' : 'pacsConclusion');
            if (!el) { Clinic.toast.warning('请先切换到「报告撰写」页签'); return; }
            el.value = el.value.trim()
                ? el.value.replace(/\s*$/, '') + '\n' + text
                : text;
            Clinic.toast.success('已复制到当前报告' + (kind === 'findings' ? '【影像学表现】' : '【影像学诊断】'));
            imgRightTab('write');
        },
    });
}

/* ---------- 模板（下拉选择 → 覆盖/续写选择弹窗，参考经典模态框交互） ---------- */
var IMG_TPLS2 = [];
function loadPacsTpls() {
    var sel = document.getElementById('pacsTplSel');
    if (!sel) return;
    // 与经典模态框共用同一数据源（/api/template imaging_report）
    Clinic.get('/api/template?action=list&type=imaging_report', null, {
        loading: false,
        onSuccess: function (j) {
            IMG_TPLS2 = (j.data && j.data.list) || [];
            sel.innerHTML = '<option value="">选择模板…</option>' + IMG_TPLS2.map(function (t) {
                return '<option value="' + t.id + '">' + esc(t.title) + '</option>';
            }).join('');
            var hint = document.getElementById('pacsTplHint');
            if (hint) hint.textContent = '选择模板后预览，支持覆盖或续写应用';
        },
    });
}

/* 选择模板：弹出预览 + 覆盖/续写选择（与经典模态框「预览→覆盖/续写」一致） */
function pacsTplPick(tplId) {
    if (!tplId) return;
    Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
        loading: false,
        onSuccess: function (j) {
            var t = j.data && j.data.template;
            if (!t || !t.content) { Clinic.toast.warning('模板内容为空'); return; }
            var f = (t.content && t.content.findings) || '';
            var c = (t.content && t.content.conclusion) || '';
            var pv = (f ? '【影像所见】\n' + f : '') + (c ? '\n\n【影像诊断】\n' + c : '');
            window.__pacsPendingTpl = t;
            Clinic.modal.open(
                '<div class="fs-13 fw-700 mb-8">' + esc(t.title) + '</div>' +
                '<div class="textarea" readonly style="height:220px;white-space:pre-wrap;overflow-y:auto;cursor:text;background:var(--bg-soft)">' + esc(pv || '（模板无内容）') + '</div>',
                {
                    title: '应用报告模板',
                    size: 'modal-sm',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        { text: '续写', cls: 'btn-outline', autoClose: false, onClick: function () { pacsTplApply('append'); } },
                        { text: '覆盖', cls: 'btn-primary', autoClose: false, onClick: function () { pacsTplApply('overwrite'); } },
                    ],
                }
            );
            var sel = document.getElementById('pacsTplSel');
            if (sel) sel.value = '';
        },
    });
}

function pacsTplApply(mode) {
    var t = window.__pacsPendingTpl;
    if (!t || !t.content) { Clinic.modal.close(); return; }
    var f = (t.content.findings) || '';
    var c = (t.content.conclusion) || '';
    var fEl = document.getElementById('pacsFindings');
    var cEl = document.getElementById('pacsConclusion');
    if (mode === 'overwrite') {
        if (fEl) fEl.value = f;
        if (cEl) cEl.value = c;
    } else {
        if (fEl) fEl.value = (fEl.value.trim() ? fEl.value.replace(/\s*$/, '') + '\n' : '') + f;
        if (cEl) cEl.value = (cEl.value.trim() ? cEl.value.replace(/\s*$/, '') + '\n' : '') + c;
    }
    window.__pacsPendingTpl = null;
    Clinic.modal.close();
    Clinic.toast.success('模板已' + (mode === 'overwrite' ? '覆盖' : '续写') + '应用');
    imgRightTab('write');
}

/* 常用语快捷插入 */
function pacsQuickInsert(textareaId, el) {
    var ta = document.getElementById(textareaId);
    if (!ta) return;
    ta.value = ta.value.trim()
        ? ta.value.replace(/\s*$/, '') + (el ? el.textContent : '')
        : (el ? el.textContent : '');
    ta.focus();
}

/* ---------- 一体化底部操作栏 ---------- */
function imgCurWritable() {
    var cur = window.__imgCurItem;
    if (!cur) { Clinic.toast.warning('当前无待书写报告的检查项目'); return null; }
    if (cur.status !== 'registered') { Clinic.toast.warning('该项目已出报告，如需修改请先申请撤回'); return null; }
    return cur;
}

/* 保存草稿：仅暂存前端（正式提交才落库；防误关页面丢失） */
var IMG_DRAFT_KEY = 'clinic_img_draft_';
function imgDraftSave() {
    var cur = window.__imgCurItem;
    var f = document.getElementById('pacsFindings');
    var c = document.getElementById('pacsConclusion');
    if (!cur || !f || !c) { Clinic.toast.warning('暂无可保存的报告内容'); return; }
    try {
        localStorage.setItem(IMG_DRAFT_KEY + cur.id, JSON.stringify({ findings: f.value, conclusion: c.value, at: Date.now() }));
        Clinic.toast.success('草稿已暂存（仅本机，提交后失效）');
    } catch (e) { Clinic.toast.warning('本地存储不可用，草稿保存失败'); }
}

/* 提交审核（当前阶段直接生成报告，与经典模式 save_result 同一后端） */
var IMG_PUBLISHING = false;
function imgPublish() {
    var cur = imgCurWritable();
    if (!cur || IMG_PUBLISHING) return;
    var findings = (document.getElementById('pacsFindings') || {}).value || '';
    var conclusion = (document.getElementById('pacsConclusion') || {}).value || '';
    if (!findings.trim()) { Clinic.toast.warning('请填写影像学表现'); return; }
    if (!conclusion.trim()) { Clinic.toast.warning('请填写影像学诊断'); return; }
    IMG_PUBLISHING = true;
    Clinic.ajax('/api/imaging', { action: 'save_result', item_id: cur.id, findings: findings, conclusion: conclusion }, {
        loading: true,
        onSuccess: function (json) {
            IMG_PUBLISHING = false;
            try { localStorage.removeItem(IMG_DRAFT_KEY + cur.id); } catch (e) { /* 忽略 */ }
            finishImgPublish(json);
        },
        onError: function () { IMG_PUBLISHING = false; },
    });
}

/* 退回修改：清空当前撰写区（重新书写），保留登记状态 */
function imgRejectBack() {
    Clinic.modal.confirm('确认清空当前报告撰写内容，重新书写？', function () {
        var f = document.getElementById('pacsFindings');
        var c = document.getElementById('pacsConclusion');
        if (f) f.value = '';
        if (c) c.value = '';
        Clinic.toast.success('已清空，可重新撰写');
    }, { title: '退回修改', okText: '确认清空' });
}

/* 右侧申请单大纲（局部刷新时复用） */
function renderImgSide(data) {
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'imaging'; });
    Clinic.deptwork.renderOrderSide(orders, {
        emoji: '🩻', title: '检查申请单', empty: '暂无检查项目',
        pending: function (o) { return o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; }); },
        subDot: function (it) { return it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done'); },
    });
}

/* 局部刷新单张申请单区块（登记/提交报告后，仅重建该区块 + 右栏 + 候诊数，
   不重建整页、保持滚动位置） */
function refreshImgSec(orderId) {
    Clinic.deptwork.fetchPatient(function (data) {
        // 一体化模式：整工作台重建（三栏数据联动）
        if (imgReadMode() === 'integrated') { renderImgWork(data); Clinic.deptwork.refreshQueue(); return; }
        var order = null;
        (data.orders || []).forEach(function (o) { if (o.order_id === orderId) order = o; });
        if (order) {
            var el = document.getElementById('imgSec_' + orderId);
            if (el) el.outerHTML = imgOrderHtml(order);
        }
        // 更新「去写报告」缓存
        var imgItems = [];
        (data.orders || []).forEach(function (o) { if (o.order_type === 'imaging') imgItems = imgItems.concat(o.items); });
        window.__imgItems = imgItems;
        renderImgSide(data);
        Clinic.deptwork.refreshQueue();
    });
}

/* 抬头：医院名称 + 第二名称 + 影像诊断报告单 + 患者信息两行（统一走 Clinic.deptwork.headHtml） */
function imgHeadHtml(data) {
    return Clinic.deptwork.headHtml(data, '影 像 诊 断 报 告 单');
}

/* ==================== 模式 B：独立视窗阅片（跨窗口 Session 联动） ==================== */
function imgOpenSoloWindow() {
    var visit = Clinic.deptwork.currentVisit();
    var url = '/viewer.php' + (visit ? '?visit=' + encodeURIComponent(visit) : '');
    // 独立无工具栏窗口（多显示器全屏拖拽阅片）：与主窗口共享登录 Session，
    // Session 失效时由 viewer.php 内 authsync（BroadcastChannel）联动锁定
    window.open(url, 'clinic_img_viewer_' + (visit || 'solo'),
        'width=1280,height=800,menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=no');
}

/* ==================== 单张申请单区块（模式 B 主布局） ==================== */
function imgOrderHtml(o) {
    var hasPaid = o.items.some(function (it) { return it.status === 'paid'; });
    var pending = hasPaid || o.items.some(function (it) { return it.status === 'registered'; });
    var badge = pending
        ? '<span class="badge badge-warning" style="font-size:11px">检查中</span>'
        : '<span class="badge badge-success" style="font-size:11px">已完成</span>';
    var regBtn = hasPaid
        ? '<button class="btn btn-primary btn-sm" style="margin-left:12px" onclick="doImgRegisterOrder(\'' + esc(o.order_id) + '\')">📝 登记</button>'
        : '';
    // 独立视窗阅片按钮（患者行级，任务2 模式 B）
    var soloBtn = '<button class="btn btn-outline btn-sm pacs-openwin-btn" style="margin-left:auto" ' +
        'onclick="imgOpenSoloWindow()" title="弹出独立无工具栏阅片窗口（多显示器全屏阅片）">🖥️ 独立视窗阅片</button>';
    var itemsHtml = o.items.map(imgItemHtml).join('');
    return '<div class="card dw-lab-order" id="imgSec_' + esc(o.order_id) + '" style="margin-bottom:14px">' +
        '<div class="dw-lab-order-head">' +
        '  <span class="fw-700">🩻 检查申请单</span>' +
        '  <a href="javascript:void(0)" style="color:var(--primary);cursor:pointer;text-decoration:underline;margin-left:10px" ' +
        'onclick="previewImgOrder(\'' + esc(o.order_id) + '\',\'' + esc(o.order_no) + '\')">' + esc(o.order_no) + '</a>' +
        '  <span class="fs-12 text-muted" style="margin-left:10px">开单医生：' + esc(o.doctor_name || '') + ' ｜ ' + esc((o.created_at || '').substr(0, 16)) + '</span>' +
        badge + regBtn + soloBtn +
        '</div>' + itemsHtml + '</div>';
}

/* 整张申请单统一登记 */
function doImgRegisterOrder(orderId) {
    Clinic.ajax('/api/imaging', { action: 'register_order', order_id: orderId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterImgAction(orderId);
        },
    });
}

function previewImgOrder(orderId, orderNo) {
    if (!orderId) return;
    Clinic.print.preview('/api/print?action=order&order_id=' + orderId, null, '检查申请单预览：' + (orderNo || ''));
}

function imgItemHtml(it) {
    var id = esc(it.id);
    var badge = imgStatusBadge(it.status);
    var inner;
    if (it.status === 'open') {
        // 未缴费项目：不落入「已完成」展示，提示待缴费
        inner = '<div class="fs-13 text-muted">该项目尚未缴费，缴费后进入影像科待登记队列。</div>';
    } else if (it.status === 'paid') {
        inner = '<div class="fs-13 text-muted">该项目已缴费，尚未登记检查（整张申请单统一登记）。</div>';
    } else if (it.status === 'registered') {
        inner =
            '<div class="fs-13 text-muted">该项目已登记，请点击「去写报告」书写影像所见与影像诊断（或切换到一体化阅片模式撰写）。</div>' +
            '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" onclick="openImgReportModal(\'' + id + '\')">✍️ 去写报告</button></div>';
    } else {
        inner =
            '<div class="dw-report-sec-label">影像所见</div>' +
            '<div class="dw-report-sec-text' + (it.findings ? '' : ' empty') + '">' + (it.findings ? nl2br(esc(it.findings)) : '—') + '</div>' +
            '<div class="dw-report-sec-label">影像诊断</div>' +
            '<div class="dw-report-sec-text' + (it.conclusion ? '' : ' empty') + '">' + (it.conclusion ? nl2br(esc(it.conclusion)) : '—') + '</div>' +
            '<div class="dw-report-foot"><span>报告医生：' + esc(it.executed_by || it.doctor_name || '') + '</span>' +
            '<span>报告编号：' + esc(it.report_no || '—') + '</span><span>' + esc((it.executed_at || '').substr(0, 16)) + '</span></div>' +
            '<div class="dw-report-actions">' +
            (it.report_id ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' + esc(it.report_id) + '\',null)">🖨️ 查看报告</button>' : '') +
            (it.report_id ? '<button class="btn btn-outline btn-sm" onclick="imgWithdraw(\'' + esc(it.report_id) + '\')">申请撤回</button>' : '') +
            '</div>';
    }
    return '<div class="dw-report-item">' +
        '<div class="dw-report-item-name">' + esc(it.item_name) + ' <span class="dw-report-item-status">' + badge + '</span></div>' + inner + '</div>';
}

/* ==================== 去写报告：模板 + 影像所见/影像诊断 模态框（模式 B） ==================== */
var CUR_IMG_ITEM = null;
var IMG_TPLS = [];

function openImgReportModal(id) {
    var it = null;
    (window.__imgItems || []).forEach(function (x) { if (x.id === id) it = x; });
    if (!it) return;
    CUR_IMG_ITEM = it;
    var mask = Clinic.modal.open(
        '<div class="flex" style="gap:14px;height:500px">' +
        '  <div style="width:300px;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid var(--border);padding-right:14px;min-height:0">' +
        '    <div class="form-group"><label class="form-label">报告模板</label>' +
        '    <input class="input" id="imgTplSearch" placeholder="🔍 搜索模板" oninput="imgRenderTpls()"></div>' +
        '    <div id="imgTplList" style="flex:1;overflow-y:auto;min-height:0"></div>' +
        '    <div id="imgHistBox" style="max-height:180px;overflow-y:auto;border-top:1px solid var(--border);padding-top:8px;margin-top:8px">' +
        '      <div class="fs-12 text-muted" style="margin-bottom:4px"><b>🕘 历史报告</b></div>' +
        '      <div class="fs-12 text-muted">加载中…</div>' +
        '    </div>' +
        '    <div class="dw-crit-queue" style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">' +
        '      <button type="button" class="btn btn-outline btn-sm" style="width:100%" onclick="openImgCritSend()">🚨 报危急值</button>' +
        '      <div class="dw-crit-queue-title">危急值等待发送（<span id="imgCritCount">0</span>）</div>' +
        '      <div id="imgCritQueue" style="margin-top:6px"></div>' +
        '    </div>' +
        '  </div>' +
        '  <div style="flex:1;min-width:0;display:flex;flex-direction:column">' +
        '    <div class="form-group">' +
        '      <label class="form-label">模板预览</label>' +
        '      <div id="imgTplPreview" class="textarea" readonly style="height:130px;resize:none;white-space:pre-wrap;overflow-y:auto;cursor:text">点击左侧模板查看内容</div>' +
        '    </div>' +
        '    <div class="flex gap-8" style="margin-bottom:10px">' +
        '      <button type="button" class="btn btn-primary btn-sm" style="flex:1" onclick="imgApplyTpl(\'overwrite\')">覆盖</button>' +
        '      <button type="button" class="btn btn-outline btn-sm" style="flex:1" onclick="imgApplyTpl(\'append\')">续写</button>' +
        '      <button type="button" class="btn btn-outline btn-sm" style="flex:1" onclick="Clinic.modal.close()">关闭</button>' +
        '    </div>' +
        '    <div class="form-group" style="flex:1;display:flex;flex-direction:column;min-height:0">' +
        '      <label class="form-label">影像所见 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="imgModalFindings" style="flex:2;min-height:0" placeholder="请填写影像所见描述">' + esc(it.findings) + '</textarea></div>' +
        '    <div class="form-group" style="flex:1;display:flex;flex-direction:column;min-height:0">' +
        '      <label class="form-label">影像诊断 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="imgModalConclusion" style="flex:1;min-height:0" placeholder="请填写影像诊断（检查结论）">' + esc(it.conclusion) + '</textarea></div>' +
        '  </div>' +
        '</div>',
        { title: '✍️ 书写检查报告：' + it.item_name, size: 'modal-lg', buttons: [] }
    );
    IMG_TPLS = [];
    loadImgTpls();
    // 历史报告调阅（模态框）：按 patient_id 检索，复制目标为模态框两输入区
    var data = window.__imgData || {};
    var p = data.patient || {};
    if (p.patient_id) {
        Clinic.pacsHistory.setPatient({ patient_id: p.patient_id });
        Clinic.pacsHistory.mount({
            container: 'imgHistBox',
            patientId: p.patient_id,
            emptyText: '该患者暂无历史影像报告',
            onCopy: function (kind, text) {
                if (!text) { Clinic.toast.warning('该历史报告字段为空，无可复制内容'); return; }
                var el = document.getElementById(kind === 'findings' ? 'imgModalFindings' : 'imgModalConclusion');
                if (!el) return;
                el.value = el.value.trim() ? el.value.replace(/\s*$/, '') + '\n' + text : text;
                Clinic.toast.success('已复制到当前报告' + (kind === 'findings' ? '【影像所见】' : '【影像诊断】'));
            },
        });
    } else {
        document.getElementById('imgHistBox').innerHTML =
            '<div class="fs-12 text-muted" style="margin-bottom:4px"><b>🕘 历史报告</b></div>' +
            '<div class="fs-12 text-muted">缺少患者唯一标识，无法检索历史报告</div>';
    }
    // 危急值预览队列：随报告发布一并发送（未发布即关闭则本次不发送）
    window.__imgCritQueue = [];
    renderImgCritQueue();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" onclick="imgModalSave()">💾 提交并打印报告</button>';
}

/* ==================== 影像科危急值（手动上报，发布时一并发送） ==================== */
function openImgCritSend() {
    if (!window.__imgCurItem && !CUR_IMG_ITEM) return;
    var cur = window.__imgCurItem || CUR_IMG_ITEM;
    // 将已添加的危急值预览回传弹窗：再次点开可看到已填内容，避免误以为丢失
    Clinic.critical.openSend({
        source: 'imaging',
        report_id: '',
        mode: 'imaging',
        doctor_id: cur.doctor_id || 0,
        doctor_name: cur.doctor_name || '',
        existing: window.__imgCritQueue || [],
        onAdd: function (q) {
            (window.__imgCritQueue || []).push(q);
            renderImgCritQueue();
        },
    });
}

function renderImgCritQueue() {
    var box = document.getElementById('imgCritQueue');
    var cnt = document.getElementById('imgCritCount');
    var q = window.__imgCritQueue || [];
    if (cnt) cnt.textContent = q.length;
    if (!box) return;
    box.innerHTML = q.length
        ? q.map(function (x, i) {
            return '<div class="dw-crit-queue-item">' +
                '<span class="crit-q-name">' + esc(x.item) + '</span>' +
                '<span class="fs-12 text-muted">→ ' + esc(x.to_doctor_name) + '</span>' +
                '<button type="button" class="btn btn-outline btn-sm" style="margin-left:auto;padding:1px 8px" onclick="imgCritRemove(' + i + ')">✕</button></div>';
        }).join('')
        : '<div class="fs-12 text-muted">暂无危急值（点击上方按钮手动上报）</div>';
}

function imgCritRemove(i) {
    (window.__imgCritQueue || []).splice(i, 1);
    renderImgCritQueue();
}

function loadImgTpls() {
    Clinic.get('/api/template?action=list&type=imaging_report', null, {
        loading: false,
        onSuccess: function (j) { IMG_TPLS = j.data.list || []; imgRenderTpls(); },
    });
}

function imgRenderTpls() {
    var box = document.getElementById('imgTplList');
    if (!box) return;
    var kw = ((document.getElementById('imgTplSearch') || {}).value || '').trim().toLowerCase();
    var list = IMG_TPLS.filter(function (t) { return !kw || (t.title || '').toLowerCase().indexOf(kw) !== -1; });
    box.innerHTML = list.length ? list.map(function (t) {
        return '<div class="dd-item" style="cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px" onclick="imgPickTpl(' + t.id + ')">' +
            '<div class="fw-600 fs-13">' + esc(t.title) + '</div>' +
            '<div class="fs-12 text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc((t.content && t.content.findings) || '') + '</div></div>';
    }).join('') : '<div class="fs-12 text-muted">暂无影像报告模板（可自由书写）</div>';
}

var IMG_CUR = null;   // 当前选中的影像报告模板

/* 点击模板：选中并在右侧预览（不直接写入），由 覆盖/续写/关闭 按钮应用 */
function imgPickTpl(tplId) {
    Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
        loading: false,
        onSuccess: function (j) {
            var t = j.data && j.data.template;
            IMG_CUR = t || null;
            var pv = document.getElementById('imgTplPreview');
            if (!pv) return;
            var f = (t && t.content && t.content.findings) || '';
            var c = (t && t.content && t.content.conclusion) || '';
            pv.textContent = (f ? '影像所见：\n' + f : '') + (c ? '\n\n影像诊断：\n' + c : '');
        },
    });
}

/* 模板应用：覆盖 = 清空后完全按模板；续写 = 保留原有内容、模板内容插入到后面 */
function imgApplyTpl(mode) {
    var t = IMG_CUR;
    if (!t || !t.content) { Clinic.toast.warning('请先在左侧选择一个模板'); return; }
    var f = (t.content.findings) || '';
    var c = (t.content.conclusion) || '';
    var fEl = document.getElementById('imgModalFindings');
    var cEl = document.getElementById('imgModalConclusion');
    if (mode === 'overwrite') {
        if (fEl) fEl.value = f;
        if (cEl) cEl.value = c;
    } else {
        if (fEl) fEl.value = (fEl.value.trim() ? fEl.value.replace(/\s*$/, '') + '\n' : '') + f;
        if (cEl) cEl.value = (cEl.value.trim() ? cEl.value.replace(/\s*$/, '') + '\n' : '') + c;
    }
}

/* 提交防重入锁（双击确认会重复生成报告） */
var IMG_SUBMITTING = false;

/** 发布完成收尾：关闭弹窗 + 打印报告 + 局部刷新（危急值已发送完毕后调用） */
function finishImgPublish(json) {
    Clinic.modal.close();
    Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
    afterImgAction((window.__imgCurItem || CUR_IMG_ITEM || {}).order_id);
}

function imgModalSave() {
    if (!CUR_IMG_ITEM) return;
    if (IMG_SUBMITTING) return;
    var findings = ((document.getElementById('imgModalFindings') || {}).value || '').trim();
    var conclusion = ((document.getElementById('imgModalConclusion') || {}).value || '').trim();
    if (!findings) { Clinic.toast.warning('请填写影像所见'); return; }
    if (!conclusion) { Clinic.toast.warning('请填写影像诊断'); return; }
    var it = CUR_IMG_ITEM;
    IMG_SUBMITTING = true;
    Clinic.ajax('/api/imaging', { action: 'save_result', item_id: it.id, findings: findings, conclusion: conclusion }, {
        loading: true,
        onSuccess: function (json) {
            IMG_SUBMITTING = false;
            // 报告已发布：若存在危急值预览队列，逐条发送（手动上报的危急值随发布一并发出）
            var queue = window.__imgCritQueue || [];
            window.__imgCritQueue = [];
            if (!queue.length) { finishImgPublish(json); return; }
            var remaining = queue.length;
            queue.forEach(function (q) {
                Clinic.critical.send({
                    source: 'imaging',
                    report_id: json.data.report_id,
                    to_doctor_id: q.to_doctor_id,
                    item: q.item,
                }, {
                    onSuccess: function () {
                        remaining--;
                        if (remaining <= 0) {
                            Clinic.toast.success('报告已生成，危急值已发送并通知医生');
                            finishImgPublish(json);
                        }
                    },
                    onError: function () {
                        remaining--;
                        if (remaining <= 0) {
                            Clinic.toast.warning('报告已生成，部分危急值发送失败，请到危急值管理核实');
                            finishImgPublish(json);
                        }
                    },
                });
            });
        },
        onError: function () { IMG_SUBMITTING = false; },
    });
}

function imgWithdraw(reportId) {
    Clinic.modal.prompt({
        title: '申请撤回报告',
        label: '请填写撤回原因',
        placeholder: '如：影像描述有误，需重新检查',
        required: true,
        onOk: function (reason) {
            Clinic.modal.confirm('确认申请撤回该报告？需管理员审核通过后生效。', function () {
                Clinic.ajax('/api/imaging', { action: 'withdraw', report_id: reportId, reason: reason }, {
                    onSuccess: function (json) {
                        Clinic.toast.success(json.msg);
                        afterImgAction();
                    },
                });
            });
        },
    });
}

/* ==================== 启动：注入模式切换器 + 初始化工作台 ==================== */
(function injectModeSwitch() {
    var actions = document.querySelector('.emr-top-actions');
    if (!actions) return;
    var sw = document.createElement('div');
    sw.className = 'pacs-mode-switch';
    sw.innerHTML =
        '<button type="button" class="pms-btn" data-mode="classic" onclick="imgSwitchMode(\'classic\')">🖥️ 经典双屏分屏</button>' +
        '<button type="button" class="pms-btn" data-mode="integrated" onclick="imgSwitchMode(\'integrated\')">🩻 一体化阅片</button>';
    actions.insertBefore(sw, actions.firstChild);
    // 独立视窗按钮（顶部工具栏级入口）
    var solo = document.createElement('button');
    solo.type = 'button';
    solo.className = 'btn btn-outline btn-sm pacs-openwin-btn';
    solo.title = '弹出独立无工具栏阅片窗口（多显示器全屏阅片）';
    solo.innerHTML = '🖥️ 独立视窗阅片';
    solo.addEventListener('click', imgOpenSoloWindow);
    actions.insertBefore(solo, sw.nextSibling);
    var mode = imgReadMode();
    sw.querySelectorAll('.pms-btn').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-mode') === mode);
    });
    if (mode === 'integrated') {
        var ws = document.querySelector('.emr-workspace-layout');
        if (ws) ws.classList.add('pacs-integrated');
    }
})();

Clinic.deptwork.init();
</script>
