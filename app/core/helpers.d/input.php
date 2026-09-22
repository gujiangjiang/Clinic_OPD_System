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

/* ============================================================
 * 日期范围跨度钳制（防全表扫描压力，按业务功能差异化限制）
 * ------------------------------------------------------------
 * 各支持日期范围搜索的功能，开始/结束之间跨度不能超过该功能域
 * 上限（天），超限时以结束日期为锚前推至上限边界。不同功能上限
 * 按业务性质与数据量级分配：
 *   critical  31  天（1 个月）— 危急值为即时告警数据，超月无临床回溯价值
 *   print     92  天（3 个月）— 统一打印中心就诊记录量最大，补打集中近三月
 *   refs      183 天（6 个月）— 影像引用台账 PACS 调阅溯源周期较长
 *   audit     366 天（1 年）  — 审核事项量小，审计回溯周期最长
 *   patient   366 天（1 年）  — 患者建档时间回溯（与运营分析同口径）
 *   ana       366 天（1 年）  — 运营分析趋势图（原有 366 天上限）
 * @param string $domain 功能域（见上表）
 * @param string $from   开始日期（YYYY-MM-DD，空=不限，原样返回）
 * @param string $to     结束日期（YYYY-MM-DD，空=不限，原样返回）
 * @return array [from, to] 钳制后
 * ============================================================ */
function date_span_clamp($domain, $from, $to) {
    static $limits = array(
        'critical' => 31,
        'print'    => 92,
        'refs'     => 183,
        'audit'    => 366,
        'patient'  => 366,
        'ana'      => 366,
    );
    if (!isset($limits[$domain])) return array($from, $to);
    if ($from === '' || $to === '') return array($from, $to);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return array($from, $to);
    }
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    try {
        $ds = new DateTime($from);
        $de = new DateTime($to);
        if ((int)$ds->diff($de)->format('%a') > $limits[$domain]) {
            $from = $de->modify('-' . $limits[$domain] . ' days')->format('Y-m-d');
        }
    } catch (Exception $e) {
        return array($from, $to);
    }
    return array($from, $to);
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
 * 生成唯一业务单号（前缀 + 时间戳 + 2 位随机，循环查重防撞号）。
 * 说明：申请单号（JY/JC/CZ/CF/DD）、会诊单号（HZ）、证明号（ZM）此前
 * 各位置手写同一套 `do { $no = 'XX'.date('YmdHis').rand(); } while (count)`
 * 循环，统一收敛到本函数。
 * @param string $prefix 单号前缀（JY/JC/CZ/CF/DD/HZ/ZM 等，仅字母数字）
 * @param string $table  查重表名（内部常量，白名单校验防注入）
 * @param string $col    查重列名（内部常量，白名单校验防注入）
 * @return string 唯一单号
 */
function gen_unique_no($prefix, $table, $col) {
    if (!preg_match('/^[A-Za-z0-9]+$/', $prefix)) {
        throw new Exception('非法单号前缀: ' . $prefix);
    }
    if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $col)) {
        throw new Exception('非法查重表/列名');
    }
    do {
        $no = $prefix . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
    } while ((int)DB::val("SELECT COUNT(*) FROM $table WHERE $col=?", array($no)) > 0);
    return $no;
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
 *                  item_meta（可选）：报告出具时项目字典快照（lab_items/exam_items 行，
 *                  含 name/unit/normal_range/critical_low/critical_high/category），
 *                  供打印/详情使用，杜绝事后改字典影响历史报告
 * @return int 报告自增 id
 */
