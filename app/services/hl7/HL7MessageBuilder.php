<?php
/**
 * ============================================================
 * services/hl7/HL7MessageBuilder.php — HL7 v2.x 消息构建器
 * ============================================================
 * 说明：按 HL7 v2.4 编码规则组装标准消息段（MSH/PID/PV1/ORC/OBR/RXE/MSA）：
 *  - 分隔符：|^~\&，字段重复 ~、组件 ^、子组件 &
 *  - MSH-9 消息类型、MSH-10 消息控制 ID（唯一）
 *  - 支持 ADT^A04（建档/挂号）、ADT^A08（更新）、ORM^O01（开单）、
 *    ACK 应答（MSA 段 AA/AE/AR）
 * 消息头字段取自 integration.outbound.hl7.* 配置。
 * ============================================================ */
class HL7MessageBuilder {

    /** 组装 MSH 段 */
    public static function msh($msgType, $msgControlId = '') {
        $now = now_str();
        $dt = substr($now, 0, 4) . substr($now, 5, 2) . substr($now, 8, 2) . substr($now, 11, 2) . substr($now, 14, 2);
        if ($msgControlId === '') $msgControlId = self::controlId();
        $f = array(
            'MSH',
            '^~\&',
            integration_cfg('outbound.hl7.sending_app', 'CLINIC_OPD', 'hl7_sending_app'),
            integration_cfg('outbound.hl7.sending_facility', ''),
            integration_cfg('outbound.hl7.receiving_app', '', 'hl7_receiving_app'),
            integration_cfg('outbound.hl7.receiving_facility', ''),
            $dt,
            '',
            $msgType,
            $msgControlId,
            'P',
            '2.4',
        );
        return implode('|', $f);
    }

