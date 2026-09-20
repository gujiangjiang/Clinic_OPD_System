<?php
/**
 * admin/printcenter.php — 统一打印中心
 * 说明：左右两栏布局——左栏就诊记录列表（支持 患者姓名/患者ID/门诊流水号/身份证号
 * 检索，按就诊时间倒序，滚动分段加载），右栏选中就诊的可打印单据
 * （挂号凭条 / 电子病历 / 申请单 / 处方 / 报告 / 诊断证明），点单据即打印预览。
 */
Router::title('打印中心');
?>
<div class="list-layout">
<div class="page-head">
    <div><div class="page-title">🖨️ 统一打印中心</div><div class="page-desc">集中补打挂号凭条 / 电子病历 / 申请单 / 处方 / 报告 / 诊断证明</div></div>
</div>

<!-- 检索工具条 -->
<div class="card list-filter">
    <div class="flex gap-8">
        <input class="input" id="pcKw" placeholder="输入患者姓名 / 患者ID / 门诊流水号 / 身份证号" style="flex:1" autocomplete="off" onkeydown="if(event.key==='Enter')pcSearch()">
        <button class="btn btn-primary btn-sm" onclick="pcSearch()">查询</button>
        <button class="btn btn-outline btn-sm" onclick="pcReset()">重置</button>
    </div>
</div>

<!-- 左右两栏：左=就诊列表（分段加载） 右=可打印单据 -->
<div class="pc-layout">
    <div class="card pc-left">
        <div class="pc-left-head">📋 就诊记录 <span class="fs-12 text-muted" id="pcTotal"></span></div>
        <div class="pc-list" id="pcList"></div>
    </div>
    <div class="card pc-right" id="pcItems">
        <div class="empty" style="padding:60px 0"><div class="empty-ico">🖨️</div>从左侧选择就诊记录，查看可打印单据</div>
    </div>
</div>
</div>

