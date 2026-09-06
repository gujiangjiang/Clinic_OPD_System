<?php
/**
 * ============================================================
 * lab/dashboard.php — 检验科工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：检验中 / 完成 / 当日；点击患者弹出检验结果
 *   录入列表（含计量单位/正常范围/危急值提示，组合检验逐项录入），
 *   提交自动生成报告并打印。
 * 数据接口：/api/deptwork（queue/patient）+ /api/lab（register/
 * save_result/withdraw）。
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
    return '<span class="badge ' + cls + '" style="font-size:11px">' + itemStatusName(s) + '</span>';
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
    // 右栏大纲：按申请单分组（点击滚动定位对应申请单区块）
    var sideItems = orders.map(function (o) {
        var pending = o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; });
        return '<div class="dw-side-item" onclick="scrollToLab(\'' + esc(o.order_id) + '\')"><span class="dot ' + (pending ? 'pending' : 'ok') + '"></span>' +
            esc(o.order_no) + '（' + o.items.length + ' 项）</div>';
    }).join('');
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">🧪 检验申请单（' + orders.length + ' 张）</div>' +
        (sideItems || '<div class="dw-side-item">暂无检验项目</div>') + '</div>';

    // 主区：抬头（参照护理记录单样式）+ 各申请单区块
    var head = labHeadHtml(data);
    var body = '';
    if (!orders.length) {
        body = '<div class="card"><div class="empty" style="padding:40px 0"><div class="empty-ico">🧪</div>本次就诊暂无检验项目</div></div>';
    } else {
        orders.forEach(function (o) { body += labOrderHtml(o); });
    }
    document.getElementById('dwMain').innerHTML = head + body;
    // 绑定操作
    orders.forEach(function (o) {
        o.items.forEach(function (it) {
            if (it.status === 'paid') {
                var b = document.getElementById('labReg_' + it.id);
                if (b) b.onclick = function () { doLabRegister(it); };
            } else if (it.status === 'registered') {
                var s = document.getElementById('labSave_' + it.id);
                if (s) s.onclick = function () { doLabSave(it); };
            }
        });
    });
}

/* 抬头：医院名称 + 第二名称 + 检验报告单 + 患者信息两行（与护理记录单同版式） */
function labHeadHtml(data) {
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
        '<div class="dw-title-bar"><div class="dw-title">检 验 报 告 单</div></div>' +
        '<div class="dw-pat-lines">' +
        '  <div class="dw-line-row">' +
        cell('姓名', esc(v.name)) + cell('性别', esc(v.gender)) + cell('年龄', esc(v.age_fmt || '')) + cell('出生日期', esc(p.birth_date || '')) +
        '  </div>' +
        '  <div class="dw-line-row">' +
        cell('患者ID', esc(p.patient_id)) + cell('流水号', esc(v.visit_no)) + cell('首诊科室', esc(v.first_dept_name || '')) + cell('首诊时间', esc((v.created_at || '').substr(0, 16))) +
        '  </div>' +
        '</div></div>';
}

/* 单张申请单区块：申请单号（可点击预览检验申请单）+ 其下各检验项目 */
function labOrderHtml(o) {
    var pending = o.items.some(function (it) { return it.status === 'paid' || it.status === 'registered'; });
    var badge = pending
        ? '<span class="badge badge-warning" style="font-size:11px">检验中</span>'
        : '<span class="badge badge-success" style="font-size:11px">已完成</span>';
    var itemsHtml = o.items.map(labItemHtml).join('');
    return '<div class="card dw-lab-order" id="labSec_' + esc(o.order_id) + '" style="margin-bottom:14px">' +
        '<div class="dw-lab-order-head">' +
        '  <span class="fw-700">🧪 检验申请单</span>' +
        '  <a href="javascript:void(0)" style="color:var(--primary);cursor:pointer;text-decoration:underline;margin-left:10px" ' +
        'onclick="previewLabOrder(\'' + esc(o.order_id) + '\',\'' + esc(o.order_no) + '\')">' + esc(o.order_no) + '</a>' +
        '  <span class="fs-12 text-muted" style="margin-left:10px">开单医生：' + esc(o.doctor_name || '') + ' ｜ ' + esc((o.created_at || '').substr(0, 16)) + '</span>' +
        badge +
        '</div>' + itemsHtml + '</div>';
}

function previewLabOrder(orderId, orderNo) {
    if (!orderId) return;
    Clinic.print.preview('/api/print?action=order&order_id=' + orderId, null, '检验申请单预览：' + (orderNo || ''));
}

function scrollToLab(orderId) {
    var el = document.getElementById('labSec_' + orderId);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        inner = '<div class="fs-13 text-muted">该项目已缴费，尚未登记采样。</div>' +
            '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" id="labReg_' + id + '">📝 登记</button></div>';
    } else if (it.status === 'registered') {
        var inputs = '';
        if (it.is_group) {
            if (!it.members || !it.members.length) {
                inputs = '<div class="fs-13" style="color:var(--danger)">该组合项目未配置组内成员，无法录入结果，请联系管理员在【检验管理】中完善检验组合。</div>';
            } else {
                inputs = '<div class="fs-12 text-muted mb-4">该检验为组合项目，请逐一填写组内各项检验结果：</div>';
                (it.members || []).forEach(function (m) {
                    inputs += '<div class="dw-lab-item" style="margin-bottom:8px">' +
                        '<div class="dw-lab-item-name">' + esc(m.name) + ' <span class="fs-12 text-muted fw-400">（单位：' + esc(m.unit || '—') + '）</span></div>' +
                        '<div class="dw-lab-item-hint">正常范围：' + esc(m.normal_range || '—') + '</div>' + critHint(it, m) +
                        '<input type="text" class="input mt-4" id="labVal_' + id + '_' + esc(m.id) + '" placeholder="请输入检验结果数值"></div>';
                });
            }
        } else {
            inputs = '<div class="dw-lab-item">' +
                '<div class="dw-lab-item-name">化验数值 <span class="fs-12 text-muted fw-400">（单位：' + esc(it.unit || '—') + '）</span></div>' +
                '<div class="dw-lab-item-hint">正常范围：' + esc(it.normal_range || '—') + '</div>' + critHint(it, null) +
                '<input type="text" class="input mt-4" id="labVal_' + id + '" placeholder="请输入检验结果数值"></div>';
        }
        inner = inputs +
            '<div class="fs-12 text-muted mt-4">提交后自动生成报告并打印。</div>' +
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
            shown = '<div class="dw-lab-value">化验数值：<b>' + esc(val.value || '—') + '</b> ' + esc(it.unit || '') + '</div>';
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

function doLabRegister(it) {
    Clinic.ajax('/api/lab', { action: 'register', item_id: it.id }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterLabAction();
        },
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
        if (!value) { Clinic.toast.warning('请输入检验结果数值'); return; }
        isGroup = 0;
    }
    Clinic.ajax('/api/lab', {
        action: 'save_result', item_id: it.id, value: value, is_group: isGroup,
    }, {
        loading: true,
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            Clinic.print.load('/api/print?action=report&report_id=' + json.data.report_id, null);
            afterLabAction();
        },
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
