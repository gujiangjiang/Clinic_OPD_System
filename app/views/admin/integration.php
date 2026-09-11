<?php
/**
 * ============================================================
 * admin/integration.php — 外部接口集成（统一外部接口设置中心）
 * ============================================================
 * 说明：原系统设置中的【HIS 接口配置】迁移至此，并新增按 Tab 选项卡
 * 统一维护的外部接口配置：
 *   1. HIS 接口（API 地址、系统代码、密钥、同步模式）
 *   2. 支付接口（微信支付、支付宝、聚合收单占位）
 *   3. 医保接口（国家/地方医保前置机、机构编码、操作员账号）
 *   4. 医疗影像与互联标准接口（重点）：
 *      - DICOM/PACS（PACS 服务器、AETitle、WADO-RS/DICOMweb、Web 阅片器 URL 模板 {study_uid}）
 *      - HL7 v2.x（MLLP/HTTP 接收地址、发送方/接收方标识）
 *      - FHIR（R4 Endpoint、Token 认证参数）
 * 数据存储：settings 键值对（pacs_/hl7_/fhir_/his_/pay_/yibao_ 前缀），
 * 由 /api/admin action=integration_load / integration_save 读写。
 * ============================================================ */
Router::title('外部接口集成');

$groups = integration_field_groups();
$vals = array();
foreach ($groups as $g) {
    foreach ($g['fields'] as $f) {
        $vals[$f['key']] = setting($f['key'], $f['default']);
    }
}
?>
<div class="page-head">
    <div><div class="page-title">🔌 外部接口集成</div>
    <div class="page-desc">统一维护 HIS、支付、医保与医疗影像互联标准接口（DICOM/PACS · HL7 v2.x · FHIR R4）配置</div></div>
</div>

<div class="card" style="padding-bottom:6px">
    <div class="itg-tabs" id="itgTabs">
        <?php foreach ($groups as $gi => $g): ?>
            <button type="button" class="itg-tab<?php echo $gi === 0 ? ' active' : ''; ?>"
                data-tab="<?php echo e($g['id']); ?>" onclick="itgTab('<?php echo e($g['id']); ?>')">
                <?php echo e($g['emoji'] . ' ' . $g['title']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" style="padding:10px 14px 12px">
        提示：以下配置仅保存在本系统数据库（settings 键值对），不会自动连接外部服务；各接口调用行为需由对应功能模块实现。带 <span class="req">*</span> 的密钥类字段请妥善保管。
    </div>
</div>

<?php foreach ($groups as $gi => $g): ?>
    <div class="card itg-pane" id="itgPane_<?php echo e($g['id']); ?>"<?php echo $gi === 0 ? '' : ' style="display:none"'; ?>>
        <div class="card-title"><?php echo e($g['emoji'] . ' ' . $g['title']); ?></div>
        <?php if (!empty($g['desc'])): ?>
            <div class="fs-12 text-muted mb-12" style="margin-top:-8px"><?php echo e($g['desc']); ?></div>
        <?php endif; ?>
        <?php foreach ($g['fields'] as $f): ?>
            <div class="form-group">
                <label class="form-label"><?php echo e($f['label']); ?>
                    <?php if (!empty($f['monospace'])): ?><span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span><?php endif; ?>
                </label>
                <?php if ($f['type'] === 'select'): ?>
                    <select class="select" id="itg_<?php echo e($f['key']); ?>">
                        <?php foreach ($f['options'] as $ov => $ot): ?>
                            <option value="<?php echo e($ov); ?>"<?php echo $vals[$f['key']] === (string)$ov ? ' selected' : ''; ?>><?php echo e($ot); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="input" id="itg_<?php echo e($f['key']); ?>"
                        value="<?php echo e($vals[$f['key']]); ?>"
                        placeholder="<?php echo e($f['placeholder']); ?>"
                        <?php if (!empty($f['monospace'])): ?> style="font-family:monospace"<?php endif; ?>>
                <?php endif; ?>
                <?php if (!empty($f['hint'])): ?>
                    <div class="fs-12 text-muted mt-4"><?php echo e($f['hint']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($g['id'] === 'his'): ?>
            <div class="fs-12 text-muted mb-12">
                接口地址：/api/his（GET，携带 api_key 参数或 X-HIS-Key 请求头），仅提供只读查询；密钥留空 = 关闭 HIS 外部接口。
            </div>
        <?php endif; ?>
        <?php if ($g['id'] === 'pacs'): ?>
            <div class="fs-12 text-muted mb-12">
                Web 阅片器 URL 模板支持 <code>{study_uid}</code> 变量替换：书写阅片时系统会将当前检查对应的 Study UID 替换进模板打开阅片器。
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>')">保存本组配置</button>
    </div>
<?php endforeach; ?>

<script>
/* ---------- Tab 选项卡切换 ---------- */
function itgTab(id) {
    document.querySelectorAll('#itgTabs .itg-tab').forEach(function (t) {
        t.classList.toggle('active', t.getAttribute('data-tab') === id);
    });
    document.querySelectorAll('.itg-pane').forEach(function (p) {
        p.style.display = (p.id === 'itgPane_' + id) ? '' : 'none';
    });
}

/* ---------- 分组保存（仅提交该组字段） ---------- */
var ITG_KEYS = <?php echo json_encode(array_map(function ($g) {
    return array('id' => $g['id'], 'keys' => array_map(function ($f) { return $f['key']; }, $g['fields']));
}, $groups), JSON_UNESCAPED_UNICODE); ?>;

function itgSave(groupId) {
    var group = null;
    ITG_KEYS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    var data = { action: 'integration_save', group: groupId };
    group.keys.forEach(function (k) {
        var el = document.getElementById('itg_' + k);
        if (el) data[k] = el.value.trim();
    });
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); },
    });
}
</script>
