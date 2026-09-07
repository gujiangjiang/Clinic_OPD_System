<?php
/**
 * ============================================================
 * nurse/dashboard.php — 护士站工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：待处置 / 完成（互斥单选）+ 当日（叠加）；点击患者
 *   弹出护理记录单页面：① 护理记录 ② 病历摘要（只读 + 查看完整病历
 *   预览）③ 生命体征趋势 + 录入悬浮窗 ④ 待处理处置（医嘱单号可点击
 *   预览处置单）⑤ 待执行医嘱（处方号可点击预览处方）。
 *   各区块操作后仅局部刷新，不重建整页（保持滚动位置）。
 * 数据接口：/api/deptwork（queue/patient）+ /api/nurse（nursing_list/
 * nursing_add、vitals、save_vitals、complete、med_start、med_done）
 * + /api/print（record/order 只读预览）。
 * ============================================================ */
require APP_ROOT . '/app/includes/dept_workbench.php';
dept_workbench(array(
    'role' => 'nurse',
    'title' => '护士工作站',
    'desc' => '护理记录、生命体征录入、处置执行与执行医嘱',
    'emoji' => '💉',
));
?>
<script>
/* ==================== 护士站工作台：患者工作台渲染 ==================== */
Clinic.deptwork.configure({
    role: 'nurse',
    render: renderNurseWork,
    afterAction: function () { Clinic.deptwork.refreshQueue(); },
});

var CUR_VISIT = '';   // 当前患者混淆码（体征/护理/处置操作回传）

