<?php
/**
 * profile.php — 个人信息
 * 说明：
 * 1. 姓名/职称/职务：只读，需联系管理员修改（不提供编辑）
 * 2. 头像/界面主题：即时保存（保存资料按钮）
 * 3. 学历/学位/个人介绍：需提交审核，审核通过才生效；
 *    提交后显示灰色只读「等待审核中，暂未生效」；审核结果站内消息提醒。
 */
Router::title('个人信息');
$u = Auth::user();
$user = DB::one('user', 'SELECT * FROM users WHERE id=?', array($u['id']));
// 是否有待审核的个人资料申请
$pendingAudit = DB::one('core', "SELECT * FROM audits WHERE type='profile_update' AND ref_id=? AND status='pending' ORDER BY id DESC LIMIT 1", array($u['id']));
$pending = $pendingAudit ? true : false;
// 待审核的新值（若有，用于展示申请中内容）
$pendingData = $pendingAudit ? json_decode($pendingAudit['data'], true) : null;
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

    <?php if ($pending): ?>
    <div class="mb-12" style="background:var(--warning-soft);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--warning)">
        ⏳ 您已提交个人资料修改申请，<b>等待审核中，暂未生效</b>。审核通过后自动生效；结果将通过站内消息通知您。
    </div>
    <?php endif; ?>

    <!-- 基本信息（只读，需联系管理员修改） -->
    <div class="card-title mt-8"><span>基本信息（只读，需联系管理员修改）</span></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">姓名</label><input class="input" value="<?php echo e($user['name']); ?>" disabled></div>
        <div class="form-group"><label class="form-label">工号 / 用户名</label><input class="input" value="<?php echo e($user['emp_no'] . ' / ' . $user['username']); ?>" disabled></div>
    </div>
    <div class="form-row">
        <?php if (in_array($user['role'], array('doctor', 'nurse', 'lab', 'imaging'), true)): ?>
        <div class="form-group"><label class="form-label">职称</label><input class="input" value="<?php echo e($user['title'] ?: '未设置'); ?>" disabled></div>
        <?php endif; ?>
        <div class="form-group"><label class="form-label">职务</label><input class="input" value="<?php echo e($user['position'] ?: '未设置'); ?>" disabled></div>
    </div>
    <div class="fs-12 text-muted mb-12">如需修改姓名、职称、职务，请联系管理员在【用户管理】中调整。</div>

    <!-- 需审核字段（学历/学位/个人介绍） -->
    <div class="card-title mt-8"><span>📋 需审核修改（学历 / 学位 / 个人介绍）</span></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">学历</label><select class="select" id="f_education"<?php echo $pending ? ' disabled' : ''; ?>><?php echo opt_options('education', $pending && isset($pendingData['education']) ? $pendingData['education'] : $user['education']); ?></select></div>
        <div class="form-group"><label class="form-label">学位</label><select class="select" id="f_degree"<?php echo $pending ? ' disabled' : ''; ?>><?php echo opt_options('degree', $pending && isset($pendingData['degree']) ? $pendingData['degree'] : $user['degree']); ?></select></div>
    </div>
    <div class="form-group"><label class="form-label">个人介绍</label><textarea class="textarea" id="f_intro" rows="3"<?php echo $pending ? ' disabled' : ''; ?>><?php echo e($pending && isset($pendingData['intro']) ? $pendingData['intro'] : $user['intro']); ?></textarea></div>
    <?php if ($pending): ?>
    <div class="fs-12 text-muted mb-8" style="color:var(--text-muted)">申请中（等待审核），审核通过后生效。</div>
    <button type="button" class="btn btn-outline" disabled>⏳ 提交审核（待审核）</button>
    <?php else: ?>
    <div class="fs-12 text-muted mb-8">学历、学位、个人介绍修改需提交管理员审核，审核通过后才生效。</div>
    <button type="button" class="btn btn-primary" onclick="submitProfileAudit()">📨 提交审核</button>
    <?php endif; ?>

    <!-- 即时生效字段（头像/主题） -->
    <div class="card-title mt-24"><span>⚙️ 即时生效（头像 / 界面主题）</span></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">头像（可选）</label><input type="file" class="input" id="f_photo" accept="image/*"></div>
    </div>
    <div class="form-group">
        <label class="form-label">界面主题（跟随用户保存）</label>
        <select class="select" id="f_theme">
            <option value="auto"<?php echo $user['theme'] === 'auto' ? ' selected' : ''; ?>>自动模式（跟随系统）</option>
            <option value="light"<?php echo $user['theme'] === 'light' ? ' selected' : ''; ?>>明亮模式</option>
            <option value="dark"<?php echo $user['theme'] === 'dark' ? ' selected' : ''; ?>>夜间模式</option>
        </select>
    </div>
    <button type="button" class="btn btn-primary" onclick="saveProfile()">💾 保存资料</button>
</div>

<!-- 打印偏好：自动打印的总开关（保存到服务器，跟随用户跨设备生效）。
     打印预览工具栏里也有同名选项，但开启后预览会打印完自动关闭，
     可能来不及取消勾选——此处提供始终可达的总开关。 -->
<div class="card" style="max-width:640px;margin-top:16px">
    <div class="card-title"><span>🖨️ 打印偏好</span></div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
        <input type="checkbox" id="autoPrintChk"<?php echo !empty($user['print_auto']) ? ' checked' : ''; ?>>
        <span>自动打印（弹出打印预览后自动调起系统打印，打印完成后自动收起预览）</span>
    </label>
    <div class="fs-12 text-muted mt-8">偏好保存在服务器上、跟随账号在所有设备生效；关闭本开关后，打印时需在预览页手动点击「打印」按钮。</div>
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

/* 提交需审核字段（学历/学位/介绍） */
function submitProfileAudit() {
    Clinic.modal.confirm('确定提交学历、学位、个人介绍修改申请吗？审核通过后才生效。', function () {
        Clinic.ajax('/api/auth', {
            action: 'profile_submit',
            education: document.getElementById('f_education').value,
            degree: document.getElementById('f_degree').value,
            intro: document.getElementById('f_intro').value,
        }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                setTimeout(function () { location.reload(); }, 700);
            },
        });
    }, { title: '提交审核确认', okText: '确认提交' });
}

/* 保存即时生效字段（头像/主题） */
function saveProfile() {
    var fd = new FormData();
    fd.append('csrf_token', document.body.getAttribute('data-csrf'));
    fd.append('action', 'profile_save');
    var photo = document.getElementById('f_photo').files[0];
    if (photo) fd.append('photo', photo);
    var theme = document.getElementById('f_theme').value;
    fd.append('theme', theme);

    // 主题立即应用（不刷新页面也能看到效果）
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
