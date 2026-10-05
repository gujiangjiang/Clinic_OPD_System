<?php
/**
 * ============================================================
 * helpers.d/log.php — 统一日志埋点辅助函数
 * ============================================================
 * 说明：业务代码调用本组函数写入日志中心（system_logs 表），
 * 底层统一委托 LogService；全部静默降级，绝不影响业务主流程。
 *  - log_operation()  操作日志（登录/退出/改密/改资料/解锁等）
 *  - log_interface()  接口日志（入向/出向，按外部系统分类）
 * ============================================================ */

/**
 * 写操作日志
 * @param string $category 子分类 LogService::OP_LOGIN / OP_ACCOUNT
 * @param string $action   动作标识（login/logout/password_change/profile_update/user_unlock…）
 * @param string $summary  摘要
 * @param string $detail   详情
 * @param string $level    级别（normal/info/warning/error）
 * @param string $target   操作对象（用户ID / 工号等）
 * @param array  $ctx      附加上下文（未登录场景显式补充 username/remote_ip 等）
 * @return bool
 */
function log_operation($category, $action, $summary, $detail = '', $level = 'info', $target = '', $ctx = array()) {
    try {
        if (!class_exists('LogService')) return false;
        $row = array(
            'channel'  => LogService::CH_OPERATION,
            'category' => $category,
            'action'   => $action,
            'level'    => $level,
            'summary'  => $summary,
            'detail'   => $detail,
            'target'   => $target,
        );
        if (is_array($ctx) && $ctx) $row = array_merge($row, $ctx);
        return LogService::write($row);
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[log_operation] ' . $ex->getMessage());
        return false;
    }
}

/**
 * 写接口日志
 * @param string $category  外部系统分类（fhir/dicom/hl7/lis/his/insurance/evid）
 * @param string $direction inbound 入向 / outbound 出向
 * @param string $endpoint  端点/业务标识
 * @param bool   $ok        是否成功
 * @param string $summary   摘要
 * @param string $payload   原始报文
 * @param string $detail    详情
 * @param string $target    出向目标系统地址（对方系统 URL；入向可空）
 * @return bool
 */
function log_interface($category, $direction, $endpoint, $ok, $summary = '', $payload = '', $detail = '', $target = '') {
    try {
        if (!class_exists('LogService')) return false;
        return LogService::interfaceLog($category, $direction, $endpoint, $ok, $summary, $payload, $detail, $target);
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[log_interface] ' . $ex->getMessage());
        return false;
    }
}
