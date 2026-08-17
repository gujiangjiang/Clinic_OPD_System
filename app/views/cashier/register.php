<?php
/**
 * cashier/register.php — 挂号收费
 * 说明：
 * 1. 输入身份证 → 自动校验（18位含校验码）→ 自动计算并锁定
 *    出生日期/年龄/性别；有既往登记自动填充可修改信息
 * 2. 未填写身份证：仅显示急诊科室、费用类别锁定自费
 * 3. 显示今日剩余号源，号源满提示联系医生工作站加号
 * 4. 挂号成功 → 缴费（模拟）→ 自动弹出挂号凭条打印
 */
Router::title('挂号收费');
?>
<div class="page-head">
    <div><div class="page-title">🎫 挂号收费</div><div class="page-desc">输入患者身份证信息完成挂号，缴费成功后自动打印挂号凭条</div></div>
</div>

<div class="flex gap-16" style="align-items:flex-start">
    <!-- 挂号表单 -->
    <div class="card" style="flex:1;min-width:0">
        <div class="card-title"><span>患者信息与挂号</span><span id="regNotice" class="fs-13"></span></div>

        <div class="form-row">
            <div class="form-group"><label class="form-label">身份证号码</label>
                <input class="input" id="idCard" maxlength="18" placeholder="18位身份证号（不填仅可挂急诊）" autocomplete="off">
                <div class="fs-12 text-muted mt-4" id="cardMsg"></div></div>
        </div>

        <div class="form-row">
            <div class="form-group"><label class="form-label">姓名 <span class="req">*</span></label><input class="input" id="name"></div>
            <div class="form-group"><label class="form-label">性别（自动锁定）</label>
                <select class="select" id="gender" disabled><option value="男">男</option><option value="女">女</option></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">出生日期（自动锁定）</label><input class="input" id="birth" disabled></div>
            <div class="form-group"><label class="form-label">年龄（自动锁定）</label><input class="input" id="age" disabled></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">民族</label><select class="select" id="ethnicity"><?php echo opt_options('ethnicity', '汉族'); ?></select></div>
            <div class="form-group"><label class="form-label">婚姻状况</label><select class="select" id="marital"><?php echo opt_options('marital'); ?></select></div>
            <div class="form-group"><label class="form-label">费用类别</label><select class="select" id="fee_type"><?php echo opt_options('fee_type', '自费'); ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">职业</label><select class="select" id="occupation"><?php echo opt_options('occupation'); ?></select></div>
            <div class="form-group"><label class="form-label">联系电话</label><input class="input" id="phone" maxlength="11"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">工作单位</label><input class="input" id="work_unit"></div>
            <div class="form-group"><label class="form-label">联系地址</label><input class="input" id="address"></div>
        </div>

        <div class="form-group">
            <label class="form-label">挂号科室 <span class="req">*</span></label>
            <select class="select" id="dept" onchange="updateDeptInfo()"><option value="">请先输入身份证选择科室</option></select>
            <div class="fs-12 text-muted mt-4" id="deptInfo"></div>
        </div>

        <div class="flex gap-8">
            <button class="btn btn-primary btn-lg" style="flex:1" onclick="doRegister()">挂号（¥<span id="regFee">0.00</span>）</button>
        </div>
    </div>

    <!-- 今日号源概览 -->
    <div class="card" style="width:320px;flex-shrink:0">
        <div class="card-title"><span>今日号源</span><span class="badge badge-primary" id="todayBadge"></span></div>
        <div id="slotBox" class="fs-13">输入身份证后显示门诊号源</div>
    </div>
</div>

<script>
var REG = { id_card: '', patient_no: '', visit_id: 0 };

/* ---------- 身份证输入：校验 + 自动计算 + 既往登记检索 ---------- */
document.getElementById('idCard').addEventListener('input', function () { onCardChange(); });

function onCardChange() {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    var msg = document.getElementById('cardMsg');
    var feeType = document.getElementById('fee_type');
    if (card === '') {
        // 未填身份证：仅急诊 + 自费锁定
        REG.id_card = '';
        msg.innerHTML = '';
        feeType.value = '自费';
        feeType.disabled = true;
        clearDerived();
        loadDepts('');
        document.getElementById('regNotice').innerHTML = '';
        return;
    }
    if (!Clinic.validate.idCard(card)) {
        REG.id_card = '';
        msg.innerHTML = '<span class="text-danger">身份证号码不正确，请输入正确的18位身份证号码</span>';
        feeType.disabled = false;
        clearDerived();
        loadDepts('');
        return;
    }
    REG.id_card = card;
    msg.innerHTML = '<span class="text-success">✔ 身份证校验通过</span>';
    feeType.disabled = false;
    // 自动计算并锁定
    document.getElementById('gender').value = Clinic.validate.genderFromId(card);
    document.getElementById('birth').value = Clinic.validate.birthFromId(card);
    document.getElementById('age').value = Clinic.validate.ageFromId(card) + '岁';
    // 既往登记自动获取
    Clinic.get('/api/patient?action=by_card&id_card=' + encodeURIComponent(card), null, {
        onSuccess: function (json) {
            var p = json.data.patient;
            if (p) {
                document.getElementById('name').value = p.name || '';
                document.getElementById('ethnicity').value = p.ethnicity || '汉族';
                document.getElementById('marital').value = p.marital || '';
                document.getElementById('occupation').value = p.occupation || '';
                document.getElementById('phone').value = p.phone || '';
                document.getElementById('work_unit').value = p.work_unit || '';
                document.getElementById('address').value = p.address || '';
                document.getElementById('regNotice').innerHTML = '<span class="badge badge-success">已检索到既往登记信息，可修改</span>';
            } else {
                document.getElementById('regNotice').innerHTML = '<span class="badge badge-warning">未检索到该患者信息，请完善信息后完成挂号</span>';
            }
        },
    });
    loadDepts(card);
}

