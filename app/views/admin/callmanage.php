<?php
/**
 * admin/callmanage.php — 叫号大屏/诊室管理中心
 * 说明：按科室分类管理大屏（医生诊室/检验/影像/药房/护士站）：
 * 选择科室后经鼠标悬浮窗快速新建（科室/类型自动推断，仅填名称，自动生成 Token）、
 * 预览、复制链接、重置 Token、强制释放占用、编辑（名称/类型/语音/脱敏）、删除。
 * 大屏在线状态由 screen.php 心跳维护（阈值 30 秒）。
 */
Router::title('叫号管理');

// 科室数据（含大屏统计：total 总数 / online 在线数，在线按心跳 30 秒判定）
$depts = DB::q('dept', 'SELECT * FROM departments WHERE status=1 ORDER BY sort, id');
$deptOpts = '<option value="">请选择科室</option>';
foreach ($depts as $d) {
    $deptOpts .= '<option value="' . (int)$d['id'] . '">' . e($d['name']) . '</option>';
}
$deptPickerData = array();
foreach ($depts as $d) {
    $total = (int)DB::val('clinic_rooms', 'SELECT COUNT(*) FROM clinic_rooms WHERE dept_id=?', array((int)$d['id']));
    $online = (int)DB::val('clinic_rooms', "SELECT COUNT(*) FROM clinic_rooms WHERE dept_id=? AND screen_last_heartbeat IS NOT NULL AND (strftime('%s','now','localtime') - strftime('%s',screen_last_heartbeat)) <= 30", array((int)$d['id']));
    $deptPickerData[] = array(
        'id' => (int)$d['id'],
        'name' => $d['name'],
        'type' => $d['type'],
        'room_count' => $total,
        'online_count' => $online,
    );
}
?>
<div class="page-head">
    <div><div class="page-title">🖥️ 叫号管理</div><div class="page-desc">诊室 / 大屏配置、Token 与在线状态管理</div></div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="flex-between" style="flex-wrap:wrap;gap:12px">
        <div class="flex gap-8" style="align-items:center">
            <button class="btn btn-primary" id="cmDeptBtn" onclick="openDeptPicker()">🏥 选择科室</button>
            <span class="fs-14 fw-600" id="cmDeptLabel">未选择科室</span>
            <span class="fs-13 text-muted" id="cmDeptStats"></span>
        </div>
        <button class="btn btn-outline" id="cmCreateBtn" style="display:none" onclick="createRoom(event)">＋ 新建诊室 / 大屏</button>
    </div>
    <div class="fs-12 text-muted mt-8">大屏在线状态以心跳为准（最近 30 秒内有心跳视为在线），页面每 10 秒自动刷新状态；重置 Token 后旧链接立即失效。</div>
</div>

<div id="cmList"><div class="card"><div class="empty"><div class="empty-ico">🏥</div>请先选择科室，查看该科室下的大屏配置<br><span class="fs-13 text-muted">点击上方「🏥 选择科室」按钮开始使用</span></div></div></div>

