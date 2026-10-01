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
 * HIS 模块另附「同步与对账监控」面板（his_sync_tasks Outbox 任务重试 +
 * 入向调用审计），支撑财务单边账补偿与排障溯源。
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

/**
 * 接口状态总览行（快速一览：开关/模式/关键地址/凭证/端点数）
 * @param array $g    接口分组定义
 * @param array $vals 当前配置值表
 * @return array [label, value, cls(badge class)]
 */
function itg_status_rows($g, $vals) {
    $rows = array();
    $get = function ($k) use ($vals) { return isset($vals[$k]) ? trim((string)$vals[$k]) : ''; };
    $on = function ($k) use ($vals, $get) { return $get($k) === '1'; };
    $badge = function ($k) use ($vals, $get) { return $get($k) !== ''; };
    // 医保/支付分组：状态总览直接列出各支付方式（微信/支付宝/银行卡/医保卡）开关状态
    if ($g['id'] === 'insurance') {
        $payModes = array(
            array('label' => '微信支付', 'key' => 'pay_wechat_enabled'),
            array('label' => '支付宝', 'key' => 'pay_alipay_enabled'),
            array('label' => '银行卡', 'key' => 'pay_bankcard_enabled'),
            array('label' => '医保卡', 'key' => 'pay_medicare_enabled'),
        );
        foreach ($payModes as $pm) {
            $rows[] = array('label' => $pm['label'], 'value' => $on($pm['key']) ? '已启用' : '未启用', 'cls' => $on($pm['key']) ? 'success' : 'muted');
        }
        // 医保前置机关键配置
        $gw = $get('integration.outbound.insurance.gateway_url');
        $rows[] = array('label' => '医保前置机地址', 'value' => $gw !== '' ? $gw : '未配置', 'cls' => $gw !== '' ? 'info' : 'muted');
        $rows[] = array('label' => '支付结果回调', 'value' => '端点已暴露', 'cls' => 'info');
        return $rows;
    }
    // 开关（rule=bool 或 key 以 .enabled 结尾）：出向/入向启用状态
    foreach ($g['fields'] as $f) {
        $k = $f['key'];
        $isBool = (isset($f['rule']) && $f['rule'] === 'bool') || substr($k, -8) === '.enabled';
        if ($isBool) {
            $rows[] = array('label' => $f['label'], 'value' => $on($k) ? '已启用' : '未启用', 'cls' => $on($k) ? 'success' : 'muted');
        }
    }
    // 模式/通道/传输 select：展示当前选择（.enabled 开关已在上面以徽章展示，跳过避免重复）
    foreach ($g['fields'] as $f) {
        if (isset($f['type']) && $f['type'] === 'select' && substr($f['key'], -8) !== '.enabled') {
            $cur = $get($f['key']);
            if ($cur !== '') {
                $opts = isset($f['options']) ? $f['options'] : array();
                $rows[] = array('label' => $f['label'], 'value' => isset($opts[$cur]) ? $opts[$cur] : $cur, 'cls' => 'primary');
            }
        }
    }
    // 首个网关/地址字段（rule=url）：展示或标记未配置
    $urlShown = false;
    foreach ($g['fields'] as $f) {
        $k = $f['key'];
        if (isset($f['rule']) && $f['rule'] === 'url' && !$urlShown) {
            $v = $get($k);
            $rows[] = array('label' => $f['label'], 'value' => $v !== '' ? $v : '未配置', 'cls' => $v !== '' ? 'info' : 'muted');
            $urlShown = true;
        }
    }
    // 凭证类字段：优先入向 token（.token 结尾），其次 webhook_secret / secret_key / app_secret
    $tokKey = '';
    foreach ($g['fields'] as $f) {
        $k = $f['key'];
        if (preg_match('/(\.token|token|webhook_secret|secret_key|app_secret)$/', $k)) {
            $tokKey = $k;
            if (substr($k, -6) === '.token') break;   // 入向鉴权 token 优先展示
        }
    }
    if ($tokKey !== '') {
        $label = '凭证';
        foreach ($g['fields'] as $f) { if ($f['key'] === $tokKey) { $label = $f['label']; break; } }
        $rows[] = array('label' => $label, 'value' => $badge($tokKey) ? '已配置' : '未配置', 'cls' => $badge($tokKey) ? 'success' : 'muted');
    }
    // 入向开放端点数
    if (!empty($g['endpoints'])) {
        $cnt = 0;
        foreach ($g['endpoints'] as $ep) { if (!empty($ep['path'])) $cnt++; }
        $rows[] = array('label' => '入向开放端点', 'value' => $cnt . ' 个', 'cls' => 'info');
    }
    return $rows;
}
?>
<div class="page-head">
    <div><div class="page-title"><?= render_icon('nav:plug') ?> 接口管理</div>
    <div class="page-desc">外部系统集成中心：FHIR R4 · DICOM/PACS · HL7 v2 · LIS · HIS · 医保支付，统一出向/入向双向两舱架构</div></div>
