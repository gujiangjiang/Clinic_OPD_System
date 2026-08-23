<?php
/**
 * doctor/dashboard.php — 医生工作站
 * 说明：
 * 1. 医生关联多个科室时先选择科室；单科室直接进入患者列表
 * 2. 患者列表：待就诊 / 就诊中 / 就诊完毕
 * 3. 就诊序号显示首次挂号科室（XX门诊XXX号，转科后不改变）
 * 4. 加号：号源满时医生可为指定患者加号（仅限该患者本人使用）
 */
Router::title('医生工作站');
$u = Auth::user();
$docInfo = DB::one('user', 'SELECT emp_no, title FROM users WHERE id=?', array($u['id']));
$docEmp = $docInfo ? $docInfo['emp_no'] : '';
$docTitle = $docInfo ? $docInfo['title'] : '';
?>
<div class="page-head">
    <div>
        <div class="page-title">🩺 医生工作站</div>
        <div class="page-desc">医生：<?php echo e($u['name']); ?><?php echo $docEmp !== '' ? '（工号 ' . e($docEmp) . '）' : ''; ?><?php echo $docTitle !== '' ? ' ｜ 职称：' . e($docTitle) : ''; ?> <span id="deptDescHead">加载科室中…</span></div>
    </div>
    <div class="flex gap-8" style="position:relative">
        <button class="btn btn-outline btn-sm" id="addSlotBtn" onclick="openAddSlot()">＋ 加号</button>
        <button class="btn btn-outline btn-sm" onclick="openPatientSearch()">🔍 患者查询</button>
        <button class="btn btn-outline btn-sm" onclick="openTemplateMgr()">📋 病历模板</button>
        <button class="btn btn-outline btn-sm" id="roomBtn" onclick="toggleRoomList()" style="border-color:var(--primary);color:var(--primary)">🖥️ <span id="roomName">叫号大屏：未绑定</span></button>
        <div id="roomList" style="display:none;position:absolute;top:100%;right:0;min-width:300px;max-height:340px;overflow-y:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px;z-index:100;box-shadow:0 8px 24px var(--shadow)"></div>
    </div>
</div>

<!-- 当前科室栏：单科室直接进入；多科室登录后先弹窗选择，可随时切换 -->
<div class="flex gap-8 mb-12" style="align-items:center;flex-wrap:wrap">
    <span id="curDeptBadge" data-uid="<?php echo (int)$u['id']; ?>" data-sid="<?php echo e(session_id()); ?>"></span>
    <button type="button" class="btn btn-outline btn-sm" id="switchDeptBtn" onclick="openDeptPicker()" style="display:none">🔄 切换科室</button>
    <span class="fs-12 text-muted" id="deptDescBar">加载科室中…</span>
</div>

<!-- 状态页签 -->
<div class="flex gap-8 mb-12" id="statusTabs">
    <button class="btn btn-primary btn-sm" data-tab="waiting" onclick="switchTab('waiting')">待就诊</button>
    <button class="btn btn-outline btn-sm" data-tab="visiting" onclick="switchTab('visiting')">就诊中</button>
    <button class="btn btn-outline btn-sm" data-tab="done" onclick="switchTab('done')">就诊完毕</button>
</div>

<div id="patientList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var CUR_DEPT = 0;
var CUR_TAB = 'waiting';
var DEPT_LIST = [];   // 医生关联科室列表

/* 科室信息两处展示，避免重复：
   1. 页头 deptDescHead —— 完整信息「当前科室：XX（限号/不限号科室）」（唯一显示科室名）；
   2. 科室栏 deptDescBar —— 仅显示科室性质徽章（限号科室 · 号源满时可加号 / 不限号科室），不重复科室名。 */
function setDeptDesc(text) {
    var head = document.getElementById('deptDescHead');
    if (head) head.textContent = text;
}
function setDeptBar(html) {
    var bar = document.getElementById('deptDescBar');
    if (bar) bar.innerHTML = html;
}

