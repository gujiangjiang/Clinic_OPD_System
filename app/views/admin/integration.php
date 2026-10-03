<?php
/**
 * ============================================================
 * admin/integration.php — 接口管理（外部集成中心 · 双向两舱架构）
 * ============================================================
 * 说明：按系统划分子模块（FHIR R4 / DICOM-PACS / HL7 v2 / LIS / HIS /
 * 医保与支付 / 存证签名），每个模块内部强制划分为：
 *   【出向集成 Outbound】本系统作为 Client 调用外部（配置网关、凭证、超时、触发时机）
 *   【入向开放 Inbound】本系统作为 Server 对外暴露（只读展示端点 + 一键复制 +
 *                        认证规则 / IP 白名单 / 签名说明）
 * HIS 模块另附「同步与对账监控」面板（his_sync_tasks Outbox 任务重试），
 * 支撑财务单边账补偿与排障溯源；入向/出向调用明细统一在【日志中心 → 接口日志】查看。
 * 数据存储：settings 键值对（integration.outbound.* / integration.inbound.*
 * 命名空间，旧键自动迁移），由 /api/admin action=integration_save 读写。
 * ============================================================ */
Router::title('接口管理');

$groups = integration_field_groups();
$vals = array();
foreach ($groups as $g) {
    foreach ($g['fields'] as $f) {
        $vals[$f['key']] = setting($f['key'], isset($f['default']) ? $f['default'] : '');
    }
}
$baseHost = integration_host_base();
$endpoints = integration_inbound_endpoints();
$orgCode = trim((string)setting('org_code', ''));

/* 每个接口子模块的左侧导航（仅渲染该模块实际拥有的配置区） */
$itgNav = array(
    'fhir'       => array('overview' => '状态总览', 'outbound' => '出向上报', 'inbound' => '入向开放', 'common' => '公共配置'),
    'pacs'       => array('overview' => '状态总览', 'outbound' => '出向调阅', 'inbound' => '入向接收', 'common' => '协议通道'),
    'hl7'        => array('overview' => '状态总览', 'outbound' => '出向发送', 'inbound' => '入向接收', 'common' => '公共配置'),
    'lis'        => array('overview' => '状态总览', 'outbound' => '申请下发', 'inbound' => '入向回调', 'common' => '公共配置'),
    'his'        => array('overview' => '状态总览', 'outbound' => '出向同步', 'inbound' => '入向开放', 'common' => '公共配置', 'monitor' => '同步监控'),
    'insurance'  => array('overview' => '状态总览', 'medicare' => '医保卡', 'wechat' => '微信支付', 'alipay' => '支付宝', 'bank' => '银行卡', 'inbound' => '入向回调'),
    'evid'       => array('overview' => '状态总览', 'common' => '存证配置'),
);
/* 分组内页签（zone）顺序与图标 */
$zoneSeq = array(
    'fhir'      => array('outbound', 'inbound', 'common'),
    'pacs'      => array('common', 'outbound', 'inbound'),
    'hl7'       => array('outbound', 'inbound', 'common'),
    'lis'       => array('outbound', 'inbound', 'common'),
    'his'       => array('outbound', 'inbound', 'common'),
    'insurance' => array('medicare', 'wechat', 'alipay', 'bank', 'inbound'),
    'evid'      => array('common'),
);
$zoneIcon = array(
    'common' => 'nav:settings', 'outbound' => 'action:next', 'inbound' => 'action:link',
    'medicare' => 'action:id-card', 'wechat' => 'nav:mobile', 'alipay' => 'action:handshake', 'bank' => 'nav:card',
);

?>
<div class="page-head">
    <div><div class="page-title"><?= render_icon('nav:plug') ?> 接口管理</div>
    <div class="page-desc">外部系统集成中心：FHIR R4 · DICOM/PACS · HL7 v2 · LIS · HIS · 医保支付，统一出向/入向双向两舱架构</div></div>
</div>