function insert_report($data) {
    $repId = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            // 首次生成的编号被并发占用 → 按 MAX+1 重新生成（消除重叠）
            $data['report_no'] = next_report_no($data['type']);
        }
        try {
            $repId = OrderRepository::insert(
                'INSERT INTO reports(result_id, report_no, visit_id, patient_no, flow_no, type, doctor, status, content, apply_dept, apply_doctor, clinical_diag, apply_time, reg_time, category_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($data['result_id'], $data['report_no'], $data['visit_id'], $data['patient_no'], $data['flow_no'],
                    $data['type'], $data['doctor'], $data['status'],
                    isset($data['content']) ? (string)$data['content'] : '',
                    isset($data['apply_dept']) ? (string)$data['apply_dept'] : '',
                    isset($data['apply_doctor']) ? (string)$data['apply_doctor'] : '',
                    isset($data['clinical_diag']) ? (string)$data['clinical_diag'] : '',
                    isset($data['apply_time']) ? (string)$data['apply_time'] : '',
                    isset($data['reg_time']) ? (string)$data['reg_time'] : '',
                    isset($data['category_name']) ? (string)$data['category_name'] : '',
                    now_str())
            );
            break;
        } catch (Exception $ex) {
            if (!is_unique_conflict($ex) || $attempt >= 2) {
                throw $ex;
            }
        }
    }
    // 报告打印快照（法律合规）：固化报告出具时刻的患者资料 + 项目字典元数据，
    // 打印/详情优先使用快照，杜绝事后改患者资料或检验/检查字典影响历史报告
    try {
        snapshot_patient('report', (int)$repId, (string)$data['patient_no'], array(
            'report_type' => $data['type'],
            'item_meta' => isset($data['item_meta']) && is_array($data['item_meta']) ? $data['item_meta'] : array(),
        ));
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[报告快照失败] ' . $ex->getMessage());
    }
    return $repId;
}
/**
 * 项目状态徽章（检验/检查/处置/处方通用）：可用（approved）/ 待审核（pending）/ 已禁用（disabled）
 * @param string $status
 * @return string badge HTML
 */
function item_status_badge($status) {
    // 状态配色：可用绿色 / 禁用红色 / 待审核黄色 / 其余状态（如 unknown 等）灰色
    if ($status === 'approved') return badge_html('success', '可用');
    if ($status === 'disabled') return badge_html('danger', '已禁用');
    if ($status === 'pending' || $status === '') return badge_html('warning', '待审核');
    return badge_html('gray', (string)$status);
}

/* ============================================================
 * 项目删除流程占用检查（检验/检查/处置/处方通用）
 * ------------------------------------------------------------
 * 规则：仅当该项目存在「未完成」的开单明细时禁止删除——
 * 待缴费（open）/ 已缴费未执行（paid）/ 已登记（registered）/
 * 执行中（executing）/ 发药中（dispensing）。这些状态关联缴费、
 * 写报告、执行、发药等进行中流程，删除项目将导致流程无法继续。
 * 已完成（done/dispensed）或终态（refunded/cancelled/rejected）
 * 的历史开单不影响删除（过期作废可删）。
 * 提示引导：存在未完成流程时建议先禁用项目（医生开单列表不再显示），
 * 待全部流程完结后再删除。
 * @param string $itemType lab/imaging/procedure/prescription
 * @param int    $itemId
 * @return array ['ok' => bool, 'msg' => string]  未完成时 msg 为精准提示
 * ============================================================ */
function item_delete_check($itemType, $itemId) {
    $pending = array('open', 'paid', 'registered', 'executing', 'dispensing');
    $rows = OrderRepository::q(
        "SELECT status, COUNT(*) c FROM order_items WHERE item_type=? AND item_id=? GROUP BY status",
        array($itemType, (int)$itemId)
    );
    $unpaid = 0;   // 未缴费
    $doing  = 0;   // 已缴费但流程未完成（待检验/检查/处置/发药）
    foreach ($rows as $r) {
        if ($r['status'] === 'open') $unpaid += (int)$r['c'];
        elseif (in_array($r['status'], $pending, true)) $doing += (int)$r['c'];
    }
    if ($unpaid === 0 && $doing === 0) return array('ok' => true, 'msg' => '');
    $typeName = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
    $doLabel  = isset($typeName[$itemType]) ? '待' . $typeName[$itemType] : '待执行';
    $parts = array();
    if ($unpaid > 0) $parts[] = $unpaid . ' 位未缴费';
    if ($doing  > 0) $parts[] = $doing . ' 位' . $doLabel;
    return array('ok' => false, 'msg' => '该项目当前有 ' . implode('、', $parts) . '，请先禁用该项目，等相关流程完成后再尝试删除');
}

/* ============================================================
 * 单据打印快照（法律合规：打印时优先用开单/出具/保存时刻快照，
 * 避免事后修改字典/患者资料改变历史单据显示）
 * ------------------------------------------------------------
 * 写入：snapshot_patient($bizType, $bizId, $patientNo, $extra)
 *   开单/缴费/出报告/出具证明/保存病历 时把患者资料与上下文快照入表。
 * 读取：snapshot_get($bizType, $bizId)
 *   打印时优先返回快照（含患者字段 + extra 解出的上下文），无快照返回 null。
 * 注：电子病历个人信息例外——诊毕前允许显示最新（不写快照或更新快照），
 *   诊毕时写入终态快照（print.php record 分支按 finished 判定）。
 * ============================================================ */
