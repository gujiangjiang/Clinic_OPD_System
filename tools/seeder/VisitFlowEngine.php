<?php
/**
 * ============================================================
 * tools/seeder/VisitFlowEngine.php — 就诊全流程状态机引擎
 * ============================================================
 * 所有业务场景统一调用本引擎生成单据，严禁各场景自行编写插入 SQL。
 * 状态节点：
 *   registered  挂号成功，进入科室待诊队列（无病历医嘱）→ 门诊叫号大屏
 *   consulting  接诊中：生成体征与主诉，无处方或草稿
 *   prescribed  开立医嘱：结构化病历 + 单据，未缴费
 *   paid        划价缴费，单据进入执行科室队列 → 医技排队叫号
 *   finished    就诊闭环归档（药已发/报告已出/处置已执行）
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();

class VisitFlowEngine {

    /** @var PDO 主库连接 */
    protected $pdo;

    public function __construct() {
        $this->pdo = DatabaseManager::getMain();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** 生成唯一单号（如 JY20260920123456） */
    public function uniqueNo($prefix, $table, $col) {
        do {
            $no = $prefix . date('YmdHis') . str_pad((string)rand(0, 99), 2, '0', STR_PAD_LEFT);
        } while ((int)$this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$col}=" . $this->pdo->quote($no))->fetchColumn() > 0);
        return $no;
    }

    /**
     * 生成一张已缴费订单（orders + order_items + payments 事务写入）。
     * 用于医技排队专项：挂号→接诊→开单→缴费（paid）后单据进入执行科室队列。
     *
     * @param array  $visit    就诊记录（id/patient_no/flow_no/current_dept_id/current_dept_name）
     * @param string $orderType order_type：lab/imaging/prescription/procedure
     * @param string $itemType  item_type：lab/imaging/prescription/procedure
     * @param array  $item     项目字典（id/name/spec/unit/price/single_dose/frequency/route/fee）
     * @param float  $price    单价
     * @param array  $extra    额外（is_nurse 等）
     * @return int 订单 ID
     */
    public function createPaidOrder($visit, $orderType, $itemType, $item, $price, $extra = array()) {
        $visitId = (int)$visit['id'];
        $now = now_str();
        $prefix = array('lab' => 'JY', 'imaging' => 'JC', 'prescription' => 'CF', 'procedure' => 'CZ');
        $orderNo = $this->uniqueNo(isset($prefix[$orderType]) ? $prefix[$orderType] : 'OD', 'orders', 'order_no');
        $deptId = (int)$visit['current_dept_id'];
        $deptName = (string)$visit['current_dept_name'];
        // 开单医生：本科室任一启用医生，无则取任一医生，再退化为测试归属
        $doc = $this->pdo->query("SELECT id, name FROM users WHERE role='doctor' AND status=1 AND (dept_ids='' OR dept_ids LIKE " . $this->pdo->quote('%,' . $deptId . ',%') . ") ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$doc) $doc = $this->pdo->query("SELECT id, name FROM users WHERE role='doctor' AND status=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $docId = $doc ? (int)$doc['id'] : 0;
        $docName = $doc ? (string)$doc['name'] : '系统测试';

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO orders(visit_id, patient_no, flow_no, order_type, order_no, doctor_id, doctor_name, dept_id, dept_name, total_amount, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array($visitId, $visit['patient_no'], $visit['flow_no'], $orderType, $orderNo, $docId, $docName, $deptId, $deptName, $price, 'paid', $now, $now));
            $orderId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO order_items(order_id, visit_id, patient_no, flow_no, item_type, item_id, item_name, spec, unit, price, quantity, single_dose, frequency, route, is_nurse, status, doctor_id, doctor_name, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array(
                $orderId, $visitId, $visit['patient_no'], $visit['flow_no'],
                $itemType, (int)$item['id'], (string)$item['name'],
                isset($item['spec']) ? (string)$item['spec'] : '',
                isset($item['unit']) ? (string)$item['unit'] : '',
                (float)$price, 1,
                isset($item['single_dose']) ? (string)$item['single_dose'] : '',
                isset($item['frequency']) ? (string)$item['frequency'] : '',
                isset($item['route']) ? (string)$item['route'] : '',
                (int)(isset($extra['is_nurse']) ? $extra['is_nurse'] : 0),
                'paid', $docId, $docName, $now,
            ));
            $this->pdo->prepare(
                'INSERT INTO payments(order_id, visit_id, patient_no, flow_no, total, item_count, cashier_id, cashier_name, kind, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)'
            )->execute(array($orderId, $visitId, $visit['patient_no'], $visit['flow_no'], $price, 1, 2, '收款员', 'order', $now));
            $this->pdo->commit();
        } catch (Exception $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $ex;
        }
        return $orderId;
    }

    /**
     * 虚拟挂号（状态 registered，进入待诊队列，无病历医嘱）——门诊叫号大屏测试用。
     * @param array $opts { patient_no, flow_no, dept_id, dept_name, fee, doctor_id }
     * @return int 就诊 ID
     */
    public function register($opts) {
        $now = now_str();
        $this->pdo->prepare(
            'INSERT INTO registrations(patient_no, flow_no, current_dept_id, current_dept_name, fee, status, created_at, paid_at) VALUES(?,?,?,?,?,?,?,?)'
        )->execute(array(
            $opts['patient_no'], $opts['flow_no'], (int)$opts['dept_id'], (string)$opts['dept_name'],
            (float)(isset($opts['fee']) ? $opts['fee'] : 0), 'registered', $now, $now,
        ));
        return (int)$this->pdo->lastInsertId();
    }
}