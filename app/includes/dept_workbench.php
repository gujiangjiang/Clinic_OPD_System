<?php
/**
 * ============================================================
 * dept_workbench.php — 科室工作台公共布局骨架
 * ============================================================
 * 说明：护士站 / 检验科 / 影像科 / 药房工作台统一使用本骨架，
 * 布局与医生工作站一致（见 doctor/emr.php）：
 *   顶部：候诊按钮 + 患者信息横条（#dwHeader）+ 状态 + 操作按钮组（叫号/返回）；
 *   中部：主工作区（#dwMain，唯一独立滚动区，按角色渲染患者工作台）；
 *   右栏：全景大纲栏（#dwSide，按角色渲染条目导航）。
 * DOM 约定（deptwork.js 依赖）：
 *   #queueBtn / #dwHeader / #dwStatus / #dwCallBtn / #dwHomeBtn /
 *   #dwMain / #dwSide
 * 角色个性化渲染由各视图内联脚本通过 Clinic.deptwork.render(roleData) 注入。
 * ============================================================ */

/**
 * 渲染科室工作台骨架
 * @param array $cfg 角色配置：role / title / desc / emoji / extra_actions（可选，顶栏额外按钮 HTML）
 */
function dept_workbench($cfg) {
    $role = isset($cfg['role']) ? $cfg['role'] : '';
    $title = isset($cfg['title']) ? $cfg['title'] : '工作台';
    $desc = isset($cfg['desc']) ? $cfg['desc'] : '';
    $emoji = isset($cfg['emoji']) ? $cfg['emoji'] : '🏥';
    $extraActions = isset($cfg['extra_actions']) ? $cfg['extra_actions'] : '';
    Router::title($title);
    ?>
<div class="emr-workspace-layout">
    <header class="emr-top-bar">
        <button type="button" class="btn btn-outline btn-sm" id="queueBtn" style="flex-shrink:0" title="候诊 / 患者列表">📋 候诊 …</button>
        <div id="dwHeader"></div>
        <span class="fs-12 text-muted emr-top-status" id="dwStatus"></span>
        <div class="emr-top-actions">
            <button type="button" class="btn btn-outline btn-sm" id="dwHomeBtn" title="关闭护理记录单，返回候诊列表" style="display:none">✕ 关闭</button>
        </div>
    </header>
    <div class="emr-body-layout">
        <div class="emr-main-editor-scroll" id="dwMain">
            <div class="card wb-empty" style="padding:40px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <div style="font-size:72px;margin-bottom:16px"><?php echo e($emoji); ?></div>
                <div class="fs-18 fw-600 text-muted">欢迎使用<?php echo e($title); ?></div>
                <div class="fs-14 text-muted mt-4"><?php echo e($desc); ?></div>
                <div class="fs-12 text-muted mt-8">候诊列表已自动打开，点击患者即可进入工作台</div>
            </div>
        </div>
        <aside class="emr-sidebar-left" id="dwSide"></aside>
    </div>
</div>
    <?php
}
