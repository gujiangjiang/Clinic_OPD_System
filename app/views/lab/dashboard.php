<?php
/**
 * ============================================================
 * lab/dashboard.php — 检验科工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：检验中 / 完成 / 当日；点击患者弹出检验报告单页：
 *   抬头（医院名称+第二名称+检验报告单+患者信息两行）→ 按申请单号
 *   组合的检验项目区块（整张申请单统一登记）。
 *   结果录入：输入框位于项目右侧，失焦自动临时保存（results draft，
 *   刷新不丢失），提交后才生成正式报告。
 * 数据接口：/api/deptwork（queue/patient）+ /api/lab（register_order/
 * save_draft、save_result、withdraw）。
 * ============================================================ */
require APP_ROOT . '/app/includes/dept_workbench.php';
dept_workbench(array(
    'role' => 'lab',
    'title' => '检验科工作台',
    'desc' => '检验登记、结果录入与报告管理（检验项目请到「检验管理」维护）',
    'emoji' => '🧪',
));
?>
<script>
/* ==================== 检验科工作台：患者工作台渲染 ==================== */
Clinic.deptwork.configure({
    role: 'lab',
    render: renderLabWork,
    afterAction: afterLabAction,
});

function afterLabAction() {
    Clinic.deptwork.reloadPatient();
    Clinic.deptwork.refreshQueue();
}

