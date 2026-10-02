<?php
/**
 * ============================================================
 * services/lis/LisService.php — LIS 实验室检验双向闭环
 * ============================================================
 * 说明：
 *  - 出向 sendOrder()：检验申请下发（integration.outbound.lis.*），
 *    Outbox worker 调用；HTTP 500/超时异常由补偿表记录重试。
 *  - 入向 handleCallback()：检验中心报告 Webhook 回传（POST
 *    /api/external/lis/callback），按内部申请单号幂等回填：
 *      · 更新检验明细指标、单位、参考区间、异常标志（values_json）；
 *      · 回写报告医生与 PDF 报告附件地址（reports.doctor/pdf_url）；
 *      · 已审核完成的报告再次推送时执行覆盖性更新而非重复插入。
 * 回调 JSON 结构：
 *   { "order_no": "申请单号", "report_doctor": "李检验",
 *     "pdf_url": "http://lis/reports/1.pdf",
 *     "items": [ { "name": "白细胞计数", "value": "6.2",
 *                  "unit": "10^9/L", "ref_range": "3.5-9.5", "flag": "N" } ] }
 * ============================================================ */
class LisService {

    /* ==================== 出向：检验申请下发 ==================== */

    /**
     * 下发检验申请到 LIS
     * @param int $orderId
     * @throws Exception 下发失败
     */
    public static function sendOrder($orderId) {
        $url = trim((string)setting('integration.outbound.lis.order_url', ''));
        $token = trim((string)setting('integration.outbound.lis.auth_token', ''));
        if ($url === '') {
            throw new Exception('LIS 申请接口未配置（integration.outbound.lis.order_url）');
        }
        $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array((int)$orderId));
        if (!$order) throw new Exception('检验申请单不存在：' . (int)$orderId);
        $patient = PatientRepository::one('SELECT name, gender, birth_date, id_card, phone FROM patients WHERE patient_no=?', array($order['patient_no']));
        $items = OrderRepository::q("SELECT item_id, item_name, price FROM order_items
            WHERE order_id=? AND item_type='lab' AND item_id>0 ORDER BY id", array((int)$orderId));
        $payload = array(
            'order_no' => $order['order_no'],
            'visit_id' => (int)$order['visit_id'],
            'flow_no' => $order['flow_no'],
            'patient_no' => $order['patient_no'],
            'patient' => $patient ? $patient : array(),
            'doctor_name' => $order['doctor_name'],
            'created_at' => $order['created_at'],
            'items' => $items,
        );
        $headers = array('Content-Type: application/json; charset=utf-8');
        if ($token !== '') $headers[] = 'X-LIS-Token: ' . $token;
        $resp = HttpClient::request('POST', $url, array('json' => $payload, 'timeout' => 15, 'headers' => $headers));
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new Exception('LIS 申请下发失败（HTTP ' . $resp['status'] . '）：' . $resp['body']);
        }
    }

    /* ==================== 入向：报告回传处理（幂等回填） ==================== */

    /**
     * 处理 LIS 报告回调
     * @param array $payload 解析后的 JSON 载荷
     * @return array { updated:int, report_id:int, msg:string }
     * @throws Exception 载荷无效 / 申请单不存在
     */
    public static function handleCallback($payload) {
        if (!is_array($payload)) {
            throw new Exception('载荷必须为 JSON 对象');
        }
        $orderNo = trim((string)(isset($payload['order_no']) ? $payload['order_no'] : ''));
        if ($orderNo === '' && isset($payload['flow_no'])) {
            $orderNo = trim((string)$payload['flow_no']);   // 兼容以就诊流水号作为定位键
        }
        if ($orderNo === '') {
            throw new Exception('缺少 order_no 申请单号（或 flow_no 就诊流水号）');
        }
        // 观察明细：items（首选）或 results（别名）
        $items = array();
        if (isset($payload['items']) && is_array($payload['items'])) $items = $payload['items'];
        elseif (isset($payload['results']) && is_array($payload['results'])) $items = $payload['results'];
        $doctor = '';
        foreach (array('report_doctor', 'review_doctor', 'doctor_name') as $k) {
            if (!empty($payload[$k])) { $doctor = trim((string)$payload[$k]); break; }
        }
        return self::applyObservationReport(
            $orderNo,
            $items,
            $doctor,
            trim((string)(isset($payload['pdf_url']) ? $payload['pdf_url'] : '')),
            trim((string)(isset($payload['report_no']) ? $payload['report_no'] : ''))
        );
    }

    /**
     * 核心回填（LIS 回调 / HL7 ORU^R01 共用）：
     * 按申请单号定位 order，将观察项（name/value/unit/ref_range/flag）幂等写入
     * results.values_json，并覆盖性更新/新建报告。
     * @param string $orderNo      内部申请单号（或就诊流水号）
     * @param array  $items        观察项列表
     * @param string $reportDoctor 报告医生
     * @param string $pdfUrl       PDF 报告附件地址
     * @param string $reportNo     外部报告号（可空）
     * @return array { updated:int, report_id:int, msg:string }
     * @throws Exception
     */
    public static function applyObservationReport($orderNo, $items, $reportDoctor = '', $pdfUrl = '', $reportNo = '') {
        $orderNo = trim((string)$orderNo);
        if ($orderNo === '') {
            throw new Exception('缺少申请单号');
        }
        $order = OrderRepository::one('SELECT * FROM orders WHERE order_no=?', array($orderNo));
        if (!$order) {
            // 兼容以就诊流水号作为申请单号的下发
            $order = OrderRepository::one('SELECT * FROM orders WHERE flow_no=? ORDER BY id DESC LIMIT 1', array($orderNo));
        }
        if (!$order) {
            throw new Exception('申请单不存在：' . $orderNo);
        }
        $orderId = (int)$order['id'];
        $visitId = (int)$order['visit_id'];
        $patientNo = (string)$order['patient_no'];
        $flowNo = (string)$order['flow_no'];

        $reportDoctor = trim((string)$reportDoctor);
        $pdfUrl = trim((string)$pdfUrl);
        $reportNo = trim((string)$reportNo);
        $items = is_array($items) ? $items : array();
        if (!$items) {
            throw new Exception('回调缺少观察明细');
        }

        // 申请单下所有检验明细（order_items）与项目字典
        $orderItems = OrderRepository::q("SELECT oi.*, li.name AS dict_name, li.unit AS dict_unit, li.normal_range AS dict_range,
            li.is_group AS dict_group, li.parent_id AS dict_parent
            FROM order_items oi LEFT JOIN lab_items li ON li.id=oi.item_id
            WHERE oi.order_id=? AND oi.item_type='lab' ORDER BY oi.id", array($orderId));
        if (!$orderItems) {
            throw new Exception('申请单无检验明细：' . $orderNo);
        }

        // 建立 项目名→order_item 索引（优先字典名，回退开单名）
        $byName = array();
        $groupByMember = array();   // 成员名 → 组 order_item（用于组结果合并）
        foreach ($orderItems as $oi) {
            $name = trim((string)($oi['dict_name'] !== '' ? $oi['dict_name'] : $oi['item_name']));
            if ($name !== '') $byName[$name] = $oi;
            if ((int)$oi['dict_group'] === 1) {
                $members = OrderRepository::q('SELECT id, name FROM lab_items WHERE parent_id=? AND is_group=0', array((int)$oi['item_id']));
                foreach ($members as $m) {
                    $groupByMember[trim((string)$m['name'])] = array('group_oi' => $oi, 'member_id' => (int)$m['id']);
                }
            }
        }

        $updated = 0;
        $resultIds = array();
        foreach ($items as $obs) {
            if (!is_array($obs)) continue;
            $name = trim((string)(isset($obs['name']) ? $obs['name'] : ''));
            if ($name === '') continue;
            $value = (string)(isset($obs['value']) ? $obs['value'] : '');
            $unit = (string)(isset($obs['unit']) ? $obs['unit'] : '');
            $ref = (string)(isset($obs['ref_range']) ? $obs['ref_range'] : '');
            $flag = (string)(isset($obs['flag']) ? $obs['flag'] : '');
            $meta = array('unit' => $unit, 'ref_range' => $ref, 'flag' => $flag);

            $target = null;
            $memberId = 0;
            if (isset($byName[$name])) {
                $target = $byName[$name];
            } elseif (isset($groupByMember[$name])) {
                $target = $groupByMember[$name]['group_oi'];
                $memberId = $groupByMember[$name]['member_id'];
            }
            if (!$target) continue;   // 未匹配的观察项跳过（不阻断整单）

            $itemId = (int)$target['item_id'];
            $orderItemId = (int)$target['id'];
            $isGroup = (int)$target['dict_group'] === 1;

            $result = OrderRepository::one('SELECT * FROM results WHERE order_item_id=?', array($orderItemId));
            if ($isGroup) {
                // 组结果：values 按成员 id 合并（覆盖性更新），meta 附加异常标志
                $vals = $result ? (array)(json_decode((string)$result['values_json'], true)) : array('group' => 1, 'values' => array());
                if (!isset($vals['values']) || !is_array($vals['values'])) $vals['values'] = array();
                $vals['values'][(string)$memberId] = $value;
                if (!isset($vals['meta']) || !is_array($vals['meta'])) $vals['meta'] = array();
                $vals['meta'][(string)$memberId] = $meta;
                $valuesJson = json_encode($vals, JSON_UNESCAPED_UNICODE);
            } else {
                // 单项结果：value + 单位/参考区间/异常标志（打印/详情仅读 value 与 group，向后兼容）
                $valuesJson = json_encode(array('value' => $value) + $meta, JSON_UNESCAPED_UNICODE);
            }

            if ($result) {
                OrderRepository::exec("UPDATE results SET values_json=?, status='done', executed_by=?, updated_at=? WHERE id=?",
                    array($valuesJson, $reportDoctor !== '' ? $reportDoctor : 'LIS', now_str(), (int)$result['id']));
                $resultId = (int)$result['id'];
            } else {
                $resultId = OrderRepository::insert(
                    'INSERT INTO results(item_id, order_item_id, visit_id, patient_no, flow_no, type, values_json, executed_by, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                    array($itemId, $orderItemId, $visitId, $patientNo, $flowNo, 'lab', $valuesJson,
                        $reportDoctor !== '' ? $reportDoctor : 'LIS', 'done', now_str(), now_str())
                );
            }
            // 回写 order_items.result_id（检验完成队列据此关联报告）
            OrderRepository::exec('UPDATE order_items SET result_id=? WHERE id=?', array($resultId, $orderItemId));
            $resultIds[$resultId] = $resultId;
            $updated++;
        }

        if ($updated === 0) {
            throw new Exception('回调明细均未匹配申请单内检验项目');
        }

        $reportId = self::upsertReport($order, $reportNo, $reportDoctor, $pdfUrl, $resultIds);
        return array('updated' => $updated, 'report_id' => $reportId, 'msg' => '已回填 ' . $updated . ' 项检验结果');
    }

    /**
     * 报告覆盖性更新（幂等：有报告号按报告号更新；无报告号按首个结果关联报告；
     * 已存在报告 → 更新 doctor/pdf_url/status，绝不重复插入）
     * @param array  $order        申请单行
     * @param string $reportNo     外部报告号（可空）
     * @param string $reportDoctor 报告医生
     * @param string $pdfUrl       PDF 附件地址
     * @param array  $resultIds    本次回填的结果 id 集合
     * @return int 报告 id
     */
    private static function upsertReport($order, $reportNo, $reportDoctor, $pdfUrl, $resultIds) {
        $visitId = (int)$order['visit_id'];
        $patientNo = (string)$order['patient_no'];
        $flowNo = (string)$order['flow_no'];
        $report = null;
        if ($reportNo !== '') {
            $report = OrderRepository::one('SELECT * FROM reports WHERE report_no=?', array($reportNo));
        }
        if (!$report && $resultIds) {
            $rid = (int)reset($resultIds);
            $report = OrderRepository::one('SELECT * FROM reports WHERE result_id=? AND type=? ORDER BY id LIMIT 1', array($rid, 'lab'));
        }
        $fields = array(
            'doctor_name' => $reportDoctor !== '' ? $reportDoctor : 'LIS',
            'pdf_url' => $pdfUrl,
            'status' => 'done',
        );
        if ($report) {
            $set = array();
            $params = array();
            foreach ($fields as $k => $v) {
                $set[] = $k . '=?';
                $params[] = $v;
            }
            $params[] = (int)$report['id'];
            OrderRepository::exec('UPDATE reports SET ' . implode(',', $set) . ' WHERE id=?', $params);
            return (int)$report['id'];
        }
        $no = $reportNo !== '' ? $reportNo : next_report_no('lab');
        return insert_report(array(
            'result_id' => $resultIds ? (int)reset($resultIds) : 0,
            'report_no' => $no,
            'visit_id' => $visitId, 'patient_no' => $patientNo, 'flow_no' => $flowNo,
            'type' => 'lab', 'doctor_name' => $reportDoctor !== '' ? $reportDoctor : 'LIS', 'status' => 'done',
            'pdf_url' => $pdfUrl,
            'apply_dept_name' => (string)$order['dept_name'],
            'apply_doctor_name' => (string)$order['doctor_name'],
            'applied_at' => (string)$order['created_at'],
        ));
    }
}