    /** 生成唯一消息控制 ID（时间 + 4 位随机） */
    public static function controlId() {
        return date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** 患者资料行（patients 表） */
    public static function patientByNo($patientNo) {
        return $patientNo !== '' ? PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo)) : null;
    }

    /**
     * 按业务类型构建消息（Outbox worker 分发用）
     * @param string $businessType hl7_adt / hl7_orm
     * @param array  $payload      { visit_id, order_id }
     * @return string 消息文本；业务数据缺失返回 ''
     */
    public static function forBusiness($businessType, $payload) {
        if ($businessType === 'hl7_adt') {
            $visitId = isset($payload['visit_id']) ? (int)$payload['visit_id'] : 0;
            if ($visitId <= 0) return '';
            $visit = PatientRepository::one('SELECT * FROM registrations WHERE id=?', array($visitId));
            if (!$visit) return '';
            $patient = self::patientByNo((string)$visit['patient_no']);
            return self::adtA04($visit, $patient);
        }
        if ($businessType === 'hl7_orm') {
            $orderId = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
            if ($orderId <= 0) return '';
            $order = OrderRepository::one('SELECT * FROM orders WHERE id=?', array($orderId));
            if (!$order) return '';
            $visit = PatientRepository::one('SELECT * FROM registrations WHERE id=?', array((int)$order['visit_id']));
            $patient = self::patientByNo((string)$order['patient_no']);
            $items = OrderRepository::q('SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($orderId));
            return self::ormO01($order, $items, $visit, $patient);
        }
        return '';
    }

    /** ADT^A04：患者入院/挂号建档 */
    public static function adtA04($visit, $patient) {
        return self::adt($visit, $patient, 'ADT^A04');
    }

    /** ADT^A08：患者信息更新 */
    public static function adtA08($visit, $patient) {
        return self::adt($visit, $patient, 'ADT^A08');
    }

    /** ADT 组装（PID + PV1） */
    private static function adt($visit, $patient, $type) {
        $lines = array();
        $lines[] = self::msh($type);
        $lines[] = self::pid($patient, 1);
        $lines[] = self::pv1($visit);
        return implode("\r", $lines);
    }

    /** PID 段（患者标识） */
    public static function pid($patient, $setId = 1) {
        $p = $patient ? $patient : array();
        $name = (string)(isset($p['name']) ? $p['name'] : '');
        $f = array(
            'PID', (string)$setId, '',
            (string)(isset($p['patient_no']) ? $p['patient_no'] : ''),
            (string)(isset($p['patient_no']) ? $p['patient_no'] : ''),
            $name . '^' . $name,
            '',
            (string)(isset($p['birth_date']) ? $p['birth_date'] : ''),
            (string)(isset($p['gender']) ? $p['gender'] : ''),
        );
        // PID-11 地址 / PID-13 电话
        $addr = (string)(isset($p['address']) ? $p['address'] : '');
        $f[] = ''; $f[] = $addr !== '' ? $addr . '^' : '';
        $f[] = ''; $f[] = (string)(isset($p['phone']) ? $p['phone'] : '');
        return implode('|', $f);
    }

    /** PV1 段（就诊信息） */
    public static function pv1($visit, $setId = 1) {
        $v = $visit ? $visit : array();
        $f = array(
            'PV1', (string)$setId, 'O',
            (string)(isset($v['current_dept_name']) ? $v['current_dept_name'] : ''),
            '',
            '',
            (string)(isset($v['current_dept_name']) ? $v['current_dept_name'] : ''),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            (string)(isset($v['flow_no']) ? $v['flow_no'] : ''),
            '',
            (string)(isset($v['registered_at']) ? substr($v['registered_at'], 0, 10) : ''),
        );
        return implode('|', $f);
    }

    /** ORM^O01：处方/检查/检验开单 */
    public static function ormO01($order, $items, $visit = null, $patient = null) {
        $lines = array();
        $lines[] = self::msh('ORM^O01');
        if ($patient) $lines[] = self::pid($patient, 1);
        if ($visit) $lines[] = self::pv1($visit, 1);
        $orderNo = (string)(isset($order['order_no']) ? $order['order_no'] : '');
        $doctor = (string)(isset($order['doctor_name']) ? $order['doctor_name'] : '');
        $createdAt = (string)(isset($order['created_at']) ? $order['created_at'] : '');
        $isRx = (string)(isset($order['order_type']) ? $order['order_type'] : '') === 'prescription';
        $setId = 0;
        foreach ((array)$items as $it) {
            $setId++;
            $lines[] = self::orc($orderNo, $doctor, $createdAt);
            if ($isRx) {
                $lines[] = self::rxe($it, $setId);
            } else {
                $lines[] = self::obr($it, $orderNo, $doctor, $createdAt, $setId);
            }
        }
        return implode("\r", $lines);
    }

    /** ORC 段（公共订单） */
    public static function orc($orderNo, $doctor, $createdAt) {
        $dt = substr(str_replace(array('-', ' ', ':'), '', $createdAt), 0, 14);
        $f = array(
            'ORC', 'NW', $orderNo, '',
            'SC', '', '',
            '', $dt, '',
            '', $doctor,
        );
        return implode('|', $f);
    }

    /** OBR 段（检验/检查申请） */
    public static function obr($item, $orderNo, $doctor, $createdAt, $setId) {
        $dt = substr(str_replace(array('-', ' ', ':'), '', $createdAt), 0, 14);
        $itemName = (string)(isset($item['item_name']) ? $item['item_name'] : '');
        $f = array(
            'OBR', (string)$setId, $orderNo, '',
            $itemName . '^' . $itemName,
            '',
            $dt,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $doctor,
            '',
            $orderNo,
        );
        return implode('|', $f);
    }

    /** RXE 段（处方明细） */
    public static function rxe($item, $setId = 1) {
        $itemName = (string)(isset($item['item_name']) ? $item['item_name'] : '');
        $qty = (string)(isset($item['quantity']) ? $item['quantity'] : '');
        $unit = (string)(isset($item['unit']) ? $item['unit'] : '');
        $dose = (string)(isset($item['single_dose']) ? $item['single_dose'] : '');
        $freq = (string)(isset($item['frequency']) ? $item['frequency'] : '');
        $route = (string)(isset($item['route']) ? $item['route'] : '');
        $f = array(
            'RXE', $itemName . '^' . $itemName, '', $qty . '^' . $unit,
            '', $dose, '', $unit, '', '',
            $freq, '', $route,
        );
        return implode('|', $f);
    }

    /**
     * ACK 应答组装：MSH(ACK) + MSA|AA|原消息控制 ID
     * @param string $receivedRaw 原始接收消息（用于解析 MSH-9/MSH-10）
     * @param string $ackCode     AA 成功 / AE 应用错误 / AR 拒绝
     * @param string $text        错误说明（可空）
     * @return string
     */
    public static function ack($receivedRaw, $ackCode = 'AA', $text = '') {
        $parsed = HL7MessageParser::parse((string)$receivedRaw);
        $msh = $parsed && isset($parsed['msh']) ? $parsed['msh'] : array();
        $ctrl = isset($msh['control_id']) ? $msh['control_id'] : '';
        $ack = array();
        $ack[] = self::msh('ACK', self::controlId());
        $msa = array('MSA', $ackCode, $ctrl);
        if ($text !== '') $msa[] = $text;
        $ack[] = implode('|', $msa);
        // 处理失败（AE/AR）附 ERR 段：ERR|^^^<error code>|<error text>
        if ($ackCode !== 'AA') {
            $errCode = ($ackCode === 'AR') ? '100' : '207';   // 100=拒绝 207=应用内部错误
            $ack[] = 'ERR|^^^' . $errCode . '|' . str_replace(array("\r", "\n", '|'), ' ', (string)$text);
        }
        return implode("\r", $ack);
    }
}