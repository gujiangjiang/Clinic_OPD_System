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
        <div class="page-desc">医生：<?php echo e($u['name']); ?><?php echo $docEmp !== '' ? '（工号 ' . e($docEmp) . '）' : ''; ?><?php echo $docTitle !== '' ? ' ｜ 职称：' . e($docTitle) : ''; ?> <span id="deptDesc">加载科室中…</span></div>
    </div>
    <div class="flex gap-8">
        <button class="btn btn-outline btn-sm" onclick="openAddSlot()">＋ 加号</button>
        <button class="btn btn-outline btn-sm" onclick="openPatientSearch()">🔍 患者查询</button>
        <button class="btn btn-outline btn-sm" onclick="openTemplateMgr()">📋 病历模板</button>
    </div>
</div>

<!-- 当前科室栏：单科室直接进入；多科室登录后先弹窗选择，可随时切换 -->
<div class="flex gap-8 mb-12" style="align-items:center;flex-wrap:wrap">
    <span id="curDeptBadge"></span>
    <button type="button" class="btn btn-outline btn-sm" id="switchDeptBtn" onclick="openDeptPicker()" style="display:none">🔄 切换科室</button>
    <span class="fs-12 text-muted" id="deptDesc">加载科室中…</span>
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

/* ---------- 加载医生科室（登录后首先进入：单科室直接进入，多科室弹窗选择） ---------- */
function loadDepts() {
    Clinic.get('/api/doctor?action=depts', null, {
        onSuccess: function (json) {
            DEPT_LIST = json.data.list || [];
            if (!DEPT_LIST.length) {
                document.getElementById('deptDesc').textContent = '您尚未关联科室，请联系管理员在【用户管理】中为您设置';
                return;
            }
            document.getElementById('deptDesc').textContent = '';
            if (DEPT_LIST.length === 1) {
                // 只有一个科室权限：直接进入该科室患者列表
                pickDept(DEPT_LIST[0].id);
            } else {
                // 多科室权限：优先恢复本次会话已选科室，否则弹出科室选择弹窗
                var saved = parseInt(sessionStorage.getItem('clinic_doc_dept') || '0', 10);
                var hasSaved = false;
                DEPT_LIST.forEach(function (d) { if (d.id === saved) hasSaved = true; });
                if (hasSaved) pickDept(saved);
                else openDeptPicker();
            }
        },
    });
}

/* ---------- 科室选择弹窗（首次进入 / 点击切换科室） ---------- */
function openDeptPicker() {
    var opts = DEPT_LIST.map(function (d) {
        return '<option value="' + d.id + '"' + (d.id === CUR_DEPT ? ' selected' : '') + '>' + d.name + '</option>';
    }).join('');
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">请选择本次登录看诊的科室</label>' +
        '<select class="select" id="pkDept">' + opts + '</select></div>' +
        '<div class="fs-12 text-muted">选择后可通过页面上方【切换科室】按钮随时更换。</div>',
        {
            title: '选择科室',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '确定', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var id = parseInt(document.getElementById('pkDept').value, 10) || 0;
                        if (!id) { Clinic.toast.warning('请选择科室'); return; }
                        Clinic.modal.close();
                        pickDept(id);
                    },
                },
            ],
        }
    );
}

/* ---------- 选定科室 ---------- */
function pickDept(id) {
    CUR_DEPT = id;
    // 记住本次会话选择的科室（刷新页面后自动恢复）
    sessionStorage.setItem('clinic_doc_dept', String(id));
    var cur = null;
    DEPT_LIST.forEach(function (d) { if (d.id === id) cur = d; });
    document.getElementById('curDeptBadge').innerHTML =
        '<span class="badge badge-primary" style="font-size:14px;padding:6px 14px">当前科室：' + (cur ? cur.name : '') + '</span>';
    // 仅多科室权限显示切换按钮
    document.getElementById('switchDeptBtn').style.display = DEPT_LIST.length > 1 ? '' : 'none';
    document.getElementById('deptDesc').textContent = cur ? '' : '科室信息异常，请联系管理员';
    loadList();
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

/* ---------- 加号（号源满时，仅限该患者本人使用） ---------- */
function openAddSlot() {
    var cur = null;
    DEPT_LIST.forEach(function (d) { if (d.id === CUR_DEPT) cur = d; });
    var depts = [{ id: CUR_DEPT, name: (cur && cur.name) || '当前科室' }];
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

/* ---------- 患者查询（ID/身份证/姓名） ---------- */
function openPatientSearch() {
    var kw = prompt('请输入患者ID / 身份证号 / 姓名：');
    if (!kw) return;
    Clinic.get('/api/patient?action=search&kw=' + encodeURIComponent(kw.trim()), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            if (!list.length) { Clinic.toast.info('未检索到该患者'); return; }
            var html = '<div class="fs-13 text-muted mb-8">检索到 ' + list.length + ' 位患者，点击查看全部就诊历史</div>' +
                list.map(function (p) {
                    return '<div class="dd-item" onclick="showPatientHistory(\'' + p.patient_no + '\')">' +
                        '<div class="flex-between"><span class="fw-600">' + p.name + '</span>' +
                        '<span class="text-muted fs-12">' + p.patient_no + ' ｜ ' + p.gender + '/' + p.age + '岁</span></div></div>';
                }).join('');
            Clinic.modal.open(html, { title: '患者查询', size: 'modal-sm' });
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
</script>
