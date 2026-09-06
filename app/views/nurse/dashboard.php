<?php
/**
 * ============================================================
 * nurse/dashboard.php — 护士站工作台（新）
 * ============================================================
 * 说明：采用与医生工作站一致的「顶部患者信息横条 + 左侧候诊列表 +
 * 主工作区」布局（公共骨架见 app/includes/dept_workbench.php，
 * 公共交互见 deptwork.js）：
 *   候诊列表页签：待处置 / 完成 / 当日；点击患者弹出护理记录单页面：
 *   ① 护理记录（查看 + 新增）② 病历摘要（只读：主诉/现病史/既往史/
 *   过敏史/查体/初步诊断）③ 生命体征趋势图 + 手动录入 ④ 待处理处置
 *   / 待执行医嘱操作。
 * 数据接口：/api/deptwork（queue/patient）+ /api/nurse（nursing_list/
 * nursing_add、vitals、save_vitals、complete、med_start、med_done）。
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
    afterAction: afterNurseAction,
});

var CUR_VISIT = '';   // 当前患者混淆码（体征/护理/处置操作回传）

function afterNurseAction() {
    Clinic.deptwork.reloadPatient();
    Clinic.deptwork.refreshQueue();
}

function esc(s) { return Clinic.escHtml(s); }
function nl2br(s) { return (s || '').replace(/\n/g, '<br>'); }
function itemStatusName(s) {
    var map = { paid: '待执行', dispensing: '执行中', done: '已完成', dispensed: '已执行', rejected: '已拒绝', refunded: '已退费', cancelled: '已取消' };
    return map[s] || s;
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

    return '<div class="dw-nurse-sec">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📈</span>生命体征趋势</div>' + trendHtml +
        '<div class="fs-13 fw-700 mt-8 mb-4">体征历史记录</div>' +
        '<div style="max-height:180px;overflow-y:auto"><table class="table table-sm" style="font-size:12px"><thead><tr>' +
        '<th>时间</th><th>血压</th><th>心率</th><th>脉搏</th><th>血氧</th><th>呼吸</th><th>录入人</th></tr></thead><tbody>' +
        histRows + '</tbody></table></div>' +
        '<div class="fs-13 fw-700 mt-8 mb-4">手动录入生命体征</div>' +
        '<div class="form-row">' +
        '<div class="form-group"><label class="form-label">收缩压（mmHg）</label><input class="input" id="vSys" type="number" min="0" placeholder="收缩压"></div>' +
        '<div class="form-group"><label class="form-label">舒张压（mmHg）</label><input class="input" id="vDia" type="number" min="0" placeholder="舒张压"></div></div>' +
        '<div class="form-row">' +
        '<div class="form-group"><label class="form-label">心率（次/分）</label><input class="input" id="vHR" placeholder="心率"></div>' +
        '<div class="form-group"><label class="form-label">脉搏（次/分）</label><input class="input" id="vPulse" placeholder="脉搏"></div></div>' +
        '<div class="form-row">' +
        '<div class="form-group"><label class="form-label">血氧饱和度（%）</label><input class="input" id="vSpO2" placeholder="血氧"></div>' +
        '<div class="form-group"><label class="form-label">呼吸（次/分）</label><input class="input" id="vRR" placeholder="呼吸"></div></div>' +
        '<div class="fs-12 text-muted">每次保存将新增一条体征记录，医生工作站病历将自动同步显示。</div>' +
        '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" id="vSaveBtn">💾 保存体征</button></div>' +
        '</div>';
}

function saveVitals() {
    Clinic.ajax('/api/nurse', {
        action: 'save_vitals',
        visit_id: CUR_VISIT,
        vital_sbp: parseInt(document.getElementById('vSys').value, 10) || 0,
        vital_dbp: parseInt(document.getElementById('vDia').value, 10) || 0,
        vital_heart_rate: (document.getElementById('vHR') || {}).value || '',
        vital_pulse: (document.getElementById('vPulse') || {}).value || '',
        vital_spo2: (document.getElementById('vSpO2') || {}).value || '',
        vital_respiration: (document.getElementById('vRR') || {}).value || '',
    }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterNurseAction();
        },
    });
}

/* ==================== 护理记录 ==================== */
function nursingSection(data) {
    var list = data.nursing || [];
    var rows = list.length ? list.map(function (r) {
        return '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
            '<div class="fs-13">' + nl2br(esc(r.content)) + '</div>' +
            '<div class="fs-12 text-muted mt-4">' + esc(r.operator) + ' ｜ ' + esc(r.created_at) + '</div></div>';
    }).join('') : '<div class="fs-13 text-muted">暂无护理记录</div>';
    return '<div class="dw-nurse-sec">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📝</span>护理记录</div>' + rows +
        '<div class="form-group mt-8"><label class="form-label">新增护理记录</label>' +
        '<textarea class="textarea" id="nursingContent" rows="2" placeholder="如：测量体温36.5℃，患者生命体征平稳"></textarea></div>' +
        '<div class="dw-report-actions"><button class="btn btn-primary btn-sm" id="nursingAddBtn">➕ 添加护理记录</button></div>' +
        '</div>';
}