/* ---------- 加载医生科室（登录后首先进入：单科室直接进入，多科室弹窗选择） ---------- */
function loadDepts() {
    Clinic.get('/api/doctor?action=depts', null, {
        onSuccess: function (json) {
            DEPT_LIST = json.data.list || [];
            if (!DEPT_LIST.length) {
                setDeptDesc('您尚未关联科室，请联系管理员在【用户管理】中为您设置');
                setDeptBar('');
                document.getElementById('addSlotBtn').style.display = 'none';
                return;
            }
            setDeptDesc('');
            setDeptBar('');
            if (DEPT_LIST.length === 1) {
                // 只有一个科室权限：直接进入该科室患者列表
                pickDept(DEPT_LIST[0].id);
            } else {
                // 多科室权限：仅恢复「本账号 + 本次登录会话」内已选的科室；
                // 首次登录 / 退出重登 / 换账号一律弹出科室选择弹窗——
                // 记忆键绑定 PHP 会话 ID（登录与退出都会 regenerate，
                // 因此记忆天然只在本次登录过程中有效，无需跨登录记住）
                var saved = readSavedDept();
                var hasSaved = false;
                DEPT_LIST.forEach(function (d) { if (d.id === saved) hasSaved = true; });
                if (hasSaved) pickDept(saved);
                else openDeptPicker();
            }
        },
    });
}

/* 读取本次登录会话内保存的科室选择（同时绑定账号 ID 与 PHP 会话 ID：
   登录/退出时服务端 session_regenerate_id 使会话 ID 变化 → 记忆自动失效） */
function deptMemKey() {
    var badge = document.getElementById('curDeptBadge');
    return {
        u: badge ? String(badge.getAttribute('data-uid') || '') : '',
        s: badge ? String(badge.getAttribute('data-sid') || '') : '',
    };
}
function readSavedDept() {
    try {
        var k = deptMemKey();
        var sv = JSON.parse(sessionStorage.getItem('clinic_doc_dept') || '""');
        return (sv && String(sv.u) === k.u && String(sv.s) === k.s) ? (parseInt(sv.d, 10) || 0) : 0;
    } catch (e) { return 0; }
}

/* ---------- 科室选择弹窗（首次进入 / 点击切换科室） ----------
   复用通用科室选择组件（Clinic.deptPicker，select 模式）：
   卡片式选择、不显示挂号相关信息、当前科室标记「当前」。 */
function openDeptPicker() {
    Clinic.deptPicker.open({
        mode: 'select',
        depts: DEPT_LIST,
        currentId: CUR_DEPT,
        onSelect: function (d) { pickDept(d.id); },
    });
}

/* ---------- 选定科室 ---------- */
function pickDept(id) {
    CUR_DEPT = id;
    // 记住本次登录会话内选择的科室（绑定账号+PHP会话ID：退出重登后
    // 会话 ID 变化，记忆自动失效，重新弹出科室选择）
    sessionStorage.setItem('clinic_doc_dept', JSON.stringify((function (k) { return { u: k.u, s: k.s, d: id }; })(deptMemKey())));
    // 通知服务端记录当前科室：叫号大屏完全跟随医生端选择动态显示
    Clinic.ajax('/api/doctor', { action: 'set_dept', dept_id: id }, {});
    var cur = null;
    DEPT_LIST.forEach(function (d) { if (d.id === id) cur = d; });
    document.getElementById('curDeptBadge').innerHTML =
        '<span class="badge badge-primary" style="font-size:14px;padding:6px 14px">当前科室：' + (cur ? cur.name : '') + '</span>';
    // 仅多科室权限显示切换按钮
    document.getElementById('switchDeptBtn').style.display = DEPT_LIST.length > 1 ? '' : 'none';
    if (cur) {
        // 页头显示完整科室信息（唯一显示科室名的位置）
        setDeptDesc('当前科室：' + cur.name + (cur.limited ? '（限号科室，号源满时可加号）' : '（不限号科室）'));
        // 科室栏仅显示科室性质徽章，避免与页头重复
        setDeptBar(cur.limited
            ? '<span class="badge badge-warning">限号科室 · 号源满时可加号</span>'
            : '<span class="badge badge-gray">不限号科室</span>');
    } else {
        setDeptDesc('科室信息异常，请联系管理员');
        setDeptBar('');
    }
    // 加号功能：仅限号科室显示（急诊/不限号科室隐藏）
    document.getElementById('addSlotBtn').style.display = (cur && cur.limited) ? '' : 'none';
    loadList();
    // 切换科室后刷新大屏绑定状态
    if (typeof loadRoomList === 'function') loadRoomList();
}

