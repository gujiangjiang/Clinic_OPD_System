<?php
/**
 * cashier/register.php — 挂号收费
 * 说明：
 * 1. 输入身份证 → 自动校验（18位含校验码）→ 自动计算并锁定
 *    出生日期/年龄/性别；有既往登记自动填充可修改信息
 * 2. 未填写身份证：仅可挂急诊科室、费用类别锁定自费
 * 3. 右侧「今日号源」为纯展示总览（全部科室余号，不随身份证变化）
 * 4. 姓名/性别/出生日期必填；出生日期点击弹出日历选择，年龄自动计算
 * 5. 点击【挂号】弹出通用科室选择弹窗（急诊/门诊 Tab + 余号/费用）
 *    → 选定科室挂号成功 → 确认框核对信息 → 缴费（模拟）→ 自动打印凭条
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
            <div class="form-group"><label class="form-label">性别 <span class="req">*</span></label>
                <select class="select" id="gender"><option value="男">男</option><option value="女">女</option></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">出生日期（点击选择） <span class="req">*</span></label>
                <input class="input" id="birth" readonly placeholder="点击选择日期" style="cursor:pointer;background:var(--bg-soft)">
                <div class="fs-12 text-muted mt-4" id="birthMsg">填写身份证后自动计算锁定</div></div>
            <div class="form-group"><label class="form-label">年龄（按出生日期自动计算）</label><input class="input" id="age" readonly placeholder="—" style="background:var(--bg-soft)"></div>
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

        <div class="flex gap-8">
            <button class="btn btn-primary btn-lg" style="flex:1" onclick="doRegister()">🎫 挂号（选择科室）</button>
        </div>
    </div>

    <!-- 今日号源概览（纯展示：全部科室余号一目了然，与身份证填写无关） -->
    <div class="card" style="width:320px;flex-shrink:0">
        <div class="card-title"><span>今日号源</span><span class="badge badge-primary" id="todayBadge"></span></div>
        <div id="slotBox" class="fs-13">加载中…</div>
    </div>
</div>

<script>
var REG = { id_card: '', patient_no: '', visit_id: 0, dept: null };

/* ---------- 年龄：按出生日期自动计算（只读，不可手动输入） ---------- */
function calcAge(birth) {
    var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(birth || '');
    if (!m) return '';
    var b = new Date(+m[1], +m[2] - 1, +m[3]);
    var t = new Date();
    var age = t.getFullYear() - b.getFullYear();
    if (t.getMonth() < b.getMonth() || (t.getMonth() === b.getMonth() && t.getDate() < b.getDate())) age--;
    return age < 0 ? '' : String(age);
}
function recalcAge() {
    document.getElementById('age').value = calcAge(document.getElementById('birth').value);
}

/* 出生日期：只读，点击弹出日历选择（拒绝手动输入避免格式错误；不可选未来日期） */
document.getElementById('birth').addEventListener('click', function () {
    Clinic.datePicker.open(this, { maxToday: true, onChange: recalcAge });
});

/* ---------- 身份证输入：校验 + 自动计算 + 既往登记检索 ---------- */
document.getElementById('idCard').addEventListener('input', function () { onCardChange(); });

function onCardChange() {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    var msg = document.getElementById('cardMsg');
    var feeType = document.getElementById('fee_type');
    if (card === '') {
        // 未填身份证：仅急诊 + 自费锁定；性别/出生日期可手动填写（年龄自动计算）
        REG.id_card = '';
        msg.innerHTML = '';
        feeType.value = '自费';
        feeType.disabled = true;
        setDerivedLocked(false);
        document.getElementById('regNotice').innerHTML = '';
        return;
    }
    if (!Clinic.validate.idCard(card)) {
        REG.id_card = '';
        msg.innerHTML = '<span class="text-danger">身份证号码不正确，请输入正确的18位身份证号码</span>';
        feeType.disabled = false;
        setDerivedLocked(false);
        clearDerived();
        return;
    }
    REG.id_card = card;
    msg.innerHTML = '<span class="text-success">✔ 身份证校验通过</span>';
    feeType.disabled = false;
    // 自动计算并锁定（身份证计算出的出生日期/性别确保正确，年龄随出生日期联动）
    setDerivedLocked(true);
    document.getElementById('gender').value = Clinic.validate.genderFromId(card);
    document.getElementById('birth').value = Clinic.validate.birthFromId(card);
    recalcAge();
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
}

function clearDerived() {
    document.getElementById('gender').value = '男';
    document.getElementById('birth').value = '';
    recalcAge();
}

