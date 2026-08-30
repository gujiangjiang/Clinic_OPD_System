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
Router::title('医生工作站');

$visitCode = trim((string)get('visit_id', ''));
$visitId = did($visitCode);
$refId = (int)did(get('ref', 0));
// 无 visit_id（空白工作台）：渲染病历工作台骨架，首次进入自动弹出候诊列表
if ($visitId <= 0) {
    ?>
<div class="emr-workspace-layout">
    <header class="emr-top-bar">
        <div id="emrHeader"></div>
        <span class="fs-12 text-muted emr-top-status" id="saveStatus"></span>
        <div class="emr-top-actions" style="visibility:hidden"></div>
    </header>
    <div class="emr-body-layout">
        <div class="emr-main-editor-scroll">
            <div class="card wb-empty" style="padding:40px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <div style="font-size:72px;margin-bottom:16px">🩺</div>
                <div class="fs-18 fw-600 text-muted">欢迎使用医生工作站</div>
                <div class="fs-14 text-muted mt-4">请从左侧候诊列表选择患者开始就诊</div>
                <div class="fs-12 text-muted mt-8">候诊列表已自动打开，点击患者即可进入病历书写</div>
            </div>
        </div>
        <aside class="emr-sidebar-left">
            <div class="ena-sec"><div class="ena-sec-title">📋 病历节点</div><div class="ena-sec-body"><div class="ena-empty">暂无病历</div></div></div>
            <div class="ena-sec"><div class="ena-sec-title">📝 初步诊断</div><div class="ena-sec-body"><div class="ena-empty">暂无诊断</div></div></div>
            <div class="ena-sec"><div class="ena-sec-title">🩻 检查</div><div class="ena-sec-body"><div class="ena-empty">暂无检查</div></div></div>
            <div class="ena-sec"><div class="ena-sec-title">🧪 检验</div><div class="ena-sec-body"><div class="ena-empty">暂无检验</div></div></div>
            <div class="ena-sec"><div class="ena-sec-title">💊 处方</div><div class="ena-sec-body"><div class="ena-empty">暂无处方</div></div></div>
        </aside>
    </div>
</div>
<script>
/* 工作台科室加载：单科室自动进入，多科室检查会话记忆，未选则弹选择 */
var WB_DEPT_LIST = [];
var WB_CUR_DEPT = 0;

function wbDeptMemKey() {
    // 改用 body data-uid + data-sid（layout.php 已注入，全局统一）
    return { u: document.body.getAttribute('data-uid') || '', s: document.body.getAttribute('data-sid') || '' };
}
function wbReadSavedDept() {
    try {
        var k = wbDeptMemKey();
        var sv = JSON.parse(sessionStorage.getItem('clinic_doc_dept') || '""');
        return (sv && String(sv.u) === k.u && String(sv.s) === k.s) ? (parseInt(sv.d, 10) || 0) : 0;
    } catch (e) { return 0; }
}

/* 选定科室 */
/* 选定科室：仅保存在本次登录会话（sessionStorage 绑定账号+会话ID，
   退出重登自动失效），不持久化到服务器；候诊列表按所选科室显示 */
function wbPickDept(id) {
    WB_CUR_DEPT = id;
    var k = wbDeptMemKey();
    sessionStorage.setItem('clinic_doc_dept', JSON.stringify({ u: k.u, s: k.s, d: id }));
    // 更新空状态提示
    document.querySelector('.wb-empty .fs-18').textContent = '🏥 已选择科室';
    document.querySelector('.wb-empty .fs-14').textContent = '候诊列表已打开，点击患者即可进入病历书写';
    document.querySelector('.wb-empty .fs-12').innerHTML = '';
    // 候诊面板按所选科室加载并自动弹出
    if (window.Clinic && Clinic.queuePanel) {
        Clinic.queuePanel.setDept(id);
        Clinic.queuePanel.open();
    }
}

function wbOpenQueue() {
    if (window.Clinic && Clinic.queuePanel) Clinic.queuePanel.open();
}

function wbLoadDepts() {
    Clinic.get('/api/doctor?action=depts', null, {
        onSuccess: function (json) {
            WB_DEPT_LIST = json.data.list || [];
            if (!WB_DEPT_LIST.length) {
                document.querySelector('.wb-empty .fs-18').textContent = '⚠️ 尚未关联科室';
                document.querySelector('.wb-empty .fs-14').textContent = '请联系管理员在【用户管理】中为您设置科室';
                return;
            }
            if (WB_DEPT_LIST.length === 1) {
                wbPickDept(WB_DEPT_LIST[0].id);
            } else {
                var saved = wbReadSavedDept();
                var hasSaved = false;
                WB_DEPT_LIST.forEach(function (d) { if (d.id === saved) hasSaved = true; });
                if (hasSaved) {
                    wbPickDept(saved);
                } else {
                    document.querySelector('.wb-empty .fs-18').textContent = '🩺 请先选择科室后开始接诊';
                    document.querySelector('.wb-empty .fs-14').textContent = '正在为你弹出科室选择…';
                    document.querySelector('.wb-empty .fs-12').innerHTML = '<button class="btn btn-primary btn-sm mt-8" onclick="wbOpenDeptPicker()">🏥 选择科室</button>';
                    // 主动弹出科室选择窗
                    wbOpenDeptPicker();
                }
            }
        },
    });
}

function wbOpenDeptPicker() {
    Clinic.deptPicker.open({
        mode: 'select',
        depts: WB_DEPT_LIST,
        currentId: WB_CUR_DEPT,
        onSelect: function (d) { wbPickDept(d.id); },
    });
}

document.addEventListener('DOMContentLoaded', wbLoadDepts);
</script>
    <?php
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
            <div class="ena-sec-title" onclick="toggleNavSec(this)">📝 知情同意书<span class="ena-add emr-write" title="添加知情同意书" onclick="emrNavAdd('consent',event);event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navConsent">
                <div class="ena-empty">暂无知情同意书</div>
            </div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🔎 初步诊断<span class="ena-add emr-write" id="diagsAddBtn" title="添加诊断" onclick="emrNavAdd('diags',event);event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navDiags"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🩻 检查<span class="ena-count" id="cntImaging" style="display:none"></span><span class="ena-sum" id="sumImaging"></span><span class="ena-add emr-write" id="imgAddBtn" title="开具检查" onclick="emrNavAdd('imaging');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navImaging"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🧪 检验<span class="ena-count" id="cntLab" style="display:none"></span><span class="ena-sum" id="sumLab"></span><span class="ena-add emr-write" id="labAddBtn" title="开具检验" onclick="emrNavAdd('lab');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navLab"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🩹 门诊处置<span class="ena-count" id="cntProc" style="display:none"></span><span class="ena-sum" id="sumProc"></span><span class="ena-add emr-write" id="procAddBtn" title="开具处置" onclick="emrNavAdd('procedure');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navProc"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">💊 处方<span class="ena-count" id="cntRx" style="display:none"></span><span class="ena-sum" id="sumRx"></span><span class="ena-add emr-write" id="rxAddBtn" title="开具处方" onclick="emrNavAdd('prescription');event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navRx"></div>
        </div>
        <div class="ena-sec">
            <div class="ena-sec-title" onclick="toggleNavSec(this)">🤝 会诊<span class="ena-add emr-write" id="consAddBtn" title="发起会诊" onclick="Clinic.emr.openConsultCreate(event);event.stopPropagation()">+</span><span class="ena-arrow">▾</span></div>
            <div class="ena-sec-body" id="navConsult"><div class="ena-empty">暂无会诊</div></div>
        </div>
        <div class="ena-sec" id="certSec">
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