<style>
/* 大屏预览：尺寸切换工具条 + 舞台（ifarme 按所选比例锁定显示） */
.pv-toolbar { display: flex; gap: 8px; justify-content: center; margin-bottom: 10px; }
.pv-stage {
    width: 100%;
    height: min(66vh, 640px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #0f2027, #203a43);
    border-radius: 10px;
    overflow: hidden;
}
</style>
<script>
var CM_DEPT = 0;
var CM_DEPS = <?php echo json_encode($deptPickerData); ?>;
var CM_TIMER = null;

/* 科室选择模态框（复用通用组件） */
function openDeptPicker() {
    Clinic.deptPicker.open({
        mode: 'call',
        depts: CM_DEPS,
        currentId: CM_DEPT,
        // 叫号大屏场景：只显示大屏统计（🖥️ 在线/总数），不显示 门诊/急诊 徽章；
        // 含 急诊/门诊/医技/其他 四个 Tab
        showRoomStats: true,
        onSelect: function (d) { pickDept(d.id); },
    });
}

function pickDept(id) {
    CM_DEPT = id;
    var cur = null;
    CM_DEPS.forEach(function (d) { if (d.id === id) cur = d; });
    document.getElementById('cmDeptLabel').textContent = '「' + (cur ? cur.name : '') + '」';
    document.getElementById('cmCreateBtn').style.display = '';
    loadRooms();
    startAutoRefresh();
}

function loadRooms() {
    if (!CM_DEPT) return;
    Clinic.get('/api/admin?action=room_list&dept_id=' + CM_DEPT, null, {
        onSuccess: function (json) {
            document.getElementById('cmList').innerHTML = json.data.html;
            document.getElementById('cmDeptStats').textContent =
                '共 ' + json.data.total_count + ' 块大屏，' + json.data.online_count + ' 块在线';
        },
    });
}

/* 刷新全科室大屏统计（同步到选择科室模态框的数据源 CM_DEPS）：
   新建/删除/编辑/重置Token/强制释放后调用，保证模态框内「在线/总数」实时准确，
   无需整页刷新即可看到刚创建的大屏 */
function refreshDeptStats() {
    Clinic.get('/api/admin?action=room_stats', null, {
        loading: false,
        onSuccess: function (json) {
            var map = {};
            (json.data.list || []).forEach(function (s) { map[s.id] = s; });
            CM_DEPS.forEach(function (d) {
                var s = map[d.id];
                if (s) {
                    d.room_count = s.room_count;
                    d.online_count = s.online_count;
                }
            });
        },
    });
}

/* 操作按钮事件委托（服务端不再将名称/Token 嵌入 onclick，统一走 data-room-action） */
document.getElementById('cmList').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-room-action]');
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-room-id'), 10) || 0;
    if (!id) return;
    var tr = btn.closest('tr[data-token]');
    switch (btn.getAttribute('data-room-action')) {
        case 'preview': previewRoom(id); break;
        case 'copy': copyRoomLink(id, tr ? tr.getAttribute('data-token') : ''); break;
        case 'reset': resetRoomToken(id); break;
        case 'release': releaseRoom(id); break;
        case 'del': delRoom(id); break;
        case 'edit':
            editRoom(id,
                tr ? tr.getAttribute('data-room-name') : '',
                tr ? tr.getAttribute('data-room-type') : 'doctor',
                tr ? (tr.getAttribute('data-room-voice') === '1') : false,
                tr ? (tr.getAttribute('data-room-mask') === '1') : false,
                tr ? (tr.getAttribute('data-room-cross') === '1') : false);
            break;
    }
});

/* 每 10 秒自动刷新大屏在线状态 */
function startAutoRefresh() {
    if (CM_TIMER) clearInterval(CM_TIMER);
    CM_TIMER = setInterval(function () {
        if (CM_DEPT) loadRooms();
    }, 10000);
}

/* 大屏类型中文名（悬浮窗提示用） */
var CM_TYPE_NAMES = { doctor: '医生诊室', lab: '检验科', imaging: '影像科', pharmacy: '药房', nurse: '护士站' };

/* 由当前科室自动推断大屏类型（无需人工选择）：
   名称含 检验/化验→检验科、影像/放射/超声等→影像科、药→药房、护士/输液→护士站；
   否则按科室类型：医技→检验科、其他→护士站、门诊/急诊→医生诊室 */
function guessRoomType() {
    var cur = null;
    CM_DEPS.forEach(function (d) { if (d.id === CM_DEPT) cur = d; });
    var n = cur ? cur.name : '';
    var t = cur ? cur.type : '';
    if (/影像|放射|CT|MR|DR|超声|B超|心电/.test(n)) return 'imaging';
    if (/检验|化验/.test(n)) return 'lab';
    if (/药/.test(n)) return 'pharmacy';
    if (/护士|输液|注射/.test(n)) return 'nurse';
    if (t === 'tech') return 'lab';
    if (t === 'other') return 'nurse';
    return 'doctor';
}

/* 新建诊室：跟随鼠标位置弹出的轻量悬浮窗。
   科室固定为当前科室、大屏类型按科室自动推断，仅需输入诊室/窗口名称 */