<div class="card" style="padding-bottom:6px">
    <div class="itg-tabs" id="itgTabs">
        <?php foreach ($groups as $gi => $g): ?>
            <?php
            // 健康指示：本模块任一启用开关为 1 即点亮
            $gEnabled = false;
            foreach ($g['fields'] as $gf) {
                if ((isset($gf['rule']) && $gf['rule'] === 'bool') || preg_match('/\.enabled$/', $gf['key'])) {
                    if ((isset($vals[$gf['key']]) ? (string)$vals[$gf['key']] : '') === '1') { $gEnabled = true; break; }
                }
            }
            ?>
            <button type="button" class="itg-tab btn btn-sm<?php echo $gi === 0 ? ' btn-primary' : ' btn-outline'; ?>"
                data-tab="<?php echo e($g['id']); ?>" onclick="itgTab('<?php echo e($g['id']); ?>')">
                <span class="itg-tab-dot<?php echo $gEnabled ? ' on' : ''; ?>" title="<?php echo $gEnabled ? '已启用' : '未启用'; ?>"></span>
                <?php echo $g['emoji'] . ' ' . e($g['title']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" id="itgTabDesc" style="padding:10px 14px 12px"></div>
</div>

<?php
/* 各接口子 Tab 的简短描述（点击切换时在 Tab 下方显示） */
$itgTabDesc = array(
    'fhir'      => 'FHIR R4 资源出向上报与入向调阅',
    'pacs'      => 'DICOM/PACS 出向调阅与入向接收',
    'hl7'       => 'HL7 v2.x 出向发送与入向接收',
    'lis'       => 'LIS 检验申请下发与结果回调',
    'his'       => 'HIS 出向同步与入向开放（含同步监控）',
    'insurance' => '医保·支付出向与支付结果回调',
    'evid'      => '电子存证 / 签名的调用与配置',
);
?>

<?php foreach ($groups as $gi => $g): ?>
<?php
    // 字段按 zone 分组（zone 即左侧导航页签；insurance 分组按 支付小类 分列独立保存）
    $zoneFields = array();
    foreach ($g['fields'] as $f) {
        $z = isset($f['zone']) ? $f['zone'] : '';
        if (!isset($zoneFields[$z])) $zoneFields[$z] = array();
        $zoneFields[$z][] = $f;
    }
    $zones = isset($zoneSeq[$g['id']]) ? $zoneSeq[$g['id']] : array_keys($zoneFields);
    $hasEndpoints = !empty($g['endpoints']);
    $znav = function ($z) use ($g, $itgNav) { return isset($itgNav[$g['id']][$z]) ? $itgNav[$g['id']][$z] : $z; };
    $zico = function ($z) use ($zoneIcon) { return isset($zoneIcon[$z]) ? $zoneIcon[$z] : 'nav:settings'; };
    // 入向启用开关键（用于「对外暴露端点」按钮显隐与后端拒绝）
    $inboundEnabledKey = '';
    foreach ($g['fields'] as $gf) {
        if ((isset($gf['zone']) ? $gf['zone'] : '') === 'inbound' && preg_match('/[._]enabled$/', $gf['key'])) { $inboundEnabledKey = $gf['key']; break; }
    }
    // 除医保/支付外，入向端点统一以模态框呈现
    $useEndpointModal = ($hasEndpoints && $g['id'] !== 'insurance');
?>
<div class="itg-pane" id="itgPane_<?php echo e($g['id']); ?>" data-tab="<?php echo e($g['id']); ?>"<?php echo $gi === 0 ? '' : ' style="display:none"'; ?>>
    <div class="db-center itg-center">
        <div class="card db-sidebar itg-sidenav" id="itgNav_<?php echo e($g['id']); ?>">
            <div class="db-nav active" data-itgpan="overview" onclick="itgSideTab('<?php echo e($g['id']); ?>','overview')"><?= render_icon('action:eye') ?> <?php echo e($znav('overview')); ?></div>
            <?php foreach ($zones as $z): $zKey = ($z === 'common') ? '' : $z; if (!isset($zoneFields[$zKey])) continue; ?>
            <div class="db-nav" data-itgpan="<?php echo e($zKey === '' ? 'common' : $zKey); ?>" onclick="itgSideTab('<?php echo e($g['id']); ?>','<?php echo e($zKey === '' ? 'common' : $zKey); ?>')"><?= render_icon($zico($zKey)) ?> <?php echo e($znav($z)); ?></div>
            <?php endforeach; ?>
            <?php if ($hasEndpoints && !isset($zoneFields['inbound'])): ?>
            <div class="db-nav" data-itgpan="inbound" onclick="itgSideTab('<?php echo e($g['id']); ?>','inbound')"><?= render_icon('action:link') ?> <?php echo e($znav('inbound')); ?></div>
            <?php endif; ?>
            <?php if ($g['id'] === 'his'): ?>
            <div class="db-nav" data-itgpan="monitor" onclick="itgSideTab('<?php echo e($g['id']); ?>','monitor')"><?= render_icon('nav:chart') ?> <?php echo e($znav('monitor')); ?></div>
            <?php endif; ?>
            <div class="db-nav" data-itgpan="audit" onclick="itgSideTab('<?php echo e($g['id']); ?>','audit')"><?= render_icon('nav:chart') ?> 变更记录</div>
            <div class="db-nav itg-nav-help" data-itgpan="help" onclick="itgHelp('<?php echo e($g['id']); ?>')"><?= render_icon('action:idea') ?> 帮助</div>
        </div>
        <div class="db-main">

            <!-- ===== 状态总览 ===== -->
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_overview">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon('action:eye') ?> 状态总览</div>
                    <div class="fs-12 text-muted mb-12" style="margin-top:-4px"><?php echo e($g['desc']); ?></div>
                    <div class="itg-status" id="itgStatus_<?php echo e($g['id']); ?>">
                        <?php foreach (IntegrationStatus::rows($g, $vals) as $sr): ?>
                        <div class="itg-status-row">
                            <span class="itg-status-label"><?php echo e($sr['label']); ?></span>
                            <span class="badge badge-<?php echo $sr['cls']; ?>"><?php echo e($sr['value']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex" style="gap:8px;margin-top:14px">
                        <button class="btn btn-primary btn-sm" onclick="itgTest('<?php echo e($g['id']); ?>')"><?= render_icon('action:bolt') ?> 连通性测试</button>
                    </div>
                </div>
            </div>

            <!-- ===== 变更记录 ===== -->
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_audit" style="display:none">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon('nav:chart') ?> 最近配置变更</div>
                    <?php $audits = ConfigAudit::byAreaPrefix('integration:' . $g['id'], 50); ?>
                    <div class="table-wrap itg-fixed-scroll"><table class="table">
                        <thead><tr><th>时间</th><th>操作人</th><th>区域</th><th>变更项</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php if (!$audits): ?>
                            <tr><td colspan="5" class="text-center text-muted fs-12" style="padding:20px">暂无配置变更记录</td></tr>
                        <?php else: foreach ($audits as $a): ?>
                            <tr>
                                <td class="fs-12"><?php echo e($a['created_at']); ?></td>
                                <td class="fs-12"><?php echo e($a['actor']); ?></td>
                                <td class="fs-12"><?php echo e($a['detail']); ?></td>
                                <td class="fs-12" style="max-width:380px;word-break:break-all"><?php echo e($a['keys_changed']); ?></td>
                                <td class="fs-12"><?php echo e($a['ip']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <?php foreach ($zones as $z): $zKey = ($z === 'common') ? '' : $z; if (!isset($zoneFields[$zKey])) continue; $paneSuffix = ($zKey === '') ? 'common' : $zKey; ?>
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_<?php echo e($paneSuffix); ?>" style="display:none">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon($zico($zKey)) ?> <?php echo e($znav($z)); ?></div>
                    <?php foreach ($zoneFields[$zKey] as $f): ?>
                        <?php render_itg_field($f, $vals); ?>
                    <?php endforeach; ?>
                    <?php if ($paneSuffix === 'inbound' && $hasEndpoints && !$useEndpointModal): ?>
                        <?php render_itg_endpoints($g, $baseHost); ?>
                    <?php endif; ?>
                    <div class="flex" style="gap:8px;margin-top:14px">
                        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>','<?php echo e($paneSuffix); ?>')">保存<?php echo e($znav($z)); ?></button>
                        <?php if ($paneSuffix === 'inbound' && $useEndpointModal && $inboundEnabledKey !== ''): ?>
                        <button type="button" class="btn btn-outline btn-sm itg-ep-btn" data-group="<?php echo e($g['id']); ?>" data-enabled-key="<?php echo e($inboundEnabledKey); ?>" onclick="itgEndpoints('<?php echo e($g['id']); ?>')" style="display:none"><?= render_icon('action:link') ?> 对外暴露端点</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($hasEndpoints && !isset($zoneFields['inbound'])): ?>
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_inbound" style="display:none">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon('action:link') ?> <?php echo e($znav('inbound')); ?></div>
                    <?php render_itg_endpoints($g, $baseHost); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($g['id'] === 'his'): ?>
            <div class="db-pane" id="itgpan_his_monitor" style="display:none">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon('nav:chart') ?> 外部集成同步与对账监控</div>
                    <div class="fs-12 text-muted mb-8">出向任务（HIS 挂号/结算/发药 · FHIR Bundle · HL7 · LIS 申请）：本地事务提交后异步入队，失败自动累计重试次数，可一键重试。入向调用记录见【日志中心 → 接口日志】。</div>
                    <div class="itg-mon-row" id="itgMonStats"></div>
                    <div class="flex" style="gap:8px;margin:10px 0 12px">
                        <button type="button" class="btn btn-primary btn-sm" onclick="itgMonRun()"><?= render_icon('action:next') ?> 执行待办</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="itgMonRetryAll()">重试全部失败</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="itgMonClear()">清空历史</button>
                    </div>
                    <div class="flex" style="gap:8px;margin-bottom:10px">
                        <select class="select" id="itgMonStatus" style="width:130px">
                            <option value="">全部状态</option>
                            <option value="pending">待处理</option>
                            <option value="success">成功</option>
                            <option value="failed">失败</option>
                        </select>
                        <input class="input" id="itgMonKw" placeholder="业务类型 / 业务ID / 载荷关键字" style="max-width:260px">
                        <button type="button" class="btn btn-outline btn-sm" onclick="itgMonLoad(1)">查询</button>
                    </div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>ID</th><th>业务类型</th><th>业务ID</th><th>状态</th><th>重试</th><th>错误 / 载荷</th><th>更新时间</th><th></th></tr></thead>
                        <tbody id="itgMonBody"></tbody>
                    </table></div>
                    <div id="itgMonMore" class="flex-center" style="padding:8px"></div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
/**
 * 对外暴露端点渲染（只读 + 一键复制完整 URL；含方法徽章/说明/示例）
 * @param array  $g        接口分组定义
 * @param string $baseHost 站点基地址
 */
function render_itg_endpoints($g, $baseHost) {
    if (empty($g['endpoints'])) return;
    echo '<div class="itg-ep-block">';
    echo '<div class="itg-ep-block-title">' . render_icon('action:link') . ' 对外暴露端点（只读，点击链接或「复制」按钮复制完整地址）</div>';
    foreach ($g['endpoints'] as $ep) {
        if (!empty($ep['path'])) {
            $full = $baseHost . $ep['path'];
            echo '<div class="itg-ep">';
            echo '<div class="itg-ep-head">';
            if (!empty($ep['method'])) echo '<span class="badge badge-success">' . e($ep['method']) . '</span>';
            echo '<span class="fs-13 fw-600">' . e($ep['label']) . '</span>';
            echo '</div>';
            echo '<div class="flex" style="gap:8px">';
            echo '<code class="itg-ep-url" title="点击复制" style="cursor:pointer" onclick="itgCopy(this.textContent.trim())">' . e($full) . '</code>';
            echo '<button type="button" class="btn btn-outline btn-sm itg-copy-btn" style="flex-shrink:0" data-copy="' . e($full) . '">' . render_icon('action:check') . ' 复制</button>';
            echo '</div>';
            if (!empty($ep['note'])) echo '<div class="fs-12 text-muted mt-4">' . e($ep['note']) . '</div>';
            if (!empty($ep['example'])) echo '<div class="itg-ep-example fs-12 mt-4">' . e($ep['example']) . '</div>';
            echo '</div>';
        } else {
            echo '<div class="itg-ep">';
            echo '<div class="itg-ep-head"><span class="fs-13 fw-600">' . e($ep['label']) . '</span></div>';
            if (!empty($ep['note'])) echo '<div class="fs-12 text-muted mt-4">' . e($ep['note']) . '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
}

/**
 * 字段渲染助手（input / select / textarea + show_if 联动）
 * @param array $f    字段定义
 * @param array $vals 当前值表
 */
function render_itg_field($f, $vals) {
    $key = $f['key'];
    $val = isset($vals[$key]) ? (string)$vals[$key] : (isset($f['default']) ? (string)$f['default'] : '');
    $showIf = isset($f['show_if']) ? ' data-show-if="' . e($f['show_if']['key']) . '" data-show-val="' . e($f['show_if']['value']) . '"' : '';
    $mono = !empty($f['monospace']) ? ' style="font-family:monospace"' : '';
    $isPort = isset($f['rule']) && $f['rule'] === 'port';
    echo '<div class="form-group"' . $showIf . '>';
    echo '<label class="form-label">' . e($f['label']);
    if (!empty($f['optional'])) echo ' <span class="fs-12 text-muted" style="font-weight:400">（可选）</span>';
    elseif (!empty($f['monospace'])) echo ' <span class="fs-12 text-muted" style="font-weight:400">（建议保密，勿外传）</span>';
    echo '</label>';
    if (isset($f['type']) && $f['type'] === 'select') {
        echo '<select class="select" id="itg_' . e($key) . '">';
        foreach ($f['options'] as $ov => $ot) {
            echo '<option value="' . e($ov) . '"' . ($val === (string)$ov ? ' selected' : '') . '>' . e($ot) . '</option>';
        }
        echo '</select>';
    } elseif (isset($f['type']) && $f['type'] === 'textarea') {
        $gen = isset($f['gen']) ? $f['gen'] : '';
        $genLabel = ($gen === 'oauthclient') ? '追加客户端' : '追加 Token';
        $genBtn = $gen !== '' ? '<button type="button" class="btn btn-outline btn-sm itg-gen-btn" onclick="itgGen(\'itg_' . e($key) . '\',\'' . e($gen) . '\')">' . render_icon('nav:key') . ' ' . $genLabel . '</button>' : '';
        echo '<div style="position:relative">';
        echo '<textarea class="input" id="itg_' . e($key) . '" rows="3" placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '" style="font-family:monospace;padding-right:' . ($gen !== '' ? '110px' : '0') . '">' . e($val) . '</textarea>';
        if ($genBtn !== '') echo '<span style="position:absolute;right:6px;top:6px">' . $genBtn . '</span>';
        echo '</div>';
    } else {
        // 密钥/Token 类字段默认掩码显示（type=password），附「👁」显示切换，避免明文暴露
        $isSecret = (bool)preg_match('/(secret|token|api_key|password|private_key)$/i', $key) && stripos($key, 'public') === false;
        $inputType = $isPort ? 'number' : ($isSecret ? 'password' : 'text');
        $gen = isset($f['gen']) ? $f['gen'] : '';
        $pad = 6;
        if ($isSecret) $pad += 36;
        if ($gen !== '') $pad += ($gen === 'secret' ? 100 : 88);
        echo '<div style="position:relative">';
        echo '<input class="input' . ($isSecret ? ' itg-secret' : '') . '" id="itg_' . e($key) . '" type="' . $inputType . '" value="' . e($val) . '"' .
            (($isSecret || $gen !== '') ? ' style="font-family:monospace;padding-right:' . $pad . 'px"' : $mono) .
            ' autocomplete="off" placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '">';
        $right = 6;
        if ($isSecret) {
            echo '<button type="button" class="btn btn-outline btn-sm itg-eye" style="position:absolute;right:' . $right . 'px;top:50%;transform:translateY(-50%);padding:2px 7px" title="显示 / 隐藏">' . render_icon('action:eye') . '</button>';
            $right += 36;
        }
        if ($gen !== '') {
            $genTxt = ($gen === 'secret') ? '生成密钥' : '生成 Token';
            echo '<button type="button" class="btn btn-outline btn-sm itg-gen-btn" style="position:absolute;right:' . $right . 'px;top:50%;transform:translateY(-50%)" onclick="itgGen(\'itg_' . e($key) . '\',\'' . e($gen) . '\')">' . render_icon('nav:key') . ' ' . $genTxt . '</button>';
        }
        echo '</div>';
    }
    if (!empty($f['hint'])) echo '<div class="fs-12 text-muted mt-4">' . e($f['hint']) . '</div>';
    echo '</div>';
}
?>

<script>
var ITG_GROUPS = <?php echo json_encode(array_map(function ($g) {
    return array('id' => $g['id'], 'title' => $g['title'],
        'keys' => array_map(function ($f) { return $f['key']; }, $g['fields']),
        'labels' => array_map(function ($f) { return $f['label']; }, $g['fields']),
        'zones' => array_map(function ($f) { return isset($f['zone']) ? $f['zone'] : ''; }, $g['fields']));
}, $groups), JSON_UNESCAPED_UNICODE); ?>;
var ITG_TAB_DESC = <?php echo json_encode($itgTabDesc, JSON_UNESCAPED_UNICODE); ?>;

/* ---------- Tab 切换 ---------- */
function itgTab(id) {
    document.querySelectorAll('#itgTabs .itg-tab').forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('active', on);
        t.classList.toggle('btn-primary', on);
        t.classList.toggle('btn-outline', !on);
    });
    document.querySelectorAll('.itg-pane').forEach(function (p) {
        p.style.display = (p.getAttribute('data-tab') === id) ? '' : 'none';
    });
    var desc = document.getElementById('itgTabDesc');
    if (desc) desc.textContent = ITG_TAB_DESC[id] || '';
    if (id === 'his') itgMonLoad(1);
    itgFitHeight();
}

/* 右侧两栏高度：精确填满视口剩余空间，内容在框内滚动，整页不滚动 */
function itgFitHeight() {
    document.querySelectorAll('.itg-pane').forEach(function (pane) {
        if (pane.style.display === 'none') return;
        var center = pane.querySelector('.itg-center');
        if (!center) return;
        var top = center.getBoundingClientRect().top;
        var h = window.innerHeight - top - 18;   // 底部留 18px，与其它管理页一致
        if (h < 260) h = 260;
        center.style.height = h + 'px';
    });
}
window.addEventListener('resize', itgFitHeight);
window.addEventListener('load', itgFitHeight);

/* ---------- 子模块左右分栏：左侧导航切换右侧内容区 ---------- */
function itgSideTab(groupId, paneId) {
    var nav = document.getElementById('itgNav_' + groupId);
    if (nav) {
        nav.querySelectorAll('.db-nav').forEach(function (n) {
            n.classList.toggle('active', n.getAttribute('data-itgpan') === paneId);
        });
    }
    document.querySelectorAll('.itg-pane[data-tab="' + groupId + '"] .db-pane').forEach(function (p) {
        p.style.display = (p.id === 'itgpan_' + groupId + '_' + paneId) ? '' : 'none';
    });
    // 监控面板：进入时刷新数据
    if (groupId === 'his' && paneId === 'monitor') itgMonLoad(1);
}

/* ---------- 联动显隐（PACS 协议模式等） ---------- */
function itgSyncShowIf() {
    document.querySelectorAll('[data-show-if]').forEach(function (row) {
        var k = row.getAttribute('data-show-if');
        var v = row.getAttribute('data-show-val');
        var el = document.getElementById('itg_' + k);
        row.style.display = (el && el.value === v) ? '' : 'none';
    });
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.id && e.target.id.indexOf('itg_') === 0) itgSyncShowIf();
});
itgSyncShowIf();

/* ---------- 复制 ---------- */
function itgCopy(t) {
    var done = function () { Clinic.toast.success('已复制到剪贴板'); };
    var fallback = function () {
        // 兼容非安全上下文 / 剪贴板 API 被拒绝：textarea + execCommand 兜底
        var ta = document.createElement('textarea');
        ta.value = t;
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            Clinic.toast.warning('复制失败，请手动选择复制');
        }
        ta.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(done).catch(fallback);
    } else {
        fallback();
    }
}

