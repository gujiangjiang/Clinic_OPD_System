<?php
/**
 * admin/analytics.php — 医院运营分析
 * 说明：多维度运营数据看板（口径：已缴费）：
 * 1. 运营总览：日期范围 KPI 卡（门诊人次/挂号费/药费/检验费/检查费/处置费/总收入）
 *    + 收入与门诊人次日趋势点线图；
 * 2. 科室统计：按科室汇总人次与各类收入，附收入排行条形图；
 * 3. 医生统计：按医生汇总接诊人次与分类型缴费收入（处方/检验/检查/处置分开列示）；
 * 4. 自定义统计：时间粒度（日/月/年）× 维度（科室/医生）× 指标自选，表格 + 图表。
 */
Router::title('运营分析');
// 科室选项（医生统计筛选 / 自定义统计维度）
$depts = DB::q('dept', 'SELECT id, name FROM departments WHERE status=1 ORDER BY sort, id');
?>
<div class="page-head">
    <div><div class="page-title">📊 医院运营分析</div><div class="page-desc">多维度运营数据统计与趋势分析（口径：已缴费）</div></div>
</div>

<!-- 日期范围工具条 -->
<div class="card" style="margin-bottom:16px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input type="text" class="input" id="anaStart" readonly placeholder="开始日期" style="width:150px;cursor:pointer;background:var(--bg)" onclick="Clinic.datePicker.open(this, { maxToday: false, onChange: function () { anaLoad(); } })">
        <span class="text-muted">至</span>
        <input type="text" class="input" id="anaEnd" readonly placeholder="结束日期" style="width:150px;cursor:pointer;background:var(--bg)" onclick="Clinic.datePicker.open(this, { maxToday: true, onChange: function () { anaLoad(); } })">
        <button class="btn btn-primary btn-sm" onclick="anaLoad()">查询</button>
        <span class="flex gap-4" style="flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" data-ana-quick="today" onclick="anaQuick('today')">今日</button>
            <button class="btn btn-outline btn-sm" data-ana-quick="yesterday" onclick="anaQuick('yesterday')">昨日</button>
            <button class="btn btn-outline btn-sm" data-ana-quick="7d" onclick="anaQuick('7d')">近7天</button>
            <button class="btn btn-outline btn-sm" data-ana-quick="30d" onclick="anaQuick('30d')">近30天</button>
            <button class="btn btn-outline btn-sm" data-ana-quick="month" onclick="anaQuick('month')">本月</button>
            <button class="btn btn-outline btn-sm" data-ana-quick="year" onclick="anaQuick('year')">本年</button>
        </span>
    </div>
</div>

<!-- Tab 切换 -->
<div class="flex gap-8 mb-12">
    <button class="btn btn-primary btn-sm" data-ana-tab="overview" onclick="anaTab('overview')">📈 运营总览</button>
    <button class="btn btn-outline btn-sm" data-ana-tab="dept" onclick="anaTab('dept')">🏥 科室统计</button>
    <button class="btn btn-outline btn-sm" data-ana-tab="doctor" onclick="anaTab('doctor')">👨‍⚕️ 医生统计</button>
    <button class="btn btn-outline btn-sm" data-ana-tab="custom" onclick="anaTab('custom')">🧮 自定义统计</button>
    <button class="btn btn-outline btn-sm" data-ana-tab="disposition" onclick="anaTab('disposition')">🧭 转归查询</button>
</div>

<!-- ============ 转归查询 ============ -->
<div id="ana-pane-disposition" style="display:none">
    <div class="card" style="margin-bottom:12px">
        <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
            <div class="flex gap-8" id="dispFilters">
                <button class="btn btn-primary btn-sm" data-disp="全部" onclick="dispFilter('全部')">全部</button>
                <button class="btn btn-outline btn-sm" data-disp="自主离院" onclick="dispFilter('自主离院')">自主离院</button>
                <button class="btn btn-outline btn-sm" data-disp="住院" onclick="dispFilter('住院')">住院</button>
                <button class="btn btn-outline btn-sm" data-disp="转院" onclick="dispFilter('转院')">转院</button>
                <button class="btn btn-outline btn-sm" data-disp="死亡" onclick="dispFilter('死亡')">死亡</button>
                <button class="btn btn-outline btn-sm" data-disp="其他" onclick="dispFilter('其他')">其他</button>
            </div>
            <input class="input" id="dispSearch" placeholder="🔍 搜索患者姓名 / 门诊号 / 身份证号" style="width:240px" oninput="renderDispTable()">
        </div>
    </div>
    <div class="card">
        <div class="card-title"><span>患者转归情况</span></div>
        <div id="dispTable"></div>
    </div>
