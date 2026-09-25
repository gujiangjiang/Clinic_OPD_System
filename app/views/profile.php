<?php
/**
 * profile.php — 个人信息（GitHub 风格个人主页）
 * 说明：
 * 1. 左侧：头像、姓名、工号/用户名、角色；左下方：界面主题、打印偏好、密码修改
 * 2. 右侧：姓名/工号/职务（只读）+ 学历/学位/安全邮箱/个人介绍（可编辑提交审核）
 * 3. 密码修改：点击左侧【修改密码】弹出模态框（内容与原修改密码页一致，校验/后端逻辑不变）
 */
Router::title('个人信息');
$u = Auth::user();
$user = DB::one('user', 'SELECT * FROM users WHERE id=?', array($u['id']));
$pendingAudit = DB::one('core', "SELECT * FROM audits WHERE type='profile_update' AND ref_id=? AND status='pending' ORDER BY id DESC LIMIT 1", array($u['id']));
$pending = $pendingAudit ? true : false;
$pendingData = $pendingAudit ? json_decode($pendingAudit['data'], true) : null;
$pendingPhoto = $pending && is_array($pendingData) && !empty($pendingData['photo']);
$showPhoto = $pendingPhoto ? $pendingData['photo'] : $user['photo'];
?>
<div class="page-head">
    <div><div class="page-title">👤 个人信息</div><div class="page-desc">个人资料、界面偏好与账号安全</div></div>
</div>