/* ---------- 通用连通性测试（入向本地服务 + 出向远端服务器，无需保存即可测试） ---------- */
function itgTest(groupId) {
    var group = null;
    ITG_GROUPS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    var fieldsHtml = group.keys.map(function (k, i) {
        var el = document.getElementById('itg_' + k);
        var val = el ? el.value : '';
        return '<div class="form-group"><label class="form-label">' + Clinic.escHtml(group.labels[i] || k) + '</label>' +
            '<input class="input" id="itgtest_' + groupId + '_' + i + '" value="' + Clinic.escHtml(val) + '"></div>';
    }).join('');
    Clinic.modal.open(
        '<div class="fs-12 text-muted mb-12">按当前表单值逐条探测入向本地服务与出向远端服务器，无需保存即可测试。</div>' +
        '<div style="max-height:280px;overflow-y:auto">' + fieldsHtml + '</div>' +
        '<div id="itgTestResult" class="mt-12"></div>',
        {
            title: '连通性测试：' + group.title,
            size: 'modal-lg',
            buttons: [
                { text: '关闭', cls: 'btn-outline' },
                { text: '开始测试', cls: 'btn-primary', onClick: function () { itgTestRun(groupId); } },
            ],
        });
}

function itgTestRun(groupId) {
    var group = null;
    ITG_GROUPS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    var data = { action: 'integration_test', group: groupId };
    group.keys.forEach(function (k, i) {
        var el = document.getElementById('itgtest_' + groupId + '_' + i);
        if (el) data[k] = el.value.trim();
    });
    var box = document.getElementById('itgTestResult');
    if (!box) return;
    box.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:22px;height:22px;margin:0 auto"></div>';
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) {
            var d = json.data || {};
            var items = d.items || [];
            box.innerHTML = items.map(function (it) {
                var okCls = it.ok ? 'badge-success' : (it.blocking ? 'badge-danger' : 'badge-warning');
                var okTxt = it.ok ? '通过' : (it.blocking ? '失败' : '提示');
                return '<div class="itg-test-item" style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:8px">' +
                    '<div class="flex-between"><span class="fs-13 fw-600">' + Clinic.escHtml(it.name || '') + '</span>' +
                    '<span class="badge ' + okCls + '">' + okTxt + '</span></div>' +
                    '<div class="fs-12 text-muted" style="margin-top:4px;word-break:break-all">' + Clinic.escHtml(it.detail || '') + '</div>' +
                    '</div>';
            }).join('') || '<div class="fs-12 text-muted">无探测项</div>';
        },
        onError: function (j) {
            box.innerHTML = '<div class="fs-12" style="color:var(--danger,#dc2626)">' + Clinic.escHtml((j && j.msg) || '测试请求失败') + '</div>';
        },
    });
}