</div>

<!-- ============ 运营总览 ============ -->
<div id="ana-pane-overview">
    <div class="kpi-grid">
        <div class="card kpi-card"><div class="kpi-label">门诊人次</div><div class="kpi-value" id="kPatients">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">总收入</div><div class="kpi-value kpi-primary" id="kTotal">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">挂号费</div><div class="kpi-value" id="kReg">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">药费</div><div class="kpi-value" id="kDrug">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">检验费</div><div class="kpi-value" id="kLab">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">检查费</div><div class="kpi-value" id="kImaging">—</div></div>
        <div class="card kpi-card"><div class="kpi-label">处置费</div><div class="kpi-value" id="kProc">—</div></div>
    </div>
    <div class="card" style="margin-top:16px">
        <div class="card-title"><span>收入构成趋势（元 / 日）</span></div>
        <div id="chartRevenue"></div>
    </div>
    <div class="card" style="margin-top:16px">
        <div class="card-title"><span>门诊人次趋势（人 / 日）</span></div>
        <div id="chartPatients"></div>
    </div>
</div>

<!-- ============ 科室统计 ============ -->
<div id="ana-pane-dept" style="display:none">
    <div class="card">
        <div class="card-title"><span>科室收入排行（含挂号费）</span></div>
        <div id="chartDept"></div>
    </div>
    <div class="card" style="margin-top:16px">
        <div class="flex gap-8" style="align-items:center;flex-wrap:wrap;margin-bottom:10px">
            <input class="input" id="deptSearch" placeholder="🔍 搜索科室" style="width:220px" oninput="renderDeptTable()">
            <span class="flex gap-4" id="deptTypeTabs" style="flex-wrap:wrap">
                <button class="btn btn-sm btn-primary" data-dtype="" onclick="deptTypeFilter(this,'')">全部</button>
                <button class="btn btn-sm btn-outline" data-dtype="clinic" onclick="deptTypeFilter(this,'clinic')">门诊</button>
                <button class="btn btn-sm btn-outline" data-dtype="emergency" onclick="deptTypeFilter(this,'emergency')">急诊</button>
            </span>
        </div>
        <div id="deptTable"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
    </div>
</div>

<!-- ============ 医生统计 ============ -->
<div id="ana-pane-doctor" style="display:none">
    <div class="card" style="margin-bottom:16px">
        <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
            <span class="fs-13 text-muted">科室筛选：</span>
            <select class="select" id="docDeptSel" onchange="loadDoctor()" style="width:auto"><option value="0">全部科室</option></select>
        </div>
    </div>
    <div class="card">
        <div class="flex gap-8" style="align-items:center;flex-wrap:wrap;margin-bottom:10px">
            <span class="fs-13 text-muted">科室筛选：</span>
            <select class="select" id="docDeptSel" onchange="loadDoctor()" style="width:auto"><option value="0">全部科室</option></select>
            <input class="input" id="docSearch" placeholder="🔍 搜索工号 / 姓名 / 职称" style="width:200px" oninput="renderDoctorTable()">
        </div>
        <div id="doctorTable"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>
    </div>
</div>

