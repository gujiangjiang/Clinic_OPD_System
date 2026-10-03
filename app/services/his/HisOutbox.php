<?php
/**
 * ============================================================
 * services/his/HisOutbox.php — 出向同步任务补偿表（Outbox）
 * ============================================================
 * 说明：挂号/收费结算/开单/发药等本地事务提交后异步入队本表，
 * 由后台 worker（tools/cli/integration_outbox_run.php）或监控面板
 * 手动触发投递。职责：
 *  - 不因外部 HIS/HIS 网络波动阻塞本地数据库主事务；
 *  - 500/超时等异常记录失败状态与重试次数，支持一键重试；
 *  - 同业务（business_type+business_id）重复入队幂等合并。
 * 任务状态：pending 待处理 / success 成功 / failed 失败（可重试）。
 * ============================================================ */
class HisOutbox {

    /** 失败最大重试次数（超过后不再自动重试，保留供人工处理） */
    const MAX_RETRY = 5;

    /** 后台 worker 并发互斥锁窗口（秒） */
    const LOCK_WINDOW = 60;

    /**
     * 任务入队（幂等合并）
     * @param string $businessType
     * @param int    $businessId
     * @param array  $payload
     */
    public static function enqueue($businessType, $businessId, $payload = array()) {
        $payloadJson = json_encode((array)$payload, JSON_UNESCAPED_UNICODE);
        return IntegrationRepository::enqueueTask($businessType, (int)$businessId, $payloadJson);
    }

    /** 统计（监控面板） */
    public static function stats() {
        return IntegrationRepository::taskStats();
    }

    /** 任务列表（监控面板） */
    public static function listTasks($status = '', $kw = '', $page = 1, $pageSize = 20) {
        $where = '1=1';
        $params = array();
        if (in_array($status, array('pending', 'success', 'failed'), true)) {
            $where .= ' AND status=?';
            $params[] = $status;
        }
        if ($kw !== '') {
            $where .= ' AND (business_type LIKE ? OR CAST(business_id AS TEXT) LIKE ? OR payload LIKE ?)';
            $like = '%' . $kw . '%';
            $params = array_merge($params, array($like, $like, $like));
        }
        $page = max(1, (int)$page);
        $pageSize = min(50, max(10, (int)$pageSize));
        $res = IntegrationRepository::taskPaginate($where, $params, $page, $pageSize);
        return array('list' => $res['list'], 'total' => $res['total'], 'page' => $page, 'page_size' => $pageSize);
    }