function switchTab(tab) {
    CUR_TAB = tab;
    document.querySelectorAll('#statusTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.getAttribute('data-tab') === tab ? 'btn-primary' : 'btn-outline');
    });
    loadList();
}

function loadList() {
    if (!CUR_DEPT) return;
    Clinic.get('/api/doctor?action=list&status=' + CUR_TAB + '&dept_id=' + CUR_DEPT, null, {
        onSuccess: function (json) {
            document.getElementById('patientList').innerHTML = json.data.html;
        },
    });
}

/* ---------- 接诊（转科引用：新科室医生可一键引用原病历） ---------- */
function takePatient(visitId) {
    Clinic.ajax('/api/doctor', { action: 'take', visit_id: visitId }, {
        onSuccess: function (json) {
            Clinic.toast.success('接诊成功');
            var url = '/doctor/emr?visit_id=' + visitId;
            if (json.data.ref_record_id > 0) url += '&ref=' + json.data.ref_record_id;
            location.href = url;
        },
    });
}

/* ---------- 患者就诊历史 ---------- */
function showPatientHistory(patientNo) {
    Clinic.get('/api/patient?action=history&patient_no=' + encodeURIComponent(patientNo), null, {
        onSuccess: function (json) {
            Clinic.modal.open(json.data.html, { title: '患者就诊历史', size: 'modal-lg' });
        },
    });
}

/* ---------- 加号（号源满时，仅限该患者本人使用；仅限号科室可用） ---------- */
function openAddSlot() {
    var cur = null;
    DEPT_LIST.forEach(function (d) { if (d.id === CUR_DEPT) cur = d; });
    if (!cur || !cur.limited) { Clinic.toast.warning('该科室为不限号科室，无需加号'); return; }
    var depts = [{ id: CUR_DEPT, name: cur.name }];
    var opts = '<option value="' + (CUR_DEPT || '') + '">' + (depts[0].name || '请选择') + '</option>';
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">科室</label><select class="select" id="asDept">' + opts + '</select></div>' +
        '<div class="form-group"><label class="form-label">患者身份证号码 <span class="req">*</span></label>' +
        '<input class="input" id="asCard" placeholder="18位身份证号"></div>' +
        '<div class="form-group"><label class="form-label">患者姓名 <span class="req">*</span></label>' +
        '<input class="input" id="asName" placeholder="请输入患者姓名"></div>' +
        '<div class="fs-12 text-muted">加号成功后，仅该患者凭本人身份证在挂号处可挂此科室号源。</div>',
        {
            title: '医生加号',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '确认加号', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var card = document.getElementById('asCard').value.trim().toUpperCase();
                        if (!Clinic.validate.idCard(card)) { Clinic.toast.warning('请输入正确的18位身份证号码'); return; }
                        var name = document.getElementById('asName').value.trim();
                        if (!name) { Clinic.toast.warning('请填写患者姓名'); return; }
                        Clinic.ajax('/api/doctor', {
                            action: 'add_slot',
                            dept_id: document.getElementById('asDept').value,
                            id_card: card,
                            name: name,
                        }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                            },
                        });
                    },
                },
            ],
        }
    );
}

/* ---------- 患者查询（ID/身份证/姓名，弹窗内输入，不阻断操作） ---------- */
function openPatientSearch() {
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">患者ID / 身份证号 / 姓名</label>' +
        '<input class="input" id="psKw" placeholder="请输入患者ID / 身份证号 / 姓名" ' +
        'onkeydown="if(event.key===\'Enter\')doPatientSearch()"></div>' +
        '<div id="psResult" class="fs-13"></div>',
        {
            title: '患者查询',
            size: 'modal-sm',
            buttons: [
                { text: '关闭', cls: 'btn-outline' },
                { text: '查 询', cls: 'btn-primary', autoClose: false, onClick: doPatientSearch },
            ],
        }
    );
    setTimeout(function () {
        var el = document.getElementById('psKw');
        if (el) el.focus();
    }, 80);
}