<!-- ============ 自定义统计 ============ -->
<div id="ana-pane-custom" style="display:none">
    <div class="card" style="margin-bottom:16px">
        <div class="flex gap-16" style="flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0"><label class="form-label">维度</label>
                <select class="select" id="cusGroup" style="width:auto">
                    <option value="day">按日</option><option value="month">按月</option>
                    <option value="year">按年</option><option value="dept">按科室</option>
                    <option value="doctor">按医生</option>
                </select></div>
            <div class="form-group" style="margin:0"><label class="form-label">统计指标（可多选）</label>
                <div class="flex gap-8" style="flex-wrap:wrap;font-size:13px">
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="patients" class="cusMetric" checked> 门诊人次</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="reg_fee" class="cusMetric"> 挂号费</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="drug" class="cusMetric"> 药费</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="lab" class="cusMetric"> 检验费</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="imaging" class="cusMetric"> 检查费</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="procedure" class="cusMetric"> 处置费</label>
                    <label class="flex gap-4" style="cursor:pointer"><input type="checkbox" value="total" class="cusMetric" checked> 总收入</label>
                </div></div>
            <button class="btn btn-primary btn-sm" onclick="loadCustom()">开始统计</button>
        </div>
    </div>
    <div class="card">
        <div class="card-title"><span>统计图表</span></div>
        <div id="chartCustom"></div>
    </div>
    <div class="card" style="margin-top:16px"><div id="customTable"></div></div>
</div>

<script>
/* ==================== 工具 ==================== */
function anaMoney(v) { return '¥' + Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function anaNum(v) { return Number(v || 0).toLocaleString('zh-CN'); }
function anaPad(n) { return n < 10 ? '0' + n : '' + n; }
function anaDate(d) { return d.getFullYear() + '-' + anaPad(d.getMonth() + 1) + '-' + anaPad(d.getDate()); }

/* 快捷范围 */
function anaQuick(k) {
    var now = new Date();
    var s = new Date(now), e = new Date(now);
    if (k === 'today') { }
    else if (k === 'yesterday') { s.setDate(s.getDate() - 1); e = new Date(s); }
    else if (k === '7d') { s.setDate(s.getDate() - 6); }
    else if (k === '30d') { s.setDate(s.getDate() - 29); }
    else if (k === 'month') { s = new Date(now.getFullYear(), now.getMonth(), 1); }
    else if (k === 'year') { s = new Date(now.getFullYear(), 0, 1); }
    document.getElementById('anaStart').value = anaDate(s);
    document.getElementById('anaEnd').value = anaDate(e);
    // 快捷按钮激活态
    document.querySelectorAll('[data-ana-quick]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-ana-quick') === k ? 'btn-primary' : 'btn-outline');
    });
    anaLoad();
}

/* 当前 Tab 与懒加载标记 */
var ANA_TAB = 'overview';
var ANA_LOADED = {};
function anaTab(t) {
    ANA_TAB = t;
    document.querySelectorAll('[data-ana-tab]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-ana-tab') === t ? 'btn-primary' : 'btn-outline');
    });
    ['overview', 'dept', 'doctor', 'custom', 'disposition'].forEach(function (p) {
        var el = document.getElementById('ana-pane-' + p);
        if (el) el.style.display = p === t ? '' : 'none';
    });
    anaLoad();
}
function anaRange() {
    return {
        start: document.getElementById('anaStart').value || anaDate(new Date()),
        end: document.getElementById('anaEnd').value || anaDate(new Date()),
    };
}
/* 统一入口：切 Tab / 点查询时刷新当前 Tab */
function anaLoad() {
    var r = anaRange();
    if (ANA_TAB === 'overview') loadOverview(r);
    else if (ANA_TAB === 'dept') loadDept(r);
    else if (ANA_TAB === 'doctor') loadDoctor(r);
    else if (ANA_TAB === 'disposition') loadDisposition();
    else loadCustom();
}

