<?php
/**
 * ============================================================
 * parts/admin_log.php — 管理端：日志中心
 * ============================================================
 * 说明：日志中心读写接口（仅管理员）：
 *   log_meta           日志元数据（通道/分类/级别/开关/服务器日志来源）
 *   log_list           系统日志分页（操作/接口，倒序，游标上滑加载）
 *   log_latest         系统日志增量拉取（实时刷新，正序）
 *   log_server_list    服务器日志文件分页读取（尾部游标）
 *   log_clear          清空日志（系统日志按通道/分类；服务器日志截断文件）
 *   log_settings_get   日志管理配置读取
 *   log_settings_save  日志管理配置保存
 * ============================================================ */

/** 处理日志中心相关动作 */
function admin_part_log($action) {
    /* ==================== 元数据 ==================== */
    if ($action === 'log_meta') {
        json_ok(array(
            'enabled'      => LogService::enabled(),
            'channels'     => array(
                'operation' => LogService::channelEnabled('operation'),
                'interface' => LogService::channelEnabled('interface'),
                'server'    => LogService::channelEnabled('server'),
            ),
            'op_categories' => LogService::operationCategories(),
            'if_categories' => LogService::interfaceCategories(),
            'levels'        => LogService::LEVELS,
            'sources'       => LogService::serverSources(),
        ));
    }

    /* ==================== 系统日志分页（倒序，支持上滑加载更旧） ==================== */
    if ($action === 'log_list') {
        $channel = get('channel') === 'interface' ? 'interface' : 'operation';
        $filter = array(
            'category'  => trim((string)get('category', '')),
            'direction' => trim((string)get('direction', '')),
            'level'     => trim((string)get('level', '')),
            'date'      => trim((string)get('date', '')),
            'kw'        => trim((string)get('kw', '')),
            'before_id' => (int)get('before_id', 0),
        );
        $limit = (int)get('limit', 50);
        $res = LogService::query($channel, $filter, $limit);
        json_ok(array(
            'list'     => array_map('log_row_out', $res['list']),
            'has_more' => $res['has_more'],
            'counts'   => LogService::counts($channel),
        ));
    }

    /* ==================== 系统日志增量拉取（实时刷新，正序） ==================== */
    if ($action === 'log_latest') {
        $channel = get('channel') === 'interface' ? 'interface' : 'operation';
        $filter = array(
            'category'  => trim((string)get('category', '')),
            'direction' => trim((string)get('direction', '')),
            'level'     => trim((string)get('level', '')),
            'date'      => trim((string)get('date', '')),
            'kw'        => trim((string)get('kw', '')),
        );
        $rows = LogService::latest($channel, (int)get('after_id', 0), (int)get('limit', 100), $filter);
        // 同步返回分类计数：供前端实时刷新左侧栏数量
        json_ok(array('list' => array_map('log_row_out', $rows), 'counts' => LogService::counts($channel)));
    }

    /* ==================== 服务器日志（文件尾部读取） ==================== */
    if ($action === 'log_server_list') {
        $source = get('source', 'app');
        // 每日一次按容量与保留设置维护应用日志文件（外部日志只读不动）
        LogService::maintainServerFiles();
        // 服务器日志已在日志管理中关闭：不再读取，前端提示
        if (!LogService::channelEnabled('server')) {
            json_ok(array('list' => array(), 'has_more' => false, 'exists' => false,
                'path' => '', 'source' => $source, 'disabled' => true));
        }
        $offset = (int)get('offset', 0);
        $limit = (int)get('limit', 200);
        $level = trim((string)get('level', ''));
        $kw = trim((string)get('kw', ''));
        $date = trim((string)get('date', ''));
        $res = LogService::readServer($source, $offset, $limit, $level, $kw, $date);
        json_ok(array(
            'list'     => $res['list'],
            'has_more' => $res['has_more'],
            'exists'   => $res['exists'],
            'path'     => $res['path'],
            'source'   => $source,
            // 各服务器日志来源行数：供前端左侧栏计数实时刷新（清空/增量后同步）
            'counts'   => LogService::serverSourceLines(),
        ));
    }

    /* ==================== 清空日志 ==================== */
    if ($action === 'log_clear') {
        $source = trim((string)post('source', ''));
        if ($source !== '') {
            if (!in_array($source, array('app', 'external'), true)) json_fail('未知日志来源');
            if (!LogService::clearServer($source)) json_fail('日志文件不存在或不可写');
            json_ok(array(), '服务器日志已清空');
        }
        $channel = trim((string)post('channel', ''));
        $category = trim((string)post('category', ''));
        $n = LogService::clear($channel, $category);
        json_ok(array('cleared' => $n), '已清空 ' . $n . ' 条日志');
    }

    /* ==================== 日志管理配置读取 ==================== */
    if ($action === 'log_settings_get') {
        $d = LogService::defaults();
        $out = array();
        foreach ($d as $k => $v) {
            $out[$k] = LogService::cfg($k, $v);
        }
        // 级别开关未在 defaults 中，补充读取
        foreach (LogService::LEVELS as $lv) {
            $out['log.level.' . $lv] = LogService::cfg('log.level.' . $lv, '1');
        }
        $out['sources'] = LogService::serverSources();
        json_ok($out);
    }

    /* ==================== 日志管理配置保存 ==================== */
    if ($action === 'log_settings_save') {
        // 总开关关闭时锁定其余设置：仅允许「重新开启」，拒绝任何修改（防绕过前端）
        $currentlyEnabled = LogService::cfg('log.enabled', '1') === '1';
        $wantEnabled = ((string)post('enabled', '1') === '1');
        if (!$currentlyEnabled && !$wantEnabled) {
            json_fail('日志记录已禁用，无法修改日志设置，请先开启日志记录');
        }
        $bool = function ($v) { return ((string)$v === '1') ? '1' : '0'; };
        set_setting('log.enabled', $bool(post('enabled', '1')));
        set_setting('log.channel.operation', $bool(post('channel_operation', '1')));
        set_setting('log.channel.interface', $bool(post('channel_interface', '1')));
        set_setting('log.channel.server', $bool(post('channel_server', '1')));
        foreach (LogService::LEVELS as $lv) {
            set_setting('log.level.' . $lv, $bool(post('level_' . $lv, '1')));
        }
        $maxRows = (int)post('max_rows', 5000);
        if ($maxRows < 100) $maxRows = 100;
        set_setting('log.max_rows', (string)$maxRows);
        $days = (int)post('retention_days', 30);
        if ($days < 0) $days = 0;
        set_setting('log.retention_days', (string)$days);
        // 检索默认时间范围（天）：0=不限制；防止无日期条件下全表扫描
        $qdays = (int)post('query_default_days', 3);
        if ($qdays < 0) $qdays = 0;
        if ($qdays > 365) $qdays = 365;
        set_setting('log.query.default_days', (string)$qdays);
        $maxKb = (int)post('server_max_kb', 1024);
        if ($maxKb < 64) $maxKb = 64;
        set_setting('log.server.max_kb', (string)$maxKb);
        set_setting('log.server.external_path', trim((string)post('server_external_path', '')));
        json_ok(array(), '日志管理配置已保存');
    }

    json_fail('未知操作');
}

/** 日志行输出格式化（统一字段，避免暴露内部列名差异） */
function log_row_out($r) {
    return array(
        'id'         => (int)$r['id'],
        'category'   => (string)$r['category'],
        'direction'  => (string)$r['direction'],
        'action'     => (string)$r['action'],
        'level'      => (string)$r['level'],
        'level_name' => LogService::levelName((string)$r['level']),
        'summary'    => (string)$r['summary'],
        'detail'     => (string)$r['detail'],
        'payload'    => (string)$r['payload'],
        'username'   => (string)$r['username'],
        'role'       => (string)$r['role'],
        'target'     => (string)$r['target'],
        'remote_ip'  => (string)$r['remote_ip'],
        'user_agent' => (string)$r['user_agent'],
        'created_at' => (string)$r['created_at'],
    );
}