<div class="profile-layout" style="display:flex;gap:16px;align-items:flex-start">
    <!-- ===== 左侧：资料卡 + 设置 ===== -->
    <div class="card" style="width:250px;flex-shrink:0">
        <div style="text-align:center;padding:10px 0 16px">
            <div class="avatar-picker" id="avatarPicker" onclick="pickAvatar()" style="justify-content:center">
                <span class="avatar" id="avatarEl" style="width:88px;height:88px;font-size:34px">
                    <?php if ($showPhoto && ($__ava = img_data($showPhoto)) !== ''): ?><img src="<?php echo e($__ava); ?>"><?php else: ?>👤<?php endif; ?>
                    <?php if ($pendingPhoto): ?><span class="avatar-review">审核中</span><?php endif; ?>
                </span>
            </div>
            <input type="file" id="f_photo" accept="image/*" style="display:none">
            <div class="fs-18 fw-700 mt-8"><?php echo e($user['name']); ?></div>
            <div class="fs-12 text-muted mt-4">工号 <?php echo e($user['emp_no']); ?> ｜ 用户名 <?php echo e($user['username']); ?></div>
            <div class="mt-8"><?php echo badge_html('primary', Auth::roleName($user['role'])); ?></div>
            <?php if ($pendingPhoto): ?><div class="fs-12 text-muted mt-8">头像审核中，通过后生效</div><?php endif; ?>
        </div>

        <div class="setting-sec-title" style="margin-top:0">🎨 界面主题</div>
        <div class="form-group" style="margin-bottom:10px">
            <select class="select" id="f_theme" onchange="saveTheme()">
                <option value="auto"<?php echo $user['theme'] === 'auto' ? ' selected' : ''; ?>>自动模式</option>
                <option value="light"<?php echo $user['theme'] === 'light' ? ' selected' : ''; ?>>明亮模式</option>
                <option value="dark"<?php echo $user['theme'] === 'dark' ? ' selected' : ''; ?>>夜间模式</option>
            </select>
        </div>

        <div class="setting-sec-title">🖨️ 打印偏好</div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
            <input type="checkbox" id="autoPrintChk"<?php echo !empty($user['print_auto']) ? ' checked' : ''; ?>>
            <span>自动打印</span>
        </label>
        <div class="fs-12 text-muted mt-4 mb-10">弹出打印预览后自动调起系统打印</div>

        <div class="setting-sec-title">🔑 账号安全</div>
        <button type="button" class="btn btn-outline btn-sm" style="width:100%" onclick="openPwdModal()">🔑 修改密码</button>
    </div>

    <!-- ===== 右侧：资料编辑 ===== -->
    <div class="card" style="flex:1;min-width:0">
        <?php if ($pending): ?>
        <div class="mb-12" style="background:var(--warning-soft);border-radius:var(--radius-md);padding:10px 14px;font-size:13px;color:var(--warning)">
            ⏳ 您已提交个人资料修改申请，<b>等待审核中，暂未生效</b>。审核通过后自动生效；结果将通过站内消息通知您。
        </div>
        <?php endif; ?>

        <div class="card-title"><span>📝 基本资料</span></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">姓名</label><input class="input" value="<?php echo e($user['name']); ?>" disabled></div>
            <div class="form-group"><label class="form-label">工号</label><input class="input" value="<?php echo e($user['emp_no']); ?>" disabled></div>
            <div class="form-group"><label class="form-label">用户名</label><input class="input" value="<?php echo e($user['username']); ?>" disabled></div>
        </div>
        <div class="form-row">
            <?php if (in_array($user['role'], array('doctor', 'nurse', 'lab', 'imaging'), true)): ?>
            <div class="form-group"><label class="form-label">职称</label><input class="input" value="<?php echo e($user['title'] ?: '未设置'); ?>" disabled></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">职务</label><input class="input" value="<?php echo e($user['position'] ?: '未设置'); ?>" disabled></div>
        </div>
        <div class="fs-12 text-muted mb-12">如需修改姓名、职称、职务，请联系管理员在【用户管理】中调整。</div>

        <div class="card-title"><span>🎓 学历 / 学位 / 介绍</span></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education" data-csd-search="1" data-csd-clear="1"<?php echo $pending ? ' disabled' : ''; ?>><?php echo opt_options('education', $pending && isset($pendingData['education']) ? $pendingData['education'] : $user['education']); ?></select></div>
            <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree" data-csd-search="1" data-csd-clear="1"<?php echo $pending ? ' disabled' : ''; ?>><?php echo opt_options('degree', $pending && isset($pendingData['degree']) ? $pendingData['degree'] : $user['degree']); ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">安全邮箱</label><input type="email" class="input" id="f_email" placeholder="用于密码找回与安全通知" value="<?php echo e($pending && isset($pendingData['email']) ? $pendingData['email'] : $user['email']); ?>"<?php echo $pending ? ' disabled' : ''; ?>></div>
        <div class="form-group"><label class="form-label">个人介绍</label><textarea class="textarea" id="f_intro" rows="3"<?php echo $pending ? ' disabled' : ''; ?>><?php echo e($pending && isset($pendingData['intro']) ? $pendingData['intro'] : $user['intro']); ?></textarea></div>
        <?php if ($pending): ?>
        <div class="fs-12 text-muted mb-8">申请中（等待审核），审核通过后生效。</div>
        <button type="button" class="btn btn-outline" disabled>⏳ 提交审核（待审核）</button>
        <?php else: ?>
        <div class="fs-12 text-muted mb-8">学历、学位、安全邮箱、个人介绍修改需提交管理员审核，审核通过后才生效。</div>
        <button type="button" class="btn btn-primary" onclick="submitProfileAudit()">📨 提交审核</button>
        <?php endif; ?>
    </div>
</div>

<script>
/* 自动打印偏好保存到服务器（users.print_auto，与 print.js 预览工具栏同一开关） */
(function () {
    var chk = document.getElementById('autoPrintChk');
    chk.addEventListener('change', function () {
        document.body.setAttribute('data-print-auto', chk.checked ? '1' : '0');
        Clinic.ajax('/api/auth', { action: 'print_auto', value: chk.checked ? 1 : 0 }, {
            loading: false,
            onSuccess: function (json) { Clinic.toast.success(json.msg); },
        });
    });
})();

/* 点击头像 → 触发文件选择 */
function pickAvatar() {
    var pending = document.querySelector('#avatarEl .avatar-review');
    if (pending) { Clinic.toast.warning('头像审核中，请等待管理员审核'); return; }
    document.getElementById('f_photo').click();
}

