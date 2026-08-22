<?php
/**
 * doctor/emr.php — 电子病历（医生看诊页）
 * 说明：页面框架由本视图渲染，病历编辑/开单/模板/转科/诊断证明
 * 等交互逻辑由 emr.js / order.js 驱动（WYSIWYG 编辑器 + ICD10 联动）。
 * 门诊与急诊病历抬头不同（由 dept_type 决定）。
 * DOM 约定（emr.js 依赖）：
 *   #visitId / #refRecordId（隐藏输入）
 *   #emrHeader（患者信息头）、#emrCard（所见即所得病历文档，
 *   内含医院抬头/标题栏/患者信息两栏/病历内容/签名）、
 *   #orderList（已开项目）、#saveStatus
 * 安全：URL 中的 visit_id 为混淆串（防撞库遍历），此处一次性解码；
 *   #visitId 隐藏域保存混淆原串供前端全程透传（后端各接口统一 did 解码）。
 */
Router::title('电子病历');

$visitCode = trim((string)get('visit_id', ''));
$visitId = did($visitCode);
$refId = (int)did(get('ref', 0));   // 转诊引用仅前端回显比对用，不回传服务端
if ($visitId <= 0) {
    echo '<div class="card"><div class="empty"><div class="empty-ico">🔗</div>链接无效或已过期<br>' .
        '<span class="fs-12 text-muted">请从医生工作站的患者列表重新进入</span><br>' .
        '<a href="/doctor/dashboard">返回医生工作站</a></div></div>';
    return;
}
$row = $visitId ? get_visit_row($visitId) : null;
if (!$row) {
    echo '<div class="card"><div class="empty"><div class="empty-ico">⚠️</div>就诊记录不存在<br><a href="/doctor/dashboard">返回医生工作站</a></div></div>';
    return;
}
$patient = $row['patient'];
?>
<input type="hidden" id="visitId" value="<?php echo e($visitCode); ?>">
<input type="hidden" id="refRecordId" value="<?php echo (int)$refId; ?>">

<!-- 条形码源（与挂号凭条一致：门诊号 flow_no，Code 128 SVG，emr.js 放入页头右上角） -->
<div id="emrBarcodeSrc" style="display:none"><?php
    $bcCode = !empty($row['visit']['flow_no']) ? $row['visit']['flow_no'] : $row['patient']['patient_no'];
    echo barcode128_svg($bcCode);
?></div>

<div class="emr-layout">
    <div class="emr-main">
        <!-- 患者信息头（不可编辑，emr.js 填充） -->
        <div id="emrHeader"></div>

        <!-- 所见即所得病历文档（emr.js 整体渲染：医院抬头/标题栏/患者信息两栏/病历内容/签名） -->
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
    </div>

    <!-- 右侧看诊操作工具栏（.emr-write 为写操作按钮：患者诊毕后自动隐藏，病历只读）
         固定在右侧、不随页面滚动，与左侧导航栏一致 -->
    <aside class="card emr-toolbar">
        <div class="emr-toolbar-title">看诊操作</div>
        <button class="btn btn-outline btn-sm emr-write" onclick="Clinic.order.open('lab')">🧪 开检验</button>
        <button class="btn btn-outline btn-sm emr-write" onclick="Clinic.order.open('imaging')">🩻 开检查</button>
        <button class="btn btn-outline btn-sm emr-write" onclick="Clinic.order.open('procedure')">🩹 开处置</button>
        <button class="btn btn-outline btn-sm emr-write" onclick="Clinic.order.open('prescription')">💊 开处方</button>
        <div class="emr-toolbar-divider"></div>
        <button class="btn btn-primary btn-sm emr-write" onclick="Clinic.emr.save(false)">💾 保存病历</button>
        <button class="btn btn-success btn-sm emr-write" onclick="Clinic.emr.save(true)">✅ 保存并诊毕</button>
        <div class="emr-toolbar-divider"></div>
        <button class="btn btn-outline btn-sm emr-write" onclick="openTransfer()">↔️ 转科</button>
        <button class="btn btn-outline btn-sm emr-write" onclick="Clinic.emr.openCertificate()">📄 诊断证明</button>
        <div class="emr-toolbar-divider"></div>
        <button class="btn btn-outline btn-sm" onclick="Clinic.emr.printRecord()">🖨️ 打印病历</button>
        <button class="btn btn-outline btn-sm" onclick="showPatientHistory('<?php echo e($patient['patient_no']); ?>')">📚 就诊历史</button>
        <button class="btn btn-outline btn-sm" onclick="Clinic.patient.editModal('<?php echo e($patient['patient_no']); ?>')">✏️ 修改患者信息</button>
        <div class="emr-toolbar-divider"></div>
        <span class="fs-12 text-muted" id="saveStatus"></span>
    </aside>
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
