<?php
/**
 * ============================================================
 * imaging/dashboard.php — 影像科工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：检查中 / 完成 / 当日；点击患者弹出所见即所得
 *   影像诊断报告单页，可直接书写「影像所见 / 影像诊断」并提交
 *   生成报告（提交后自动打印），已完成项目可查看报告/申请撤回。
 * 数据接口：/api/deptwork（queue/patient）+ /api/imaging（register/
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
    var items = [];
    (data.orders || []).forEach(function (o) {
        if (o.order_type !== 'imaging') return;
        o.items.forEach(function (it) { items.push(it); });
    });
    // 右栏大纲：检查项目导航（点击滚动定位对应报告段）
    var sideItems = items.map(function (it) {
        var dot = it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done');
        return '<div class="dw-side-item" onclick="scrollToImg(\'' + esc(it.id) + '\')"><span class="dot ' + dot + '"></span>' + esc(it.item_name) + '</div>';
    }).join('');
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">🩻 本次检查（' + items.length + ' 项）</div>' +
        (sideItems || '<div class="dw-side-item">暂无检查项目</div>') + '</div>';

    // 主区：所见即所得报告单
    var hosp = document.body.getAttribute('data-hosp') || '';
    var head = '<div class="card dw-report-card"><div class="dw-report-head">' +
        '<div class="dw-report-hosp">' + esc(hosp) + '</div>' +
        '<div class="dw-report-title">影 像 诊 断 报 告 单</div></div>' +
        '<div class="dw-report-meta">' +
        '<span>姓名：<b>' + esc(v.name) + '</b></span>' +
        '<span>性别：' + esc(v.gender) + '</span>' +
        '<span>年龄：' + esc(v.age_fmt || '') + '</span>' +
        '<span>患者ID：' + esc(p.patient_id) + '</span>' +
        '<span>流水号：' + esc(v.visit_no) + '</span>' +
        '<span>就诊科室：' + esc(v.dept_name || v.first_dept_name) + '</span></div>';
    var body = '';
    if (!items.length) {
        body = '<div class="dw-report-item"><div class="empty" style="padding:30px 0"><div class="empty-ico">🩻</div>本次就诊暂无检查项目</div></div>';
    } else {
        items.forEach(function (it) { body += imgItemHtml(it); });
    }
    document.getElementById('dwMain').innerHTML = head + body + '</div>';
    // 绑定操作
    items.forEach(function (it) {
        if (it.status === 'paid') {
            var b = document.getElementById('imgReg_' + it.id);
            if (b) b.onclick = function () { doImgRegister(it); };
        } else if (it.status === 'registered') {
            var s = document.getElementById('imgSave_' + it.id);
            if (s) s.onclick = function () { doImgSave(it); };
        }
    });
}

function scrollToImg(id) {
    var el = document.getElementById('imgSec_' + id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function imgItemHtml(it) {
    var id = esc(it.id);
    var badge = imgStatusBadge(it.status);
    var inner;
    if (it.status === 'paid') {
        inner = '<div class="fs-13 text-muted">该项目已缴费，尚未登记检查。</div>' +
            '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" id="imgReg_' + id + '">📝 登记</button></div>';
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
    return '<div class="dw-report-item" id="imgSec_' + id + '">' +
        '<div class="dw-report-item-name">' + esc(it.item_name) + ' <span class="dw-report-item-status">' + badge + '</span></div>' + inner + '</div>';
}

function doImgRegister(it) {
    Clinic.ajax('/api/imaging', { action: 'register', item_id: it.id }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterImgAction();
        },
    });
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