</div>

<div class="card" style="padding-bottom:6px">
    <div class="itg-tabs" id="itgTabs">
        <?php foreach ($groups as $gi => $g): ?>
            <button type="button" class="itg-tab btn btn-sm<?php echo $gi === 0 ? ' btn-primary' : ' btn-outline'; ?>"
                data-tab="<?php echo e($g['id']); ?>" onclick="itgTab('<?php echo e($g['id']); ?>')">
                <?php echo $g['emoji'] . ' ' . e($g['title']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" style="padding:10px 14px 12px">
        出向 = 本系统调用外部（Client）；入向 = 外部调用本系统（Server，端点只读展示、一键复制）。配置仅保存在本系统数据库，各接口行为由对应服务引擎实现。
    </div>
</div>

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
            <div class="db-nav itg-nav-help" data-itgpan="help" onclick="itgHelp('<?php echo e($g['id']); ?>')"><?= render_icon('action:idea') ?> 帮助</div>
        </div>
        <div class="db-main">

            <!-- ===== 状态总览 ===== -->
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_overview">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon('action:eye') ?> 状态总览</div>
                    <div class="fs-12 text-muted mb-12" style="margin-top:-4px"><?php echo e($g['desc']); ?></div>
                    <div class="itg-status">
                        <?php foreach (itg_status_rows($g, $vals) as $sr): ?>
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

            <?php foreach ($zones as $z): $zKey = ($z === 'common') ? '' : $z; if (!isset($zoneFields[$zKey])) continue; $paneSuffix = ($zKey === '') ? 'common' : $zKey; ?>
            <div class="db-pane" id="itgpan_<?php echo e($g['id']); ?>_<?php echo e($paneSuffix); ?>" style="display:none">
                <div class="card setting-card">
                    <div class="card-title"><?= render_icon($zico($zKey)) ?> <?php echo e($znav($z)); ?></div>
                    <?php foreach ($zoneFields[$zKey] as $f): ?>
                        <?php render_itg_field($f, $vals); ?>
                    <?php endforeach; ?>
                    <?php if ($paneSuffix === 'inbound' && $hasEndpoints): ?>
                        <?php render_itg_endpoints($g, $baseHost); ?>
                    <?php endif; ?>
                    <div class="flex" style="gap:8px;margin-top:14px">
                        <button class="btn btn-primary btn-sm" onclick="itgSave('<?php echo e($g['id']); ?>','<?php echo e($paneSuffix); ?>')">保存<?php echo e($znav($z)); ?></button>
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
                    <div class="card-title"><?= render_icon('nav:chart') ?> HIS 同步与对账监控</div>
                    <div class="fs-12 text-muted mb-8">出向任务（his_sync_tasks）：挂号/结算/发药本地事务提交后异步入队，失败自动累计重试次数，可一键重试。</div>
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
                    <div class="itg-zone-sub-title mt-16">入向调用审计（inbound_events，全部开放端点接收记录）</div>
                    <div class="flex" style="gap:8px;margin:8px 0">
                        <input class="input" id="itgInboundKw" placeholder="端点 / 提供方 / 摘要" style="max-width:260px">
                        <label class="checkbox" style="align-items:center"><input type="checkbox" id="itgInboundFail"> 仅看失败</label>
                        <button type="button" class="btn btn-outline btn-sm" onclick="itgInboundLoad(1)">查询</button>
                    </div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>ID</th><th>端点</th><th>结果</th><th>摘要</th><th>来源 IP</th><th>时间</th></tr></thead>
                        <tbody id="itgInboundBody"></tbody>
                    </table></div>
                    <div id="itgInboundMore" class="flex-center" style="padding:8px"></div>
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
            echo '<button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0" onclick="itgCopy(' . "'" . e($full) . "'" . ')">' . render_icon('action:check') . ' 复制</button>';
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
        $inputType = $isPort ? 'number' : 'text';
        $gen = isset($f['gen']) ? $f['gen'] : '';
        if ($gen !== '') {
            $genTxt = ($gen === 'secret') ? '生成密钥' : '生成 Token';
            echo '<div style="position:relative">';
            echo '<input class="input" id="itg_' . e($key) . '" type="' . $inputType . '" value="' . e($val) . '" style="font-family:monospace;padding-right:104px"' .
                ' placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '">';
            echo '<button type="button" class="btn btn-outline btn-sm itg-gen-btn" style="position:absolute;right:6px;top:50%;transform:translateY(-50%)" onclick="itgGen(\'itg_' . e($key) . '\',\'' . e($gen) . '\')">' . render_icon('nav:key') . ' ' . $genTxt . '</button>';
            echo '</div>';
        } else {
            echo '<input class="input" id="itg_' . e($key) . '" type="' . $inputType . '" value="' . e($val) . '"' . $mono .
                ' placeholder="' . e(isset($f['placeholder']) ? $f['placeholder'] : '') . '">';
        }
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
    if (id === 'his') itgMonLoad(1);
}

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
            if (groupId === 'his' && paneId === 'inbound') { renderHisTokenLive(); }
        },
    });
}

