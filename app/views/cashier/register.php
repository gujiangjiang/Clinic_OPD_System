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
            <div class="form-group"><label class="form-label">年龄（按出生日期自动计算）</label><input class="input" id="age" disabled placeholder="—"></div>
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
            <button class="btn btn-primary btn-lg" id="btnNormal" style="flex:1;display:none" onclick="doRegister()" disabled>🎫 挂号（选择科室）</button>
            <button class="btn btn-success btn-lg" id="btnQuick" style="flex:1" onclick="openQuickReg()">🚑 快速挂号（无名氏）</button>
        </div>
        <div class="fs-12 text-muted mt-4" id="regBtnTip">未填写身份证或姓名时无法实名挂号，可使用【快速挂号】：自动生成无名氏姓名，仅限 0 元挂号费科室</div>
    </div>

    <!-- 今日号源概览（纯展示：全部科室余号一目了然，与身份证填写无关） -->
    <div class="card" style="width:320px;flex-shrink:0">
        <div class="card-title"><span>今日号源</span><span class="badge badge-primary" id="todayBadge"></span></div>
        <div id="slotBox" class="fs-13">加载中…</div>
    </div>
</div>

<script>
var REG = { id_card: '', patient_no: '', visit_id: 0, dept: null, age_years: 0 };

/* ---------- 年龄：按出生日期自动计算（只读，不可手动输入） ----------
 * 展示用 EMR 全年龄段格式（Clinic.validate.formatAge），
 * 入库快照另存周岁数字（REG.age_years，由 ageFromBirth 计算） */
function recalcAge() {
    var b = document.getElementById('birth').value;
    document.getElementById('age').value = Clinic.validate.formatAge(b);
    REG.age_years = Clinic.validate.ageFromBirth(b);
}

/* 出生日期：只读，点击弹出日历选择（拒绝手动输入避免格式错误；不可选未来日期） */
document.getElementById('birth').addEventListener('click', function () {
    Clinic.datePicker.open(this, { maxToday: true, onChange: recalcAge });
});

/* ---------- 身份证输入：校验 + 自动计算 + 既往登记检索 ---------- */
/* 单一监听：onCardChange 内部已同步 refreshRegState，避免每次击键重复执行 */
document.getElementById('idCard').addEventListener('input', function () { onCardChange(); });
document.getElementById('name').addEventListener('input', refreshRegState);
/* 性别/出生日期变化（含日历选择派发的 change 事件）同步按钮状态 */
document.getElementById('gender').addEventListener('change', refreshRegState);
document.getElementById('birth').addEventListener('change', refreshRegState);

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
        refreshRegState();
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
    refreshRegState(); // 程序赋值不触发事件，手动同步按钮状态
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
            // 程序赋值（姓名自动填充）不触发 input 事件，需手动刷新按钮状态
            refreshRegState();
        },
    });
}

function clearDerived() {
    document.getElementById('gender').value = '男';
    document.getElementById('birth').value = '';
    recalcAge();
    refreshRegState(); // 清空出生日期后按钮应回到不可点击
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
            var sch = json.data.schedule || {};
            var stateText = { before: '未上班', am: '上午放号中', noon: '午休', pm: '下午放号中', after: '已下班' };
            document.getElementById('todayBadge').textContent = stateText[sch.state] || '';
            var box = document.getElementById('slotBox');
            // 作息提示条（非放号时段展示原因）
            var banner = sch.msg
                ? '<div class="mb-8" style="background:var(--warning-soft);color:var(--warning);border-radius:8px;padding:8px 12px;font-size:12px;line-height:1.6">⏰ ' + sch.msg + '</div>'
                : '';
            if (!list.length) {
                box.innerHTML = banner + '<div class="text-muted">暂无科室数据</div>';
                return;
            }
            box.innerHTML = banner + list.map(function (d) {
                var info;
                if (d.type === 'emergency') {
                    info = '<span class="badge badge-danger">急诊 · 24小时</span>';
                } else if (d.bookable === false) {
                    info = '<span class="badge badge-gray">' + (stateText[sch.state] || '停挂') + '</span>';
                } else {
                    info = d.full ? '<span class="badge badge-danger">已满号</span>' : '<span class="badge badge-success">余' + d.remaining + '号</span>';
                }
                return '<div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border)">' +
                    '<span>' + d.name + '</span>' + info + '</div>';
            }).join('');
        },
    });
}