function createRoom(ev) {
    if (!CM_DEPT) { Clinic.toast.warning('请先选择科室'); return; }
    closeQuickPop();
    var cur = null;
    CM_DEPS.forEach(function (d) { if (d.id === CM_DEPT) cur = d; });
    var rt = guessRoomType();
    var pop = document.createElement('div');
    pop.id = 'cmQuickPop';
    pop.style.cssText = 'position:fixed;z-index:3000;width:280px;background:#fff;border-radius:10px;' +
        'border:1px solid var(--bd,#e5e7eb);box-shadow:0 10px 40px rgba(0,0,0,.18);padding:14px;';
    pop.innerHTML =
        '<div class="fs-14 fw-600 mb-4">＋ 新建诊室 / 大屏</div>' +
        '<div class="fs-12 text-muted mb-8">科室「' + (cur ? cur.name : '') + '」 · 类型：' + CM_TYPE_NAMES[rt] + '（按科室自动）</div>' +
        '<input class="input" id="cqName" placeholder="如：骨科1诊室 / 抽血室2 / 西药1号窗口">' +
        '<div class="flex gap-8 mt-8"><button class="btn btn-primary btn-sm" id="cqOk" style="flex:1">创建</button>' +
        '<button class="btn btn-outline btn-sm" id="cqCancel" style="flex:1">取消</button></div>';
    document.body.appendChild(pop);
    /* 定位到鼠标附近，并防止超出视口 */
    var x = (ev && ev.clientX) ? ev.clientX : (window.innerWidth / 2 - 140);
    var y = (ev && ev.clientY) ? ev.clientY : 120;
    pop.style.left = Math.max(8, Math.min(x + 12, window.innerWidth - 292)) + 'px';
    pop.style.top = Math.max(8, Math.min(y + 12, window.innerHeight - 200)) + 'px';
    document.getElementById('cqName').focus();
    function submit() {
        var name = document.getElementById('cqName').value.trim();
        if (!name) { Clinic.toast.warning('请填写诊室 / 窗口名称'); return; }
        Clinic.ajax('/api/admin', {
            action: 'room_create', dept_id: CM_DEPT, room_name: name, room_type: rt,
        }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                closeQuickPop();
                loadRooms();
                refreshDeptStats();
            },
        });
    }
    document.getElementById('cqOk').addEventListener('click', submit);
    document.getElementById('cqCancel').addEventListener('click', closeQuickPop);
    document.getElementById('cqName').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') submit();
    });
    /* 点击悬浮窗外部 / Esc 关闭（延迟绑定避免本次点击立即触发） */
    setTimeout(function () {
        document.addEventListener('mousedown', cmPopOutside, true);
        document.addEventListener('keydown', cmPopEsc, true);
    }, 0);
}

function cmPopOutside(e) {
    var pop = document.getElementById('cmQuickPop');
    if (pop && !pop.contains(e.target)) closeQuickPop();
}

function cmPopEsc(e) {
    if (e.key === 'Escape') closeQuickPop();
}

function closeQuickPop() {
    var pop = document.getElementById('cmQuickPop');
    if (pop) pop.remove();
    document.removeEventListener('mousedown', cmPopOutside, true);
    document.removeEventListener('keydown', cmPopEsc, true);
}

