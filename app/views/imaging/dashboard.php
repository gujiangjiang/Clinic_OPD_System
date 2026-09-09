<?php
/**
 * ============================================================
 * imaging/dashboard.php — 影像科工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：检查中 / 完成 / 当日；点击患者弹出影像诊断报告单页：
 *   抬头（医院名称+第二名称+影像诊断报告单+患者信息两行，参照急诊病历版式）
 *   → 按申请单号组合的检查项目区块（整张申请单统一登记）。
 *   报告书写：注册后即可在申请单内书写影像所见 / 影像诊断并提交生成报告。
 * 数据接口：/api/deptwork（queue/patient）+ /api/imaging（register_order/
 * save_result/withdraw）。
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
/* ==================== 影像科工作台：患者工作台渲染 ==================== */
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

function renderImgWork(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'imaging'; });
    var imgItems = [];
    orders.forEach(function (o) { imgItems = imgItems.concat(o.items); });
    window.__imgItems = imgItems;
    // 右栏大纲：按申请单分组（申请单号可点「+」展开该单全部检查项目）
    renderImgSide(data);

    // 主区：抬头（参照护理/急诊病历版式）+ 各申请单区块
    var head = imgHeadHtml(data);
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">🩻</div>本次就诊暂无检查项目</div></div>';
    } else {
        orders.forEach(function (o) { body += imgOrderHtml(o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
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

/* 单张申请单区块：申请单号（可点击预览检查申请单）+ 统一登记按钮 + 检查项目 */
function imgOrderHtml(o) {
    var hasPaid = o.items.some(function (it) { return it.status === 'paid'; });
    var pending = hasPaid || o.items.some(function (it) { return it.status === 'registered'; });
    var badge = pending
        ? '<span class="badge badge-warning" style="font-size:11px">检查中</span>'
        : '<span class="badge badge-success" style="font-size:11px">已完成</span>';
    var regBtn = hasPaid
        ? '<button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="doImgRegisterOrder(\'' + esc(o.order_id) + '\')">📝 登记</button>'
        : '';
    var itemsHtml = o.items.map(imgItemHtml).join('');
    return '<div class="card dw-lab-order" id="imgSec_' + esc(o.order_id) + '" style="margin-bottom:14px">' +
        '<div class="dw-lab-order-head">' +
        '  <span class="fw-700">🩻 检查申请单</span>' +
        '  <a href="javascript:void(0)" style="color:var(--primary);cursor:pointer;text-decoration:underline;margin-left:10px" ' +
        'onclick="previewImgOrder(\'' + esc(o.order_id) + '\',\'' + esc(o.order_no) + '\')">' + esc(o.order_no) + '</a>' +
        '  <span class="fs-12 text-muted" style="margin-left:10px">开单医生：' + esc(o.doctor_name || '') + ' ｜ ' + esc((o.created_at || '').substr(0, 16)) + '</span>' +
        badge +
        regBtn +
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
            '<div class="fs-13 text-muted">该项目已登记，请点击「去写报告」书写影像所见与影像诊断。</div>' +
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

/* ==================== 去写报告：模板 + 影像所见/影像诊断 模态框 ==================== */
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
    // 危急值预览队列：随报告发布一并发送（未发布即关闭则本次不发送）
    window.__imgCritQueue = [];
    renderImgCritQueue();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" onclick="imgModalSave()">💾 提交并打印报告</button>';
}

/* ==================== 影像科危急值（手动上报，发布时一并发送） ==================== */
function openImgCritSend() {
    if (!CUR_IMG_ITEM) return;
    // 将已添加的危急值预览回传弹窗：再次点开可看到已填内容，避免误以为丢失
    Clinic.critical.openSend({
        source: 'imaging',
        report_id: '',
        mode: 'imaging',
        doctor_id: CUR_IMG_ITEM.doctor_id || 0,
        doctor_name: CUR_IMG_ITEM.doctor_name || '',
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
    afterImgAction(CUR_IMG_ITEM.order_id);
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

Clinic.deptwork.init();
</script>
