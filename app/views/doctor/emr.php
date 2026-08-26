<?php
/**
 * doctor/emr.php — 电子病历（医生看诊页）工作台
 * 说明：视口锁定布局（100vh），仅中栏编辑器独立滚动：
 *   顶部：患者信息横条（通栏，头像点击=就诊历史，姓名点击=修改患者信息）
 *         + 右侧看诊操作按钮组（保存/诊毕/转科/打印）；
 *   中栏：Word 风格所见即所得病历编辑器（唯一独立滚动区）；
 *   右栏：固定全景大纲栏（病历节点/知情同意书/全部诊断/检查/检验/门诊处置/
 *         处方/诊断证明，分区标题右侧「＋」快捷添加入口（emrNavAdd），
 *         分类金额汇总 + 缴费报告状态指示灯 + 条目行内删除，不随页面滚动）。
 * 原右栏工具栏已移除——功能拆分至顶部按钮组与患者头像/姓名点击入口。
 * DOM 约定（emr.js 依赖）：
 *   #visitId / #refRecordId（隐藏输入）、#emrBarcodeSrc、
 *   #emrHeader（顶部患者信息条）、#emrCard（病历文档）、#saveStatus、
 *   大纲栏各容器：#navRecords / #navConsent / #navDiags / #navImaging /
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

<!-- ===== 工作区（顶部通栏信息条 + 中编辑器 + 右大纲栏） ===== -->
<div class="emr-workspace-layout">

    <!-- ===== 顶部：患者信息横条（emr.js 填充） + 看诊操作按钮组 =====
         就诊历史 = 点击患者头像；修改患者信息 = 点击患者姓名 -->
    <header class="emr-top-bar">
        <div id="emrHeader"></div>
        <span class="fs-12 text-muted emr-top-status" id="saveStatus"></span>
        <div class="emr-top-actions">
            <button class="btn btn-primary btn-sm emr-write" onclick="Clinic.emr.save(false)">💾 保存</button>
            <button class="btn btn-success btn-sm emr-write" onclick="Clinic.emr.confirmFinish(this)">✅ 诊毕</button>
            <button class="btn btn-outline btn-sm emr-write" onclick="openTransfer()">↔️ 转科</button>
            <button class="btn btn-outline btn-sm" onclick="Clinic.emr.printRecord()">🖨️ 打印</button>
        </div>
    </header>

    <!-- ===== 工作区两栏：编辑器（唯一独立滚动区） + 右侧全景大纲栏 ===== -->
    <div class="emr-body-layout">

        <!-- 中间：病历编辑器 -->
        <div class="emr-main-editor-scroll">
            <!-- 所见即所得病历文档（emr.js 整体渲染：医院抬头/标题栏/患者信息两栏/病历内容/签名） -->
            <div class="card" id="emrCard" style="padding:0;overflow:hidden">
                <div style="padding:18px 20px">
                    <div class="text-muted fs-13 mb-8">病历编辑器加载中…（医院名称与患者信息区域不可编辑）</div>
                </div>
            </div>
            <!-- 纸张外独立页脚徽章：最近保存时间（不参与打印，emr.js 更新显隐与文案） -->
            <div id="docSavedBadge" class="doc-saved-badge" style="display:none"></div>
        </div>

        <!-- ===== 右侧：全景大纲栏（分区标题右侧「＋」为快捷添加入口，见 emrNavAdd） ===== -->
        <aside class="emr-sidebar-left">
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">📋 病历节点<span class="ena-add emr-write" title="添加病历" onclick="emrNavAdd('records');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navRecords"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">📝 知情同意书<span class="ena-add emr-write" title="添加知情同意书" onclick="emrNavAdd('consent');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navConsent">
                <div class="ena-empty">暂无知情同意书</div>
            </div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🔎 初步诊断<span class="ena-add emr-write" title="添加诊断" onclick="emrNavAdd('diags',event);event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navDiags"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🩻 检查<span class="ena-count" id="cntImaging" style="display:none"></span><span class="ena-sum" id="sumImaging"></span><span class="ena-add emr-write" title="开具检查" onclick="emrNavAdd('imaging');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navImaging"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🧪 检验<span class="ena-count" id="cntLab" style="display:none"></span><span class="ena-sum" id="sumLab"></span><span class="ena-add emr-write" title="开具检验" onclick="emrNavAdd('lab');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navLab"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🩹 门诊处置<span class="ena-count" id="cntProc" style="display:none"></span><span class="ena-sum" id="sumProc"></span><span class="ena-add emr-write" title="开具处置" onclick="emrNavAdd('procedure');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navProc"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">💊 处方<span class="ena-count" id="cntRx" style="display:none"></span><span class="ena-sum" id="sumRx"></span><span class="ena-add emr-write" title="开具处方" onclick="emrNavAdd('prescription');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navRx"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">📄 诊断证明<span class="ena-add emr-write" id="certAddBtn" title="开具诊断证明" onclick="emrNavAdd('cert');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navCert"></div>
        </div>
    </aside>

    </div>
</div>

<script>
/* 左侧大纲栏异步刷新总线：开单提交 / 缴费状态变化后调用，
   局部刷新左栏金额与指示灯（30 秒轮询兜底覆盖收费处缴费场景） */
function refreshLeftNavSummary() {
    if (window.Clinic && Clinic.emr && Clinic.emr.loadOrders) Clinic.emr.loadOrders();
}
setInterval(refreshLeftNavSummary, 30000);
</script>