/* 编辑诊室 */
function editRoom(id, name, type, voice, mask, crossDay) {
    // 获取当前温馨提示（从列表行获取，data-tips 存 JSON 数组字符串）
    var tips = '';
    var tipInterval = 5;
    document.querySelectorAll('#cmList tr[data-tips]').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-id'), 10) === id) {
            tips = tr.getAttribute('data-tips') || '';
            tipInterval = parseInt(tr.getAttribute('data-interval'), 10) || 5;
        }
    });
    // JSON 数组 → 每行一条（兼容旧数据缺失引号/括号的裸文本）
    var tipsText = '';
    try {
        var arr = JSON.parse(tips);
        tipsText = Array.isArray(arr) ? arr.join('\n') : String(tips);
    } catch (e) {
        tipsText = String(tips);
    }
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">诊室 / 窗口名称 <span class="req">*</span></label>' +
        '<input class="input" id="erName" value="' + Clinic.escHtml(name) + '"></div>' +
        '<div class="form-group"><label class="form-label">类型</label>' +
        '<select class="select" id="erType">' +
        '<option value="doctor"' + (type === 'doctor' ? ' selected' : '') + '>医生诊室</option>' +
        '<option value="lab"' + (type === 'lab' ? ' selected' : '') + '>检验科</option>' +
        '<option value="imaging"' + (type === 'imaging' ? ' selected' : '') + '>影像科</option>' +
        '<option value="pharmacy"' + (type === 'pharmacy' ? ' selected' : '') + '>药房</option>' +
        '<option value="nurse"' + (type === 'nurse' ? ' selected' : '') + '>护士站</option>' +
        '</select></div>' +
        '<label class="flex gap-4 mb-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="erVoice"' + (voice ? ' checked' : '') + '> 语音播报</label>' +
        '<label class="flex gap-4 mb-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="erMask"' + (mask ? ' checked' : '') + '> 患者姓名脱敏（张*三）</label>' +
        '<label class="flex gap-4 mb-4" style="font-size:13px;cursor:pointer" title="默认只叫当天号源；开启后在一次登录（绑定）期间支持跨0点继续叫号（如急诊夜班）"><input type="checkbox" id="erCross"' + (crossDay ? ' checked' : '') + '> 允许跨天叫号（急诊夜班场景）</label>' +
        '<div class="fs-12 text-muted mb-8">跨天规则：不允许时跨天自动清空前一天所有叫号记录；允许时仅本次登录内跨0点延续，重新登录后仍只显示当天号源。</div>' +
        '<div class="card-title mt-8"><span>💡 温馨提示</span></div>' +
        '<div class="fs-12 text-muted mb-4">每行一条，留空则使用默认提示；多条提示自动轮播切换。</div>' +
        '<textarea class="textarea" id="erTips" rows="4" placeholder="请输入温馨提示，每行一条">' + Clinic.escHtml(tipsText) + '</textarea>' +
        '<div class="form-row mt-4"><div class="form-group"><label class="form-label">轮播间隔（秒）</label>' +
        '<input class="input" id="erInterval" type="number" min="2" max="60" value="' + tipInterval + '"></div></div>',
        {
            title: '编辑诊室',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '保存', cls: 'btn-primary', autoClose: false, onClick: function () {
                    var nm = document.getElementById('erName').value.trim();
                    if (!nm) { Clinic.toast.warning('请填写名称'); return; }
                    // 将多行文本转为 JSON 数组
                    var tipsLines = document.getElementById('erTips').value.trim().split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s; });
                    var tipsJson = tipsLines.length ? JSON.stringify(tipsLines) : '';
                    Clinic.ajax('/api/admin', {
                        action: 'room_save', id: id, room_name: nm,
                        room_type: document.getElementById('erType').value,
                        enable_voice: document.getElementById('erVoice').checked ? 1 : 0,
                        enable_mask: document.getElementById('erMask').checked ? 1 : 0,
                        allow_cross_day: document.getElementById('erCross').checked ? 1 : 0,
                        screen_tips: tipsJson,
                        tip_interval: parseInt(document.getElementById('erInterval').value, 10) || 5,
                    }, {
                        onSuccess: function (json) { Clinic.toast.success(json.msg); Clinic.modal.close(); loadRooms(); refreshDeptStats(); },
                    });
                } },
            ],
        }
    );
}