function addNursing() {
    var content = (document.getElementById('nursingContent') || {}).value || '';
    content = content.trim();
    if (!content) { Clinic.toast.warning('请输入护理记录内容'); return; }
    Clinic.ajax('/api/nurse', { action: 'nursing_add', visit_id: CUR_VISIT, content: content }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterNurseAction();
        },
    });
}

/* ==================== 病历摘要（只读） ==================== */
function summarySection(data) {
    var s = data.summary || {};
    var grid = function (label, value) {
        return '<div class="item"><span class="label">' + label + '</span><span class="value">' + (value ? esc(value) : '—') + '</span></div>';
    };
    return '<div class="dw-nurse-sec">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">📋</span>病历摘要（只读）</div>' +
        '<div class="dw-nurse-grid">' +
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
            items.push({ it: it, o: o });
        });
    });
    var rows = '';
    var hasTodo = false;
    items.forEach(function (e) {
        var it = e.it, o = e.o;
        if (it.status === 'paid' || it.status === 'done') hasTodo = true;
        rows += '<tr>' +
            '<td class="fw-600">' + esc(it.item_name) + ' ×' + it.quantity + '</td>' +
            '<td>' + esc(o.order_no) + '</td>' +
            '<td>' + esc(o.doctor_name || '') + '</td>' +
            '<td class="fs-12">' + esc((it.created_at || '').substr(5, 11)) + '</td>' +
            '<td>' + itemStatusBadge(it.status) + '</td>' +
            '<td>' + (it.status === 'paid'
                ? '<button class="btn btn-success btn-sm" onclick="completeProc(\'' + esc(it.id) + '\')">完成处置</button>'
                : '') + '</td></tr>';
    });
    if (!rows) rows = '<tr><td colspan="6" class="text-muted text-center">暂无处置项目</td></tr>';
    return '<div class="dw-nurse-sec">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">💉</span>处置项目' + (hasTodo ? '' : '') + '</div>' +
        '<div class="table-wrap"><table class="table table-sm" style="font-size:12.5px"><thead><tr>' +
        '<th>处置项目</th><th>医嘱单号</th><th>开单医生</th><th>开单时间</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div></div>';
}

function itemStatusBadge(s) {
    var cls = s === 'done' || s === 'dispensed' ? 'badge-success' : (s === 'paid' || s === 'dispensing' ? 'badge-warning' : 'badge-gray');
    return '<span class="badge ' + cls + '" style="font-size:11px">' + itemStatusName(s) + '</span>';
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
        rows += '<tr>' +
            '<td class="fw-600">' + esc(it.item_name) + ' ×' + it.quantity + ' <span class="fs-12 text-muted fw-400">' + esc(it.route || '') + '</span></td>' +
            '<td>' + esc(o.order_no) + '</td>' +
            '<td>' + esc(o.doctor_name || '') + '</td>' +
            '<td class="fs-12">' + esc((it.created_at || '').substr(5, 11)) + '</td>' +
            '<td>' + itemStatusBadge(it.status) + '</td>' +
            '<td><div class="flex gap-4">' +
            (it.status === 'paid'
                ? '<button class="btn btn-primary btn-sm" onclick="medStart(\'' + esc(it.id) + '\')">等待执行</button>'
                : (it.status === 'dispensing'
                    ? '<button class="btn btn-success btn-sm" onclick="medDone(\'' + esc(it.id) + '\')">执行完成</button>'
                    : '')) +
            '</div></td></tr>';
    });
    if (!rows) rows = '<tr><td colspan="6" class="text-muted text-center">暂无待执行医嘱</td></tr>';
    return '<div class="dw-nurse-sec">' +
        '<div class="dw-nurse-sec-title"><span class="emoji">💊</span>待执行医嘱（护士站执行）</div>' +
        '<div class="fs-12 text-muted mb-4">护士站执行的药品医嘱需药房审方发药后方可执行。</div>' +
        '<div class="table-wrap"><table class="table table-sm" style="font-size:12.5px"><thead><tr>' +
        '<th>医嘱</th><th>处方号</th><th>开单医生</th><th>开单时间</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
        rows + '</tbody></table></div></div>';
}