function esc(s) { return Clinic.escHtml(s); }
function nl2br(s) { return Clinic.nl2br(s); }
function itemStatusName(s) {
    var map = { paid: '待执行', dispensing: '执行中', done: '已完成', dispensed: '已执行', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
}
function itemStatusBadge(s) {
    var cls = s === 'done' || s === 'dispensed' ? 'badge-success' : (s === 'paid' || s === 'dispensing' ? 'badge-warning' : 'badge-gray');
    return Clinic.deptwork.statusBadge(itemStatusName(s), cls);
}

/* 链接样式（处置单号/处方号可点击） */
function orderLink(orderId, orderNo, type) {
    if (!orderId) return esc(orderNo || '—');
    return '<a href="javascript:void(0)" style="color:var(--primary);cursor:pointer;text-decoration:underline" ' +
        'onclick="previewOrder(\'' + esc(orderId) + '\',\'' + esc(orderNo || '') + '\',\'' + esc(type || '') + '\')">' + esc(orderNo || '—') + '</a>';
}

/* ==================== 通用只读打印预览（病历预览入口已移至顶栏通用【病历】按钮） ==================== */
function previewOrder(orderId, orderNo, type) {
    if (!orderId) return;
    var isRx = type === 'prescription';
    // 处方：护士只关心「门诊输液（注射）笺」（单号+Z），处方笺给药房取药，不展示；
    // 处置：显示处置单
    var title = isRx ? '输液（注射）笺预览：' + (orderNo || '') : '处置单预览：' + (orderNo || '');
    Clinic.print.preview('/api/print?action=order&order_id=' + orderId + (isRx ? '&nurse_only=1' : ''), null, title);
}

/* ==================== 生命体征趋势（复用原护士站实现） ==================== */
function vitalsTrendSvg(values, min, max) {
    var n = values.length;
    if (n < 1) return '';
    var W = 220, H = 40, pad = 3;
    var range = (max - min) || 1;
    var pts = values.map(function (v, i) {
        var x = pad + (n === 1 ? W / 2 : i * (W - pad * 2) / (n - 1));
        var y = H - pad - ((Math.min(Math.max(v, min), max) - min) / range) * (H - pad * 2);
        return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    return '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" style="vertical-align:middle;flex-shrink:0">' +
        '<polyline points="' + pts + '" fill="none" stroke="var(--primary,#2563eb)" stroke-width="1.6"/>' +
        (n === 1 ? '<circle cx="' + (W / 2).toFixed(1) + '" cy="' + (H / 2).toFixed(1) + '" r="3" fill="var(--primary,#2563eb)"/>' : '') +
        '</svg>';
}

function vitalsSection(data) {
    var hist = data.vitals || [];
    var series = {
        '收缩压': { key: 'vital_sbp', unit: 'mmHg', min: 60, max: 200 },
        '舒张压': { key: 'vital_dbp', unit: 'mmHg', min: 40, max: 130 },
        '心率': { key: 'vital_heart_rate', unit: '次/分', min: 40, max: 150 },
        '血氧': { key: 'vital_spo2', unit: '%', min: 80, max: 100 },
    };
    var chartHtml = '';
    Object.keys(series).forEach(function (label) {
        var cfg = series[label];
        var vals = hist.map(function (r) { return parseFloat(r[cfg.key]) || 0; }).filter(function (x) { return x > 0; });
        if (!vals.length) return;
        chartHtml += '<div class="dw-vital-row"><span class="dw-vital-label">' + label + '</span>' +
            vitalsTrendSvg(vals, cfg.min, cfg.max) +
            '<span class="dw-vital-val">' + vals[vals.length - 1] + cfg.unit + '</span></div>';
    });
    var trendHtml = chartHtml
        ? '<div style="background:var(--bg-soft);border-radius:8px;padding:10px 12px">' + chartHtml + '</div>'
        : '<div class="fs-13 text-muted">暂无体征记录</div>';

    var histRows = hist.length ? hist.map(function (r) {
        return '<tr><td>' + esc(r.created_at || '').substr(5, 11) + '</td>' +
            '<td>' + esc(r.vital_sbp || '-') + '/' + esc(r.vital_dbp || '-') + '</td>' +
            '<td>' + esc(r.vital_heart_rate || '-') + '</td>' +
            '<td>' + esc(r.vital_pulse || '-') + '</td>' +
            '<td>' + esc(r.vital_spo2 || '-') + '%</td>' +
            '<td>' + esc(r.vital_respiration || '-') + '</td>' +
            '<td>' + esc(r.operator || '') + '</td></tr>';
    }).join('') : '<tr><td colspan="7" class="text-muted text-center">暂无记录</td></tr>';

    return '<div class="dw-nurse-sec" id="nurseSecVitals">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📈</span>生命体征趋势</div>' + trendHtml +
        '<div class="fs-13 fw-700 mt-8 mb-4">体征历史记录</div>' +
        '<div style="max-height:180px;overflow-y:auto"><table class="table table-sm" style="font-size:12px"><thead><tr>' +
        '<th>时间</th><th>血压</th><th>心率</th><th>脉搏</th><th>血氧</th><th>呼吸</th><th>录入人</th></tr></thead><tbody>' +
        histRows + '</tbody></table></div>' +
        '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" onclick="openVitalsPop(event)">🌡️ 录入生命体征</button></div>' +
        '</div>';
}

/* 生命体征录入悬浮窗（统一走 Clinic.vitals 公共组件，与医生工作站同实现；
   每次为全新录入，不预填任何已有体征数据） */
function closeVitalsPop() {
    if (window.Clinic && Clinic.vitals) Clinic.vitals.close();
}
function openVitalsPop(ev) {
    if (!window.Clinic || !Clinic.vitals) { Clinic.toast.warning('体征组件未加载'); return; }
    Clinic.vitals.open({
        cx: ev && typeof ev.clientX === 'number' ? ev.clientX : window.innerWidth / 2 - 150,
        cy: ev && typeof ev.clientY === 'number' ? ev.clientY : 120,
        hint: '保存后医生工作站病历将自动同步显示。',
        onSubmit: function (vals, done) {
            Clinic.ajax('/api/nurse', {
                action: 'save_vitals',
                visit_id: CUR_VISIT,
                vital_sbp: vals.vSys === '' ? 0 : parseInt(vals.vSys, 10),
                vital_dbp: vals.vDia === '' ? 0 : parseInt(vals.vDia, 10),
                vital_heart_rate: vals.vHR,
                vital_pulse: vals.vPulse,
                vital_spo2: vals.vSpO2,
                vital_respiration: vals.vResp,
            }, {
                onSuccess: function (j) {
                    Clinic.toast.success(j.msg);
                    done();
                    refreshNurseSec('Vitals');
                },
            });
        },
    });
}

/* ==================== 护理记录 ==================== */
function nursingSection(data) {
    var list = data.nursing || [];
    var rows = list.length ? list.map(function (r) {
        return '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
            '<div class="flex-between">' +
            '  <div class="fs-13" style="flex:1;min-width:0">' + nl2br(esc(r.content)) + '</div>' +
            '  <span class="fs-14" style="color:var(--danger);cursor:pointer;flex-shrink:0;margin-left:10px" title="删除该护理记录" onclick="delNursing(' + (r.id || 0) + ')">✕</span>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-4">' + esc(r.operator) + ' ｜ ' + esc(r.created_at) + '</div></div>';
    }).join('') : '<div class="fs-13 text-muted">暂无护理记录</div>';
    return '<div class="dw-nurse-sec" id="nurseSecNursing">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📝</span>护理记录</div>' + rows +
        '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" onclick="openNursingModal()">➕ 添加护理记录</button></div>' +
        '</div>';
}

function delNursing(id) {
    if (!id) return;
    Clinic.modal.confirm('确定删除该条护理记录？删除后不可恢复。', function () {
        Clinic.ajax('/api/nurse', { action: 'nursing_delete', id: id }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                refreshNurseSec('Nursing');
            },
        });
    });
}

/* 添加护理记录模态框：左侧护理模板列表 + 右侧自由输入（点模板带入内容） */
var NM_TPLS = [];
function openNursingModal() {
    var mask = Clinic.modal.open(
        '<div class="flex" style="gap:14px;height:460px">' +
        '  <div style="width:300px;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid var(--border);padding-right:14px;min-height:0">' +
        '    <div class="form-group"><label class="form-label">护理模板</label>' +
        '    <input class="input" id="nmSearch" placeholder="🔍 搜索模板" oninput="nmRenderTpls()"></div>' +
        '    <div id="nmTplList" style="flex:1;overflow-y:auto;min-height:0"></div>' +
        '  </div>' +
        '  <div style="flex:1;min-width:0;display:flex;flex-direction:column">' +
        '    <div class="form-group" style="flex:1;display:flex;flex-direction:column;min-height:0">' +
        '      <label class="form-label">护理记录内容 <span class="req">*</span></label>' +
        '      <textarea class="textarea" id="nmContent" style="flex:1;min-height:0" placeholder="可自由输入，或点击左侧模板直接带入"></textarea></div>' +
        '  </div>' +
        '</div>',
        { title: '➕ 添加护理记录', size: 'modal-lg', buttons: [] }
    );
    NM_TPLS = [];
    loadNursingTpls();
    mask.querySelector('.modal-foot').innerHTML =
        '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" onclick="nmSave()">添加</button>';
}

function loadNursingTpls() {
    Clinic.get('/api/template?action=list&type=nursing_record', null, {
        loading: false,
        onSuccess: function (j) {
            NM_TPLS = j.data.list || [];
            nmRenderTpls();
        },
    });
}

function nmRenderTpls() {
    var box = document.getElementById('nmTplList');
    if (!box) return;
    var kw = ((document.getElementById('nmSearch') || {}).value || '').trim().toLowerCase();
    var list = NM_TPLS.filter(function (t) {
        return !kw || (t.title || '').toLowerCase().indexOf(kw) !== -1;
    });
    box.innerHTML = list.length ? list.map(function (t) {
        return '<div class="dd-item" style="cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px" onclick="nmPickTpl(' + t.id + ')">' +
            '<div class="fw-600 fs-13">' + esc(t.title) + '</div>' +
            '<div class="fs-12 text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + esc((t.content && t.content.content) || '') + '">' + esc((t.content && t.content.content) || '') + '</div></div>';
    }).join('') : '<div class="fs-12 text-muted">暂无护理模板（可自由输入）</div>';
}

function nmPickTpl(id) {
    Clinic.get('/api/template?action=get&id=' + id + '&for_apply=1', null, {
        loading: false,
        onSuccess: function (j) {
            var t = j.data && j.data.template;
            var ta = document.getElementById('nmContent');
            if (ta && t && t.content && t.content.content) ta.value = t.content.content;
        },
    });
}

function nmSave() {
    var content = (document.getElementById('nmContent') || {}).value || '';
    content = content.trim();
    if (!content) { Clinic.toast.warning('请输入护理记录内容'); return; }
    Clinic.ajax('/api/nurse', { action: 'nursing_add', visit_id: CUR_VISIT, content: content }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            Clinic.modal.close();
            refreshNurseSec('Nursing');
        },
    });
}