/* ---------- 挂号按钮双状态（严格按需求） ----------
 * 无任何输入（身份证、姓名均空）→ 绿色【快速挂号（无名氏）】可点击
 * 一旦检测到任意输入 → 切换为【挂号】按钮；
 * 姓名、性别、出生日期三个必填项全部有值才可点击
 * （输入身份证后性别/出生日期自动生成，视为已填写） */
function refreshRegState() {
    var card = document.getElementById('idCard').value.trim().toUpperCase();
    var name = document.getElementById('name').value.trim();
    var gender = document.getElementById('gender').value;
    var birth = document.getElementById('birth').value.trim();
    var hasInput = card !== '' || name !== '';
    var ready = name !== '' && gender !== '' && birth !== '';
    document.getElementById('btnQuick').style.display = hasInput ? 'none' : '';
    document.getElementById('btnNormal').style.display = hasInput ? '' : 'none';
    document.getElementById('btnNormal').disabled = !ready;
    document.getElementById('regBtnTip').textContent = !hasInput
        ? '未填写身份证或姓名时无法实名挂号，可使用【快速挂号】：自动生成无名氏姓名，仅限 0 元挂号费科室'
        : (ready ? '信息完整，点击【挂号】在弹窗中选择科室'
                 : '请完善' + (name === '' ? '姓名' : '') + (name === '' && (gender === '' || birth === '') ? '、' : '') + (gender === '' ? '性别' : '') + (gender === '' && birth === '' ? '、' : '') + (birth === '' ? '出生日期' : '') + '后即可挂号');
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

/* ---------- 快速挂号（无名氏）----------
 * 场景：危重症无家属/昏迷患者，无法提供身份信息。
 * 姓名：系统按患者编号自动生成（无名氏+编号，只读不可改）；
 * 年龄必填（目测估算），出生日期选填——两者双向联动：
 * 改年龄 → 自动推算出生日期（今天 − 年龄）；手选出生日期 → 自动反算年龄。
 * 仅可挂 0 元挂号费科室。 */
var QUICK = { name: '', gender: '男', birth: '', age: 0 };

function yearsAgoStr(n) {
    var d = new Date();
    d.setFullYear(d.getFullYear() - n);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function openQuickReg() {
    Clinic.get('/api/cashier?action=quick_name', null, {
        onSuccess: function (json) {
            var html = '<div class="form-group">' +
                '<label class="form-label">姓名（系统自动生成，不可修改）</label>' +
                '<input class="input" id="q_name" value="' + json.data.name + '" readonly style="background:var(--bg-soft);color:var(--text-muted);cursor:default">' +
                '<div class="fs-12 text-muted mt-4">以挂号患者编号动态生成：全局唯一、不与实名患者冲突，「无名氏」前缀便于识别区分</div></div>' +
                '<div class="form-row">' +
                '<div class="form-group"><label class="form-label">性别</label>' +
                '<select class="select" id="q_gender"><option value="男">男</option><option value="女">女</option></select></div>' +
                '<div class="form-group"><label class="form-label">估算年龄 <span class="req">*</span></label>' +
                '<input class="input" id="q_age" type="number" min="1" max="130" placeholder="目测估算，如 40"></div></div>' +
                '<div class="form-group"><label class="form-label">出生日期（选填）</label>' +
                '<input class="input" id="q_birth" readonly placeholder="填写年龄后自动推算；也可点击手动选择" style="cursor:pointer;background:var(--bg-soft)">' +
                '<div class="fs-12 text-muted mt-4">年龄与出生日期互相关联：修改年龄自动推算出生日期；手动选择出生日期则自动反算年龄</div></div>';
            Clinic.modal.open(html, {
                title: '🚑 快速挂号（无名氏）',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    { text: '继续 → 选择科室', cls: 'btn-success', autoClose: false, onClick: quickNext },
                ],
            });
            /* 年龄 → 出生日期 */
            document.getElementById('q_age').addEventListener('input', function () {
                var a = parseInt(this.value, 10);
                if (a >= 1 && a <= 130) document.getElementById('q_birth').value = yearsAgoStr(a);
            });
            /* 出生日期 → 年龄 */
            document.getElementById('q_birth').addEventListener('click', function () {
                var el = this;
                Clinic.datePicker.open(el, { maxToday: true, onChange: function (v) {
                    if (v) document.getElementById('q_age').value = Clinic.validate.ageFromBirth(v);
                }});
            });
        },
    });
}

/* 快速挂号第二步：校验后进入科室选择（仅 0 元挂号费科室可选） */
function quickNext() {
    var age = parseInt(document.getElementById('q_age').value, 10);
    if (!age || age < 1 || age > 130) { Clinic.toast.warning('请填写估算年龄（1-130 岁）'); return; }
    QUICK.name = document.getElementById('q_name').value;
    QUICK.gender = document.getElementById('q_gender').value;
    QUICK.birth = document.getElementById('q_birth').value.trim();
    QUICK.age = age;
    Clinic.modal.close();
    Clinic.deptPicker.open({
        mode: 'register',
        fetchUrl: '/api/cashier?action=depts&all=1&id_card=',
        onlyFree: true,
        defaultTab: 'emergency', // 无名氏默认跳转急诊 Tab（门诊 Tab 仍可选 0 元科室）
        onSelect: function (d) { submitRegister(d, true); },
    });
}

/* 选定科室 → 调用挂号接口 → 弹出确认框（患者基本信息 + 就诊序号 + 费用）
 * quick=true 时使用快速挂号数据（无名氏），否则读取页面表单 */
function submitRegister(d, quick) {
    var card, name, gender, birth, age, feeType;
    if (quick) {
        card = ''; name = QUICK.name; gender = QUICK.gender;
        birth = QUICK.birth; age = QUICK.age; feeType = '自费';
    } else {
        card = document.getElementById('idCard').value.trim().toUpperCase();
        name = document.getElementById('name').value.trim();
        gender = document.getElementById('gender').value;
        birth = document.getElementById('birth').value.trim();
        age = REG.age_years || 0;
        feeType = document.getElementById('fee_type').value;
    }
    Clinic.ajax('/api/cashier', {
        action: 'register',
        id_card: card,
        name: name,
        gender: gender,
        birth_date: birth,
        age: age,
        ethnicity: quick ? '汉族' : document.getElementById('ethnicity').value,
        marital: quick ? '' : document.getElementById('marital').value,
        occupation: quick ? '' : document.getElementById('occupation').value,
        fee_type: feeType,
        phone: quick ? '' : document.getElementById('phone').value.trim(),
        work_unit: quick ? '' : document.getElementById('work_unit').value.trim(),
        address: quick ? '' : document.getElementById('address').value.trim(),
        dept_id: d.id,
        quick: quick ? '1' : '',
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
                '<tr><th>姓名</th><td class="fw-700">' + (v.name || name) + '</td></tr>' +
                '<tr><th>性别</th><td>' + gender + '</td></tr>' +
                '<tr><th>费用类别</th><td>' + feeType + '</td></tr>' +
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
            Clinic.print.load('/api/print?action=receipt&visit_id=' + REG.visit_id, null, 'ticket');
            // 重置表单并刷新号源总览
            document.getElementById('idCard').value = '';
            document.getElementById('name').value = '';
            REG.dept = null;
            clearDerived();
            onCardChange();
            loadOverview();
            refreshRegState();
        },
    });
}

/* 初始加载：号源总览（纯展示）+ 挂号按钮状态 */
loadOverview();
refreshRegState();
</script>
