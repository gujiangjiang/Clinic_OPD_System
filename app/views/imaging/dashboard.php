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

function afterImgAction() {
    Clinic.deptwork.reloadPatient();
    Clinic.deptwork.refreshQueue();
}

function esc(s) { return Clinic.escHtml(s); }
function nl2br(s) { return (s || '').replace(/\n/g, '<br>'); }
function itemStatusName(s) {
    var map = { paid: '待登记', registered: '待出报告', done: '已完成', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
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
    Clinic.deptwork.renderOrderSide(orders, {
        emoji: '🩻', title: '检查申请单', empty: '暂无检查项目',
        pending: function (o) { return o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; }); },
        subDot: function (it) { return it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done'); },
        scrollTo: 'Img',
    });

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
            afterImgAction();
        },
    });
}

function previewImgOrder(orderId, orderNo) {
    if (!orderId) return;
    Clinic.print.preview('/api/print?action=order&order_id=' + orderId, null, '检查申请单预览：' + (orderNo || ''));
}

function scrollToImg(orderId) {
    var el = document.getElementById('imgSec_' + orderId);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function imgItemHtml(it) {
    var id = esc(it.id);
    var badge = imgStatusBadge(it.status);
    var inner;
    if (it.status === 'paid') {
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
        '  </div>' +
        '  <div style="flex:1;min-width:0;display:flex;flex-direction:column">' +
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
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" onclick="imgModalSave()">💾 提交并打印报告</button>';
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
        return '<div class="dd-item" style="cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px" onclick="imgApplyTpl(' + t.id + ')">' +
            '<div class="fw-600 fs-13">' + esc(t.title) + '</div>' +
            '<div class="fs-12 text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc((t.content && t.content.findings) || '') + '</div></div>';
    }).join('') : '<div class="fs-12 text-muted">暂无影像报告模板（可自由书写）</div>';
}

/* 点击模板：弹出小悬浮窗询问「替换 / 追加」 */
function imgApplyTpl(tplId) {
    Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
        loading: false,
        onSuccess: function (j) {
            var t = j.data && j.data.template;
            if (!t) return;
            Clinic.modal.open(
                '<div class="fs-13 fw-700 mb-8">模板「' + esc(t.title) + '」应用方式：</div>' +
                '<div class="flex gap-8">' +
                '  <button class="btn btn-primary btn-sm" style="flex:1" onclick="imgDoApplyTpl(' + t.id + ',1)">替换</button>' +
                '  <button class="btn btn-outline btn-sm" style="flex:1" onclick="imgDoApplyTpl(' + t.id + ',0)">追加</button>' +
                '</div>' +
                '<div class="fs-12 text-muted mt-4">替换：直接替换影像所见全部内容；追加：另起一行将内容顺延下去，保留当前影像所见内容。</div>',
                { title: '应用报告模板', size: 'modal-sm', buttons: [{ text: '关闭', cls: 'btn-outline' }] }
            );
        },
    });
}

function imgDoApplyTpl(tplId, replace) {
    Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
        loading: false,
        onSuccess: function (j) {
            var t = j.data && j.data.template;
            if (!t) return;
            var f = (t.content && t.content.findings) || '';
            var c = (t.content && t.content.conclusion) || '';
            var fEl = document.getElementById('imgModalFindings');
            var cEl = document.getElementById('imgModalConclusion');
            if (fEl) fEl.value = replace ? f : (fEl.value.trim() ? fEl.value.replace(/\s*$/, '') + '\n' : '') + f;
            if (cEl) cEl.value = replace ? c : (cEl.value.trim() ? cEl.value.replace(/\s*$/, '') + '\n' : '') + c;
            Clinic.modal.close();
        },
    });
}

/* 提交防重入锁（双击确认会重复生成报告） */
var IMG_SUBMITTING = false;
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
            Clinic.toast.success(json.msg);
            Clinic.modal.close();
            Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
            afterImgAction();
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