/* ---------- 随机凭证生成（Token / 密钥 / Token 列表追加） ---------- */
function itgGen(fieldId, type) {
    var el = document.getElementById(fieldId);
    if (!el) return;
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
    var el = document.getElementById('itg_integration.inbound.his.token');
    var key = el ? (el.value || '').trim() : '';
    document.querySelectorAll('#itgPane_his .itg-ep-url').forEach(function (c) {
        if (c.getAttribute('data-no-token')) return;
        var base = c.textContent;
        if (base.indexOf('?') >= 0) c.textContent = key ? base + '&token=' + key : base;
    });
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
    Clinic.ajax('/api/admin', { action: 'integration_outbox_clear' }, {
        onSuccess: function (json) { Clinic.toast.success(json.msg); itgMonLoad(1); },
    });
}

/* ---------- 入向调用审计 ---------- */
var itgInboundPage = 1;
function itgInboundLoad(page) {
    itgInboundPage = page || 1;
    var kw = document.getElementById('itgInboundKw').value.trim();
    var fail = document.getElementById('itgInboundFail').checked ? 1 : 0;
    Clinic.ajax('/api/admin', { action: 'integration_inbound_list', kw: kw, fail: fail, page: itgInboundPage, page_size: 20 }, {
        onSuccess: function (json) {
            var d = json.data;
            var body = document.getElementById('itgInboundBody');
            if (!d.list.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-center text-muted fs-12" style="padding:20px">暂无入向调用记录</td></tr>';
            } else {
                body.innerHTML = d.list.map(function (r) {
                    return '<tr>' +
                        '<td class="fs-12">' + r.id + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.endpoint) + '/' + Clinic.escHtml(r.provider) + '</td>' +
                        '<td>' + (r.ok ? '<span class="badge badge-success">成功</span>' : '<span class="badge badge-danger">失败</span>') + '</td>' +
                        '<td class="fs-12"><div class="itg-mon-err" title="' + Clinic.escHtml(r.body || '') + '">' + Clinic.escHtml(r.summary) + '</div></td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.remote_ip) + '</td>' +
                        '<td class="fs-12">' + Clinic.escHtml(r.created_at) + '</td>' +
                        '</tr>';
                }).join('');
            }
            document.getElementById('itgInboundMore').innerHTML = d.has_more
                ? '<button class="btn btn-outline btn-sm" onclick="itgInboundLoad(' + (itgInboundPage + 1) + ')">加载更多</button>'
                : (d.total > 20 ? '<span class="fs-12 text-muted">共 ' + d.total + ' 条</span>' : '');
        },
    });
}
</script>