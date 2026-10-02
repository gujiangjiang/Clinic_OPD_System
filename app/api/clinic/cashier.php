<?php
/**
 * ============================================================
 * cashier.php — 挂号收费处接口 — 分发入口
 * ============================================================
 * 说明：按功能拆分到 parts/（沿用 admin parts 模式）：
 *   parts/cashier_read.php  读取（home_stats/depts/reg_list/
 *                           visit_search/visit_detail/pay_orders）
 *   parts/cashier_write.php 写入（quick_name/register/pay_visit/
 *                           cancel_visit/refund_order）
 * 本文件保留公共引导、编号规则共享函数与动作分发。
 * 另承接支付结果回调入向端点（POST /api/cashier/pay-notify/{provider}），
 * 不依赖登录会话，先于 _init.php 鉴权处理。
 * ============================================================ */

/* ==================== 支付结果回调（入向，微信/支付宝/银联聚合） ====================
 * 说明：第三方支付平台按各自规范向本端点推送支付结果。生产环境接入时必须
 * 按各平台规范验签（微信商户密钥 / 支付宝公钥 / 银联证书），本实现先落审计
 * 并应答成功，后续扩展验签与入账闭环。 */
if (defined('CURRENT_API_SUB') && strpos((string)CURRENT_API_SUB, 'pay-notify/') === 0) {
    $__provider = strtolower(preg_replace('/[^a-z0-9_]/', '', substr((string)CURRENT_API_SUB, 11)));
    $__raw = file_get_contents('php://input');
    $__body = ($__raw === false) ? '' : (string)$__raw;
    header('Content-Type: application/json; charset=utf-8');
    $__enabledKey = array('wechat' => 'pay_wechat_enabled', 'alipay' => 'pay_alipay_enabled', 'unionpay' => 'pay_bankcard_enabled');
    // ① provider 合法性 + 启用校验
    if (!isset($__enabledKey[$__provider])) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => '未知支付渠道'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((string)setting($__enabledKey[$__provider], '0') !== '1') {
        integration_log_inbound('cashier', 'pay-notify/' . $__provider, false, '该支付方式未启用', $__body);
        http_response_code(403);
        echo json_encode(array('success' => false, 'message' => '支付方式未启用'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    // ② 验签：配置了回调密钥则强制校验 X-Pay-Sign（HMAC-SHA256 十六进制），未配置则仅记录
    $__secret = trim((string)setting('pay_' . $__provider . '_notify_secret', ''));
    if ($__secret !== '') {
        $__sig = isset($_SERVER['HTTP_X_PAY_SIGN']) ? trim((string)$_SERVER['HTTP_X_PAY_SIGN']) : '';
        if ($__sig === '' && isset($_GET['sign'])) $__sig = trim((string)$_GET['sign']);
        $__expect = hash_hmac('sha256', $__body, $__secret);
        if ($__sig === '' || !hash_equals($__expect, strtolower($__sig))) {
            integration_log_inbound('cashier', 'pay-notify/' . $__provider, false, '支付回调验签失败', $__body);
            http_response_code(401);
            echo json_encode(array('success' => false, 'message' => '签名校验失败'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        integration_log_inbound('cashier', 'pay-notify/' . $__provider, true, '支付结果回调接收（已验签）', $__body);
    } else {
        integration_log_inbound('cashier', 'pay-notify/' . $__provider, true, '支付结果回调接收（未配置验签密钥）', $__body);
    }
    echo ($__provider === 'wechat')
        ? json_encode(array('code' => 'SUCCESS', 'message' => 'OK'), JSON_UNESCAPED_UNICODE)
        : json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../_init.php';

$u = Auth::user();

/* ==================== 编号规则函数 ==================== */

/** 患者唯一ID：年月日 + 当日序号2位（25031101） */
function next_patient_no() {
    $ymd = date('ymd');
    $n = CashierRepository::countPatientsByPrefix($ymd);
    return $ymd . str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT);
}

/** 门诊流水号：年月日 + 当日序号4位（2503110001） */
function next_flow_no() {
    $ymd = date('ymd');
    $n = CashierRepository::countRegistrationsByPrefix($ymd);
    return $ymd . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** 门诊就诊序号：每科室每日3位独立递增（含退费/取消记录，序号不回收）。
 *  MAX+1 生成（在挂号事务内执行）+ 唯一索引防并发重复：两个窗口同时挂号
 *  同科室同日时可能取到相同 MAX，由唯一约束冲突触发挂号事务撞号重试。 */
function next_visit_seq($deptId) {
    return CashierRepository::maxVisitSeq($deptId, today_str()) + 1;
}

/**
 * 缴费流水号：与挂号流水号关联（JF + 流水号 + HHMMSS + 2位随机），
 * 长位数防重复；同一批次（批量缴费合并一张凭条）共享同一编号。
 * @param string $flowNo 挂号流水号（如 2609030001）
 * @return string 缴费流水号
 */
function next_payment_no($flowNo) {
    return 'JF' . $flowNo . date('His') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
}

/** 当日某科室某时段已用号源数 */
function dept_used_count($deptId, $session) {
    return CashierRepository::deptUsed($deptId, $session);
}

require __DIR__ . '/../parts/cashier_read.php';
require __DIR__ . '/../parts/cashier_write.php';

switch ($action) {
    case 'home_stats':
    case 'depts':
    case 'reg_list':
    case 'visit_search':
    case 'visit_detail':
    case 'payment_batch_detail':
    case 'pay_orders':
        cashier_part_read($action);
        break;

    case 'quick_name':
    case 'register':
    case 'pay_visit':
    case 'cancel_visit':
    case 'refund_order':
    case 'refund_batch':
        cashier_part_write($action);
        break;

    default:
        json_fail('未知操作');
}