    /**
     * 处理待办任务（worker / 监控面板"立即执行"调用）
     * @param int $limit 本次处理上限
     * @return array { processed:int, success:int, failed:int }
     */
    public static function processPending($limit = 20) {
        // 并发互斥：worker 已在运行则跳过（60 秒窗口）
        $lockAt = (int)ConfigStore::get('integration.outbox.lock', '0');
        if ($lockAt > 0 && (time() - $lockAt) < self::LOCK_WINDOW) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0, 'locked' => true);
        }
        ConfigStore::set('integration.outbox.lock', (string)time());
        $limit = min(100, max(1, (int)$limit));
        $tasks = IntegrationRepository::pendingTasks(self::MAX_RETRY, $limit);
        $processed = 0;
        $success = 0;
        $failed = 0;
        foreach ($tasks as $task) {
            $processed++;
            try {
                $res = self::dispatchTask($task);
                // 日志中心·接口日志：出向调用统一落账（HIS/HL7/FHIR/LIS）
                if (function_exists('log_interface')) {
                    log_interface(self::moduleOf((string)$task['business_type']), 'outbound',
                        (string)$task['business_type'],
                        !empty($res['ok']),
                        !empty($res['ok']) ? '出向调用成功' : ('出向调用失败：' . (isset($res['error']) ? $res['error'] : '未知错误')),
                        (string)$task['payload']);
                }
                if ($res['ok']) {
                    $success++;
                    IntegrationRepository::updateTaskStatus((int)$task['id'], 'success');
                } else {
                    $failed++;
                    IntegrationRepository::failTask((int)$task['id'], (string)$res['error']);
                }
            } catch (Exception $ex) {
                $failed++;
                if (function_exists('log_interface')) {
                    log_interface(self::moduleOf((string)$task['business_type']), 'outbound',
                        (string)$task['business_type'], false, '出向调用异常：' . $ex->getMessage(), (string)$task['payload']);
                }
                IntegrationRepository::failTask((int)$task['id'], $ex->getMessage());
            }
        }
        ConfigStore::set('integration.outbox.lock', '0');
        return array('processed' => $processed, 'success' => $success, 'failed' => $failed);
    }

    /** 业务类型 → 日志中心接口子分类 */
    public static function moduleOf($businessType) {
        $b = (string)$businessType;
        if (strpos($b, 'his_') === 0) return 'his';
        if (strpos($b, 'hl7_') === 0) return 'hl7';
        if (strpos($b, 'fhir_') === 0) return 'fhir';
        if (strpos($b, 'lis_') === 0) return 'lis';
        if (strpos($b, 'dicom') === 0) return 'dicom';
        if (strpos($b, 'evid') === 0) return 'evid';
        return 'his';
    }

    /** 按业务类型分发到对应驱动 */
    public static function dispatchTask($task) {
        $businessType = (string)$task['business_type'];
        $payload = json_decode((string)$task['payload'], true);
        if (!is_array($payload)) $payload = array();

        switch (true) {
            case strpos($businessType, 'his_') === 0:
                $driver = RestHisDriver::make();
                return $driver->send($businessType, $payload);

            case $businessType === 'hl7_adt' || $businessType === 'hl7_orm':
                return self::dispatchHl7($businessType, $payload);

            case $businessType === 'fhir_bundle':
                $visitId = isset($payload['visit_id']) ? (int)$payload['visit_id'] : 0;
                if ($visitId <= 0) return array('ok' => false, 'error' => 'visit_id 缺失');
                FhirService::pushVisitBundle($visitId);
                return array('ok' => true, 'error' => '');

            case $businessType === 'lis_order':
                $orderId = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
                if ($orderId <= 0) return array('ok' => false, 'error' => 'order_id 缺失');
                LisService::sendOrder($orderId);
                return array('ok' => true, 'error' => '');

            default:
                return array('ok' => false, 'error' => '未知业务类型：' . $businessType);
        }
    }

    /** HL7 消息发送（构建 + 传输 + MSA 校验） */
    private static function dispatchHl7($businessType, $payload) {
        $msg = HL7MessageBuilder::forBusiness($businessType, $payload);
        if ($msg === '') {
            return array('ok' => false, 'error' => 'HL7 消息构建失败（业务数据缺失）');
        }
        $res = HL7Client::send($msg);
        if (!$res['ok']) {
            return array('ok' => false, 'error' => $res['error']);
        }
        // 强校验 MSA 段：AA 成功；AE/AR 视为失败入日志
        if ($res['msa_code'] !== '' && $res['msa_code'] !== 'AA') {
            return array('ok' => false, 'error' => 'HL7 ACK 非成功（MSA-' . $res['msa_code'] . '）：' . $res['ack_text']);
        }
        return array('ok' => true, 'error' => '');
    }

    /** 监控面板：重试单条任务 */
    public static function retryTask($id) {
        $row = IntegrationRepository::taskById($id);
        if (!$row) return false;
        if ($row['status'] === 'pending') return true;
        IntegrationRepository::updateTaskStatus((int)$row['id'], 'pending');
        return true;
    }

    /** 监控面板：全部失败任务重置为待处理 */
    public static function retryFailed() {
        return IntegrationRepository::retryFailedTasks();
    }

    /** 监控面板：清空历史（保留失败记录） */
    public static function clearHistory() {
        return IntegrationRepository::clearTaskHistory();
    }
}