<?php
/**
 * cashier/regmanage.php — 挂号管理
 * 说明：
 * 1. 按日期查询当天/任意一天的挂号记录（含退费、取消挂号）
 * 2. 挂号科室显示【首次挂号科室】，补打凭条同样显示首次挂号科室
 *    （不受转科等操作影响）
 * 3. 点击患者姓名弹出患者信息修改弹窗
 *    （可修改除姓名/性别/身份证/出生年月外的信息）
 * 4. 退费后同一首次科室可重新挂号（就诊序号按规则递增）
 */
Router::title('挂号管理');
?>
<style>
/* 挂号管理：内容区 flex 纵向布局，列表区自适应填满剩余视口，表格内部滚动，消除下方留白/溢出 */
.content { display: flex; flex-direction: column; }
.regm-fill { flex: 1; min-height: 0; display: flex; flex-direction: column; }
.regm-fill .table-wrap { max-height: none; flex: 1; min-height: 0; }
.regm-fill .empty { flex: 1; }
</style>
<div class="page-head">
    <div><div class="page-title">📋 挂号管理</div><div class="page-desc">查询任意一天的挂号记录，支持补打凭条、退费/取消</div></div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="flex gap-8" style="align-items:center;flex-wrap:wrap">
        <input type="text" class="input" id="regDate" value="<?php echo date('Y-m-d'); ?>" readonly placeholder="点击选择日期" style="width:150px;cursor:pointer" onclick="Clinic.datePicker.open(this, { maxToday: false })">
        <input class="input" id="regKw" placeholder="🔍 搜索 姓名 / 患者ID / 流水号" style="width:220px" onkeydown="if(event.key==='Enter')loadList()">
        <select class="select" id="regDeptType" style="width:110px" onchange="loadList()">
            <option value="">全部科室</option>
            <option value="clinic">门诊</option>
            <option value="emergency">急诊</option>
        </select>
        <select class="select" id="regStatus" style="width:120px" onchange="loadList()">
            <option value="">全部状态</option>
            <option value="pending">未缴费</option>
            <option value="paid">待就诊</option>
            <option value="visiting">就诊中</option>
            <option value="finished">已完成</option>
            <option value="refunded">已退费</option>
            <option value="cancelled">已取消</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="loadList()">查询</button>
    </div>
</div>
<div id="regList" class="regm-fill"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadList() {
    var date = document.getElementById('regDate').value || '<?php echo date('Y-m-d'); ?>';
    var kw = document.getElementById('regKw').value.trim();
    var deptType = document.getElementById('regDeptType').value;
    var status = document.getElementById('regStatus').value;
    var qs = 'date=' + encodeURIComponent(date) + '&dept_type=' + encodeURIComponent(deptType) + '&status=' + encodeURIComponent(status) + '&kw=' + encodeURIComponent(kw);
    Clinic.get('/api/cashier?action=reg_list&' + qs, null, {
        onSuccess: function (json) {
            document.getElementById('regList').innerHTML = json.data.html;
        },
    });
}

/* 患者信息修改弹窗（点击患者姓名） */
function patientEdit(patientNo) {
    Clinic.patient.editModal(patientNo);
}

/* 继续缴费（待缴费的挂号）：完成缴费后自动打印凭条并刷新列表 */
function payVisit(visitId) {
    Clinic.payMethod.open('挂号费缴费', function (method) {
        Clinic.ajax('/api/cashier', { action: 'pay_visit', visit_id: visitId, method: method }, {
            onSuccess: function (json) {
                Clinic.toast.success('缴费成功（' + method + '），挂号完成');
                Clinic.print.load('/api/print?action=receipt&visit_id=' + visitId, null, 'ticket');
                loadList();
            },
        });
    });
}

/* 退费 / 取消挂号 */
function cancelVisit(visitId, status) {
    var tip = status === 'paid' ? '确定为该挂号退费？退费后该患者可在同一首次科室重新挂号。' : '确定取消该挂号？';
    Clinic.modal.confirm(tip, function () {
        var reason = prompt('请填写' + (status === 'paid' ? '退费' : '取消') + '原因（可留空）：', '');
        if (reason === null) return;
        Clinic.ajax('/api/cashier', { action: 'cancel_visit', visit_id: visitId, reason: reason }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadList();
            },
        });
    }, { title: status === 'paid' ? '退费确认' : '取消确认' });
}

loadList();
</script>