function clearDerived() {
    document.getElementById('gender').value = '男';
    document.getElementById('birth').value = '';
    document.getElementById('age').value = '';
}

/* ---------- 加载可挂科室及号源 ---------- */
function loadDepts(card) {
    Clinic.get('/api/cashier?action=depts&id_card=' + encodeURIComponent(card), null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var sel = document.getElementById('dept');
            var opts = '<option value="">请选择科室</option>';
            list.forEach(function (d) {
                var label = d.name + '（' + (d.type === 'emergency' ? '急诊' : (d.remaining >= 0 ? '余号' + d.remaining : '')) + '，挂号费¥' + d.fee.toFixed(2) + '）';
                opts += '<option value="' + d.id + '" data-fee="' + d.fee + '" data-full="' + (d.full ? 1 : 0) + '">' + label + '</option>';
            });
            sel.innerHTML = opts;
            document.getElementById('todayBadge').textContent = (json.data.session === 'am' ? '上午' : '下午');
            // 号源概览
            var box = document.getElementById('slotBox');
            if (!list.length) {
                box.innerHTML = '<div class="text-muted">暂无可用科室</div>';
            } else {
                box.innerHTML = list.map(function (d) {
                    var info = d.type === 'emergency' ? '<span class="badge badge-danger">急诊·不限号</span>' :
                        (d.full ? '<span class="badge badge-danger">已满号</span>' : '<span class="badge badge-success">余' + d.remaining + '号</span>');
                    return '<div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border)">' +
                        '<span>' + d.name + '</span>' + info + '</div>';
                }).join('');
            }
            updateDeptInfo();
        },
    });
}

function updateDeptInfo() {
    var sel = document.getElementById('dept');
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { document.getElementById('regFee').textContent = '0.00'; document.getElementById('deptInfo').textContent = ''; return; }
    document.getElementById('regFee').textContent = parseFloat(opt.getAttribute('data-fee')).toFixed(2);
    document.getElementById('deptInfo').innerHTML = opt.getAttribute('data-full') == 1
        ? '<span class="text-danger">该门诊号源已满，无法挂号，可联系医生工作站加号</span>'
        : '<span class="text-success">可挂号</span>';
}

/* ---------- 提交挂号 ---------- */
function doRegister() {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    var name = document.getElementById('name').value.trim();
    var dept = document.getElementById('dept').value;
    if (!name) { Clinic.toast.warning('请填写患者姓名'); return; }
    if (!dept) { Clinic.toast.warning('请选择挂号科室'); return; }
    if (!card && document.getElementById('fee_type').value !== '自费') {
        document.getElementById('fee_type').value = '自费';
    }
    Clinic.ajax('/api/cashier', {
        action: 'register',
        id_card: card,
        name: name,
        gender: document.getElementById('gender').value,
        birth_date: document.getElementById('birth').value,
        age: document.getElementById('age').value.replace('岁', ''),
        ethnicity: document.getElementById('ethnicity').value,
        marital: document.getElementById('marital').value,
        occupation: document.getElementById('occupation').value,
        fee_type: document.getElementById('fee_type').value,
        phone: document.getElementById('phone').value.trim(),
        work_unit: document.getElementById('work_unit').value.trim(),
        address: document.getElementById('address').value.trim(),
        dept_id: dept,
    }, {
        loading: true,
        onSuccess: function (json) {
            var d = json.data;
            REG.patient_no = d.patient_no;
            REG.visit_id = d.visit_id;
            Clinic.modal.open(
                '<div class="fs-13 text-muted mb-12">挂号成功！请核对以下信息后点击【缴费】完成挂号：</div>' +
                '<div class="table-wrap"><table class="table">' +
                '<tr><th>患者唯一ID</th><td class="fw-700">' + d.patient_no + '</td></tr>' +
                '<tr><th>门诊流水号</th><td class="fw-700">' + d.flow_no + '</td></tr>' +
                '<tr><th>身份证号</th><td>' + (d.id_card || '—') + '</td></tr>' +
                '<tr><th>挂号科室</th><td>' + d.dept_name + '（第' + String(d.visit_seq).padStart(3, '0') + '号）</td></tr>' +
                '<tr><th>挂号费</th><td>¥' + parseFloat(d.fee).toFixed(2) + '</td></tr>' +
                (d.is_extra ? '<tr><th>号源</th><td><span class="badge badge-warning">医生加号</span></td></tr>' : '') +
                '</table></div>',
                {
                    title: '挂号成功',
                    buttons: [
                        { text: '取消挂号', cls: 'btn-outline', onClick: function () { Clinic.modal.close(); } },
                        { text: '💳 缴费（模拟）', cls: 'btn-success', autoClose: false, onClick: payAndPrint },
                    ],
                }
            );
        },
    });
}

/* ---------- 缴费（模拟）→ 自动弹出挂号凭条打印 ---------- */
function payAndPrint() {
    Clinic.ajax('/api/cashier', { action: 'pay_visit', visit_id: REG.visit_id }, {
        onSuccess: function (json) {
            Clinic.modal.close();
            Clinic.toast.success('缴费成功，挂号完成');
            // 自动弹出挂号凭条打印模块
            Clinic.print.load('/api/print?action=receipt&visit_id=' + REG.visit_id, null);
            // 重置表单
            document.getElementById('idCard').value = '';
            document.getElementById('name').value = '';
            clearDerived();
            onCardChange();
        },
    });
}

/* 初始加载（未填身份证状态） */
onCardChange();
</script>