<style>
.pc-layout { display: flex; gap: 14px; align-items: stretch; height: calc(100vh - 300px); min-height: 420px; }
.pc-left { width: 400px; flex-shrink: 0; display: flex; flex-direction: column; overflow: hidden; padding: 0; }
.pc-left-head {
    padding: 12px 14px; font-weight: 700; font-size: 14px;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
}
.pc-list { flex: 1; overflow-y: auto; padding: 10px 10px 18px; }
.pc-item {
    border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px;
    margin-bottom: 8px; cursor: pointer; transition: border-color .12s, background .12s;
}
.pc-item:hover { border-color: var(--primary); }
.pc-item.active { border-color: var(--primary); background: var(--primary-soft, rgba(37,99,235,.06)); }
.pc-item .pc-item-name { font-weight: 700; font-size: 14px; }
.pc-item .pc-item-meta { font-size: 12px; color: var(--muted); margin-top: 3px; }
.pc-right { flex: 1; min-width: 0; overflow-y: auto; padding: 14px; }
.pc-list-more { text-align: center; padding: 8px 0 2px; }
/* ===== 右栏四子页签（就诊/开单/缴费/报告） ===== */
.pc-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.pc-tab {
    border: 1px solid var(--border); background: var(--bg-soft, #f5f7fa); color: var(--text);
    border-radius: 18px; padding: 5px 14px; font-size: 13px; cursor: pointer;
}
.pc-tab.active { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
.pc-tabpane { min-height: 120px; }
.pc-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 9px 2px;
}
.pc-row-info { flex: 1; min-width: 0; }
.pc-row-title { font-weight: 600; font-size: 13.5px; }
.pc-row-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
.pc-row-actions { flex-shrink: 0; display: flex; align-items: center; gap: 6px; }
.pc-row-actions .btn[disabled] { opacity: .5; cursor: not-allowed; }
/* 退费/撤回：红色删除线（保留展示，便于溯源） */
.pc-dead { text-decoration: line-through; color: var(--danger, #dc2626) !important; }
/* 项目间 / 分组间虚线分隔 */
.pc-sep { border-top: 1px dashed var(--border); margin: 6px 0; }
.pc-group-title { font-weight: 700; font-size: 13px; color: var(--primary); padding: 8px 0 2px; }
.pc-empty { padding: 36px 0; text-align: center; color: var(--muted); font-size: 13px; }
</style>

<script>
var PC_SELECTED = '';
var PC_LIST = null;   // 统一动态加载封装句柄

function pcStatusBadge(s) {
    var map = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '就诊完毕', refunded: '已退费', cancelled: '已取消' };
    var cls = s === 'finished' ? 'badge-success' : (s === 'visiting' ? 'badge-warning' : 'badge-gray');
    return '<span class="badge ' + cls + '" style="font-size:11px">' + (map[s] || s) + '</span>';
}

function pcItemHtml(v) {
    return '<div class="pc-item' + (PC_SELECTED === v.visit_id ? ' active' : '') + '" data-vid="' + v.visit_id + '" onclick="pcPick(\'' + v.visit_id + '\')">' +
        '<div class="flex-between">' +
        '  <span class="pc-item-name">' + Clinic.escHtml(v.patient_name || '—') + '</span>' +
        '  ' + pcStatusBadge(v.status) +
        '</div>' +
        '<div class="pc-item-meta">' + Clinic.escHtml(v.flow_no || '') + ' ｜ ' + Clinic.escHtml(v.dept_name || '') +
        ' 第' + String(v.visit_seq).padStart(3, '0') + '号 ｜ ' + Clinic.escHtml((v.registered_at || '').substring(0, 16)) + '</div>' +
        '</div>';
}

/** 初始化就诊列表（统一动态加载封装 Clinic.infiniteList） */
function initPcList() {
    var box = document.getElementById('pcList');
    if (!box || PC_LIST || !window.Clinic || !Clinic.infiniteList) return;
    PC_LIST = Clinic.infiniteList({
        el: box,
        pageSize: 15,   // 就诊记录每页 15 条
        threshold: 40,
        totalEl: document.getElementById('pcTotal'),
        emptyHtml: '<div class="empty" style="padding:30px 0"><div class="empty-ico">🔍</div>未检索到就诊记录</div>',
        url: '/api/admin?action=print_visits&kw=' + encodeURIComponent((document.getElementById('pcKw') || {}).value || ''),
        render: function (list, isFirst) { return list.map(pcItemHtml).join(''); },
        onSuccess: function (json) {
            // 首次加载自动选中最新一条，右栏直接呈现可打印单据
            var d = json.data || {};
            var list = d.list || [];
            if (d.total && list.length && PC_SELECTED === '' ) pcPick(list[0].visit_id);
        },
    });
}

/** 搜索：重置列表到第一页 */
function pcSearch() {
    PC_SELECTED = '';
    var box = document.getElementById('pcList');
    if (box) box.innerHTML = '<div class="text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>';
    if (PC_LIST) PC_LIST.reset();
    else initPcList();
}

/** 重置：清空关键字回到全部列表 */
function pcReset() {
    document.getElementById('pcKw').value = '';
    PC_SELECTED = '';
    if (PC_LIST) PC_LIST.reset();
    else initPcList();
}

/** 选中就诊 → 右栏加载可打印单据 */
function pcPick(visitId) {
    PC_SELECTED = visitId;
    document.querySelectorAll('#pcList .pc-item').forEach(function (el) {
        el.classList.toggle('active', el.getAttribute('data-vid') === visitId);
    });
    var right = document.getElementById('pcItems');
    right.innerHTML = '<div class="text-center" style="padding:40px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>';
    Clinic.get('/api/admin?action=print_items&visit_id=' + visitId, null, {
        loading: false,
        onSuccess: function (json) {
            right.innerHTML = json.data.html || '<div class="empty">该就诊暂无可打印单据</div>';
        },
        onError: function () {
            right.innerHTML = '<div class="empty">加载失败，请重试</div>';
        },
    });
}

/** 右栏子页签切换（就诊/开单/缴费/报告） */
function pcTab(name) {
    document.querySelectorAll('#pcItems .pc-tab').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-tab') === name);
    });
    document.querySelectorAll('#pcItems .pc-tabpane').forEach(function (p) {
        p.style.display = (p.id === 'pcPane_' + name) ? '' : 'none';
    });
}

/* 进入页面即加载最新就诊列表 */
initPcList();
</script>
