<?php
/**
 * ============================================================
 * parts/admin_integration.php — 管理端：外部集成监控面板
 * ============================================================
 * 说明：HIS 出向同步与对账监控 + 入向调用审计：
 *   integration_outbox_stats   任务统计（pending/success/failed）
 *   integration_outbox_list    任务列表（状态/关键字过滤 + 分页 + 载荷/响应预览）
 *   integration_outbox_retry   一键重试（单条 / 全部失败）
 *   integration_outbox_clear   清空历史（保留失败记录）
 *   integration_outbox_run     触发后台 worker 立即执行待办
 *   integration_inbound_list   入向调用记录（审计，倒序分页）
 * ============================================================ */

/** 处理外部集成监控相关动作 */
function admin_part_integration($action) {
    $u = Auth::user();

    /* ==================== 出向任务统计 ==================== */
    if ($action === 'integration_outbox_stats') {
        json_ok(array('stats' => HisOutbox::stats()));
    }

    /* ==================== 出向任务列表 ==================== */
    if ($action === 'integration_outbox_list') {
        $status = trim(get('status', ''));
        $kw = trim(get('kw', ''));
        list($page, $pageSize) = paged_params(20);
        $res = HisOutbox::listTasks($status, $kw, $page, $pageSize);
        $list = array();
        foreach ($res['list'] as $t) {
            $payload = json_decode((string)$t['payload'], true);
            $list[] = array(
                'id' => (int)$t['id'],
                'business_type' => (string)$t['business_type'],
                'business_id' => (int)$t['business_id'],
                'status' => (string)$t['status'],
                'retry_count' => (int)$t['retry_count'],
                'last_error' => (string)$t['last_error'],
                'payload' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : (string)$t['payload'],
                'created_at' => (string)$t['created_at'],
                'updated_at' => (string)$t['updated_at'],
            );
        }
        json_ok(array(
            'list' => $list,
            'total' => $res['total'],
            'page' => $res['page'],
            'page_size' => $res['page_size'],
            'has_more' => paged_has_more($res['page'], $res['page_size'], $res['total']),
            'stats' => HisOutbox::stats(),
        ));
    }

    /* ==================== 一键重试（id=0 全部失败；否则单条） ==================== */
    if ($action === 'integration_outbox_retry') {
        $id = (int)get('id', 0);
        if ($id > 0) {
            if (!HisOutbox::retryTask($id)) json_fail('任务不存在');
        } else {
            $n = HisOutbox::retryFailed();
            if ($n === 0) json_fail('没有可重试的失败任务');
        }
        integration_spawn_worker();
        json_ok(array(), '已重置为待处理并触发后台执行');
    }

    /* ==================== 清空历史（保留失败记录供人工排查） ==================== */
    if ($action === 'integration_outbox_clear') {
        $n = HisOutbox::clearHistory();
        json_ok(array('cleared' => $n), '已清空 ' . $n . ' 条历史记录（失败记录保留）');
    }

    /* ==================== 触发后台 worker 执行待办 ==================== */
    if ($action === 'integration_outbox_run') {
        integration_spawn_worker();
        json_ok(array(), '已触发后台执行，请稍候刷新查看结果');
    }

    /* ==================== 入向调用审计列表 ==================== */
    if ($action === 'integration_inbound_list') {
        $kw = trim(get('kw', ''));
        $onlyFail = (int)get('fail', 0) === 1;
        list($page, $pageSize) = paged_params(20);
        $where = '1=1';
        $params = array();
        if ($onlyFail) { $where .= ' AND ok=0'; }
        if ($kw !== '') {
            $where .= ' AND (endpoint LIKE ? OR provider LIKE ? OR summary LIKE ?)';
            $like = '%' . $kw . '%';
            $params = array_merge($params, array($like, $like, $like));
        }
        $total = (int)DB::val('SELECT COUNT(*) FROM inbound_events WHERE ' . $where, $params);
        $rows = DB::q(
            'SELECT * FROM inbound_events WHERE ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            array_merge($params, array($pageSize, ($page - 1) * $pageSize))
        );
        $list = array();
        foreach ($rows as $r) {
            $list[] = array(
                'id' => (int)$r['id'],
                'endpoint' => (string)$r['endpoint'],
                'provider' => (string)$r['provider'],
                'ok' => (int)$r['ok'] === 1,
                'summary' => (string)$r['summary'],
                'body' => (string)$r['body'],
                'remote_ip' => (string)$r['remote_ip'],
                'created_at' => (string)$r['created_at'],
            );
        }
        json_ok(array(
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => paged_has_more($page, $pageSize, $total),
        ));
    }

    json_fail('未知操作');
}