function doPatientSearch() {
    var kw = document.getElementById('psKw').value.trim();
    if (!kw) { Clinic.toast.warning('请输入患者ID / 身份证号 / 姓名'); return; }
    var box = document.getElementById('psResult');
    box.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
    Clinic.get('/api/patient?action=search&kw=' + encodeURIComponent(kw), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            if (!list.length) { box.innerHTML = '<div class="text-muted">未检索到该患者</div>'; return; }
            box.innerHTML = '<div class="fs-13 text-muted mb-8">检索到 ' + list.length + ' 位患者，点击查看全部就诊历史</div>' +
                list.map(function (p) {
                    return '<div class="dd-item" style="cursor:pointer" onclick="showPatientHistory(\'' + p.patient_no + '\')">' +
                        '<div class="flex-between"><span class="fw-600">' + p.name + '</span>' +
                        '<span class="text-muted fs-12">' + p.patient_no + ' ｜ ' + p.gender + '/' + (p.age_fmt || Clinic.validate.formatAge(p.birth_date)) + '</span></div></div>';
                }).join('');
        },
    });
}

/* ---------- 病历模板管理（创建/我的模板） ---------- */
function openTemplateMgr() {
    Clinic.get('/api/template?action=list&dept_id=' + CUR_DEPT, null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var scopeNames = { personal: '个人', department: '全科', hospital: '全院' };
            var myHtml = list.filter(function (t) { return t.scope === 'personal'; }).map(function (t) {
                return '<div class="flex-between dd-item" style="padding:8px 12px;border-bottom:1px solid var(--border)">' +
                    '<span>' + t.name + '</span>' +
                    '<button class="btn btn-outline btn-sm" onclick="delTemplate(' + t.id + ')">删除</button></div>';
            }).join('');
            var shareHtml = list.filter(function (t) { return t.scope !== 'personal'; }).map(function (t) {
                return '<div class="flex-between dd-item" style="padding:8px 12px;border-bottom:1px solid var(--border)">' +
                    '<span>' + t.name + ' <span class="badge badge-gray">' + (scopeNames[t.scope] || '') + '</span></span></div>';
            }).join('');
            Clinic.modal.open(
                '<div class="fs-13 fw-600 mb-8">我的模板（' + list.filter(function (t) { return t.scope === 'personal'; }).length + '）</div>' +
                (myHtml || '<div class="fs-13 text-muted mb-12">暂无个人模板</div>') +
                '<div class="fs-13 fw-600 mb-8">共享模板（全科/全院，审核后生效）</div>' +
                (shareHtml || '<div class="fs-13 text-muted mb-12">暂无共享模板</div>') +
                '<div class="form-row mt-8">' +
                '<div class="form-group"><label class="form-label">模板名称</label><input class="input" id="tplName"></div>' +
                '<div class="form-group"><label class="form-label">范围</label><select class="select" id="tplScope">' +
                '<option value="personal">个人（即时生效）</option>' +
                '<option value="department">全科（需审核）</option>' +
                '<option value="hospital">全院（需审核）</option></select></div></div>' +
                '<div class="form-group"><label class="form-label">模板内容</label><textarea class="textarea" id="tplContent" rows="4" placeholder="JSON：{\"chief_complaint\":\"主诉\",\"present_illness\":\"现病史\",\"past_history\":\"既往史\",\"allergy_history\":\"过敏史\"}"></textarea></div>' +
                '<div class="fs-12 text-muted">提示：也可在病历编辑页应用现有模板后保存为模板。</div>',
                {
                    title: '病历模板管理',
                    size: 'modal-lg',
                    buttons: [
                        { text: '关闭', cls: 'btn-outline' },
                        {
                            text: '保存模板', cls: 'btn-primary', autoClose: false,
                            onClick: function () {
                                var name = document.getElementById('tplName').value.trim();
                                if (!name) { Clinic.toast.warning('请填写模板名称'); return; }
                                var content = document.getElementById('tplContent').value.trim() || '{}';
                                try { JSON.parse(content); } catch (e) { Clinic.toast.warning('模板内容需为合法 JSON'); return; }
                                Clinic.ajax('/api/template', {
                                    action: 'save', name: name,
                                    scope: document.getElementById('tplScope').value,
                                    content: content,
                                }, {
                                    onSuccess: function (json) {
                                        Clinic.toast.success(json.msg);
                                        Clinic.modal.close();
                                    },
                                });
                            },
                        },
                    ],
                }
            );
        },
    });
}

function delTemplate(id) {
    Clinic.modal.confirm('确定删除该模板？', function () {
        Clinic.ajax('/api/template', { action: 'delete', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); openTemplateMgr(); },
        });
    });
}