function snapshot_patient($bizType, $bizId, $patientNo, $extra = array()) {
    $bizType = (string)$bizType;
    $bizId = (int)$bizId;
    if ($bizId <= 0 || $bizType === '') return;
    $p = $patientNo !== ''
        ? DB::one('SELECT patient_no, name, gender, birth_date, id_card, ethnicity, occupation, marital, phone FROM patients WHERE patient_no=?', array($patientNo))
        : null;
    $row = array(
        'patient_no' => $p ? $p['patient_no'] : (string)$patientNo,
        'patient_name' => $p ? $p['name'] : '',
        'gender' => $p ? $p['gender'] : '',
        'birth_date' => $p ? $p['birth_date'] : '',
        'id_card' => $p ? $p['id_card'] : '',
        'ethnicity' => $p ? $p['ethnicity'] : '',
        'job' => $p ? $p['occupation'] : '',
        'marital' => $p ? $p['marital'] : '',
        'phone' => $p ? $p['phone'] : '',
        'extra' => json_encode(is_array($extra) ? $extra : array(), JSON_UNESCAPED_UNICODE),
        'created_at' => now_str(),
    );
    $existed = (int)DB::val('SELECT COUNT(*) FROM print_snapshots WHERE biz_type=? AND biz_id=?', array($bizType, $bizId));
    if ($existed) {
        DB::exec('UPDATE print_snapshots SET patient_no=?, patient_name=?, gender=?, birth_date=?, id_card=?, ethnicity=?, job=?, marital=?, phone=?, extra=?, created_at=? WHERE biz_type=? AND biz_id=?',
            array_values($row) + array($bizType, $bizId));
    } else {
        DB::insert('INSERT INTO print_snapshots(biz_type, biz_id, patient_no, patient_name, gender, birth_date, id_card, ethnicity, job, marital, phone, extra, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge(array($bizType, $bizId), array_values($row)));
    }
}

/** 读取单据快照；无快照返回 null。extra 自动 JSON 解码为数组。 */
function snapshot_get($bizType, $bizId) {
    $r = DB::one('SELECT * FROM print_snapshots WHERE biz_type=? AND biz_id=?', array((string)$bizType, (int)$bizId));
    if (!$r) return null;
    $r['extra'] = json_decode((string)$r['extra'], true);
    if (!is_array($r['extra'])) $r['extra'] = array();
    return $r;
}

/** 快照不存在时按患者现资料补齐（兼容存量单据：首次打印即固化） */
function snapshot_get_or_live($bizType, $bizId, $patientNo, $extra = array()) {
    $s = snapshot_get($bizType, $bizId);
    if ($s) return $s;
    snapshot_patient($bizType, $bizId, $patientNo, $extra);
    return snapshot_get($bizType, $bizId);
}

/**
 * 打印时用单据快照覆盖患者资料（有快照才覆盖，无快照保持 live 兼容存量单据）。
 * 用于 print.php 各 case：拿到 get_visit_row 后调用，使历史单据打印
 * 使用开单/出具/保存时刻的患者姓名/性别/出生日期等快照。
 * @param array  $row     get_visit_row() 返回的关联数组（引用修改 row['patient']）
 * @param string $bizType 单据类型（order/payment/report/certificate/record）
 * @param int    $bizId   单据 ID
 * @return void
 */
function snapshot_apply_patient(&$row, $bizType, $bizId) {
    $s = snapshot_get($bizType, $bizId);
    if (!$s || !is_array($row) || !isset($row['patient']) || !is_array($row['patient'])) return;
    if ((string)$s['patient_name'] !== '') $row['patient']['name'] = $s['patient_name'];
    if ((string)$s['gender'] !== '') $row['patient']['gender'] = $s['gender'];
    if ((string)$s['birth_date'] !== '') $row['patient']['birth_date'] = $s['birth_date'];
    if ((string)$s['id_card'] !== '') $row['patient']['id_card'] = $s['id_card'];
    if ((string)$s['phone'] !== '') $row['patient']['phone'] = $s['phone'];
    if ((string)$s['job'] !== '') $row['patient']['job'] = $s['job'];
    if ((string)$s['marital'] !== '') $row['patient']['marital'] = $s['marital'];
    if ((string)$s['ethnicity'] !== '') $row['patient']['ethnicity'] = $s['ethnicity'];
}
