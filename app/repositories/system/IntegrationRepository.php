<?php
/**
 * ============================================================
 * IntegrationRepository.php — 外部集成数据仓库
 * ============================================================
 * 说明：封装外部接口双向数据表：
 *  - inbound_events   入向调用审计表（FHIR/HL7/LIS/HIS/支付回调统一落账）
 *  - his_sync_tasks   出向同步任务补偿表（Outbox：挂号/结算/开单/发药异步入队）
 * 统一经主库 DatabaseManager::getMain() 预编译参数绑定。
 * ============================================================ */
class IntegrationRepository extends BaseRepository {

    /** 入向调用落账（审计表，监控面板溯源） */
    public static function logInbound($endpoint, $provider, $ok, $summary, $body, $ip) {
        return self::insert(
            'INSERT INTO inbound_events(endpoint, provider, is_success, summary, payload, remote_ip, created_at) VALUES(?,?,?,?,?,?,?)',
            array($endpoint, $provider, $ok ? 1 : 0, (string)$summary, substr((string)$body, 0, 8000), $ip, now_str())
        );
    }

    /** 入向审计分页（监控面板，按状态/关键字过滤） */
    public static function inboundPaginate($where, $params, $page, $pageSize) {
        $total = (int)self::val('SELECT COUNT(*) FROM inbound_events WHERE ' . $where, $params);
        $rows = self::q(
            'SELECT * FROM inbound_events WHERE ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            array_merge($params, array($pageSize, ($page - 1) * $pageSize))
        );
        return array('list' => $rows, 'total' => $total);
    }

    /** Outbox 入队（幂等合并：同业务仅一条任务） */
    public static function enqueueTask($businessType, $businessId, $payloadJson) {
        $now = now_str();
        $row = self::one('SELECT * FROM his_sync_tasks WHERE business_type=? AND business_id=?', array($businessType, (int)$businessId));
        if ($row) {
            if ($row['status'] === 'success') return false;   // 已成功：不再重复投递
            self::exec('UPDATE his_sync_tasks SET payload=?, status=?, updated_at=? WHERE id=?',
                array($payloadJson, 'pending', $now, (int)$row['id']));
            return true;
        }
        self::insert(
            'INSERT INTO his_sync_tasks(business_type, business_id, payload, status, retry_count, last_error, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)',
            array($businessType, (int)$businessId, $payloadJson, 'pending', 0, '', $now, $now)
        );
        return true;
    }

    /** Outbox 任务统计（监控面板） */
    public static function taskStats() {
        $rows = self::q('SELECT status, COUNT(*) AS cnt FROM his_sync_tasks GROUP BY status');
        $out = array('pending' => 0, 'success' => 0, 'failed' => 0);
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['cnt'];
        }
        return $out;
    }

    /** Outbox 待办任务（worker 拉取） */
    public static function pendingTasks($maxRetry, $limit) {
        return self::q(
            "SELECT * FROM his_sync_tasks
             WHERE status='pending' OR (status='failed' AND retry_count<?)
             ORDER BY updated_at ASC LIMIT ?",
            array((int)$maxRetry, (int)$limit)
        );
    }

    /** Outbox 单条任务 */
    public static function taskById($id) {
        return self::one('SELECT * FROM his_sync_tasks WHERE id=?', array((int)$id));
    }

    /** Outbox 任务列表（监控面板，分页） */
    public static function taskPaginate($where, $params, $page, $pageSize) {
        $total = (int)self::val('SELECT COUNT(*) FROM his_sync_tasks WHERE ' . $where, $params);
        $rows = self::q(
            'SELECT * FROM his_sync_tasks WHERE ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            array_merge($params, array($pageSize, ($page - 1) * $pageSize))
        );
        return array('list' => $rows, 'total' => $total);
    }

    /** Outbox 任务状态更新 */
    public static function updateTaskStatus($id, $status, $lastError = '') {
        return self::exec('UPDATE his_sync_tasks SET status=?, last_error=?, updated_at=? WHERE id=?',
            array($status, (string)$lastError, now_str(), (int)$id));
    }

    /** Outbox 任务失败重试计数 */
    public static function failTask($id, $error) {
        return self::exec('UPDATE his_sync_tasks SET status=?, retry_count=retry_count+1, last_error=?, updated_at=? WHERE id=?',
            array('failed', (string)$error, now_str(), (int)$id));
    }

    /** Outbox 全部失败任务重置为待处理 */
    public static function retryFailedTasks() {
        return self::exec("UPDATE his_sync_tasks SET status='pending', updated_at=? WHERE status='failed'", array(now_str()));
    }

    /** Outbox 清空历史（保留失败记录） */
    public static function clearTaskHistory() {
        return self::exec("DELETE FROM his_sync_tasks WHERE status IN ('success','failed')");
    }
}