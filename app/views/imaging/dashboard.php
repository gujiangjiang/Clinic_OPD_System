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
    return '<span class="badge ' + cls + '" style="font-size:11px">' + itemStatusName(s) + '</span>';
}

function renderImgWork(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'imaging'; });
    // 右栏大纲：按申请单分组（申请单号可点「+」展开该单全部检查项目）
    var sideItems = orders.map(function (o) {
        var pending = o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; });
        var subs = o.items.map(function (it) {
            var dot = it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done');
            return '<div class="dw-side-item dw-side-subitem"><span class="dot ' + dot + '"></span>' + esc(it.item_name) + '</div>';
        }).join('');
        return '<div class="dw-side-order">' +
            '<div class="dw-side-item" onclick="scrollToImg(\'' + esc(o.order_id) + '\')">' +
            '<span class="dw-side-plus" id="sidePlus_' + esc(o.order_id) + '" title="展开该单检查项目" ' +
            'onclick="event.stopPropagation();Clinic.deptwork.toggleSideOrder(\'' + esc(o.order_id) + '\')">+</span>' +
            '<span class="dot ' + (pending ? 'pending' : 'ok') + '"></span>' +
            '<span class="dw-side-oname">' + esc(o.order_no) + '（' + o.items.length + ' 项）</span>' +
            '</div>' +
            '<div class="dw-side-sub" id="sideSub_' + esc(o.order_id) + '" style="display:none">' + subs + '</div>' +
            '</div>';
    }).join('');
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">🩻 检查申请单（' + orders.length + ' 张）</div>' +
        (sideItems || '<div class="dw-side-item">暂无检查项目</div>') + '</div>';

    // 主区：抬头（参照护理/急诊病历版式）+ 各申请单区块
    var head = imgHeadHtml(data);
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">🩻</div>本次就诊暂无检查项目</div></div>';
    } else {
        orders.forEach(function (o) { body += imgOrderHtml(o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
    // 绑定「提交并打印报告」操作
    orders.forEach(function (o) {
        o.items.forEach(function (it) {
            if (it.status === 'registered') {
                var s = document.getElementById('imgSave_' + it.id);
                if (s) s.onclick = function () { doImgSave(it); };
            }
        });
    });
}

/* 抬头：医院名称 + 第二名称 + 影像诊断报告单 + 患者信息两行（急诊病历版式） */
function imgHeadHtml(data) {
    var v = data.visit || {}, p = data.patient || {};
    var hosp = document.body.getAttribute('data-hosp') || '';
    var hosp2 = document.body.getAttribute('data-hosp2') || '';
    var cell = function (label, value) {
        return '<div class="dw-line-cell"><span class="lbl">' + label + '：</span><span class="val">' + (value || '—') + '</span></div>';
    };
    return '<div class="card dw-nurse-doc">' +
        '<div class="dw-hosp-block">' +
        '  <div class="dw-hosp">' + esc(hosp) + '</div>' +
        (hosp2 ? '  <div class="dw-sub">' + esc(hosp2) + '</div>' : '') +
        '</div>' +
        '<div class="dw-title-bar"><div class="dw-title">影 像 诊 断 报 告 单</div></div>' +
        '<div class="dw-pat-lines">' +
        '  <div class="dw-line-row">' +
        cell('姓名', esc(v.name)) + cell('性别', esc(v.gender)) + cell('年龄', esc(v.age_fmt || '')) + cell('出生日期', esc(p.birth_date || '')) +
        '  </div>' +
        '  <div class="dw-line-row">' +
        cell('患者ID', esc(p.patient_id)) + cell('流水号', esc(v.visit_no)) + cell('首诊科室', esc(v.first_dept_name || '')) + cell('首诊时间', esc((v.created_at || '').substr(0, 16))) +
        '  </div>' +
        '</div></div>';
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
            '<div class="dw-report-sec-label">影像所见</div>' +
            '<textarea class="textarea" id="imgFindings_' + id + '" rows="4" placeholder="请填写影像所见描述">' + esc(it.findings) + '</textarea>' +
            '<div class="dw-report-sec-label">影像诊断</div>' +
            '<textarea class="textarea" id="imgConclusion_' + id + '" rows="3" placeholder="请填写影像诊断（检查结论）">' + esc(it.conclusion) + '</textarea>' +
            '<div class="fs-12 text-muted mt-4">提交后自动生成报告并打印，需完善影像所见与影像诊断。</div>' +
            '<div class="dw-report-actions"><button class="btn btn-success btn-sm" id="imgSave_' + id + '">💾 提交并打印报告</button></div>';
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

function doImgSave(it) {
    var findings = document.getElementById('imgFindings_' + it.id).value.trim();
    var conclusion = document.getElementById('imgConclusion_' + it.id).value.trim();
    if (!findings) { Clinic.toast.warning('请填写影像所见'); return; }
    if (!conclusion) { Clinic.toast.warning('请填写影像诊断'); return; }
    Clinic.ajax('/api/imaging', { action: 'save_result', item_id: it.id, findings: findings, conclusion: conclusion }, {
        loading: true,
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
            afterImgAction();
        },
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