/* 选择本地照片后立即提交审核（头像半透明+「审核中」提示，通过后自动刷新显示） */
document.getElementById('f_photo').addEventListener('change', function () {
    var f = this.files[0];
    if (!f) return;
    Clinic.modal.confirm('提交头像修改申请？审核通过后生效，未通过将自动还原。', function () {
        var fd = new FormData();
        fd.append('csrf_token', document.body.getAttribute('data-csrf'));
        fd.append('action', 'profile_submit');
        fd.append('photo', f);
        var el = document.getElementById('avatarEl');
        el.innerHTML = '<img src="' + URL.createObjectURL(f) + '"><span class="avatar-review">审核中</span>';
        fetch('/api/auth', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.ok) { Clinic.toast.success(json.msg); setTimeout(function () { location.reload(); }, 800); }
                else { Clinic.toast.error(json.msg || '提交失败'); location.reload(); }
            })
            .catch(function () { Clinic.toast.error('网络请求失败'); location.reload(); });
    }, { title: '头像审核确认', okText: '确认提交' });
});

/* 主题即时生效（无需审核，保存后应用） */
function saveTheme() {
    Clinic.theme.save(document.getElementById('f_theme').value);
}

/* 提交需审核字段（学历/学位/安全邮箱/介绍） */
function submitProfileAudit() {
    var email = (document.getElementById('f_email').value || '').trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { Clinic.toast.warning('安全邮箱格式不正确'); return; }
    Clinic.modal.confirm('确定提交学历、学位、安全邮箱、个人介绍修改申请吗？审核通过后才生效。', function () {
        Clinic.ajax('/api/auth', {
            action: 'profile_submit',
            education: document.getElementById('f_education').value,
            degree: document.getElementById('f_degree').value,
            email: email,
            intro: document.getElementById('f_intro').value,
        }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                setTimeout(function () { location.reload(); }, 700);
            },
        });
    }, { title: '提交审核确认', okText: '确认提交' });
}

/* ==================== 修改密码（模态框，逻辑与原修改密码页一致） ==================== */
function openPwdModal() {
    var mask = Clinic.modal.open(
        '<div class="form-group"><label class="form-label">原密码 <span class="req">*</span></label>' +
        '<input type="password" class="input" id="old_password" autocomplete="current-password"></div>' +
        '<div class="form-group"><label class="form-label">新密码（至少6位）<span class="req">*</span></label>' +
        '<input type="password" class="input" id="new_password" autocomplete="new-password"></div>' +
        '<div class="form-group"><label class="form-label">确认新密码 <span class="req">*</span></label>' +
        '<input type="password" class="input" id="new_password2" autocomplete="new-password"></div>',
        { title: '🔑 修改密码' }
    );
    // 底部按钮区：忘记密码靠左（outline），取消/确认修改靠右
    mask.querySelector('.modal-foot').innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;width:100%">' +
        '<button type="button" class="btn btn-danger" onclick="forgotPwd()">忘记密码？</button>' +
        '<span class="flex gap-8"><button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
        '<button type="button" class="btn btn-primary" id="pwdSaveBtn">确认修改</button></span></div>';
    document.getElementById('pwdSaveBtn').addEventListener('click', savePwd);
    setTimeout(function () { document.getElementById('old_password').focus(); }, 80);
}
function savePwd() {
    var old = document.getElementById('old_password').value;
    var n1 = document.getElementById('new_password').value;
    var n2 = document.getElementById('new_password2').value;
    if (!old) { Clinic.toast.warning('请输入原密码'); return; }
    if (n1.length < 6) { Clinic.toast.warning('新密码不能少于6位'); return; }
    if (n1 !== n2) { Clinic.toast.warning('两次输入的新密码不一致'); return; }
    Clinic.ajax('/api/auth', { action: 'password', old_password: old, new_password: n1 }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            Clinic.modal.close();
        },
    });
}
function forgotPwd() {
    Clinic.modal.confirm('确定忘记密码？提交后将通知管理员审核，审核通过后密码重置为初始密码。', function () {
        Clinic.ajax('/api/auth', { action: 'forgot' }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); },
        });
    }, { title: '忘记密码', okText: '提交申请' });
}
</script>