function esc(s) { return Clinic.escHtml(s); }
function itemStatusName(s) {
    var map = { paid: '待登记', registered: '检验中', done: '已完成', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}
function labStatusBadge(s) {
    var cls = s === 'done' ? 'badge-success' : (s === 'registered' ? 'badge-warning' : 'badge-gray');
    return Clinic.deptwork.statusBadge(itemStatusName(s), cls);
}

/* 解析已保存的化验值（单值 {"value":"5.2"}；组合 {"group":1,"values":{memberId:"5.2"}}） */
function parseLabValues(it) {
    try {
        var o = JSON.parse(it.values_json || 'null');
        if (o && o.group) return { group: 1, values: o.values || {} };
        if (o && typeof o.value !== 'undefined') return { group: 0, value: o.value };
    } catch (e) {}
    return { group: 0, value: '' };
}

function renderLabWork(data) {
    var v = data.visit || {}, p = data.patient || {};
    var orders = (data.orders || []).filter(function (o) { return o.order_type === 'lab'; });
    // 缓存当前项目列表（失焦临时保存用）
    var items = [];
    orders.forEach(function (o) { items = items.concat(o.items); });
    window.__labItems = items;

    // 右栏大纲：按申请单分组（申请单号可点「+」展开该单全部检验项目）
    Clinic.deptwork.renderOrderSide(orders, {
        emoji: '🧪', title: '检验申请单', empty: '暂无检验项目',
        pending: function (o) { return o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; }); },
        subDot: function (it) { return it.status === 'done' ? 'ok' : (it.status === 'registered' ? 'pending' : 'done'); },
    });

    // 主区：抬头（参照护理记录单样式）+ 各申请单区块
    var head = labHeadHtml(data);
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">🧪</div>本次就诊暂无检验项目</div></div>';
    } else {
        orders.forEach(function (o) { body += labOrderHtml(o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
    // 绑定「提交并打印报告」操作
    items.forEach(function (it) {
        if (it.status === 'registered') {
            var s = document.getElementById('labSave_' + it.id);
            if (s) s.onclick = function () { doLabSave(it); };
        }
    });
    // 输入框失焦自动临时保存（focusout 冒泡，事件委托一次绑定）
    var main = document.getElementById('dwMain');
    main.onfocusout = function (e) {
        var el = e.target;
        if (el && el.getAttribute && el.getAttribute('data-draft')) labDraft(el);
    };
}

/* 抬头：医院名称 + 第二名称 + 检验报告单 + 患者信息两行（统一走 Clinic.deptwork.headHtml） */
function labHeadHtml(data) {
    return Clinic.deptwork.headHtml(data, '检 验 报 告 单');
}

/* 单张申请单区块：申请单号（可点击预览检验申请单）+ 统一登记按钮 + 各检验项目 */
function labOrderHtml(o) {
    var hasPaid = o.items.some(function (it) { return it.status === 'paid'; });
    var pending = hasPaid || o.items.some(function (it) { return it.status === 'registered'; });
    var badge = pending
        ? '<span class="badge badge-warning" style="font-size:11px">检验中</span>'
        : '<span class="badge badge-success" style="font-size:11px">已完成</span>';
    var regBtn = hasPaid
        ? '<button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="doLabRegisterOrder(\'' + esc(o.order_id) + '\')">📝 登记</button>'
        : '';
    var itemsHtml = o.items.map(labItemHtml).join('');
    return '<div class="card dw-lab-order" id="labSec_' + esc(o.order_id) + '" style="margin-bottom:14px">' +
        '<div class="dw-lab-order-head">' +
        '  <span class="fw-700">🧪 检验申请单</span>' +
        '  <a href="javascript:void(0)" style="color:var(--primary);cursor:pointer;text-decoration:underline;margin-left:10px" ' +
        'onclick="previewLabOrder(\'' + esc(o.order_id) + '\',\'' + esc(o.order_no) + '\')">' + esc(o.order_no) + '</a>' +
        '  <span class="fs-12 text-muted" style="margin-left:10px">开单医生：' + esc(o.doctor_name || '') + ' ｜ ' + esc((o.created_at || '').substr(0, 16)) + '</span>' +
        badge +
        regBtn +
        '</div>' + itemsHtml + '</div>';
}

/* 整张申请单统一登记 */
function doLabRegisterOrder(orderId) {
    Clinic.ajax('/api/lab', { action: 'register_order', order_id: orderId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterLabAction();
        },
    });
}

function previewLabOrder(orderId, orderNo) {
    if (!orderId) return;
    Clinic.print.preview('/api/print?action=order&order_id=' + orderId, null, '检验申请单预览：' + (orderNo || ''));
}

/* 危急值提示（低/高任一配置即显示） */
function critHint(cf, member) {
    var m = member || cf;
    if ((m.critical_low || '') === '' && (m.critical_high || '') === '') return '';
    return '<div class="dw-lab-crit">⚠️ 危急值：低 ' + esc(m.critical_low || '—') + ' / 高 ' + esc(m.critical_high || '—') + '，超出时请立即复核并通知医生</div>';
}

function labItemHtml(it) {
    var id = esc(it.id);
    var badge = labStatusBadge(it.status);
    var inner;
    if (it.status === 'paid') {
        inner = '<div class="fs-13 text-muted">该项目已缴费，尚未登记（整张申请单统一登记）。</div>';
    } else if (it.status === 'registered') {
        var val = parseLabValues(it);
        var rows = '';
        if (it.is_group) {
            if (!it.members || !it.members.length) {
                inner = '<div class="fs-13" style="color:var(--danger)">该组合项目未配置组内成员，无法录入结果，请联系管理员在【检验管理】中完善检验组合。</div>';
            } else {
                rows = '<div class="fs-12 text-muted mb-4">该检验为组合项目，请逐一填写组内各项检验结果：</div>';
                (it.members || []).forEach(function (m) {
                    var mv = val.group ? (val.values[String(m.id)] || '') : '';
                    rows += '<div class="dw-lab-item-row">' +
                        '<div class="dw-lab-item-info">' +
                        '  <div class="dw-lab-item-name">' + esc(m.name) + ' <span class="fs-12 text-muted fw-400">（单位：' + esc(m.unit || '—') + '）</span></div>' +
                        '  <div class="dw-lab-item-hint">正常范围：' + esc(m.normal_range || '—') + '</div>' + critHint(it, m) +
                        '</div>' +
                        '<input type="text" class="input dw-lab-input" data-draft="' + id + '" data-mid="' + esc(m.id) + '" ' +
                        'id="labVal_' + id + '_' + esc(m.id) + '" value="' + esc(mv) + '" placeholder="请输入检验结果"></div>';
                });
                inner = rows;
            }
        } else {
            var sv = val.group ? '' : (val.value || '');
            inner = '<div class="dw-lab-item-row">' +
                '<div class="dw-lab-item-info">' +
                '  <div class="dw-lab-item-name">化验数值 <span class="fs-12 text-muted fw-400">（单位：' + esc(it.unit || '—') + '）</span></div>' +
                '  <div class="dw-lab-item-hint">正常范围：' + esc(it.normal_range || '—') + '</div>' + critHint(it, null) +
                '</div>' +
                '<input type="text" class="input dw-lab-input" data-draft="' + id + '" ' +
                'id="labVal_' + id + '" value="' + esc(sv) + '" placeholder="请输入检验结果"></div>';
        }
        inner = inner +
            '<div class="fs-12 text-muted mt-4">输入后失焦自动临时保存（刷新不丢失），提交后生成正式报告。</div>' +
            '<div class="dw-report-actions"><button class="btn btn-success btn-sm" id="labSave_' + id + '">💾 提交并打印报告</button></div>';
    } else {
        var val = parseLabValues(it);
        var shown = '';
        if (val.group) {
            (it.members || []).forEach(function (m) {
                var mv = val.values[String(m.id)] || '';
                shown += '<div class="dw-lab-value">' + esc(m.name) + '：<b>' + esc(mv || '—') + '</b> ' + esc(m.unit || '') + '</div>';
            });
        } else {
            shown = '<div class="dw-lab-value">化验结果：<b>' + esc(val.value || '—') + '</b> ' + esc(it.unit || '') + '</div>';
        }
        inner = shown +
            '<div class="dw-report-foot"><span>检验技师：' + esc(it.executed_by || it.doctor_name || '') + '</span>' +
            '<span>报告编号：' + esc(it.report_no || '—') + '</span><span>' + esc((it.executed_at || '').substr(0, 16)) + '</span></div>' +
            '<div class="dw-report-actions">' +
            (it.report_id ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' + esc(it.report_id) + '\',null)">🖨️ 查看报告</button>' : '') +
            (it.report_id ? '<button class="btn btn-outline btn-sm" onclick="labWithdraw(\'' + esc(it.report_id) + '\')">申请撤回</button>' : '') +
            '</div>';
    }
    return '<div class="dw-report-item">' +
        '<div class="dw-report-item-name">' + esc(it.item_name) + ' <span class="dw-report-item-status">' + badge + '</span></div>' + inner + '</div>';
}

/* 输入框失焦自动临时保存（草稿） */
function labDraft(el) {
    var id = el.getAttribute('data-draft');
    var it = null;
    (window.__labItems || []).forEach(function (x) { if (x.id === id) it = x; });
    if (!it) return;
    var value, isGroup;
    if (it.is_group) {
        var vals = {};
        (it.members || []).forEach(function (m) {
            var e = document.getElementById('labVal_' + it.id + '_' + m.id);
            vals[m.id] = e ? e.value.trim() : '';
        });
        value = JSON.stringify(vals);
        isGroup = 1;
    } else {
        value = el.value.trim();
        isGroup = 0;
    }
    Clinic.ajax('/api/lab', { action: 'save_draft', item_id: id, value: value, is_group: isGroup }, {
        loading: false,
        onSuccess: function () { /* 静默成功，无需提示 */ },
    });
}

function doLabSave(it) {
    var value, isGroup;
    if (it.is_group) {
        if (!it.members || !it.members.length) {
            Clinic.toast.warning('该组合项目未配置组内成员，请联系管理员完善检验组合');
            return;
        }
        var vals = {};
        (it.members || []).forEach(function (m) {
            var el = document.getElementById('labVal_' + it.id + '_' + m.id);
            vals[m.id] = el ? el.value.trim() : '';
        });
        var missing = (it.members || []).filter(function (m) { return vals[m.id] === ''; })
            .map(function (m) { return m.name; });
        if (missing.length) { Clinic.toast.warning('请填写检验结果：' + missing.join('、')); return; }
        value = JSON.stringify(vals);
        isGroup = 1;
    } else {
        value = (document.getElementById('labVal_' + it.id) || {}).value || '';
        value = value.trim();
        if (!value) { Clinic.toast.warning('请输入检验结果'); return; }
        isGroup = 0;
    }
    // 提交确认弹窗：可选填写检验备注（显示在报告单页脚）
    Clinic.modal.open(
        '<div class="fs-13 fw-700 mb-8">确认提交该检验结果并生成报告？</div>' +
        '<div class="form-group"><label class="form-label">检验备注（可选）</label>' +
        '<textarea class="textarea" id="labNote" rows="2" placeholder="如：标本轻度溶血，结果仅供参考"></textarea></div>' +
        '<div class="fs-12 text-muted">备注将显示在报告单页脚；提交后生成正式报告并打印。</div>',
        {
            title: '提交检验结果',
            size: 'modal-md',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '💾 确认提交', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var note = (document.getElementById('labNote') || {}).value || '';
                        submitLabResult(it, value, isGroup, note.trim());
                    },
                },
            ],
        }
    );
}

