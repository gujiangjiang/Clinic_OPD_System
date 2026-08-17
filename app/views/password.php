<?php
/**
 * password.php — 修改密码
 * 说明：所有登录用户均可修改自己的密码（管理员首次进入系统也会提醒）。
 */
Router::title('修改密码');
$u = Auth::user();
?>
<div class="page-head">
    <div><div class="page-title">🔑 修改密码</div><div class="page-desc">为保障账号安全，请定期修改密码</div></div>
</div>
<div class="card" style="max-width:520px">
    <div class="form-group">
        <label class="form-label">原密码 <span class="req">*</span></label>
        <input type="password" class="input" id="old_password" autocomplete="current-password">
    </div>
    <div class="form-group">
        <label class="form-label">新密码（至少6位）<span class="req">*</span></label>
        <input type="password" class="input" id="new_password" autocomplete="new-password">
    </div>
    <div class="form-group">
        <label class="form-label">确认新密码 <span class="req">*</span></label>
        <input type="password" class="input" id="new_password2" autocomplete="new-password">
    </div>
    <button type="button" class="btn btn-primary" onclick="savePwd()">确认修改</button>
    <button type="button" class="btn btn-outline" style="margin-left:10px" onclick="forgotPwd()">忘记密码？</button>
    <div class="fs-12 text-muted mt-8">忘记密码时点击【忘记密码？】，系统将通知管理员审核，审核通过后您的密码将重置为初始密码，并可在站内消息中直接设置新密码。</div>
</div>

<script>
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
            document.getElementById('old_password').value = '';
            document.getElementById('new_password').value = '';
            document.getElementById('new_password2').value = '';
        },
    });
}

/* 忘记密码：提交重置申请（通知管理员审核） */
function forgotPwd() {
    Clinic.modal.confirm('确定忘记密码？提交后将通知管理员审核，审核通过后密码重置为初始密码。', function () {
        Clinic.ajax('/api/auth', { action: 'forgot' }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
            },
        });
    }, { title: '忘记密码', okText: '提交申请' });
}
</script>