/* 锁定/解锁 性别与出生日期（有身份证时锁定；年龄恒为只读） */
function setDerivedLocked(locked) {
    ['gender', 'birth'].forEach(function (id) {
        document.getElementById(id).disabled = locked;
    });
}

/* ---------- 今日号源总览（右侧纯展示：全部科室余号一目了然，
   不随身份证填写动态变化——动态过滤仅在挂号弹窗内） ---------- */
function loadOverview() {
    Clinic.get('/api/cashier?action=depts&all=1&id_card=', null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            document.getElementById('todayBadge').textContent = (json.data.session === 'am' ? '上午' : '下午');
            var box = document.getElementById('slotBox');
            if (!list.length) {
                box.innerHTML = '<div class="text-muted">暂无科室数据</div>';
                return;
            }
            box.innerHTML = list.map(function (d) {
                var info = d.type === 'emergency'
                    ? '<span class="badge badge-danger">急诊 · 不限号</span>'
                    : (d.full ? '<span class="badge badge-danger">已满号</span>' : '<span class="badge badge-success">余' + d.remaining + '号</span>');
                return '<div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border)">' +
                    '<span>' + d.name + '</span>' + info + '</div>';
            }).join('');
        },
    });
}

/* ---------- 提交挂号：校验必填 → 弹出通用科室选择框 ---------- */
function doRegister() {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    var name = document.getElementById('name').value.trim();
    var gender = document.getElementById('gender').value;
    var birth = document.getElementById('birth').value.trim();
    // 姓名、性别、出生日期三项必填（有身份证时由证件自动带出，同样满足）
    if (!name) { Clinic.toast.warning('请填写患者姓名'); return; }
    if (!gender) { Clinic.toast.warning('请选择患者性别'); return; }
    if (!birth) { Clinic.toast.warning('请选择患者出生日期'); return; }
    if (!card && document.getElementById('fee_type').value !== '自费') {
        document.getElementById('fee_type').value = '自费';
    }
    // 通用科室选择弹窗：显示剩余号源与挂号金额，未填身份证仅可挂急诊
    Clinic.deptPicker.open({
        mode: 'register',
        fetchUrl: '/api/cashier?action=depts&id_card=' + encodeURIComponent(card),
        noIdCard: !card,
        onSelect: function (d) { submitRegister(d); },
    });
}

/* 选定科室 → 调用挂号接口 → 弹出确认框（患者基本信息 + 就诊序号 + 费用） */
function submitRegister(d) {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    Clinic.ajax('/api/cashier', {
        action: 'register',
        id_card: card,
        name: document.getElementById('name').value.trim(),
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
        dept_id: d.id,
    }, {
        loading: true,
        onSuccess: function (json) {
            var v = json.data;
            REG.patient_no = v.patient_no;
            REG.visit_id = v.visit_id;
            REG.dept = d;
            // 确认框：姓名/性别/费用类别/ID号(身份证)/就诊序号/费用等基本信息
            Clinic.modal.open(
                '<div class="fs-13 text-muted mb-12">挂号成功！请核对以下信息后点击【缴费】完成挂号：</div>' +
                '<div class="table-wrap"><table class="table">' +
                '<tr><th>姓名</th><td class="fw-700">' + document.getElementById('name').value.trim() + '</td></tr>' +
                '<tr><th>性别</th><td>' + document.getElementById('gender').value + '</td></tr>' +
                '<tr><th>费用类别</th><td>' + document.getElementById('fee_type').value + '</td></tr>' +
                '<tr><th>ID号（身份证）</th><td>' + (v.id_card || '—') + '</td></tr>' +
                '<tr><th>患者唯一ID</th><td class="fw-700">' + v.patient_no + '</td></tr>' +
                '<tr><th>门诊流水号</th><td class="fw-700">' + v.flow_no + '</td></tr>' +
                '<tr><th>就诊序号</th><td class="fw-700">' + v.dept_name + ' 第' + String(v.visit_seq).padStart(3, '0') + '号</td></tr>' +
                '<tr><th>挂号费</th><td>¥' + parseFloat(v.fee).toFixed(2) + '</td></tr>' +
                (v.is_extra ? '<tr><th>号源</th><td><span class="badge badge-warning">医生加号</span></td></tr>' : '') +
                '</table></div>',
                {
                    title: '挂号确认',
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
            // 重置表单并刷新号源总览
            document.getElementById('idCard').value = '';
            document.getElementById('name').value = '';
            REG.dept = null;
            clearDerived();
            onCardChange();
            loadOverview();
        },
    });
}

/* 初始加载：号源总览（纯展示，不随身份证输入变化） */
loadOverview();
</script>
