<?php
/**
 * ============================================================
 * services/IntegrationStatus.php — 接口状态总览
 * ============================================================
 * 根据接口分组定义与当前配置值计算「状态总览」行（开关/模式/地址/凭证/端点数）。
 * 供管理页渲染与保存后实时刷新（integration_status 动作）共用，避免两处不一致。
 * ============================================================ */
class IntegrationStatus {

    /**
     * @param array $g    接口分组定义（integration_group 返回结构）
     * @param array $vals 当前配置值表（key => value）
     * @return array [ ['label'=>, 'value'=>, 'cls'=>], ... ]
     */
    public static function rows($g, $vals) {
        $rows = array();
        $get = function ($k) use ($vals) { return isset($vals[$k]) ? trim((string)$vals[$k]) : ''; };
        $on = function ($k) use ($vals, $get) { return $get($k) === '1'; };
        $badge = function ($k) use ($vals, $get) { return $get($k) !== ''; };

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
            $gw = $get('integration.outbound.insurance.gateway_url');
            $rows[] = array('label' => '医保前置机地址', 'value' => $gw !== '' ? $gw : '未配置', 'cls' => $gw !== '' ? 'info' : 'muted');
            $anyPay = $on('pay_wechat_enabled') || $on('pay_alipay_enabled');
            $rows[] = array('label' => '支付结果回调', 'value' => $anyPay ? '端点已暴露（待接入验签）' : '未启用', 'cls' => $anyPay ? 'info' : 'muted');
            return $rows;
        }
        foreach ($g['fields'] as $f) {
            $k = $f['key'];
            $isBool = (isset($f['rule']) && $f['rule'] === 'bool') || substr($k, -8) === '.enabled';
            if ($isBool) {
                $rows[] = array('label' => $f['label'], 'value' => $on($k) ? '已启用' : '未启用', 'cls' => $on($k) ? 'success' : 'muted');
            }
        }
        foreach ($g['fields'] as $f) {
            if (isset($f['type']) && $f['type'] === 'select' && substr($f['key'], -8) !== '.enabled') {
                $cur = $get($f['key']);
                if ($cur !== '') {
                    $opts = isset($f['options']) ? $f['options'] : array();
                    $rows[] = array('label' => $f['label'], 'value' => isset($opts[$cur]) ? $opts[$cur] : $cur, 'cls' => 'primary');
                }
            }
        }
        $urlShown = false;
        foreach ($g['fields'] as $f) {
            $k = $f['key'];
            if (isset($f['rule']) && $f['rule'] === 'url' && !$urlShown) {
                $v = $get($k);
                $rows[] = array('label' => $f['label'], 'value' => $v !== '' ? $v : '未配置', 'cls' => $v !== '' ? 'info' : 'muted');
                $urlShown = true;
            }
        }
        $tokKey = '';
        foreach ($g['fields'] as $f) {
            $k = $f['key'];
            if (preg_match('/(\.token|token|webhook_secret|secret_key|app_secret|allowed_tokens|oauth_clients|oauth_secret)$/', $k)) {
                $tokKey = $k;
                if (substr($k, -6) === '.token') break;
            }
        }
        if ($tokKey !== '') {
            $label = '凭证';
            foreach ($g['fields'] as $f) { if ($f['key'] === $tokKey) { $label = $f['label']; break; } }
            $rows[] = array('label' => $label, 'value' => $badge($tokKey) ? '已配置' : '未配置', 'cls' => $badge($tokKey) ? 'success' : 'muted');
        }
        if (!empty($g['endpoints'])) {
            $cnt = 0;
            foreach ($g['endpoints'] as $ep) { if (!empty($ep['path'])) $cnt++; }
            $rows[] = array('label' => '入向开放端点', 'value' => $cnt . ' 个', 'cls' => 'info');
        }
        return $rows;
    }

    /** 以已保存配置（setting）计算某分组的当前值表并返回状态行 */
    public static function rowsForGroup($group) {
        $vals = array();
        foreach ($group['fields'] as $f) {
            $vals[$f['key']] = (string)setting($f['key'], isset($f['default']) ? $f['default'] : '');
        }
        return self::rows($group, $vals);
    }
}
