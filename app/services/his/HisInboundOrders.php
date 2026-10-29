<?php
/**
 * ============================================================
 * services/his/HisInboundOrders.php — HIS 住院检验检查单入向
 * ============================================================
 * 说明：接收 HIS 推送的住院检验/检查申请单：
 *  1. 按身份证幂等匹配/建档患者（复用 HisInboundSync::syncPatient）；
 *  2. 按住院号幂等复用/创建住院就诊（visit_type=inpatient，状态直接 visiting，
 *     不经过挂号缴费；住院号存入 registrations.inpatient_no）；
 *  3. 按 HIS 申请单号幂等落 orders/order_items：住院开单即计费（HIS 侧收费口径），
 *     本系统一律视为已缴费（status=paid），直接进入检验科/影像科工作台登记出报告；
 *  4. 检验/检查字典按名称匹配，缺失时自动建档为已审核（可开单），价格取 HIS 推送值。
 * 由 /api/external/his/sync-orders 路由调用（鉴权在 InboundGuard）。
 * ============================================================ */
class HisInboundOrders {

    /**
     * 同步一张住院申请单（幂等键 = HIS 申请单号 order_no）
     * @param array $p {
     *   patient: { id_card, name, gender, birth_date, phone, ... },
     *   visit:   { admission_no, apply_dept, apply_doctor },
     *   order:   { order_no, order_type(lab|imaging), item_name, category?, unit?,
     *              price, created_at },
     *   paid:    bool（住院开单即计费，一律视为已缴；本接口据此直接放行）
     * }
     * @return array { order_no, order_id, order_item_id, visit_id, visit_no, patient_no, created, item_created }
     * @throws Exception
     */
    public static function syncOrder($p) {
        $p = is_array($p) ? $p : array();
        $patientIn = isset($p['patient']) && is_array($p['patient']) ? $p['patient'] : array();
        $visitIn   = isset($p['visit']) && is_array($p['visit']) ? $p['visit'] : array();
        $orderIn   = isset($p['order']) && is_array($p['order']) ? $p['order'] : array();

        $admissionNo = trim((string)(isset($visitIn['admission_no']) ? $visitIn['admission_no'] : ''));
        if ($admissionNo === '') throw new Exception('住院号（visit.admission_no）不能为空');
        $orderNo = trim((string)(isset($orderIn['order_no']) ? $orderIn['order_no'] : ''));
        if ($orderNo === '') throw new Exception('申请单号（order.order_no）不能为空');
        $itemName = trim((string)(isset($orderIn['item_name']) ? $orderIn['item_name'] : ''));
        if ($itemName === '') throw new Exception('申请项目名称（order.item_name）不能为空');
        $orderType = strtolower(trim((string)(isset($orderIn['order_type']) ? $orderIn['order_type'] : 'lab')));
        if ($orderType === 'exam') $orderType = 'imaging';
        if (!in_array($orderType, array('lab', 'imaging'), true)) throw new Exception('不支持的单据类型（order.order_type 仅支持 lab/imaging）');

        // ① 患者幂等建档（身份证匹配）
        $sync = HisInboundSync::syncPatient($patientIn);
        $patientNo = (string)$sync['patient_no'];

        // ② 住院就诊幂等复用/创建（同一住院号全程复用一条就诊）
        $visit = self::ensureInpatientVisit($patientNo, $admissionNo, $visitIn);
        $visitId = (int)$visit['id'];
        $flowNo = (string)$visit['flow_no'];

        // ③ 申请单幂等：已存在直接返回（HIS 重试安全）
        $exist = OrderRepository::one('SELECT * FROM orders WHERE order_no=?', array($orderNo));
        if ($exist) {
            $item = OrderRepository::one('SELECT id FROM order_items WHERE order_id=? ORDER BY id ASC LIMIT 1', array((int)$exist['id']));
            return array(
                'order_no' => $orderNo, 'order_id' => (int)$exist['id'],
                'order_item_id' => $item ? (int)$item['id'] : 0,
                'visit_id' => $visitId, 'visit_no' => $flowNo, 'patient_no' => $patientNo,
                'created' => false, 'item_created' => false,
            );
        }

        $price = isset($orderIn['price']) ? (float)$orderIn['price'] : 0;
        $doctorName = trim((string)(isset($visitIn['apply_doctor']) ? $visitIn['apply_doctor'] : ''));
        $deptName = trim((string)(isset($visitIn['apply_dept']) ? $visitIn['apply_dept'] : ''));
        // 入向集成：外部一律按标准 UTC 发送（ISO 8601 带 Z/偏移），
        // 经 utc_to_storage() 落库（local 模式换算站点墙钟）；非法/缺失回退 now_str()
        $createdAt = utc_to_storage(trim((string)(isset($orderIn['created_at']) ? $orderIn['created_at'] : '')));
        if ($createdAt === '') $createdAt = now_str();
        $categoryName = trim((string)(isset($orderIn['category']) ? $orderIn['category'] : ''));

        // ④ 字典匹配/自动建档（已审核，禁止因字典缺失阻断住院出报告）
        $itemCreated = false;
        $itemId = self::ensureDictItem($orderType, $itemName, $orderIn, $price, $categoryName, $itemCreated);

        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        try {
            $orderId = (int)OrderRepository::insert(
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, category_name, doctor_id, doctor_name, record_id, dept_id, dept_name, hospital_name, hospital_name2, total_amount, status, created_at, paid_at, is_skin_test) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array(
                    $visitId, $patientNo, $flowNo, $orderType, $orderNo, $categoryName,
                    0, $doctorName, 0, 0, $deptName,
                    setting('hospital_name', ''), setting('hospital_name2', ''),
                    $price, 'paid', $createdAt, now_str(), 0,
                )
            );
            $itemId2 = (int)OrderRepository::insert(
                'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, unit_type, pack_size, company_short, price, quantity, single_dose, frequency, route, is_nurse, sub_of, group_no, is_parent, parent_item_id, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array(
                    $orderId, $visitId, $patientNo, $flowNo, $orderType, $itemId,
                    $itemName, '', trim((string)(isset($orderIn['unit']) ? $orderIn['unit'] : '')), 'pack', 1,
                    '', $price, 1, '', '', '', 0, 0, 0, 1, 0,
                    'paid', 0, $doctorName, $createdAt,
                )
            );
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 并发重复推送：唯一冲突后按幂等返回已存在的申请单
            if (is_unique_conflict($ex)) {
                $exist = OrderRepository::one('SELECT * FROM orders WHERE order_no=?', array($orderNo));
                if ($exist) {
                    $item = OrderRepository::one('SELECT id FROM order_items WHERE order_id=? ORDER BY id ASC LIMIT 1', array((int)$exist['id']));
                    return array(
                        'order_no' => $orderNo, 'order_id' => (int)$exist['id'],
                        'order_item_id' => $item ? (int)$item['id'] : 0,
                        'visit_id' => $visitId, 'visit_no' => $flowNo, 'patient_no' => $patientNo,
                        'created' => false, 'item_created' => false,
                    );
                }
            }
            throw $ex;
        }

        return array(
            'order_no' => $orderNo, 'order_id' => $orderId, 'order_item_id' => $itemId2,
            'visit_id' => $visitId, 'visit_no' => $flowNo, 'patient_no' => $patientNo,
            'created' => true, 'item_created' => $itemCreated,
        );
    }

    /** 住院就诊：按住院号复用活动就诊，无则创建（visit_type=inpatient，直接 visiting） */
    private static function ensureInpatientVisit($patientNo, $admissionNo, $visitIn) {
        $visit = OrderRepository::one(
            "SELECT * FROM registrations WHERE visit_type='inpatient' AND inpatient_no=? AND status IN ('pending','paid','visiting') ORDER BY id DESC LIMIT 1",
            array($admissionNo)
        );
        if ($visit) return $visit;
        $deptName = trim((string)(isset($visitIn['apply_dept']) ? $visitIn['apply_dept'] : ''));
        if ($deptName === '') $deptName = '住院';
        $visitId = 0;
        insert_unique_retry(
            function () { return CashierRepository::maxVisitSeq(0, today_str()) + 1; },
            function ($seq) use (&$visitId, $patientNo, $admissionNo, $deptName) {
                $visitId = CashierRepository::createRegistration(array(
                    'patient_no' => $patientNo,
                    'flow_no' => self::nextFlowNo(),
                    'visit_seq' => (int)$seq,
                    'first_dept_id' => 0, 'first_dept_name' => $deptName,
                    'current_dept_id' => 0, 'current_dept_name' => $deptName,
                    'session' => 'all', 'fee_type' => '住院', 'fee' => 0,
                    'visit_type' => 'inpatient', 'inpatient_no' => $admissionNo,
                    'status' => 'visiting', 'cashier_id' => 0, 'cashier_name' => 'HIS',
                    'is_extra' => 0,
                ));
            }
        );
        return OrderRepository::one('SELECT * FROM registrations WHERE id=?', array($visitId));
    }

    /** 住院就诊流水号（与挂号同源：ymd + 当日4位序号） */
    private static function nextFlowNo() {
        $ymd = date('ymd');
        $n = (int)CashierRepository::countRegistrationsByPrefix($ymd) + 1;
        return $ymd . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 字典匹配/自动建档（按名称；缺失时以 HIS 推送价目建档为「已审核」）
     * @return int 字典项目 id
     */
    private static function ensureDictItem($orderType, $itemName, $orderIn, $price, $categoryName, &$created) {
        $unit = trim((string)(isset($orderIn['unit']) ? $orderIn['unit'] : ''));
        $created = false;
        if ($orderType === 'lab') {
            $item = OrderRepository::one("SELECT * FROM lab_items WHERE name=? ORDER BY (status='approved') DESC, id ASC LIMIT 1", array($itemName));
            if ($item) return (int)$item['id'];
            $created = true;
            return (int)OrderRepository::insert(
                'INSERT INTO lab_items(category, name, unit, price, normal_range, status, created_at) VALUES(?,?,?,?,?,?,?)',
                array($categoryName !== '' ? $categoryName : '其他', $itemName, $unit, $price, '', 'approved', now_str())
            );
        }
        $item = OrderRepository::one("SELECT * FROM exam_items WHERE name=? ORDER BY (status='approved') DESC, id ASC LIMIT 1", array($itemName));
        if ($item) return (int)$item['id'];
        $created = true;
        return (int)OrderRepository::insert(
            'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)',
            array($categoryName, $itemName, $price, '', 'approved', now_str())
        );
    }
}
