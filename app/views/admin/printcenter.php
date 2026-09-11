<?php
/**
 * admin/printcenter.php — 统一打印中心
 * 说明：左右两栏布局——左栏就诊记录列表（支持 患者姓名/患者ID/门诊流水号/身份证号
 * 检索，按就诊时间倒序，滚动分段加载），右栏选中就诊的可打印单据
 * （挂号凭条 / 电子病历 / 申请单 / 处方 / 报告 / 诊断证明），点单据即打印预览。
 */
Router::title('打印中心');
?>
<div class="page-head">
    <div><div class="page-title">🖨️ 统一打印中心</div><div class="page-desc">集中补打挂号凭条 / 电子病历 / 申请单 / 处方 / 报告 / 诊断证明</div></div>
</div>

<!-- 检索工具条 -->
<div class="card" style="margin-bottom:14px">
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

<style>
.pc-layout { display: flex; gap: 14px; align-items: stretch; height: calc(100vh - 300px); min-height: 420px; }
.pc-left { width: 400px; flex-shrink: 0; display: flex; flex-direction: column; overflow: hidden; padding: 0; }
.pc-left-head {
    padding: 12px 14px; font-weight: 700; font-size: 14px;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
}
.pc-list { flex: 1; overflow-y: auto; padding: 10px; }
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
</style>

<script>
var PC_PAGE = 1, PC_KW = '', PC_LOADING = false, PC_HAS_MORE = false, PC_SELECTED = '';

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

/** 加载就诊列表（reset=true 重置到第一页；否则追加下一页） */
function pcLoad(reset) {
    if (PC_LOADING) return;
    PC_LOADING = true;
    if (reset) PC_PAGE = 1;
    var box = document.getElementById('pcList');
    if (reset) box.innerHTML = '<div class="text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div>';
    Clinic.get('/api/admin?action=print_visits&kw=' + encodeURIComponent(PC_KW) + '&page=' + PC_PAGE, null, {
        loading: false,
        onSuccess: function (json) {
            PC_LOADING = false;
            var d = json.data;
            var list = d.list || [];
            if (reset) {
                box.innerHTML = '';
                PC_SELECTED = '';
            }
            if (reset && !list.length) {
                box.innerHTML = '<div class="empty" style="padding:30px 0"><div class="empty-ico">🔍</div>未检索到就诊记录</div>';
            } else {
                box.insertAdjacentHTML('beforeend', list.map(pcItemHtml).join(''));
            }
            PC_HAS_MORE = !!d.has_more;
            document.getElementById('pcTotal').textContent = '共 ' + d.total + ' 条';
            // 分段加载提示（滚动到底自动加载，也保留手动按钮兜底）
            var more = document.getElementById('pcMore');
            if (more) more.remove();
            if (PC_HAS_MORE) {
                box.insertAdjacentHTML('beforeend',
                    '<div class="pc-list-more"><button class="btn btn-outline btn-sm" id="pcMore" onclick="pcMore()">加载更多</button></div>');
            }
            // 首次加载自动选中最新一条，右栏直接呈现可打印单据
            if (reset && list.length) pcPick(list[0].visit_id);
        },
        onError: function () { PC_LOADING = false; },
    });
}

function pcMore() {
    if (!PC_HAS_MORE || PC_LOADING) return;
    PC_PAGE++;
    pcLoad(false);
}

/** 关键字检索 */
function pcSearch() {
    PC_KW = document.getElementById('pcKw').value.trim();
    pcLoad(true);
}

/** 重置：清空关键字回到全部列表 */
function pcReset() {
    document.getElementById('pcKw').value = '';
    PC_KW = '';
    pcLoad(true);
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

/* 左栏滚动到底自动加载下一页 */
document.getElementById('pcList').addEventListener('scroll', function () {
    if (!PC_HAS_MORE || PC_LOADING) return;
    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 40) pcMore();
});

/* 进入页面即加载最新就诊列表 */
pcLoad(true);
</script>
