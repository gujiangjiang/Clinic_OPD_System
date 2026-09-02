<?php
/**
 * ============================================================
 * helpers.d/input.php — 输入参数读取 / 时间字符串
 * ============================================================
 * 说明：POST / GET / REQUEST 参数读取、当前时间 / 日期字符串。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/** 读取 POST 参数（自动去首尾空格） */
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/**
 * 读取 POST 原始值（不去除首尾空格）
 * 说明：密码等敏感输入必须原样读取，禁止 trim：
 * 用户输入的密码可能含前导/尾随空格（复制粘贴或误输入），
 * 一旦 trim 会导致长度校验误判（如实际输入9位被判定少于6位），
 * 且入库/校验的密码与用户实际输入不一致，造成无法登录。
 */
function post_raw($key, $default = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

/** 读取 GET 参数（自动去首尾空格） */
function get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/**
 * 读取 GET 或 POST 参数（兼容两种请求方式，自动去首尾空格）
 * 说明：表单弹窗（loadModal / Clinic.modal.load）统一通过 POST 提交，
 * 而部分老接口用 get() 读参数导致编辑弹窗永远拿到 id=0（空白表单）。
 * form 类接口一律改用本函数读取，GET / POST 均兼容。
 */
function req($key, $default = '') {
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}

/** 当前时间字符串（站点时区） */
function now_str($fmt = 'Y-m-d H:i:s') {
    return date($fmt);
}

/** 当前日期字符串 */
function today_str() {
    return date('Y-m-d');
}

/**
 * 判断数据库异常是否为「唯一约束冲突」（并发撞号用）
 * SQLite：SQLSTATE 23000 + driver code 19（UNIQUE constraint failed）
 * MySQL ：SQLSTATE 23000 + driver code 1062（Duplicate entry）
 * @param Exception $ex 捕获的异常
 * @return bool
 */
function is_unique_conflict($ex) {
    if ($ex instanceof PDOException) {
        $info = $ex->errorInfo;
        $code = isset($info[1]) ? (int)$info[1] : 0;
        return $code === 19 || $code === 1062;
    }
    return false;
}

/**
 * 生成报告编号（BG + 年月日 + 4 位序号，MAX+1 复用序号）
 * @param string $type 报告类型（lab/imaging，目前编号不含类型前缀）
 * @return string 报告编号
 */
function next_report_no($type) {
    $seq = (int)OrderRepository::val(
        "SELECT MAX(CAST(substr(report_no, 11) AS INTEGER)) FROM reports WHERE substr(report_no,3,8)=?",
        array(date('Ymd'))
    ) + 1;
    return 'BG' . date('Ymd') . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * 插入报告（唯一索引防并发撞号：INSERT 触发唯一冲突时重新生成编号重试）
 * @param array $data result_id/report_no/visit_id/patient_no/flow_no/type/doctor/status
 * @return int 报告自增 id
 */
function insert_report($data) {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            // 首次生成的编号被并发占用 → 按 MAX+1 重新生成（消除重叠）
            $data['report_no'] = next_report_no($data['type']);
        }
        try {
            return OrderRepository::insert(
                'INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, doctor, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)',
                array($data['result_id'], $data['report_no'], $data['visit_id'], $data['patient_no'], $data['flow_no'],
                    $data['type'], $data['doctor'], $data['status'], now_str())
            );
        } catch (Exception $ex) {
            if (!is_unique_conflict($ex) || $attempt >= 2) {
                throw $ex;
            }
        }
    }
    throw new RuntimeException('报告编号生成失败');
}