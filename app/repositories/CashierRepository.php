<?php
/**
 * ============================================================
 * CashierRepository.php — 挂号收费处仓库
 * ============================================================
 * 说明：封装挂号流水生成、患者建档、缴费、退费、订单支付状态
 * 变更与收据流水等 SQL，统一经主库 DatabaseManager::getMain()
 * 预编译参数绑定。复合写操作事务由 API 层统一控制。
 * ============================================================ */
class CashierRepository extends BaseRepository {

    /* ---------------- 科室 / 号源 ---------------- */

    /** 查询可用科室（挂号用，可停用过滤） */
    public static function activeDept($deptId) {
        return DeptRepository::activeById($deptId);
    }

    /** 科室当日已用号源数 */
    public static function deptUsed($deptId, $session) {
        return (int)self::val(
            "SELECT COUNT(*) FROM registrations WHERE first_dept_id=? AND date(registered_at)=? AND session=? AND status IN ('pending','paid','visiting','finished')",
            array((int)$deptId, today_str(), $session)
        );
    }

    /** 查加号记录（指定科室+日期+身份证，未使用） */
    public static function unusedSlot($deptId, $idCard) {
        return self::one('SELECT id FROM extra_slots WHERE dept_id=? AND reg_date=? AND id_card=? AND used=0',
            array((int)$deptId, today_str(), $idCard));
    }

    /** 标记加号已使用（条件更新防并发双发：仅 used=0 可置为 1，返回影响行数） */
    public static function markSlotUsed($slotId) {
        return self::exec('UPDATE extra_slots SET used=1 WHERE id=? AND used=0', array((int)$slotId));
    }

    /* ---------------- 患者 ---------------- */