/* ==================== 转归查询 ==================== */
var DISP_FILTER = '全部';
var DISP_ROWS = [];
function dispFilter(t) {
    DISP_FILTER = t;
    document.querySelectorAll('#dispFilters [data-disp]').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-disp') === t ? 'btn-primary' : 'btn-outline');
    });
    loadDisposition();
}
function loadDisposition() {
    Clinic.get('/api/admin?action=ana_disposition&type=' + encodeURIComponent(DISP_FILTER), null, {
        onSuccess: function (j) {
            DISP_ROWS = j.data.list || [];
            renderDispTable();
        },
    });
}
function renderDispTable() {
    var needDetail = DISP_FILTER !== '全部' && DISP_FILTER !== '自主离院';
    var detailHead = needDetail
        ? ({ '住院': '住院病区', '转院': '接收医院', '死亡': '死亡原因', '其他': '其他转归情况' }[DISP_FILTER] || '补充信息')
        : '';
    var q = (document.getElementById('dispSearch').value || '').trim().toLowerCase();
    var rows = DISP_ROWS.filter(function (r) {
        if (!q) return true;
        return ((r.pname || '') + (r.flow_no || '') + (r.id_card || '')).toLowerCase().indexOf(q) !== -1;
    });
    var head = '<div class="fs-13 text-muted mb-8">' +
        (q ? rows.length + ' 条记录' : '共 ' + DISP_ROWS.length + ' 条转归记录（最近 200 条诊毕记录）') + '</div>';
    var table = '<div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>就诊时间</th><th>患者</th><th>门诊号</th><th>科室</th><th>医生</th>' +
        '<th>离院方式</th>' +
        (needDetail ? '<th>' + detailHead + '</th>' : '') +
        '</tr></thead><tbody>';
    var trs = rows.map(function (r) {
        return '<tr>' +
            '<td>' + (r.register_time || '') + '</td>' +
            '<td class="fw-600">' + (r.pname || '') + ' <span class="fs-12 text-muted">' + (r.gender || '') + '/' + (r.age_fmt || '') + '</span></td>' +
            '<td class="fs-12">' + (r.flow_no || '') + '</td>' +
            '<td>' + (r.dept_name || '') + '</td>' +
            '<td>' + (r.doctor_name || '') + '</td>' +
            '<td><span class="badge badge-primary">' + (r.disposition || '') + '</span></td>' +
            (needDetail ? '<td>' + (r.disposition_detail || '') + '</td>' : '') +
            '</tr>';
    }).join('');
    document.getElementById('dispTable').innerHTML =
        head + table + trs + '</tbody></table></div>' +
        (rows.length ? '' : '<div class="empty"><div class="empty-ico">🧭</div>' + (q ? '未找到匹配患者' : '暂无符合条件的转归记录') + '</div>');
}

/* ==================== 运营总览 ==================== */
function loadOverview(r) {
    Clinic.get('/api/admin?action=ana_overview&start=' + r.start + '&end=' + r.end, null, {
        onSuccess: function (j) {
            var k = j.data.kpi;
            document.getElementById('kPatients').textContent = anaNum(k.patients) + ' 人';
            document.getElementById('kTotal').textContent = anaMoney(k.total);
            document.getElementById('kReg').textContent = anaMoney(k.reg_fee);
            document.getElementById('kDrug').textContent = anaMoney(k.drug);
            document.getElementById('kLab').textContent = anaMoney(k.lab);
            document.getElementById('kImaging').textContent = anaMoney(k.imaging);
            document.getElementById('kProc').textContent = anaMoney(k.procedure);
        },
    });
    Clinic.get('/api/admin?action=ana_trend&start=' + r.start + '&end=' + r.end, null, {
        onSuccess: function (j) {
            var d = j.data;
            Clinic.chart.line('chartRevenue', {
                labels: d.labels,
                money: true,
                series: [
                    { name: '总收入', data: d.series.total, color: '#409eff' },
                    { name: '药费', data: d.series.drug, color: '#67c23a' },
                    { name: '检验费', data: d.series.lab, color: '#e6a23c' },
                    { name: '检查费', data: d.series.imaging, color: '#f56c6c' },
                    { name: '处置费', data: d.series.procedure, color: '#909399' },
                ],
            });
            Clinic.chart.line('chartPatients', {
                labels: d.labels,
                series: [{ name: '门诊人次', data: d.series.patients, color: '#409eff' }],
            });
        },
    });
}

