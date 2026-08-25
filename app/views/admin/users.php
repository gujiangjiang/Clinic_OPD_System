<?php
/**
 * admin/users.php — 用户管理
 * 说明：职工工号、姓名、默认密码（登录后可自行修改）、照片、
 * 学历、学位、职称、职务、个人介绍；
 * 医生/护士/检验/影像有职称选项，其余角色无；
 * 仅医生显示所属科室多选框（可多选科室看诊）。
 */
Router::title('用户管理');
?>
<div class="page-head">
    <div><div class="page-title">👥 用户管理</div><div class="page-desc">创建各科室账号，医生可关联多个科室</div></div>
    <div class="flex gap-8"><span id="impBtns" class="flex gap-8"></span><button class="btn btn-primary btn-sm" onclick="openUserForm(0)">＋ 新增用户</button></div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input class="input" id="userSearch" placeholder="🔍 快速搜索用户 / 工号 / 角色" style="width:220px" oninput="applyUserFilter()">
        <span class="flex gap-4" id="userRoleTabs" style="flex-wrap:wrap"></span>
    </div>
</div>
<div class="card" id="userList"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
var USER_ROLE = '';
var ROLE_NAMES = { admin: '系统管理员', doctor: '医生', nurse: '护士', cashier: '挂号收费员', lab: '检验技师', imaging: '影像技师', pharmacy: '药剂师' };
/* 角色子 tab（按数据动态生成） */
function buildUserTabs() {
    var roles = [];
    document.querySelectorAll('#userList tbody tr').forEach(function (tr) {
        var r = tr.getAttribute('data-role') || '';
        if (r && roles.indexOf(r) === -1) roles.push(r);
    });
    var bar = document.getElementById('userRoleTabs');
    bar.innerHTML = '<button class="btn btn-sm ' + (USER_ROLE === '' ? 'btn-primary' : 'btn-outline') + '" onclick="userRoleFilter(this,\'\')">全部</button>' +
        roles.map(function (r) {
            return '<button class="btn btn-sm ' + (USER_ROLE === r ? 'btn-primary' : 'btn-outline') + '" data-role="' + r + '" onclick="userRoleFilter(this,\'' + r + '\')">' + (ROLE_NAMES[r] || r) + '</button>';
        }).join('');
}
function userRoleFilter(btn, r) {
    USER_ROLE = r;
    document.querySelectorAll('#userRoleTabs .btn').forEach(function (b) {
        b.className = 'btn btn-sm ' + ((b.getAttribute('data-role') || '') === r ? 'btn-primary' : 'btn-outline');
    });
    applyUserFilter();
}
/* 快速搜索 + 动态计数（搜索去掉「共」，角色显示「（角色）」） */
function applyUserFilter() {
    var q = (document.getElementById('userSearch') ? document.getElementById('userSearch').value : '').trim().toLowerCase();
    var n = 0;
    document.querySelectorAll('#userList tbody tr').forEach(function (tr) {
        var hit = (USER_ROLE === '' || tr.getAttribute('data-role') === USER_ROLE) &&
                  tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) n++;
    });
    var cnt = document.getElementById('userCountDiv');
    if (!cnt) return;
    var roleLabel = USER_ROLE === '' ? '' : '（' + (ROLE_NAMES[USER_ROLE] || USER_ROLE) + '）';
    cnt.textContent = q !== '' ? '用户' + roleLabel + ' ' + n + ' 人' : (USER_ROLE === '' ? '共 ' + n + ' 个用户' : '用户' + roleLabel + '共 ' + n + ' 人');
}
/* 快速搜索：按行文本过滤 */
function quickFilter(q, boxId) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('#' + boxId + ' tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
}
Clinic.importer._reloads['user'] = loadUserList;
Clinic.importer.attach('user', 'impBtns', '人员');
function loadUserList() {
    Clinic.get('/api/admin?action=user_list', null, {
        onSuccess: function (json) {
            document.getElementById('userList').innerHTML = json.data.html;
            buildUserTabs();
            applyUserFilter();
        },
    });
}