/* ==================== 病历摘要（只读，纵向排列；查看完整病历入口移至顶栏【病历】按钮） ==================== */
function summarySection(data) {
    var s = data.summary || {};
    var grid = function (label, value) {
        return '<div class="item"><span class="label">' + label + '</span><span class="value">' + (value ? esc(value) : '—') + '</span></div>';
    };
    return '<div class="dw-nurse-sec" id="nurseSecSummary">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📋</span>病历摘要</div>' +
        '<div class="dw-nurse-vert">' +
        grid('主诉', s.chief_complaint) +
        grid('现病史', s.present_illness) +
        grid('既往史', s.past_history) +
        grid('过敏史', s.allergy_history) +
        grid('查体', s.physical_exam) +
        grid('初步诊断', s.diagnosis) +
        '</div></div>';
}

/* ==================== 待处理处置 / 待执行医嘱 ==================== */
function procSection(data) {
    var items = [];
    (data.orders || []).forEach(function (o) {
        if (o.order_type !== 'procedure') return;
        o.items.forEach(function (it) {
            if (it.is_nurse) items.push({ it: it, o: o });
        });
    });
    var rows = '';
    items.forEach(function (e) {
        var it = e.it, o = e.o;
        rows += '<tr>' +
            '<td class="fw-600">' + esc(it.item_name) + ' ×' + it.quantity + '</td>' +
            '<td>' + orderLink(o.order_id, o.order_no, 'procedure') + '</td>' +
            '<td>' + esc(o.doctor_name || '') + '</td>' +
            '<td class="fs-12">' + esc((it.created_at || '').substr(5, 11)) + '</td>' +
            '<td>' + itemStatusBadge(it.status) + '</td>' +
            '<td>' + (it.status === 'paid'
                ? '<button class="btn btn-success btn-sm" onclick="completeProc(\'' + esc(it.id) + '\')">完成处置</button>'
                : '') + '</td></tr>';
    });
    if (!rows) rows = '<tr><td colspan="6" class="text-muted text-center">暂无处置项目</td></tr>';
    return '<div class="dw-nurse-sec" id="nurseSecProc">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">💉</span>处置项目</div>' +
        '<div class="fs-12 text-muted mb-4">点击医嘱单号可查看处置单预览。</div>' +
        '<div class="table-wrap"><table class="table table-sm" style="font-size:12.5px"><thead><tr>' +
        '<th>处置项目</th><th>医嘱单号</th><th>开单医生</th><th>开单时间</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div></div>';
}

