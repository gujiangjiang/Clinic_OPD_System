<?php
/**
 * admin/callmanage.php — 叫号大屏/诊室管理中心
 * 说明：按科室分类管理大屏（医生诊室/检验/影像/药房/护士站）：
 * 新建诊室（自动生成 Token）、预览、复制链接、重置 Token、
 * 强制释放占用、编辑（名称/类型/语音/脱敏）、删除。
 * 大屏在线状态由 screen.php 心跳维护（阈值 30 秒）。
 */
Router::title('叫号管理');

// 科室下拉（仅门诊/急诊科室）
$depts = DB::q('dept', 'SELECT * FROM departments WHERE status=1 ORDER BY sort, id');
$deptOpts = '<option value="">请选择科室</option>';
foreach ($depts as $d) {
    $deptOpts .= '<option value="' . (int)$d['id'] . '">' . e($d['name']) . '</option>';
}
?>
<div class="page-head">
    <div><div class="page-title">🖥️ 叫号管理</div><div class="page-desc">诊室 / 大屏配置、Token 与在线状态管理</div></div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="flex-between" style="flex-wrap:wrap;gap:12px">
        <div class="flex gap-8">
            <select class="select" id="cmDept" style="min-width:200px"><?php echo $deptOpts; ?></select>
            <button class="btn btn-primary" onclick="loadRooms()">查询</button>
        </div>
        <button class="btn btn-outline" onclick="createRoom()">＋ 新建诊室 / 大屏</button>
    </div>
    <div class="fs-12 text-muted mt-8">大屏在线状态以心跳为准（最近 30 秒内有心跳视为在线）；重置 Token 后旧链接立即失效。</div>
</div>

<div id="cmList"><div class="card"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div></div>

<script>
var CM_DEPT = 0;

function loadRooms() {
    CM_DEPT = parseInt(document.getElementById('cmDept').value, 10) || 0;
    if (!CM_DEPT) { Clinic.toast.warning('请先选择科室'); return; }
    Clinic.get('/api/admin?action=room_list&dept_id=' + CM_DEPT, null, {
        onSuccess: function (json) {
            document.getElementById('cmList').innerHTML = json.data.html;
        },
    });
}

/* 新建诊室：名称 + 类型（自动生成 Token） */
function createRoom() {
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">科室 <span class="req">*</span></label>' +
        '<select class="select" id="crDept">' + document.getElementById('cmDept').innerHTML + '</select></div>' +
        '<div class="form-group"><label class="form-label">诊室 / 窗口名称 <span class="req">*</span></label>' +
        '<input class="input" id="crName" placeholder="如：骨科1诊室 / 抽血室2 / 西药房1号窗口"></div>' +
        '<div class="form-group"><label class="form-label">类型</label>' +
        '<select class="select" id="crType">' +
        '<option value="doctor">医生诊室</option><option value="lab">检验科</option>' +
        '<option value="imaging">影像科</option><option value="pharmacy">药房</option><option value="nurse">护士站</option>' +
        '</select></div>',
        {
            title: '＋ 新建诊室 / 大屏',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '创建', cls: 'btn-primary', autoClose: false, onClick: function () {
                    var dept = parseInt(document.getElementById('crDept').value, 10) || 0;
                    var name = document.getElementById('crName').value.trim();
                    if (!dept) { Clinic.toast.warning('请选择科室'); return; }
                    if (!name) { Clinic.toast.warning('请填写诊室名称'); return; }
                    Clinic.ajax('/api/admin', {
                        action: 'room_create', dept_id: dept, room_name: name,
                        room_type: document.getElementById('crType').value,
                    }, {
                        onSuccess: function (json) {
                            Clinic.toast.success(json.msg);
                            Clinic.modal.close();
                            document.getElementById('cmDept').value = dept;
                            loadRooms();
                        },
                    });
                } },
            ],
        }
    );
}

/* 编辑诊室 */
function editRoom(id, name, type, voice, mask) {
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">诊室 / 窗口名称 <span class="req">*</span></label>' +
        '<input class="input" id="erName" value="' + name + '"></div>' +
        '<div class="form-group"><label class="form-label">类型</label>' +
        '<select class="select" id="erType">' +
        '<option value="doctor"' + (type === 'doctor' ? ' selected' : '') + '>医生诊室</option>' +
        '<option value="lab"' + (type === 'lab' ? ' selected' : '') + '>检验科</option>' +
        '<option value="imaging"' + (type === 'imaging' ? ' selected' : '') + '>影像科</option>' +
        '<option value="pharmacy"' + (type === 'pharmacy' ? ' selected' : '') + '>药房</option>' +
        '<option value="nurse"' + (type === 'nurse' ? ' selected' : '') + '>护士站</option>' +
        '</select></div>' +
        '<label class="flex gap-4 mb-8" style="font-size:13px;cursor:pointer"><input type="checkbox" id="erVoice"' + (voice ? ' checked' : '') + '> 语音播报</label>' +
        '<label class="flex gap-4 mb-8" style="font-size:13px;cursor:pointer"><input type="checkbox" id="erMask"' + (mask ? ' checked' : '') + '> 患者姓名脱敏（张*三）</label>',
        {
            title: '编辑诊室',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                { text: '保存', cls: 'btn-primary', autoClose: false, onClick: function () {
                    var nm = document.getElementById('erName').value.trim();
                    if (!nm) { Clinic.toast.warning('请填写名称'); return; }
                    Clinic.ajax('/api/admin', {
                        action: 'room_save', id: id, room_name: nm,
                        room_type: document.getElementById('erType').value,
                        enable_voice: document.getElementById('erVoice').checked ? 1 : 0,
                        enable_mask: document.getElementById('erMask').checked ? 1 : 0,
                    }, {
                        onSuccess: function (json) { Clinic.toast.success(json.msg); Clinic.modal.close(); loadRooms(); },
                    });
                } },
            ],
        }
    );
}

/* 预览大屏：弹窗 iframe 加载 */
function previewRoom(id) {
    var room = null;
    document.querySelectorAll('#cmList [data-room]').forEach(function (el) {
        if (parseInt(el.getAttribute('data-room'), 10) === id) room = el.getAttribute('data-token');
    });
    // 从列表行取 token（room_list 已将 token 渲染在行内）
    var token = getTokenById(id);
    if (!token) { Clinic.toast.warning('无法获取大屏链接'); return; }
    Clinic.modal.open(
        '<iframe src="/screen.php?token=' + token + '" style="width:100%;height:70vh;border:0;border-radius:8px"></iframe>',
        { title: '大屏预览', size: 'modal-lg', buttons: [{ text: '关闭', cls: 'btn-outline' }] }
    );
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
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); },
        });
    }, { title: '重置 Token', okText: '确认重置' });
}

/* 强制释放 */
function releaseRoom(id) {
    Clinic.modal.confirm('确认强制释放该诊室？（解除当前医生占用）', function () {
        Clinic.ajax('/api/admin', { action: 'room_release', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); },
        });
    }, { title: '强制释放', okText: '确认释放' });
}

/* 删除 */
function delRoom(id) {
    Clinic.modal.confirm('确认删除该诊室/大屏？（不可恢复）', function () {
        Clinic.ajax('/api/admin', { action: 'room_delete', id: id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); loadRooms(); },
        });
    }, { title: '删除确认', okText: '确认删除' });
}
</script>