/* 预览大屏：弹窗 iframe 加载，支持切换屏幕尺寸（纵向 9:16 / 方形 1:1 / 横向 16:9 / 自由） */
function previewRoom(id) {
    // 从列表行取 token（room_list 已将 token 渲染在 data-token）
    var token = getTokenById(id);
    if (!token) { Clinic.toast.warning('无法获取大屏链接'); return; }
    var RATIOS = {
        '9_16': { w: 9, h: 16, label: '纵向 9:16' },
        '1_1':  { w: 1, h: 1,  label: '方形 1:1' },
        '16_9': { w: 16, h: 9, label: '横向 16:9' },
        'free': { w: 0, h: 0,  label: '自由' },
    };
    var cur = '16_9';
    Clinic.modal.open(
        '<div class="pv-toolbar">' +
            Object.keys(RATIOS).map(function (k) {
                return '<button type="button" class="btn btn-outline btn-sm" data-ratio="' + k + '">' + RATIOS[k].label + '</button>';
            }).join('') +
        '</div>' +
        '<div class="pv-stage" id="pvStage">' +
        '  <iframe id="pvFrame" src="/screen.php?token=' + token + '" style="border:0;border-radius:8px;background:#111"></iframe>' +
        '</div>',
        { title: '大屏预览', size: 'modal-lg', buttons: [{ text: '关闭', cls: 'btn-outline' }] }
    );
    var stage = document.getElementById('pvStage');
    var frame = document.getElementById('pvFrame');
    if (!stage || !frame) return;

    /* 在舞台可用尺寸内，按所选比例锁定 iframe 最大宽高（居中显示）；
       自由模式不限纵横比，iframe 填满舞台并随舞台尺寸自动变化（大屏按 iframe 视口自适应）；
       锁定比例时向大屏传入 pv_w/pv_h，让大屏严格按预览尺寸排版（避免文字溢出） */
    function setRatio(k) {
        var r = RATIOS[k];
        if (!r) return;
        cur = k;
        var aw = stage.clientWidth || 800;
        var ah = stage.clientHeight || 500;
        var w, h, src;
        if (r.w <= 0 || r.h <= 0) {
            // 自由：填满舞台，不限纵横比，随舞台变化自动自适应（不锁定 pv）
            frame.style.width = '100%';
            frame.style.height = '100%';
            src = '/screen.php?token=' + token;
        } else {
            if (r.w >= r.h) {
                w = aw;
                h = aw * r.h / r.w;
                if (h > ah) { h = ah; w = ah * r.w / r.h; }
            } else {
                h = ah;
                w = ah * r.w / r.h;
                if (w > aw) { w = aw; h = ah * r.w / r.h; }
            }
            w = Math.floor(w); h = Math.floor(h);
            frame.style.width = w + 'px';
            frame.style.height = h + 'px';
            src = '/screen.php?token=' + token + '&pv_w=' + w + '&pv_h=' + h;
        }
        if (frame.getAttribute('src') !== src) frame.setAttribute('src', src);
        document.querySelectorAll('.pv-toolbar [data-ratio]').forEach(function (b) {
            b.className = 'btn btn-sm ' + (b.getAttribute('data-ratio') === k ? 'btn-primary' : 'btn-outline');
        });
    }
    /* 窗口缩放时重新适配比例（弹窗关闭后元素消失自动失效） */
    var onResize = function () {
        if (!document.getElementById('pvStage') || !document.getElementById('pvFrame')) {
            window.removeEventListener('resize', onResize);
            return;
        }
        setRatio(cur);
    };
    window.addEventListener('resize', onResize);
    document.querySelectorAll('.pv-toolbar [data-ratio]').forEach(function (b) {
        b.addEventListener('click', function () { setRatio(b.getAttribute('data-ratio')); });
    });
    setTimeout(function () { setRatio(cur); }, 30);
}

/* 从列表 HTML 提取 token（按行 data-token） */
function getTokenById(id) {
    var t = '';
    document.querySelectorAll('#cmList tr[data-token]').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-id'), 10) === id) t = tr.getAttribute('data-token');
    });
    return t;
}

/* 复制完整链接 */
function copyRoomLink(id, token) {
    var url = location.origin + '/screen.php?token=' + token;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { Clinic.toast.success('大屏链接已复制'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        Clinic.toast.success('大屏链接已复制');
    }
}

/* 重置 Token */
function resetRoomToken(id) {
    Clinic.modal.confirm('重置后旧大屏链接立即失效，确认重置？', function () {
        Clinic.ajax('/api/admin', { action: 'room_reset_token', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); refreshDeptStats(); },
        });
    }, { title: '重置 Token', okText: '确认重置' });
}

/* 强制释放 */
function releaseRoom(id) {
    Clinic.modal.confirm('确认强制释放该诊室？（解除当前医生占用）', function () {
        Clinic.ajax('/api/admin', { action: 'room_release', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); refreshDeptStats(); },
        });
    }, { title: '强制释放', okText: '确认释放' });
}

/* 删除 */
function delRoom(id) {
    Clinic.modal.confirm('确认删除该诊室/大屏？（不可恢复）', function () {
        Clinic.ajax('/api/admin', { action: 'room_delete', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); refreshDeptStats(); },
        });
    }, { title: '删除确认', okText: '确认删除' });
}
</script>
