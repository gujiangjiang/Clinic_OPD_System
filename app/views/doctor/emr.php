<?php
/**
 * doctor/emr.php — 电子病历（医生看诊页）三栏式工作台
 * 说明：经典三栏视口布局（100vh 锁定）：
 *   左栏：固定全景大纲栏（病历节点/知情同意书/全部诊断/检查/检验/门诊处置/处方/诊断证明，
 *         分类金额汇总 + 缴费报告状态指示灯，点击弹出详情或定位），不随页面滚动；
 *   中栏：Word 风格所见即所得病历编辑器（唯一独立滚动区）；
 *   右栏：固定看诊操作工具栏。
 * 原底部「已开具项目」模块已移除——其数据聚合进左侧大纲栏。
 * DOM 约定（emr.js 依赖）：
 *   #visitId / #refRecordId（隐藏输入）、#emrBarcodeSrc、
 *   #emrHeader（患者信息头）、#emrCard（病历文档）、#saveStatus、
 *   左栏各容器：#navRecords / #navConsent / #navDiags / #navImaging /
 *   #navLab / #navProc / #navRx / #navCert（emr.js 渲染）
 * 安全：URL 中 visit_id 为混淆串，此处一次性解码；前端全程透传混淆串。
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

<!-- ===== 三栏式工作区（左右锁定、中间独立滚动） ===== -->
<div class="emr-workspace-layout">

    <!-- ===== 左侧：全景大纲栏 ===== -->
    <aside class="emr-sidebar-left">
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">📋 病历节点<span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navRecords"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">📝 知情同意书<span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navConsent">
                <div class="nav-item" onclick="Clinic.toast.info('知情同意书功能建设中')">＋ 添加知情同意书</div>
            </div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">🔎 全部诊断<span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navDiags"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">🩻 检查<span class="nav-sum" id="sumImaging"></span><span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navImaging"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">🧪 检验<span class="nav-sum" id="sumLab"></span><span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navLab"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">🩹 门诊处置<span class="nav-sum" id="sumProc"></span><span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navProc"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">💊 处方<span class="nav-sum" id="sumRx"></span><span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navRx"></div>
        </div>
        <div class="nav-sec">
            <div class="nav-sec-title" onclick="toggleNavSec(this)">📄 诊断证明<span class="nav-arrow">▾</span></div>
            <div class="nav-sec-body" id="navCert"></div>
        </div>
    </aside>

    <!-- ===== 中间：病历编辑器（唯一独立滚动区） ===== -->
    <div class="emr-main-editor-scroll">
        <!-- 患者信息头（不可编辑，emr.js 填充） -->
        <div id="emrHeader"></div>

        <!-- 所见即所得病历文档（emr.js 整体渲染：医院抬头/标题栏/患者信息两栏/病历内容/签名） -->
        <div class="card" id="emrCard" style="padding:0;overflow:hidden">
            <div style="padding:18px 20px">
                <div class="text-muted fs-13 mb-8">病历编辑器加载中…（医院名称与患者信息区域不可编辑）</div>
            </div>
        </div>
    </div>

    <!-- ===== 右侧：常用工具栏（固定不滚动） ===== -->
    <aside class="emr-sidebar-right">
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

/* 左侧大纲栏异步刷新总线：开单提交 / 缴费状态变化后调用，
   局部刷新左栏金额与指示灯（30 秒轮询兜底覆盖收费处缴费场景） */
function refreshLeftNavSummary() {
    if (window.Clinic && Clinic.emr && Clinic.emr.loadOrders) Clinic.emr.loadOrders();
}
setInterval(refreshLeftNavSummary, 30000);
</script>