function completeProc(itemId) {
    Clinic.modal.confirm('确认该处置已执行完成？', function () {
        Clinic.ajax('/api/nurse', { action: 'complete', item_id: itemId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                afterNurseAction();
            },
        });
    });
}

function medStart(itemId) {
    Clinic.ajax('/api/nurse', { action: 'med_start', item_id: itemId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            afterNurseAction();
        },
    });
}

function medDone(itemId) {
    Clinic.modal.confirm('确认该医嘱已执行完成？执行后将反馈医生工作站。', function () {
        Clinic.ajax('/api/nurse', { action: 'med_done', item_id: itemId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                afterNurseAction();
            },
        });
    }, { title: '执行确认', okText: '执行完成' });
}

/* ==================== 主渲染 ==================== */
function renderNurseWork(data) {
    CUR_VISIT = (data.visit || {}).code || '';
    var v = data.visit || {}, p = data.patient || {};

    // 右栏大纲：患者待办导航
    var procCnt = 0, medCnt = 0;
    (data.orders || []).forEach(function (o) {
        o.items.forEach(function (it) {
            if (o.order_type === 'procedure' && it.status === 'paid') procCnt++;
            if (o.order_type === 'prescription' && it.is_nurse && (it.status === 'paid' || it.status === 'dispensing')) medCnt++;
        });
    });
    document.getElementById('dwSide').innerHTML =
        '<div class="dw-side-sec"><div class="dw-side-title">📋 病历摘要</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecSummary\')">主诉 / 现病史 / 诊断</div></div>' +
        '<div class="dw-side-sec"><div class="dw-side-title">📈 生命体征</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecVitals\')">趋势图 / 手动录入</div></div>' +
        '<div class="dw-side-sec"><div class="dw-side-title">💉 待办事项</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecProc\')">待处置 ' + procCnt + ' 项</div>' +
        '<div class="dw-side-item" onclick="scrollToSec(\'nurseSecMed\')">待执行医嘱 ' + medCnt + ' 项</div></div>';

    // 主区：护理记录单
    var hosp = document.body.getAttribute('data-hosp') || '';
    var head = '<div class="card dw-report-card"><div class="dw-report-head">' +
        '<div class="dw-report-hosp">' + esc(hosp) + '</div>' +
        '<div class="dw-report-title">护 理 记 录 单</div></div>' +
        '<div class="dw-report-meta">' +
        '<span>姓名：<b>' + esc(v.name) + '</b></span>' +
        '<span>性别：' + esc(v.gender) + '</span>' +
        '<span>年龄：' + esc(v.age_fmt || '') + '</span>' +
        '<span>患者ID：' + esc(p.patient_id) + '</span>' +
        '<span>流水号：' + esc(v.visit_no) + '</span>' +
        '<span>就诊科室：' + esc(v.dept_name || v.first_dept_name) + '</span></div></div>';

    var body =
        '<div id="nurseSecNursing">' + nursingSection(data) + '</div>' +
        '<div id="nurseSecSummary">' + summarySection(data) + '</div>' +
        '<div id="nurseSecVitals">' + vitalsSection(data) + '</div>' +
        '<div id="nurseSecProc">' + procSection(data) + '</div>' +
        '<div id="nurseSecMed">' + medSection(data) + '</div>';

    document.getElementById('dwMain').innerHTML = head + body;

    var vSave = document.getElementById('vSaveBtn');
    if (vSave) vSave.onclick = saveVitals;
    var nAdd = document.getElementById('nursingAddBtn');
    if (nAdd) nAdd.onclick = addNursing;
}

function scrollToSec(id) {
    var el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

Clinic.deptwork.init();
</script>
