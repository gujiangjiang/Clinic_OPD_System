<?php
/**
 * ============================================================
 * services/hl7/HL7InboundService.php — HL7 入向业务处置服务
 * ============================================================
 * 说明：ORU^R01 结果报告的统一落库与危急值流转（HTTP 代理与 MLLP
 * 守护进程共用，杜绝两处重复实现）：
 *  1. 将 OBX 观察项回填至系统检验报告（LisService::applyObservationReport，
 *     按申请单号幂等，自动建报告并关联 order_items.result_id）；
 *  2. 危急值自动识别：OBX-8 异常标志命中 HH/LL/CRIT/PANIC（或依据
 *     lab_items 危急值上下限比对命中），自动写入 critical_values 流水
 *     并向接诊医生推送站内消息，实现门诊危急值闭环流转（幂等防重）。
 * ============================================================ */
class HL7InboundService {

    /** OBX-8 危急标志集合 */
    private static $criticalFlags = array('HH', 'LL', 'CRIT', 'PANIC', 'AA');

    /**
     * 处理 ORU^R01 解析结果
     * @param array $oru HL7MessageParser::oruExtract() 结果
     * @return array { updated:int, report_id:int, critical:int, msg:string }
     * @throws Exception 申请单不存在 / 明细未匹配
     */
    public static function applyOru($oru) {
        $orderNo = isset($oru['order_no']) ? trim((string)$oru['order_no']) : '';
        $observations = isset($oru['observations']) && is_array($oru['observations']) ? $oru['observations'] : array();
        if ($orderNo === '') throw new Exception('ORU 消息缺少 OBR 申请单号');
        if (!$observations) throw new Exception('ORU 消息缺少 OBX 观察项');

        $items = array();
        foreach ($observations as $o) {
            $name = trim((string)(isset($o['item_name']) && $o['item_name'] !== '' ? $o['item_name'] : (isset($o['item_code']) ? $o['item_code'] : '')));
            if ($name === '') continue;
            $items[] = array(
                'name' => $name,
                'value' => isset($o['value']) ? (string)$o['value'] : '',
                'unit' => isset($o['unit']) ? (string)$o['unit'] : '',
                'ref_range' => isset($o['ref_range']) ? (string)$o['ref_range'] : '',
                'flag' => isset($o['flag']) ? (string)$o['flag'] : '',
            );
        }
        if (!$items) throw new Exception('ORU 消息未解析到有效观察项');

        $res = LisService::applyObservationReport($orderNo, $items, '', '', '');
        $reportId = (int)$res['report_id'];

        // 危急值识别与流转
        $crit = self::detectCritical($observations);
        $raised = 0;
        if ($crit && $reportId > 0) {
            $raised = self::raiseCritical($orderNo, $reportId, $crit);
        }
        $msg = $res['msg'];
        if ($raised > 0) $msg .= '；已触发 ' . $raised . ' 条危急值流转';
        return array(
            'updated' => (int)$res['updated'],
            'report_id' => $reportId,
            'critical' => $raised,
            'msg' => $msg,
        );
    }

    /**
     * 危急值识别：优先 OBX-8 标志，其次 lab_items 阈值比对
     * @param array $observations
     * @return array 命中危急值明细
     */
    public static function detectCritical($observations) {
        $hits = array();
        foreach ((array)$observations as $o) {
            $name = trim((string)(isset($o['item_name']) && $o['item_name'] !== '' ? $o['item_name'] : (isset($o['item_code']) ? $o['item_code'] : '')));
            if ($name === '') continue;
            $value = isset($o['value']) ? (string)$o['value'] : '';
            $flag = strtoupper(trim((string)(isset($o['flag']) ? $o['flag'] : '')));
            $unit = isset($o['unit']) ? (string)$o['unit'] : '';
            $ref = isset($o['ref_range']) ? (string)$o['ref_range'] : '';
            $lo = '';
            $hi = '';

            $hit = in_array($flag, self::$criticalFlags, true);
            // 回退阈值比对：按项目名（或 OBX-3 code）查 lab_items 危急值上下限
            $code = isset($o['item_code']) ? trim((string)$o['item_code']) : '';
            $item = null;
            if ($name !== '') $item = OrderRepository::one('SELECT * FROM lab_items WHERE name=? LIMIT 1', array($name));
            if (!$item && $code !== '') $item = OrderRepository::one('SELECT * FROM lab_items WHERE name=? LIMIT 1', array($code));
            if (!$item && ctype_digit($code)) $item = OrderRepository::one('SELECT * FROM lab_items WHERE id=?', array((int)$code));
            if ($item) {
                $lo = (string)$item['critical_low'];
                $hi = (string)$item['critical_high'];
                if ($lo === '' && $hi === '' && isset($item['normal_range'])) {
                    $ref = $ref !== '' ? $ref : (string)$item['normal_range'];
                }
                if (crit_row_hit($value, $lo, $hi)) $hit = true;
            }
            if (!$hit) continue;
            $hits[] = array(
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'normal_range' => $ref,
                'critical_low' => $lo,
                'critical_high' => $hi,
                'flag' => $flag !== '' ? $flag : 'text',
            );
        }
        return $hits;
    }