function medSection(data) {
    var items = [];
    (data.orders || []).forEach(function (o) {
        if (o.order_type !== 'prescription') return;
        o.items.forEach(function (it) {
            if (it.is_nurse) items.push({ it: it, o: o });
        });
    });
    var rows = '';
    items.forEach(function (e) {
        var it = e.it, o = e.o;
        // 审方/发药拆分：paid 且药房未发药（reviewed/paid）→ 显示「待药房发药」，
        // 仅药房已发药（dispensed）后 paid 明细才可「等待执行」
        var waitDisp = (it.status === 'paid' && o.status !== 'dispensed');
        var stBadge = waitDisp
            ? '<span class="badge badge-gray" style="font-size:11px">待药房发药</span>'
            : itemStatusBadge(it.status);
        rows += '<tr>' +
            '<td class="fw-600">' + esc(it.item_name) + ' ×' + it.quantity + ' <span class="fs-12 text-muted fw-400">' + esc(it.route || '') + '</span></td>' +
            '<td>' + orderLink(o.order_id, o.order_no, 'prescription') + '</td>' +
            '<td>' + esc(o.doctor_name || '') + '</td>' +
            '<td class="fs-12">' + esc((it.created_at || '').substr(5, 11)) + '</td>' +
            '<td>' + stBadge + '</td>' +
            '<td><div class="flex gap-4">' +
            (waitDisp
                ? '<span class="fs-12 text-muted">待药房发药</span>'
                : (it.status === 'paid'
                    ? '<button class="btn btn-primary btn-sm" onclick="medStart(\'' + esc(it.id) + '\')">等待执行</button>'
                    : (it.status === 'dispensing'
                        ? '<button class="btn btn-success btn-sm" onclick="medDone(\'' + esc(it.id) + '\')">执行完成</button>'
                        : ''))) +
            '</div></td></tr>';
    });
    if (!rows) rows = '<tr><td colspan="6" class="text-muted text-center">暂无待执行医嘱</td></tr>';
    return '<div class="dw-nurse-sec" id="nurseSecMed">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">💊</span>待执行医嘱</div>' +
        '<div class="fs-12 text-muted mb-4">药房审方发药后方可执行；点击处方号可查看处方预览。</div>' +
        '<div class="table-wrap"><table class="table table-sm" style="font-size:12.5px"><thead><tr>' +
        '<th>医嘱</th><th>处方号</th><th>开单医生</th><th>开单时间</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div></div>';
}

