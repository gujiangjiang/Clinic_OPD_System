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

    /**
     * 组装 MSH 段。
     * @param string|null $sendingApp / $sendingFac / $recvApp / $recvFac 传入则覆盖配置
     *        （ACK 应答需交换收发双方）。
     */
    public static function msh($msgType, $msgControlId = '', $version = '2.4', $sendingApp = null, $sendingFac = null, $recvApp = null, $recvFac = null) {
        $now = now_str();
        $dt = substr($now, 0, 4) . substr($now, 5, 2) . substr($now, 8, 2) . substr($now, 11, 2) . substr($now, 14, 2);
        if ($msgControlId === '') $msgControlId = self::controlId();
        if ($version === '') $version = '2.4';
        $f = array();
        $f[0] = 'MSH';
        $f[1] = '^~\&';                       // MSH-2 编码字符
        $f[2] = $sendingApp !== null ? (string)$sendingApp : integration_cfg('outbound.hl7.sending_app', 'CLINIC_OPD', 'hl7_sending_app');
        $f[3] = $sendingFac !== null ? (string)$sendingFac : integration_cfg('outbound.hl7.sending_facility', '');
        $f[4] = $recvApp !== null ? (string)$recvApp : integration_cfg('outbound.hl7.receiving_app', '', 'hl7_receiving_app');
        $f[5] = $recvFac !== null ? (string)$recvFac : integration_cfg('outbound.hl7.receiving_facility', '');
        $f[6] = $dt;                          // MSH-7
        $f[7] = '';                           // MSH-8
        $f[8] = $msgType;                     // MSH-9
        $f[9] = $msgControlId;                // MSH-10
        $f[10] = 'P';                         // MSH-11
        $f[11] = $version;                    // MSH-12
        $f[17] = 'UTF-8';                     // MSH-18 字符集（中文姓名）
        return self::join($f);
    }

    /** 生成唯一消息控制 ID（时间 + 随机，降低同秒碰撞） */
    public static function controlId() {
        return date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    /** 组装段：补齐中间空字段（避免缺失下标导致字段位置整体前移） */
    private static function join($f) {
        if ($f) {
            $max = max(array_keys($f));
            for ($i = 0; $i <= $max; $i++) { if (!array_key_exists($i, $f)) $f[$i] = ''; }
            ksort($f);
        }
        return implode('|', $f);
    }

    /** HL7 转义：字段/组件/子组件/重复/转义符（\F\ \S\ \T\ \R\ \E\） */
    public static function hl7Escape($v) {
        $v = (string)$v;
        if ($v === '') return '';
        return str_replace(
            array('\\', '|', '^', '&', '~'),
            array('\\E\\', '\\F\\', '\\S\\', '\\T\\', '\\R\\'),
            $v
        );
    }

    /** 姓名 → XPN 的 姓^名（中文按首字为姓；含空格按空格拆分；无法拆分则整名置于姓） */
    public static function xpn($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        if (strpos($name, ' ') !== false) {
            $parts = preg_split('/\s+/', $name);
            return self::hl7Escape(array_shift($parts)) . '^' . self::hl7Escape(implode('', $parts));
        }
        if (preg_match('/^[\x{4e00}-\x{9fa5}]{2,}$/u', $name)) {
            return self::hl7Escape(mb_substr($name, 0, 1, 'UTF-8')) . '^' . self::hl7Escape(mb_substr($name, 1, null, 'UTF-8'));
        }
        return self::hl7Escape($name);
    }

    /** 日期 → HL7 DTM（YYYYMMDD，去掉分隔符） */
    public static function dtmDate($s) {
        $d = preg_replace('/\D/', '', (string)$s);
        return strlen($d) >= 8 ? substr($d, 0, 8) : '';
    }

    /** 性别 → HL7 表 0001（M/F/O/U） */
    public static function hl7Sex($g) {
        $g = strtolower(trim((string)$g));
        if ($g === '男' || $g === 'male' || $g === 'm' || $g === '1') return 'M';
        if ($g === '女' || $g === 'female' || $g === 'f' || $g === '2') return 'F';
        return 'U';
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
        return self::adt($visit, $patient, 'ADT^A04^ADT_A01');
    }

    /** ADT^A08：患者信息更新 */
    public static function adtA08($visit, $patient) {
        return self::adt($visit, $patient, 'ADT^A08^ADT_A01');
    }

    /** ADT 组装（EVN + PID + PV1） */
    private static function adt($visit, $patient, $type) {
        $lines = array();
        $lines[] = self::msh($type);
        // EVN-1 事件类型（ADT^A04 → A04）；EVN-2 事件记录时间
        $trigger = '';
        $tp = explode('^', (string)$type);
        if (isset($tp[1])) $trigger = $tp[1];
        $now = now_str();
        $lines[] = 'EVN|' . $trigger . '|' . substr($now, 0, 4) . substr($now, 5, 2) . substr($now, 8, 2) . substr($now, 11, 2) . substr($now, 14, 2);
        $lines[] = self::pid($patient, 1);
        $lines[] = self::pv1($visit);
        return implode("\r", $lines);
    }

    /** PID 段（患者标识） */
    public static function pid($patient, $setId = 1) {
        $p = $patient ? $patient : array();
        $patNo = (string)(isset($p['patient_no']) ? $p['patient_no'] : '');
        $f = array();
        $f[0] = 'PID';
        $f[1] = (string)$setId;                 // PID-1 Set ID
        $f[2] = '';                             // PID-2 外部患者 ID（不用）
        $f[3] = $patNo;                         // PID-3 患者标识列表（MRN）
        $f[4] = '';                             // PID-4 备用患者 ID（留空，避免与 PID-3 重复）
        $f[5] = self::xpn(isset($p['name']) ? $p['name'] : '');   // PID-5 姓名 姓^名
        $f[6] = '';                             // PID-6 母亲姓名
        $f[7] = self::dtmDate(isset($p['birth_date']) ? $p['birth_date'] : '');  // PID-7 出生日期
        $f[8] = self::hl7Sex(isset($p['gender']) ? $p['gender'] : '');           // PID-8 性别
        $f[9] = '';                             // PID-9 别名
        $f[10] = '';                            // PID-10 种族
        // PID-11 地址（组件：街道^其他^市^省^…）
        $addr = (string)(isset($p['address']) ? $p['address'] : '');
        $f[11] = $addr !== '' ? self::hl7Escape($addr) . '^' : '';
        $f[12] = '';                            // PID-12 县区码
        $f[13] = (string)(isset($p['phone']) ? $p['phone'] : '');   // PID-13 电话
        return self::join($f);
    }

    /** PV1 段（就诊信息） */
    public static function pv1($visit, $setId = 1) {
        $v = $visit ? $visit : array();
        $f = array();
        $f[0] = 'PV1';
        $f[1] = (string)$setId;                          // PV1-1 Set ID
        $f[2] = 'O';                                     // PV1-2 患者类别：O=门诊
        $f[3] = self::hl7Escape((string)(isset($v['current_dept_name']) ? $v['current_dept_name'] : ''));  // PV1-3 就诊科室
        // PV1-19 就诊号（Visit Number）：放在正确字段
        $f[19] = (string)(isset($v['flow_no']) ? $v['flow_no'] : '');
        // PV1-44 入院时间（Admit Date/Time）：完整 DTM
        $reg = (string)(isset($v['registered_at']) ? $v['registered_at'] : '');
        $rd = preg_replace('/\D/', '', substr($reg, 0, 19));
        $f[44] = $rd;
        return self::join($f);
    }

    /** ORM^O01：处方/检查/检验开单 */
    public static function ormO01($order, $items, $visit = null, $patient = null) {
        $lines = array();
        $lines[] = self::msh('ORM^O01^ORM_O01');
        if ($patient) $lines[] = self::pid($patient, 1);
        if ($visit) $lines[] = self::pv1($visit, 1);
        $orderNo = (string)(isset($order['order_no']) ? $order['order_no'] : '');
        $doctor = (string)(isset($order['doctor_name']) ? $order['doctor_name'] : '');
        $createdAt = (string)(isset($order['created_at']) ? $order['created_at'] : '');
        $isRx = (string)(isset($order['order_type']) ? $order['order_type'] : '') === 'prescription';
        // 标准 ORM：一个 ORC 订单头，后接各明细段（OBR/RXE）
        $lines[] = self::orc($orderNo, $doctor, $createdAt);
        $setId = 0;
        foreach ((array)$items as $it) {
            $setId++;
            if ($isRx) {
                $lines[] = self::rxe($it, $setId);
                $rxr = self::rxr($it);
                if ($rxr !== '') $lines[] = $rxr;
            } else {
                $lines[] = self::obr($it, $orderNo, $doctor, $createdAt, $setId);
            }
        }
        return implode("\r", $lines);
    }

    /** ORC 段（公共订单） */
    public static function orc($orderNo, $doctor, $createdAt) {
        $dt = substr(str_replace(array('-', ' ', ':'), '', $createdAt), 0, 14);
        $f = array();
        $f[0] = 'ORC';
        $f[1] = 'NW';      // ORC-1 订单控制：NW=新单
        $f[2] = $orderNo;  // ORC-2 申请单号（Placer）
        $f[3] = $orderNo;  // ORC-3 执行单号（Filler）
        $f[4] = '';        // ORC-4 Placer Group Number
        $f[5] = 'SC';      // ORC-5 订单状态：SC=Scheduled（此前误置于 ORC-4）
        $f[9] = $dt;      // ORC-9 事务时间（index=字段号）
        $f[12] = self::hl7Escape($doctor); // ORC-12 申请医生
        return self::join($f);
    }

    /** OBR 段（检验/检查申请） */
    public static function obr($item, $orderNo, $doctor, $createdAt, $setId) {
        $dt = substr(str_replace(array('-', ' ', ':'), '', $createdAt), 0, 14);
        $itemName = self::hl7Escape((string)(isset($item['item_name']) ? $item['item_name'] : ''));
        $f = array();
        $f[0] = 'OBR';
        $f[1] = (string)$setId;   // OBR-1 Set ID
        $f[2] = $orderNo;         // OBR-2 申请单号（Placer）
        $f[3] = $orderNo;         // OBR-3 执行单号（Filler）
        $f[4] = $itemName;        // OBR-4 通用服务标识（项目名）
        $f[6] = $dt;              // OBR-6 申请时间
        $f[7] = $dt;              // OBR-7 观察/执行时间
        $f[16] = self::hl7Escape($doctor);         // OBR-16 申请医生
        return self::join($f);
    }

    /** RXE 段（处方明细） */
    public static function rxe($item, $setId = 1) {
        $itemName = self::hl7Escape((string)(isset($item['item_name']) ? $item['item_name'] : ''));
        $qty = (string)(isset($item['quantity']) ? $item['quantity'] : '');
        $unit = (string)(isset($item['unit']) ? $item['unit'] : '');
        $dose = (string)(isset($item['single_dose']) ? $item['single_dose'] : '');
        $freq = (string)(isset($item['frequency']) ? $item['frequency'] : '');
        $f = array();
        $f[0] = 'RXE';
        $f[1] = '';          // RXE-1 数量/时间（此处留空）
        $f[2] = $itemName;   // RXE-2 给药代码（药品名）
        $f[3] = $dose;       // RXE-3 给药量（最小）
        $f[5] = $unit;       // RXE-5 给药单位
        $f[7] = $freq;       // RXE-7 给药说明（频次）
        $f[10] = $qty;       // RXE-10 发药量
        $f[11] = $unit;      // RXE-11 发药单位
        return self::join($f);
    }

    /** RXR 段（用药途径） */
    public static function rxr($item) {
        $route = (string)(isset($item['route']) ? $item['route'] : '');
        if ($route === '') return '';
        return 'RXR|' . $route;   // RXR-1 用药途径
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
        // MSH-9 应为 ACK^<触发事件>^ACK，并回填对端版本（MSH-12）
        $inType = isset($msh['type']) ? (string)$msh['type'] : '';
        $tp = explode('^', $inType);
        $ackType = (isset($tp[1]) && $tp[1] !== '') ? ('ACK^' . $tp[1] . '^ACK') : 'ACK';
        $version = isset($msh['version']) && $msh['version'] !== '' ? (string)$msh['version'] : '2.4';
        $ack = array();
        // ACK 的收发双方与原消息互换
        $ack[] = self::msh($ackType, self::controlId(), $version,
            isset($msh['receiving_app']) ? $msh['receiving_app'] : null,
            isset($msh['receiving_facility']) ? $msh['receiving_facility'] : null,
            isset($msh['sending_app']) ? $msh['sending_app'] : null,
            isset($msh['sending_facility']) ? $msh['sending_facility'] : null);
        $msa = array('MSA', $ackCode, $ctrl);
        if ($text !== '') $msa[] = $text;
        $ack[] = implode('|', $msa);
        // 处理失败（AE/AR）附 ERR 段（v2.4 ELD：ERR-1.4=错误码，ERR-1.5=说明）
        if ($ackCode !== 'AA') {
            $errCode = ($ackCode === 'AR') ? '200' : '207';   // 200=不支持的消息类型 207=应用内部错误
            $errText = str_replace(array("\r", "\n", '|', '^'), ' ', (string)$text);
            $ack[] = 'ERR|^^^' . $errCode . '&' . $errText;   // ELD-4 为 CE（码&文本）
        }
        return implode("\r", $ack);
    }
}