/* 职称选项（按角色，来自 options_data.php；无职称角色隐藏） */
var TITLE_SETS = {
    doctor: <?php echo json_encode(opt_list('title_doctor'), JSON_UNESCAPED_UNICODE); ?>,
    nurse: <?php echo json_encode(opt_list('title_nurse'), JSON_UNESCAPED_UNICODE); ?>,
    lab: <?php echo json_encode(opt_list('title_lab'), JSON_UNESCAPED_UNICODE); ?>,
    imaging: <?php echo json_encode(opt_list('title_imaging'), JSON_UNESCAPED_UNICODE); ?>,
};

function onRoleChange() {
    var role = document.getElementById('f_role').value;
    var titleWrap = document.getElementById('titleWrap');
    var titleSel = document.getElementById('f_title');
    var hasTitle = !!TITLE_SETS[role];
    titleWrap.style.display = hasTitle ? '' : 'none';
    if (hasTitle) {
        var cur = titleSel.getAttribute('data-cur') || '';
        var opts = '<option value="">请选择</option>' + TITLE_SETS[role].map(function (t) {
            return '<option value="' + t + '"' + (t === cur ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
        titleSel.innerHTML = opts;
    }
    // 仅医生显示所属科室多选框
    document.getElementById('deptWrap').style.display = role === 'doctor' ? '' : 'none';
}

function openUserForm(id) {
    var mask = Clinic.modal.load('/api/admin', { action: 'user_form', id: id || 0 }, { title: id ? '编辑用户' : '新增用户' });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
        // 初始化职称下拉与科室显示
        var titleSel = document.getElementById('f_title');
        titleSel.setAttribute('data-cur', (e.detail && e.detail.title) || '');
        onRoleChange();
        // 头像点击上传预览
        var photoInp = document.getElementById('f_photo');
        if (photoInp) {
            photoInp.addEventListener('change', function () {
                var f = this.files[0];
                if (!f) return;
                var img = document.getElementById('avatarPreview').querySelector('img');
                var preview = document.getElementById('avatarPreview');
                if (img) {
                    img.src = URL.createObjectURL(f);
                } else {
                    preview.innerHTML = '<img src="' + URL.createObjectURL(f) + '"><span class="avatar-badge">📷</span>';
                }
            });
        }
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="userSave">保存</button>';
        document.getElementById('userSave').addEventListener('click', function () {
            var uname = document.getElementById('f_username').value.trim();
            if (uname && !/^[A-Za-z]/.test(uname)) {
                Clinic.toast.warning('登录用户名必须以英文字母开头，不允许纯数字或数字开头');
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', document.body.getAttribute('data-csrf'));
            fd.append('action', 'user_save');
            fd.append('id', id || 0);
            fd.append('emp_no', document.getElementById('f_emp_no').value.trim());
            fd.append('username', document.getElementById('f_username').value.trim());
            fd.append('name', document.getElementById('f_name').value.trim());
            fd.append('role', document.getElementById('f_role').value);
            fd.append('password', document.getElementById('f_password').value);
            fd.append('education', document.getElementById('f_education').value);
            fd.append('degree', document.getElementById('f_degree').value);
            var t = document.getElementById('f_title');
            fd.append('title', TITLE_SETS[document.getElementById('f_role').value] ? t.value : '');
            fd.append('position', document.getElementById('f_position').value);
            fd.append('intro', document.getElementById('f_intro').value);
            var deptIds = [];
            document.querySelectorAll('.deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
            fd.append('dept_ids', deptIds.join(','));
            fd.append('status', document.getElementById('f_status').value);
            var photo = document.getElementById('f_photo').files[0];
            if (photo) fd.append('photo', photo);

            fetch('/api/admin', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.ok) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        loadUserList();
                    } else {
                        Clinic.toast.error(json.msg || '保存失败');
                    }
                })
                .catch(function () { Clinic.toast.error('网络请求失败'); });
        });
    });
}

function delUser(id) {
    Clinic.modal.confirm('确定删除该用户？', function () {
        Clinic.ajax('/api/admin', { action: 'user_delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadUserList();
            },
        });
    });
}

loadUserList();
</script>