function completeProc(itemId) {
    Clinic.modal.confirm('确认该处置已执行完成？', function () {
        Clinic.ajax('/api/nurse', { action: 'complete', item_id: itemId }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                refreshNurseSec('Proc');
                refreshNurseSide();
                Clinic.deptwork.refreshQueue();
            },
        });
    });
}

function medStart(itemId) {
    Clinic.ajax('/api/nurse', { action: 'med_start', item_id: itemId }, {
        onSuccess: function (j) {
            Clinic.toast.success(j.msg);
            refreshNurseSec('Med');
            refreshNurseSide();
            Clinic.deptwork.refreshQueue();
        },
    });
}

function medDone(itemId) {
    Clinic.modal.confirm('确认该医嘱已执行完成？执行后将反馈医生工作站。', function () {
        Clinic.ajax('/api/nurse', { action: 'med_done', item_id: itemId }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                refreshNurseSec('Med');
                refreshNurseSide();
                Clinic.deptwork.refreshQueue();
            },
        });
    }, { title: '执行确认', okText: '执行完成' });
}

/* ==================== 局部刷新（不重建整页，保持滚动位置） ==================== */
function refreshNurseSec(name) {
    Clinic.deptwork.fetchPatient(function (data) {
        var map = { Nursing: nursingSection, Vitals: vitalsSection, Summary: summarySection, Proc: procSection, Med: medSection };
        var fn = map[name];
        var el = document.getElementById('nurseSec' + name);
        if (fn && el) el.outerHTML = fn(data);
    });
}

function refreshNurseSide() {
    Clinic.deptwork.fetchPatient(function (data) { renderNurseSide(data); });
}

function renderNurseSide(data) {
    var procCnt = 0, medCnt = 0;
    (data.orders || []).forEach(function (o) {
        o.items.forEach(function (it) {
            if (o.order_type === 'procedure' && it.is_nurse && it.status === 'paid') procCnt++;
            // 仅计可执行/执行中：药房已发药（dispensed）的 paid 明细 + 执行中（dispensing）
            if (o.order_type === 'prescription' && it.is_nurse && (it.status === 'dispensing' || (it.status === 'paid' && o.status === 'dispensed'))) medCnt++;
        });
    });
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">📋 病历摘要</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecSummary\')">主诉 / 现病史 / 诊断</div></div>' +
        '<div class="dw-side-sec"><div class="dw-side-title">📝 护理记录</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecNursing\')">护理记录 / 添加 / 删除</div></div>' +
        '<div class="dw-side-sec"><div class="dw-side-title">📈 生命体征</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecVitals\')">趋势图 / 录入</div></div>' +
        '<div class="dw-side-sec"><div class="dw-side-title">💉 待办事项</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecProc\')">待处置 ' + procCnt + ' 项</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecMed\')">待执行医嘱 ' + medCnt + ' 项</div></div>';
}

/* ==================== 主渲染 ==================== */
function renderNurseWork(data) {
    CUR_VISIT = (data.visit || {}).code || '';
    var v = data.visit || {}, p = data.patient || {};
    renderNurseSide(data);

    // 主区：护理记录单（病历摘要在前、护理记录随后，符合病历逻辑；抬头统一走公共组件）
    var head = Clinic.deptwork.headHtml(data, '护 理 记 录 单');

    var body =
        summarySection(data) +
        nursingSection(data) +
        vitalsSection(data) +
        procSection(data) +
        medSection(data);

    document.getElementById('dwMain').innerHTML = head + body;
}

function scrollToSec(id) {
    var el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

Clinic.deptwork.init();
</script>
