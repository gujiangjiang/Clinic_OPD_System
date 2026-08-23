<?php
/**
 * ============================================================
 * forms.php v1.1.0 — 共享表单渲染模块
 * ============================================================
 * 说明：检验/检查项目表单、药品表单为多个工作站共用：
 *   管理端（admin）、检验科（lab）、影像科（imaging）、
 *   药房（pharmacy）均调用本模块渲染，避免重复代码（需求28）。
 * 用法：
 *   $html = form_item('lab', 0);            // 检验项目新增表单
 *   $html = form_item('imaging', 5);        // 检查项目编辑表单
 *   $res  = form_drug(3);                   // 药品表单（含途径→护士站映射）
 * ============================================================ */

/**
 * 检验/检查项目表单
 * @param string $type lab 检验 / imaging 检查
 * @param int    $id   项目ID（0 为新增）
 * @return string 表单 HTML
 */
function form_item($type, $id) {
    $table = $type === 'lab' ? 'lab_items' : 'exam_items';
    $r = $id > 0 ? DB::one('lab', "SELECT * FROM $table WHERE id=?", array((int)$id)) : array(
        'category' => '', 'name' => '', 'unit' => '', 'price' => '0', 'normal_range' => '',
        'critical_low' => '', 'critical_high' => '', 'description' => '',
    );
    if (!$r) {
        $r = array(
            'category' => '', 'name' => '', 'unit' => '', 'price' => '0', 'normal_range' => '',
            'critical_low' => '', 'critical_high' => '', 'description' => '',
        );
    }
    $cats = DB::q('lab', "SELECT name FROM item_categories WHERE ctype=? ORDER BY sort, id", array($type));
    $catOpts = '<option value="">请选择/输入分类</option>';
    foreach ($cats as $c) {
        $catOpts .= '<option value="' . e($c['name']) . '"' . ($r['category'] === $c['name'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return '<input type="hidden" id="f_id" value="' . (int)$id . '">
    <div class="form-row">
        <div class="form-group"><label class="form-label">项目名称 <span class="req">*</span></label>
            <input class="input" id="f_name" value="' . e($r['name']) . '"></div>
        <div class="form-group"><label class="form-label">所属分类（CT/MR 等）</label>
            <select class="select" id="f_category">' . $catOpts . '</select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">价格（元）</label>
            <input class="input" type="number" step="0.01" min="0" id="f_price" value="' . e($r['price']) . '"></div>' .
        ($type === 'lab' ? '<div class="form-group"><label class="form-label">计量单位</label>
            <input class="input" id="f_unit" value="' . e($r['unit']) . '" placeholder="如：mmol/L"></div>' : '') .
    '</div>' .
    ($type === 'lab' ? '<div class="form-row">
        <div class="form-group"><label class="form-label">正常范围值</label>
            <input class="input" id="f_normal" value="' . e($r['normal_range']) . '" placeholder="如：3.5-5.5"></div>
        <div class="form-group"><label class="form-label">危急值下限</label>
            <input class="input" id="f_clow" value="' . e($r['critical_low']) . '"></div>
        <div class="form-group"><label class="form-label">危急值上限</label>
            <input class="input" id="f_chigh" value="' . e($r['critical_high']) . '"></div>
    </div>' : '') .
    '<div class="form-group"><label class="form-label">项目描述</label>
        <textarea class="textarea" id="f_desc" rows="2">' . e($r['description']) . '</textarea></div>' .
    '<div class="fs-12 text-muted">新增项目提交后需在【审核中心】审核通过方可开单使用。</div>';
}

/**
 * 药品表单
 * @param int $id 药品ID（0 为新增）
 * @return array ['html'=>表单HTML, 'route_nurse'=>途径→需护士站映射, 'need_nurse'=>当前值]
 */
function form_drug($id) {
    $r = $id > 0 ? DB::one('drug', 'SELECT * FROM drugs WHERE id=?', array((int)$id)) : null;
    if (!$r) {
        $r = array(
            'name' => '', 'generic_name' => '', 'category' => '', 'vendor' => '', 'vendor_short' => '',
            'package_unit' => '', 'spec' => '', 'form' => '', 'single_dose' => '', 'frequency_name' => '',
            'route_name' => '', 'price' => '0', 'qty' => '0', 'is_rx' => 0, 'is_limited' => 0, 'note' => '', 'need_nurse' => 0,
        );
    }
    $sel = function ($stype, $cur) {
        $rows = DB::q('drug', 'SELECT * FROM drug_settings WHERE stype=? ORDER BY sort, id', array($stype));
        $html = '<option value="">请选择</option>';
        foreach ($rows as $x) {
            $html .= '<option value="' . e($x['name']) . '"' . ($cur === $x['name'] ? ' selected' : '') . '>' . e($x['name']) . '</option>';
        }
        return $html;
    };
    // 皮试关联处置名称（表单回显：必须在拼接 HTML 之前计算）
    $skinName = '';
    if (!empty($r['skin_test_item_id'])) {
        $sn = DB::val('disp', 'SELECT name FROM disposal_items WHERE id=?', array((int)$r['skin_test_item_id']));
        $skinName = (string)$sn;
    }
    $html = '<input type="hidden" id="f_id" value="' . (int)$id . '">
    <div class="form-row">
        <div class="form-group"><label class="form-label">药品名称 <span class="req">*</span></label><input class="input" id="f_name" value="' . e($r['name']) . '"></div>
        <div class="form-group"><label class="form-label">通用名称</label><input class="input" id="f_generic" value="' . e($r['generic_name']) . '"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">分类（西药/中成药/中药）</label><select class="select" id="f_category">' . $sel('category', $r['category']) . '</select></div>
        <div class="form-group"><label class="form-label">包装单位</label><select class="select" id="f_pkg">' . $sel('package', $r['package_unit']) . '</select></div>
        <div class="form-group"><label class="form-label">药品剂型</label><select class="select" id="f_form">' . $sel('form', $r['form']) . '</select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">药品企业名称</label><input class="input" id="f_vendor" value="' . e($r['vendor']) . '"></div>
        <div class="form-group"><label class="form-label">企业名称缩写</label><input class="input" id="f_vendor_short" value="' . e($r['vendor_short']) . '" placeholder="处方打印显示"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">药物规格/含量</label><input class="input" id="f_spec" value="' . e($r['spec']) . '" placeholder="如：0.25g×24片"></div>
        <div class="form-group"><label class="form-label">单次使用剂量</label><input class="input" id="f_dose" value="' . e($r['single_dose']) . '" placeholder="如：2片 / 2g"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">用药频次</label><select class="select" id="f_freq">' . $sel('freq', $r['frequency_name']) . '</select></div>
        <div class="form-group"><label class="form-label">使用途径</label><select class="select" id="f_route" onchange="syncNurse()">' . $sel('route', $r['route_name']) . '</select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">价格（元）</label><input class="input" type="number" step="0.01" min="0" id="f_price" value="' . e($r['price']) . '"></div>
        <div class="form-group"><label class="form-label">药品数量（库存）</label><input class="input" type="number" min="0" id="f_qty" value="' . (int)$r['qty'] . '"></div>
    </div>
    <div class="flex gap-16 mb-8">
        <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_rx"' . ($r['is_rx'] ? ' checked' : '') . '> 处方药</label>
        <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_limited"' . ($r['is_limited'] ? ' checked' : '') . '> 限制类药品</label>
        <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_nurse"' . ($r['need_nurse'] ? ' checked' : '') . '> 需护士站执行</label>
        <label class="flex gap-4" style="font-size:13px;cursor:pointer"><input type="checkbox" id="f_skin_test"' . ((int)$r['need_skin_test'] ? ' checked' : '') . ' onchange="syncSkinBox()"> 需皮试药品</label>
    </div>
    <div class="form-group" id="skin_box" style="' . ((int)$r['need_skin_test'] ? '' : 'display:none') . 'background:var(--bg-soft);border-radius:10px;padding:12px">
        <label class="form-label">关联皮试处置项目 <span class="req">*</span>（开方时自动联动）</label>
        <input type="hidden" id="f_skin_item" value="' . (int)$r['skin_test_item_id'] . '">
        <div class="flex gap-8">
            <input class="input" id="f_skin_item_name" value="' . e($skinName) . '" readonly placeholder="点击右侧按钮选择或新建">
            <button type="button" class="btn btn-outline btn-sm" onclick="pickSkinDisposal()">🔍 选择/新建</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearSkinDisposal()">清除</button>
        </div>
        <div class="fs-12 text-muted mt-4">如：青霉素皮试、头孢菌素类皮试。可在弹窗中检索已有处置，或就地快捷创建（非管理员提交需审核）。</div>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea class="textarea" id="f_note" rows="2">' . e($r['note']) . '</textarea></div>';
    // 给药途径 → 是否需护士站处理 映射（供前端自动勾选）
    $routeMap = array();
    foreach (DB::q('drug', "SELECT name, need_nurse FROM drug_settings WHERE stype='route'") as $rt) {
        $routeMap[$rt['name']] = (int)$rt['need_nurse'];
    }
    return array('html' => $html, 'route_nurse' => $routeMap, 'need_nurse' => (int)$r['need_nurse'],
        'need_skin_test' => (int)(isset($r['need_skin_test']) ? $r['need_skin_test'] : 0),
        'skin_test_item_id' => (int)(isset($r['skin_test_item_id']) ? $r['skin_test_item_id'] : 0),
        'skin_test_item_name' => $skinName);
}