    /** 新建患者档案（无身份证时 id_card 传 null，SQLite 允许多个 NULL） */
    public static function createPatient($data) {
        return self::insert(
            'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array(
                $data['patient_no'], $data['id_card'], $data['name'], $data['gender'], $data['birth_date'], (int)$data['age'],
                $data['ethnicity'], $data['marital'], $data['occupation'], $data['work_unit'], $data['address'], $data['phone'], now_str(),
            )
        );
    }

    /** 更新患者档案（挂号时覆盖可修改字段） */
    public static function updatePatientByIdCard($idCard, $data) {
        self::exec(
            'UPDATE patients SET name=?, ethnicity=?, marital=?, occupation=?, work_unit=?, address=?, phone=? WHERE id_card=?',
            array($data['name'], $data['ethnicity'], $data['marital'], $data['occupation'], $data['work_unit'], $data['address'], $data['phone'], $idCard)
        );
    }

    /* ---------------- 挂号 ---------------- */

    /** 查当日重复挂号（同患者+同首次科室+未完成） */
    public static function todayDupVisit($patientNo, $deptId) {
        return self::one(
            "SELECT id FROM registrations WHERE patient_no=? AND first_dept_id=? AND date(registered_at)=? AND status IN ('pending','paid','visiting','finished')",
            array($patientNo, (int)$deptId, today_str())
        );
    }

    /** 生成挂号记录，返回自增 id */
    public static function createRegistration($data) {
        return self::insert(
            'INSERT INTO registrations(patient_no, flow_no, visit_seq, first_dept_id, first_dept_name, current_dept_id, current_dept_name, session, fee_type, fee, status, cashier_id, cashier_name, registered_at, is_extra) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array(
                $data['patient_no'], $data['flow_no'], (int)$data['visit_seq'],
                (int)$data['first_dept_id'], $data['first_dept_name'], (int)$data['current_dept_id'], $data['current_dept_name'],
                $data['session'], $data['fee_type'], (float)$data['fee'], $data['status'],
                (int)$data['cashier_id'], $data['cashier_name'], now_str(), (int)$data['is_extra'],
            )
        );
    }

    /** 患者全部挂号（倒序） */
    public static function visitsOfPatient($patientNo) {
        return PatientRepository::visitsOf($patientNo);
    }

    /** 当日挂号列表（含患者姓名/性别/年龄，按就诊序号；支持科室类型/状态/关键字过滤） */
    public static function visitListByDate($date, $filters = array()) {
        $sql = 'SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE date(r.registered_at)=?';
        $params = array($date);
        // 科室类型：clinic 门诊 / emergency 急诊（按首次挂号科室）
        if (!empty($filters['dept_type']) && in_array($filters['dept_type'], array('clinic', 'emergency'), true)) {
            $sql .= ' AND EXISTS (SELECT 1 FROM departments d WHERE d.id=r.first_dept_id AND d.type=?)';
            $params[] = $filters['dept_type'];
        }
        // 状态（pending 待缴费/paid 待就诊/visiting 就诊中/finished 已完成/refunded 已退费/cancelled 已取消）
        if (!empty($filters['status'])) {
            $sql .= ' AND r.status=?';
            $params[] = $filters['status'];
        }
        // 关键字：姓名/患者ID/流水号 模糊匹配
        if (!empty($filters['kw'])) {
            $kw = '%' . $filters['kw'] . '%';
            $sql .= ' AND (p.name LIKE ? OR r.patient_no LIKE ? OR r.flow_no LIKE ?)';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        // 默认按流水号倒序（最新挂号在最上）；流水号含年月日+当日递增序号，天然单调
        $sql .= ' ORDER BY r.flow_no DESC';
        return self::q($sql, $params);
    }

    /** 更新挂号状态 */
    public static function updateVisitStatus($visitId, $status, $extra = array()) {
        $data = array('status' => $status);
        if (isset($extra['paid_at'])) $data['paid_at'] = $extra['paid_at'];
        if (isset($extra['cancel_reason'])) $data['cancel_reason'] = $extra['cancel_reason'];
        if (isset($extra['finished_at'])) $data['finished_at'] = $extra['finished_at'];
        return self::updateRow('registrations', $visitId, $data);
    }

    /* ---------------- 缴费 / 退费 ---------------- */

    /** 新增缴费流水，返回自增 id */
    public static function createPayment($data) {
        return self::insert(
            'INSERT INTO payments(visit_id, order_id, patient_no, flow_no, kind, total, item_count, cashier_id, cashier_name, created_at, payment_no, method) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            array(
                (int)$data['visit_id'], (int)$data['order_id'], $data['patient_no'], $data['flow_no'],
                $data['kind'], (float)$data['total'], (int)$data['item_count'],
                (int)$data['cashier_id'], $data['cashier_name'], now_str(),
                isset($data['payment_no']) ? $data['payment_no'] : '',
                isset($data['method']) ? $data['method'] : '现金',
            )
        );
    }

    /** 就诊缴费流水（倒序） */
    public static function paymentsOfVisit($visitId) {
        return self::q('SELECT * FROM payments WHERE visit_id=? ORDER BY id DESC', array((int)$visitId));
    }

    /** 新增退费流水 */
    public static function createRefund($data) {
        return self::insert(
            'INSERT INTO refunds(visit_id, order_id, patient_no, flow_no, total, reason, cashier_id, cashier_name, created_at, payment_no, method) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            array(
                (int)$data['visit_id'], (int)$data['order_id'], $data['patient_no'], $data['flow_no'],
                (float)$data['total'], $data['reason'], (int)$data['cashier_id'], $data['cashier_name'], now_str(),
                isset($data['payment_no']) ? $data['payment_no'] : '',
                isset($data['method']) ? $data['method'] : '现金',
            )
        );
    }

    /* ---------------- 订单 ---------------- */

    /** 按 id 查订单 */
    public static function order($orderId) {
        return self::one('SELECT * FROM orders WHERE id=?', array((int)$orderId));
    }

    /** 待缴费/可退费订单（排除已退费/已取消） */
    public static function payableOrdersOfVisit($visitId) {
        return self::q("SELECT * FROM orders WHERE visit_id=? AND status<>'refunded' AND status<>'cancelled' ORDER BY id", array((int)$visitId));
    }

    /** 订单明细 */
    public static function orderItems($orderId) {
        return self::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array((int)$orderId));
    }

    /** 更新单条明细状态 */
    public static function updateOrderItemStatus($itemId, $status, $extra = array()) {
        $data = array('status' => $status);
        if (isset($extra['executed_by'])) $data['executed_by'] = $extra['executed_by'];
        if (isset($extra['executed_at'])) $data['executed_at'] = $extra['executed_at'];
        return self::updateRow('order_items', $itemId, $data);
    }

    /* ---------------- 编号规则 / 计数 ---------------- */

    /** 当日患者数（患者编号前缀 ym 月日，生成唯一患者ID用） */
    public static function countPatientsByPrefix($ymd) {
        return (int)self::val('SELECT COUNT(*) FROM patients WHERE substr(patient_no,1,6)=?', array($ymd));
    }

    /** 当日挂号数（流水号前缀，生成门诊流水号用） */
    public static function countRegistrationsByPrefix($ymd) {
        return (int)self::val('SELECT COUNT(*) FROM registrations WHERE substr(flow_no,1,6)=?', array($ymd));
    }

    /** 某科室当日挂号数（就诊序号生成用，含退费/取消，序号不回收） */
    public static function countVisitSeq($deptId, $date) {
        return (int)self::val('SELECT COUNT(*) FROM registrations WHERE first_dept_id=? AND date(registered_at)=?', array((int)$deptId, $date));
    }

    /* ---------------- 药品库存 ---------------- */

    /** 药品退费恢复库存 */
    public static function restoreDrugStock($drugId, $qty) {
        return DrugRepository::restoreStock($drugId, $qty);
    }

    /** 新增库存流水（委托 DrugRepository 统一实现，签名保持一致） */
    public static function createInventoryTrans($drugId, $qtyChange, $type, $ref, $operator) {
        return DrugRepository::createInventoryTrans($drugId, $qtyChange, $type, $ref, $operator);
    }
}