    /**
     * 写入危急值流水 + 通知接诊医生（幂等：同报告同医生不重复）
     * @param string $orderNo
     * @param int    $reportId
     * @param array  $criticalItems
     * @return int 新建危急值条数
     */
    public static function raiseCritical($orderNo, $reportId, $criticalItems) {
        $order = OrderRepository::one('SELECT * FROM orders WHERE order_no=?', array((string)$orderNo));
        if (!$order) {
            $order = OrderRepository::one('SELECT * FROM orders WHERE flow_no=? ORDER BY id DESC LIMIT 1', array((string)$orderNo));
        }
        if (!$order) return 0;
        $report = OrderRepository::one('SELECT * FROM reports WHERE id=?', array((int)$reportId));
        if (!$report) return 0;

        $visit = CriticalValueRepository::visitById((int)$order['visit_id']);
        $patient = $order['patient_no'] !== ''
            ? PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array((string)$order['patient_no']))
            : null;

        // 接收医生：开单医生优先，回退就诊主诊所在科室的在职医生
        $doctor = null;
        if ((int)$order['doctor_id'] > 0) {
            $doctor = CriticalValueRepository::doctorActiveById((int)$order['doctor_id']);
        }
        if (!$doctor) {
            $doctor = OrderRepository::one("SELECT id, name FROM users WHERE role='doctor' AND status=1 ORDER BY id LIMIT 1");
        }
        if (!$doctor) return 0;

        // 幂等：同一报告 + 同一接收医生的危急值仅生成一次
        $dup = CriticalValueRepository::countRows('source=? AND report_id=? AND to_doctor_id=?', array('lab', (int)$reportId, (int)$doctor['id']));
        if ($dup > 0) return 0;

        $itemName = isset($criticalItems[0]['name']) ? (string)$criticalItems[0]['name'] : '检验危急值';
        $snapshot = array(
            'item_name' => $itemName,
            'rows' => array_map(function ($c) {
                $c['is_critical'] = 1;
                return $c;
            }, $criticalItems),
            'findings' => '',
            'conclusion' => '',
        );
        $cvId = (int)CriticalValueRepository::create(array(
            'source' => 'lab',
            'report_id' => (int)$reportId,
            'result_id' => (int)$report['result_id'],
            'visit_id' => (int)$order['visit_id'],
            'patient_no' => (string)$order['patient_no'],
            'flow_no' => (string)$order['flow_no'],
            'patient_name' => $patient ? (string)$patient['name'] : '',
            'patient_gender' => $patient ? (string)$patient['gender'] : '',
            'birth_date' => $patient ? (string)$patient['birth_date'] : '',
            'patient_age' => $patient ? age_format($patient['birth_date']) : '',
            'item_name' => $itemName,
            'items_json' => json_encode($criticalItems, JSON_UNESCAPED_UNICODE),
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'from_dept_name' => '检验科',
            'from_user_id' => 0,
            'from_user_name' => 'LIS',
            'to_doctor_id' => (int)$doctor['id'],
            'to_doctor_name' => (string)$doctor['name'],
            'status' => 'pending',
            'created_at' => now_str(),
        ));

        $pName = $patient ? (string)$patient['name'] : '';
        send_msg('doctor', (int)$doctor['id'],
            '危急值通知：' . $itemName,
            '患者「' . $pName . '」（' . $order['patient_no'] . '）的检验结果报危急值，请及时处理',
            'report', '/api/print?action=report&report_id=' . oid($reportId),
            array('msg_type' => 'critical', 'patient_name' => $pName, 'visit_id' => (int)$order['visit_id'], 'link_url' => '/critical_value/' . oid($cvId)));
        return 1;
    }
}