/* ---------- 分组保存（按子页签 zone 独立提交：仅收集当前页签字段，互不干扰） ---------- */
function itgSave(groupId, paneId) {
    var group = null;
    ITG_GROUPS.forEach(function (g) { if (g.id === groupId) group = g; });
    if (!group) return;
    paneId = paneId || '';
    // 公共配置页签（paneId='common'）对应无 zone 字段（zone 为空串）
    var zone = (paneId === 'common') ? '' : paneId;
    var data = { action: 'integration_save', group: groupId, zone: zone };
    group.keys.forEach(function (k, i) {
        if ((group.zones[i] || '') !== zone) return;
        var el = document.getElementById('itg_' + k);
        if (el) data[k] = el.value.trim();
    });
    Clinic.ajax('/api/admin', data, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            refreshItgStatus(groupId);   // 状态总览实时刷新（无需整页重载）
            if (groupId === 'his' && paneId === 'inbound') { renderHisTokenLive(); }
        },
    });
}

/* 保存后按已存配置重算「状态总览」并按启用状态刷新页签健康点 */
function refreshItgStatus(groupId) {
    Clinic.ajax('/api/admin', { action: 'integration_status', group: groupId }, {
        onSuccess: function (json) {
            var d = (json && json.data) || {};
            var box = document.getElementById('itgStatus_' + groupId);
            if (box && d.items) {
                box.innerHTML = d.items.map(function (it) {
                    return '<div class="itg-status-row"><span class="itg-status-label">' + Clinic.escHtml(it.label) + '</span>' +
                        '<span class="badge badge-' + it.cls + '">' + Clinic.escHtml(it.value) + '</span></div>';
                }).join('');
            }
            var on = false;
            (d.items || []).forEach(function (it) { if (it.value === '已启用') on = true; });
            var dot = document.querySelector('#itgTabs .itg-tab[data-tab="' + groupId + '"] .itg-tab-dot');
            if (dot) dot.classList.toggle('on', on);
        },
    });
}

