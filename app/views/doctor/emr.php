<?php
/**
 * doctor/emr.php — 电子病历（医生看诊页）
 * 说明：页面框架由本视图渲染，病历编辑/开单/模板/转科/诊断证明
 * 等交互逻辑由 emr.js / order.js 驱动（WYSIWYG 编辑器 + ICD10 联动）。
 * 门诊与急诊病历抬头不同（由 dept_type 决定）。
 * DOM 约定（emr.js 依赖）：
 *   #visitId / #refRecordId（隐藏输入）
 *   #emrHeader（患者信息头）、#patientCard（患者信息卡）、
 *   #emrCard（病历编辑区）、#orderList（已开项目）、#saveStatus
 */
Router::title('电子病历');

$visitId = (int)get('visit_id', 0);
$refId = (int)get('ref', 0);
$row = $visitId ? get_visit_row($visitId) : null;
if (!$row) {
    echo '<div class="card"><div class="empty"><div class="empty-ico">⚠️</div>就诊记录不存在<br><a href="/doctor/dashboard">返回医生工作站</a></div></div>';
    return;
}
$patient = $row['patient'];
?>
<input type="hidden" id="visitId" value="<?php echo (int)$visitId; ?>">
<input type="hidden" id="refRecordId" value="<?php echo (int)$refId; ?>">

<!-- 工具栏 -->
<div class="card" style="padding:12px 16px;margin-bottom:12px">
    <div class="flex gap-8" style="flex-wrap:wrap;align-items:center">
        <span class="fw-700 fs-15">看诊操作：</span>
        <button class="btn btn-outline btn-sm" onclick="Clinic.order.open('lab')">🧪 开检验</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.order.open('imaging')">🩻 开检查</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.order.open('procedure')">🩹 开处置</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.order.open('prescription')">💊 开处方</button>
        <span style="width:1px;height:22px;background:var(--border)"></span>
        <button class="btn btn-primary btn-sm" onclick="Clinic.emr.save(false)">保存病历</button>
        <button class="btn btn-success btn-sm" onclick="Clinic.emr.save(true)">保存并诊毕</button>
        <span style="width:1px;height:22px;background:var(--border)"></span>
        <button class="btn btn-outline btn-sm" onclick="openTransfer()">↔️ 转科</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.emr.openCertificate()">📄 诊断证明</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.emr.printRecord()">🖨️ 打印病历</button>
        <button class="btn btn-outline btn-sm" onclick="showPatientHistory('<?php echo e($patient['patient_no']); ?>')">📚 就诊历史</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.patient.editModal('<?php echo e($patient['patient_no']); ?>')">✏️ 修改患者信息</button>
        <span class="fs-12 text-muted" id="saveStatus"></span>
    </div>
</div>

<!-- 患者信息头（不可编辑，emr.js 填充） -->
<div id="emrHeader"></div>

<!-- 患者信息卡（不可编辑，emr.js 填充） -->
<div id="patientCard"></div>

<!-- 病历编辑区（emr.js 整体渲染：生命体征/主诉/现病史/诊断联动等） -->
<div class="card" id="emrCard" style="padding:0;overflow:hidden">
    <div style="padding:18px 20px">
        <div class="text-muted fs-13 mb-8">病历编辑器加载中…（医院名称与患者信息区域不可编辑）</div>
    </div>
</div>

<!-- 已开项目（病历处置区） -->
<div class="card">
    <div class="card-title"><span>已开项目与流程（点击查看流程进度）</span></div>
    <div id="orderList"><div class="text-muted fs-13">加载中…</div></div>
</div>

<script>
/* 就诊历史（本页共用） */
function showPatientHistory(patientNo) {
    Clinic.get('/api/patient?action=history&patient_no=' + encodeURIComponent(patientNo), null, {
        onSuccess: function (json) {
            Clinic.modal.open(json.data.html, { title: '患者就诊历史', size: 'modal-lg' });
        },
    });
}
</script>
