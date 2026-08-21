<?php
/**
 * profile.php — 个人信息
 * 说明：用户可维护自己的姓名/头像/学历/学位/职称/职务/介绍，
 * 以及主题偏好（明亮/夜间/自动，跟随用户保存）。
 */
Router::title('个人信息');
$u = Auth::user();
$user = DB::one('user', 'SELECT * FROM users WHERE id=?', array($u['id']));
?>
<div class="page-head">
    <div><div class="page-title">👤 个人信息</div><div class="page-desc">维护个人资料与界面偏好</div></div>
</div>
<div class="card" style="max-width:640px">
    <div class="flex gap-16" style="align-items:center;margin-bottom:16px">
        <span class="avatar" style="width:64px;height:64px;font-size:26px"><?php echo !empty($user['photo']) ? '<img src="' . e(upload_url($user['photo'])) . '" style="width:100%;height:100%;object-fit:cover">' : '👤'; ?></span>
        <div>
            <div class="fs-18 fw-700"><?php echo e($user['name']); ?></div>
            <div class="fs-13 text-muted">工号 <?php echo e($user['emp_no']); ?> ｜ 用户名 <?php echo e($user['username']); ?> ｜ <?php echo e(Auth::roleName($user['role'])); ?></div>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group"><label class="form-label">姓名</label><input class="input" id="f_name" value="<?php echo e($user['name']); ?>"></div>
        <div class="form-group"><label class="form-label">头像（可选）</label><input type="file" class="input" id="f_photo" accept="image/*"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education"><?php echo opt_options('education', $user['education']); ?></select></div>
        <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree"><?php echo opt_options('degree', $user['degree']); ?></select></div>
    </div>
    <div class="form-row">
        <?php if (in_array($user['role'], array('doctor', 'nurse', 'lab', 'imaging'), true)): ?>
        <div class="form-group"><label class="form-label">职称</label><select class="select" id="f_title"><?php echo opt_options('title_' . ($user['role'] === 'doctor' ? 'doctor' : ($user['role'] === 'nurse' ? 'nurse' : ($user['role'] === 'lab' ? 'lab' : 'imaging'))), $user['title']); ?></select></div>
        <?php endif; ?>
        <div class="form-group"><label class="form-label">职务</label><select class="select" id="f_position"><?php echo opt_options('position', $user['position']); ?></select></div>
    </div>
    <div class="form-group"><label class="form-label">个人介绍</label><textarea class="textarea" id="f_intro" rows="3"><?php echo e($user['intro']); ?></textarea></div>
    <div class="form-group">
        <label class="form-label">界面主题（跟随用户保存）</label>
        <select class="select" id="f_theme">
            <option value="auto"<?php echo $user['theme'] === 'auto' ? ' selected' : ''; ?>>自动模式（跟随系统）</option>
            <option value="light"<?php echo $user['theme'] === 'light' ? ' selected' : ''; ?>>明亮模式</option>
            <option value="dark"<?php echo $user['theme'] === 'dark' ? ' selected' : ''; ?>>夜间模式</option>
        </select>
    </div>
    <button type="button" class="btn btn-primary" onclick="saveProfile()">保存资料</button>
</div>

<script>
function saveProfile() {
    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'profile');
    fd.append('name', document.getElementById('f_name').value.trim());
    fd.append('education', document.getElementById('f_education').value);
    fd.append('degree', document.getElementById('f_degree').value);
    var t = document.getElementById('f_title');
    if (t) fd.append('title', t.value);
    fd.append('position', document.getElementById('f_position').value);
    fd.append('intro', document.getElementById('f_intro').value);
    var photo = document.getElementById('f_photo').files[0];
    if (photo) fd.append('photo', photo);

    var theme = document.getElementById('f_theme').value;
    if (theme !== document.body.getAttribute('data-theme-pref')) {
        Clinic.theme.save(theme);
    }
    fetch('/api/auth', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.ok) { Clinic.toast.success(json.msg); setTimeout(function () { location.reload(); }, 700); }
            else Clinic.toast.error(json.msg || '保存失败');
        })
        .catch(function () { Clinic.toast.error('网络请求失败'); });
}
</script>