/* ==================== 科室统计 ==================== */
function loadDept(r) {
    Clinic.get('/api/admin?action=ana_dept&start=' + r.start + '&end=' + r.end, null, {
        onSuccess: function (j) {
            DEPT_ROWS = j.data.rows || [];
            renderDeptTable();
            Clinic.chart.bars('chartDept', {
                labels: DEPT_ROWS.map(function (x) { return x.dept_name; }),
                data: DEPT_ROWS.map(function (x) { return x.total; }),
                color: '#409eff', money: true,
            });
        },
    });
}
var DEPT_ROWS = [];
var DEPT_TYPE = '';
function deptTypeFilter(btn, t) {
    DEPT_TYPE = t;
    document.querySelectorAll('#deptTypeTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-dtype') || '') === t ? 'btn-primary' : 'btn-outline');
    });
    renderDeptTable();
}
function renderDeptTable() {
    var q = (document.getElementById('deptSearch').value || '').trim().toLowerCase();
    var rows = DEPT_ROWS.filter(function (x) {
        if (DEPT_TYPE !== '' && (x.dept_type || 'clinic') !== DEPT_TYPE) return false;
        return !q || (x.dept_name || '').toLowerCase().indexOf(q) !== -1;
    });
    var html = '<div class="fs-13 text-muted mb-8">' +
        (q ? rows.length + ' 个科室' : (DEPT_TYPE === '' ? '共 ' + DEPT_ROWS.length + ' 个科室有运营数据' : (DEPT_TYPE === 'clinic' ? '门诊科室共 ' + rows.length + ' 个有运营数据' : '急诊科室共 ' + rows.length + ' 个有运营数据'))) + '</div>';
    if (!rows.length) {
        html += '<div class="empty">' + (q ? '未找到匹配科室' : '该时间段暂无科室运营数据') + '</div>';
    } else {
        html += '<div class="table-wrap"><table class="table"><thead><tr>' +
            '<th>科室</th><th>门诊人次</th><th>挂号费</th><th>药费</th><th>检验费</th><th>检查费</th><th>处置费</th><th>合计收入</th></tr></thead><tbody>';
        rows.forEach(function (x) {
            html += '<tr><td class="fw-600">' + x.dept_name + '</td>' +
                '<td>' + anaNum(x.patients) + '</td>' +
                '<td>' + anaMoney(x.reg_fee) + '</td><td>' + anaMoney(x.drug) + '</td>' +
                '<td>' + anaMoney(x.lab) + '</td><td>' + anaMoney(x.imaging) + '</td>' +
                '<td>' + anaMoney(x.procedure) + '</td>' +
                '<td class="fw-600">' + anaMoney(x.total) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }
    document.getElementById('deptTable').innerHTML = html;
}

/* ==================== 医生统计 ==================== */
var DOC_ROWS = [];
function loadDoctor(r) {
    r = r || anaRange();
    var dept = document.getElementById('docDeptSel').value || 0;
    Clinic.get('/api/admin?action=ana_doctor&start=' + r.start + '&end=' + r.end + '&dept_id=' + dept, null, {
        onSuccess: function (j) {
            DOC_ROWS = j.data.rows || [];
            renderDoctorTable();
        },
    });
}
function renderDoctorTable() {
    var q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    var rows = DOC_ROWS.filter(function (x) {
        if (!q) return true;
        return ((x.doctor_name || '') + (x.emp_no || '') + (x.title || '')).toLowerCase().indexOf(q) !== -1;
    });
    var html = '<div class="fs-13 text-muted mb-8">' +
        (q ? rows.length + ' 名医生' : '共 ' + DOC_ROWS.length + ' 名医生（按合计收入排序；收入口径=该医生开单且已缴费的项目）') + '</div>';
    if (!rows.length) {
        html += '<div class="empty">' + (q ? '未找到匹配医生' : '该时间段暂无医生运营数据') + '</div>';
    } else {
        html += '<div class="table-wrap"><table class="table"><thead><tr>' +
            '<th>工号</th><th>医生</th><th>职称</th><th>接诊人次</th><th>药费（处方）</th><th>检验费</th><th>检查费</th><th>处置费</th><th>开单收入合计</th></tr></thead><tbody>';
        rows.forEach(function (x) {
            html += '<tr><td class="fs-12 text-muted">' + (x.emp_no || '—') + '</td>' +
                '<td class="fw-600">' + (x.doctor_name || '—') + '</td>' +
                '<td>' + (x.title || '—') + '</td>' +
                '<td>' + anaNum(x.visits) + '</td>' +
                '<td>' + anaMoney(x.drug) + '</td><td>' + anaMoney(x.lab) + '</td>' +
                '<td>' + anaMoney(x.imaging) + '</td><td>' + anaMoney(x.procedure) + '</td>' +
                '<td class="fw-600">' + anaMoney(x.total) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }
    document.getElementById('doctorTable').innerHTML = html;
}

/* ==================== 自定义统计 ==================== */
function loadCustom() {
    var r = anaRange();
    var group = document.getElementById('cusGroup').value;
    var metrics = [];
    document.querySelectorAll('.cusMetric:checked').forEach(function (c) { metrics.push(c.value); });
    if (!metrics.length) { Clinic.toast.warning('请至少选择一个统计指标'); return; }
    Clinic.get('/api/admin?action=ana_custom&start=' + r.start + '&end=' + r.end +
        '&group_by=' + group + '&metrics=' + metrics.join(','), null, {
        onSuccess: function (j) {
            var d = j.data;
            var metricNames = { patients: '门诊人次', reg_fee: '挂号费', drug: '药费', lab: '检验费', imaging: '检查费', procedure: '处置费', total: '总收入' };
            var moneySet = ['reg_fee', 'drug', 'lab', 'imaging', 'procedure', 'total'];
            // 折线图：时间维度用点线图，科室/医生维度用条形排行
            var series = d.metrics.map(function (mk, i) {
                return {
                    name: metricNames[mk] || mk,
                    data: d.rows.map(function (row) { return row[mk] || 0; }),
                    color: ['#409eff', '#67c23a', '#e6a23c', '#f56c6c', '#909399', '#00b7a3', '#9a6fe0'][i % 7],
                };
            });
            if (group === 'day' || group === 'month' || group === 'year') {
                Clinic.chart.line('chartCustom', { labels: d.rows.map(function (x) { return x.label; }), series: series, money: false, height: 300 });
            } else {
                Clinic.chart.bars('chartCustom', {
                    labels: d.rows.map(function (x) { return x.label; }),
                    data: d.rows.map(function (x) { return x[d.metrics[0]] || 0; }),
                    color: '#409eff',
                    money: d.metrics[0] !== 'patients',
                    rowH: 30,
                });
            }
            // 表格
            var html = '<div class="table-wrap"><table class="table"><thead><tr>';
            html += '<th>' + (group === 'dept' ? '科室' : group === 'doctor' ? '医生' : '时间') + '</th>';
            d.metrics.forEach(function (mk) { html += '<th>' + (metricNames[mk] || mk) + '</th>'; });
            html += '</tr></thead><tbody>';
            if (!d.rows.length) html += '<tr><td colspan="' + (d.metrics.length + 1) + '" class="text-muted" style="text-align:center;padding:16px">该条件下暂无数据</td></tr>';
            d.rows.forEach(function (row) {
                html += '<tr><td class="fw-600">' + row.label + '</td>';
                d.metrics.forEach(function (mk) {
                    html += '<td>' + (moneySet.indexOf(mk) >= 0 ? anaMoney(row[mk]) : anaNum(row[mk])) + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('customTable').innerHTML = html;
        },
    });
}

/* ==================== 初始化 ==================== */
(function () {
    var now = new Date();
    document.getElementById('anaStart').value = anaDate(new Date(now.getFullYear(), now.getMonth(), 1));
    document.getElementById('anaEnd').value = anaDate(now);
    // 医生统计科室筛选下拉
    var sel = document.getElementById('docDeptSel');
    <?php foreach ($depts as $d): ?>
    sel.innerHTML += '<option value="<?php echo (int)$d['id']; ?>"><?php echo e($d['name']); ?></option>';
    <?php endforeach; ?>
    loadOverview(anaRange());
})();
</script>