/* 页面加载：首先加载科室（单科室直接进入，多科室弹窗选择） */
loadDepts();

/* ==================== 叫号大屏绑定 ==================== */
var ROOM_BOUND = null;   // 当前绑定诊室 {id, name}
var ROOM_TIMER = null;

/* 刷新大屏下拉列表（含在线状态/占用/绑定） */
function loadRoomList() {
    if (!CUR_DEPT) { renderRoomList([]); return; }
    Clinic.get('/api/doctor?action=get_available_rooms&dept_id=' + CUR_DEPT, null, {
        onSuccess: function (json) {
            ROOM_BOUND = json.data.bound;
            renderRoomList(json.data.list || []);
        },
        onError: function () { renderRoomList([]); },
    });
}

function renderRoomList(list) {
    var box = document.getElementById('roomList');
    var btn = document.getElementById('roomName');
    // 更新右上角按钮文案（含绑定状态）
    btn.textContent = ROOM_BOUND ? '叫号大屏：' + ROOM_BOUND.name + '（已绑定）' : '叫号大屏：未绑定';
    if (!list.length) {
        box.innerHTML = '<div class="fs-13 text-muted text-center" style="padding:16px">该科室暂无大屏配置，请联系管理员在【叫号管理】中新建</div>';
        return;
    }
    var rows = list.map(function (r) {
        var icon = r.status === 'available' ? '🟢' : (r.status === 'bound' ? '🔵' : (r.status === 'occupied' ? '🟡' : '🔴'));
        var disabled = !r.selectable;
        // 已绑定（bound）：点击 = 解绑；空闲（available）：点击 = 绑定
        var action = '';
        if (r.status === 'bound') {
            action = 'onclick="unbindRoom(\'' + r.id + '\')"';
        } else if (r.status === 'available') {
            action = 'onclick="bindRoom(\'' + r.id + '\')"';
        }
        var cls = r.status === 'bound' ? 'style="background:var(--primary-soft);border-radius:6px"' : '';
        var hint = r.status === 'bound' ? '<span class="fs-12 text-primary">（点击解绑）</span>' : '';
        return '<div class="fs-13 flex-between" style="padding:8px 10px;cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';opacity:' + (disabled ? '.55' : '1') + ';border-radius:6px"' + cls + ' ' + action + '>' +
            '<span>' + icon + ' ' + r.name + '</span>' +
            '<span class="fs-12" style="color:' + (r.status === 'offline' ? 'var(--danger)' : 'var(--text-muted)') + '">' + r.status_text + ' ' + hint + '</span></div>';
    }).join('');
    box.innerHTML = rows;
}

function toggleRoomList() {
    var box = document.getElementById('roomList');
    if (box.style.display === 'none') {
        loadRoomList();
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

/* 绑定大屏 */
function bindRoom(roomId) {
    Clinic.ajax('/api/doctor', { action: 'bind_room', room_id: roomId }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            loadRoomList();
            startRoomHeartbeat(roomId);
        },
        onError: function (json) {
            Clinic.toast.error((json && json.msg) || '绑定失败');
        },
    });
}

/* 解绑大屏 */
function unbindRoom(roomId) {
    Clinic.modal.confirm('确认解除与当前大屏的绑定？', function () {
        Clinic.ajax('/api/doctor', { action: 'unbind_room', room_id: roomId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                if (ROOM_TIMER) { clearInterval(ROOM_TIMER); ROOM_TIMER = null; }
                ROOM_BOUND = null;
                loadRoomList();
            },
        });
    }, { title: '解绑确认', okText: '确认解绑' });
}

/* 心跳保活（每 30 秒） */
function startRoomHeartbeat(roomId) {
    if (ROOM_TIMER) clearInterval(ROOM_TIMER);
    ROOM_TIMER = setInterval(function () {
        Clinic.ajax('/api/doctor', { action: 'room_heartbeat', room_id: roomId }, {
            onError: function () { /* 静默 */ },
        });
    }, 30000);
}

/* 点击下拉框外关闭 */
document.addEventListener('click', function (e) {
    var box = document.getElementById('roomList');
    if (box && box.style.display !== 'none' && !e.target.closest('#roomBtn') && !e.target.closest('#roomList')) {
        box.style.display = 'none';
    }
});
</script>