/* 提交防重入锁（双击确认会重复生成报告） */
var LAB_SUBMITTING = false;
function submitLabResult(it, value, isGroup, note) {
    if (LAB_SUBMITTING) return;
    LAB_SUBMITTING = true;
    Clinic.ajax('/api/lab', {
        action: 'save_result', item_id: it.id, value: value, is_group: isGroup, note: note,
    }, {
        loading: true,
        onSuccess: function (json) {
            LAB_SUBMITTING = false;
            Clinic.toast.success(json.msg);
            Clinic.modal.close();
            Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
            afterLabAction();
        },
        onError: function () { LAB_SUBMITTING = false; },
    });
}

function labWithdraw(reportId) {
    Clinic.modal.prompt({
        title: '申请撤回报告',
        label: '请填写撤回原因',
        placeholder: '如：检验结果有误，需重新检验',
        required: true,
        onOk: function (reason) {
            Clinic.modal.confirm('确认申请撤回该报告？需管理员审核通过后生效。', function () {
                Clinic.ajax('/api/lab', { action: 'withdraw', report_id: reportId, reason: reason }, {
                    onSuccess: function (json) {
                        Clinic.toast.success(json.msg);
                        afterLabAction();
                    },
                });
            });
        },
    });
}

Clinic.deptwork.init();
</script>