/* ---------- 随机凭证生成（Token / 密钥 / Token 列表追加） ---------- */
function itgGen(fieldId, type) {
    var el = document.getElementById(fieldId);
    if (!el) return;
    if ((type === 'secret' || type === 'token') && (el.value || '').trim() !== '' &&
        !confirm('将覆盖现有密钥/Token（旧值立即失效），确认重新生成？')) return;
    var rnd = function (bytes) {
        var arr = new Uint8Array(bytes);
        (window.crypto || window.msCrypto).getRandomValues(arr);
        return Array.prototype.map.call(arr, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    };
    var appendLine = function (line) {
        var cur = (el.value || '').replace(/\s+$/, '');
        el.value = (cur === '' ? '' : cur + '\n') + line;
    };
    if (type === 'tokenline') {
        // 长期静态 Token 列表：追加一行完整 5 段「名称,Token,,1,system/*.read」
        var n1 = (el.value || '').split('\n').filter(function (l) { return l.trim() !== ''; }).length + 1;
        appendLine('调用方' + n1 + ',' + rnd(16) + ',,1,system/*.read');
    } else if (type === 'oauthclient') {
        // OAuth2 客户端列表：追加一行「clientN,Secret,Scope」
        var n2 = (el.value || '').split('\n').filter(function (l) { return l.trim() !== ''; }).length + 1;
        appendLine('client' + n2 + ',' + rnd(16) + ',system/*.read');
    } else {
        el.value = rnd(32);
    }
    if (fieldId === 'itg_integration.inbound.his.token') renderHisTokenLive();
    Clinic.toast.success('已生成，请保存本组配置生效');
}

/* ---------- 帮助浏览器（每个接口子模块独立 HTML 帮助页，iframe 内嵌查看） ---------- */
var ITG_HELP_TITLE = { fhir: 'FHIR R4', pacs: 'DICOM / PACS', hl7: 'HL7 v2', lis: 'LIS 检验', his: 'HIS 接口', insurance: '医保 / 支付', evid: '存证 / 签名' };
function itgHelp(groupId) {
    var url = '/assets/help/integration-' + groupId + '.html';
    var title = '接口帮助：' + (ITG_HELP_TITLE[groupId] || groupId);
    var html = '<div class="itg-help-bar">' +
        '<span>帮助文档</span>' +
        '<span class="itg-help-url">' + url + '</span>' +
        '<button type="button" class="btn btn-outline btn-sm" onclick="window.open(\'' + url + '\',\'' + '_blank' + '\')"><?= render_icon("action:launch") ?> 新窗口打开</button>' +
        '</div>' +
        '<iframe class="itg-help-frame" src="' + url + '" loading="lazy"></iframe>';
    Clinic.modal.open(html, {
        title: title,
        size: 'modal-xl',
        buttons: [{ text: '关闭', cls: 'btn-outline' }],
    });
    // 帮助为只读查看项：不切换导航高亮（保留当前页签）
}
function renderHisTokenLive() {
    // 安全：端点 URL 不拼接明文 Token（避免复制/截图泄露）；调用方应经请求头 X-API-Key / Bearer 携带（见帮助页）。
}

/* ---------- HIS 同步与对账监控 ---------- */
var itgMonPage = 1;
function itgMonStatsBadge(s) {
    if (s === 'success') return '<span class="badge badge-success">成功</span>';
    if (s === 'failed') return '<span class="badge badge-danger">失败</span>';
    return '<span class="badge badge-warning">待处理</span>';
}
function itgMonLoad(page) {
    itgMonPage = page || 1;
    var status = document.getElementById('itgMonStatus').value;
    var kw = document.getElementById('itgMonKw').value.trim();
    Clinic.ajax('/api/admin', { action: 'integration_outbox_list', status: status, kw: kw, page: itgMonPage, page_size: 20 }, {
        onSuccess: function (json) {
            var d = json.data;
            var s = d.stats || {};
            document.getElementById('itgMonStats').innerHTML =
                '<span class="badge badge-warning">待处理 ' + (s.pending || 0) + '</span> ' +
                '<span class="badge badge-success">成功 ' + (s.success || 0) + '</span> ' +
                '<span class="badge badge-danger">失败 ' + (s.failed || 0) + '</span>';
            var body = document.getElementById('itgMonBody');
            if (!d.list.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted fs-12" style="padding:20px">暂无同步任务</td></tr>';
            } else {
                body.innerHTML = d.list.map(function (t) {
                    return '<tr>' +
                        '<td class="fs-12">' + t.id + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(t.business_type) + '</td>' +
                        '<td class="fs-12">' + t.business_id + '</td>' +
                        '<td>' + itgMonStatsBadge(t.status) + '</td>' +
                        '<td class="fs-12">' + t.retry_count + '</td>' +
                        '<td class="fs-12"><div class="itg-mon-err">' + (t.last_error ? Clinic.escHtml(t.last_error) : Clinic.escHtml(t.payload)) + '</div></td>' +
                        '<td class="fs-12">' + Clinic.escHtml(t.updated_at) + '</td>' +
                        '<td>' + (t.status !== 'success'
                            ? '<button class="btn btn-outline btn-sm" onclick="itgMonRetry(' + t.id + ')">重试</button>'
                            : '') + '</td>' +
                        '</tr>';
                }).join('');
            }
            document.getElementById('itgMonMore').innerHTML = d.has_more
                ? '<button class="btn btn-outline btn-sm" onclick="itgMonLoad(' + (itgMonPage + 1) + ')">加载更多</button>'
                : (d.total > 20 ? '<span class="fs-12 text-muted">共 ' + d.total + ' 条</span>' : '');
        },
    });
}
function itgMonRetry(id) {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_retry', id: id }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}
function itgMonRetryAll() {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_retry', id: 0 }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}
function itgMonRun() {
    Clinic.ajax('/api/admin', { action: 'integration_outbox_run' }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            setTimeout(function () { itgMonLoad(1); }, 1500);
        },
    });
}
function itgMonClear() {
    if (!confirm('确认清空同步历史？失败记录也会一并清空，且不可恢复。')) return;
    Clinic.ajax('/api/admin', { action: 'integration_outbox_clear' }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}

/* ---------- 密钥显示切换 / 端点复制（事件委托，避免内联注入） ---------- */
document.addEventListener('click', function (e) {
    var eye = e.target && e.target.closest ? e.target.closest('.itg-eye') : null;
    if (eye) {
        var inp = eye.parentNode ? eye.parentNode.querySelector('input') : null;
        if (inp) inp.type = (inp.type === 'password') ? 'text' : 'password';
        return;
    }
    var cp = e.target && e.target.closest ? e.target.closest('.itg-copy-btn') : null;
    if (cp) itgCopy(cp.getAttribute('data-copy') || '');
});

/* ---------- 对外暴露端点（模态框，入向未启用则隐藏且后端拒绝） ---------- */
function itgSyncEpButtons() {
    document.querySelectorAll('.itg-ep-btn').forEach(function (btn) {
        var key = btn.getAttribute('data-enabled-key') || '';
        var sel = key ? document.getElementById('itg_' + key) : null;
        if (!sel) { btn.style.display = 'none'; return; }
        btn.style.display = (sel.value === '1') ? '' : 'none';
        if (!btn._epBound) {
            btn._epBound = true;
            sel.addEventListener('change', function () { btn.style.display = (sel.value === '1') ? '' : 'none'; });
        }
    });
}
function itgEndpoints(groupId) {
    Clinic.ajax('/api/admin', { action: 'integration_endpoints', group: groupId }, {
        onSuccess: function (json) {
            var d = (json && json.data) || {};
            var items = d.items || [];
            var html = '<div class="fs-12 text-muted mb-8">以下为本系统「' + Clinic.escHtml(d.title || '') + '」对外暴露的入向端点（只读，点击可复制）。调用请携带对应鉴权头。</div>';
            if (!items.length) html += '<div class="fs-12 text-muted">暂无对外端点</div>';
            items.forEach(function (ep) {
                html += '<div class="itg-ep">' +
                    '<div class="itg-ep-head">' + (ep.method ? '<span class="badge badge-success">' + Clinic.escHtml(ep.method) + '</span>' : '') +
                    '<span class="fs-13 fw-600">' + Clinic.escHtml(ep.label || '') + '</span></div>' +
                    '<div class="flex" style="gap:8px"><code class="itg-ep-url" style="cursor:pointer" onclick="itgCopy(this.textContent.trim())">' + Clinic.escHtml(ep.url) + '</code>' +
                    '<button type="button" class="btn btn-outline btn-sm itg-copy-btn" data-copy="' + Clinic.escHtml(ep.url) + '" style="flex-shrink:0">复制</button></div>' +
                    (ep.note ? '<div class="fs-12 text-muted mt-4">' + Clinic.escHtml(ep.note) + '</div>' : '') +
                    (ep.example ? '<div class="itg-ep-example fs-12 mt-4">' + Clinic.escHtml(ep.example) + '</div>' : '') +
                    '</div>';
            });
            Clinic.modal.open(html, { title: '对外暴露端点：' + (d.title || ''), size: 'modal-lg', buttons: [{ text: '关闭', cls: 'btn-outline' }] });
        },
        onError: function (j) { Clinic.toast.error((j && j.msg) || '端点加载失败'); },
    });
}
itgSyncEpButtons();
/* 初始子 Tab 描述（首个分组） */
(function () {
    var desc = document.getElementById('itgTabDesc');
    if (desc && ITG_GROUPS.length) desc.textContent = ITG_TAB_DESC[ITG_GROUPS[0].id] || '';
})();
itgFitHeight();
</script>