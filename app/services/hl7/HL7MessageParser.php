<?php
/**
 * ============================================================
 * services/hl7/HL7MessageParser.php — HL7 v2.x 消息解析器
 * ============================================================
 * 说明：解析标准 HL7 消息：
 *  - parse()：按 \r/\n 分段、| 分字段，提取 MSH（消息类型/控制 ID/收发应用）
 *  - oruExtract()：从 ORU^R01 提取 MSH、PID、PV1、OBR（申请项目/申请医生）
 *    与 OBX 观察结果明细（数值/单位/参考范围/异常标志 OBX-8/状态 OBX-11）
 *  - 兼容 \n 换行（部分网关以 \n 结尾）与 MLLP 帧内残留
 * ============================================================ */
class HL7MessageParser {

    /**
     * 解析 HL7 消息为段结构
     * @param string $raw
     * @return array { raw, msh:{type, control_id, sending_app, receiving_app,...}, segments:[[名称, 字段...]] }
     */
    public static function parse($raw) {
        $raw = trim((string)$raw);
        // 去除 MLLP 帧字符（0x0B 起始 / 0x1C0x0D 结束）
        $raw = str_replace(array(chr(0x0B), chr(0x1C)), '', $raw);
        $result = array('raw' => $raw, 'msh' => array(), 'segments' => array());
        if ($raw === '') return $result;
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: array();
        foreach ($lines as $line) {
            $line = rtrim($line, "\x0d\x1c");
            if ($line === '') continue;
            $fields = explode('|', $line);
            $name = array_shift($fields);
            if ($name === '') continue;
            $result['segments'][] = array($name, $fields);
            if ($name === 'MSH' && empty($result['msh'])) {
                $result['msh'] = array(
                    'type' => isset($fields[7]) ? $fields[7] : '',
                    'control_id' => isset($fields[8]) ? $fields[8] : '',
                    'sending_app' => isset($fields[1]) ? $fields[1] : '',
                    'sending_facility' => isset($fields[2]) ? $fields[2] : '',
                    'receiving_app' => isset($fields[3]) ? $fields[3] : '',
                    'receiving_facility' => isset($fields[4]) ? $fields[4] : '',
                    'version' => isset($fields[10]) ? $fields[10] : '',
                );
            }
        }
        return $result;
    }

    /** 取组件首段（"A^B^C" → "A"） */
    private static function comp1($v) {
        $v = (string)$v;
        if ($v === '') return '';
        $parts = explode('^', $v);
        return trim($parts[0]);
    }

    /** 取组件第 n 段（0 基） */
    private static function compN($v, $n) {
        $parts = explode('^', (string)$v);
        return isset($parts[$n]) ? trim($parts[$n]) : '';
    }

    /**
     * 提取 ORU^R01 观察结果
     * @param array $parsed parse() 结果
     * @return array { ok:bool, msg_type, control_id, order_no, filler_no,
     *                 patient_no, patient_name, observations:[] }
     * observations 项：{ item_code, item_name, value, unit, ref_range, flag, abnormal, status }
     */
    public static function oruExtract($parsed) {
        $out = array(
            'ok' => false, 'msg_type' => '', 'control_id' => '', 'order_no' => '', 'filler_no' => '',
            'patient_no' => '', 'patient_name' => '', 'observations' => array(),
        );
        if (!$parsed || empty($parsed['msh'])) return $out;
        $msh = $parsed['msh'];
        $out['msg_type'] = isset($msh['type']) ? $msh['type'] : '';
        $out['control_id'] = isset($msh['control_id']) ? $msh['control_id'] : '';
        if (strpos($out['msg_type'], 'ORU^') !== 0 && strpos($out['msg_type'], 'ORU') !== 0) {
            return $out;
        }
        $orderNo = '';
        $fillerNo = '';
        foreach ($parsed['segments'] as $seg) {
            $name = $seg[0];
            $f = $seg[1];
            if ($name === 'PID') {
                // PID-3 患者标识（可能为重复列表 ~）；PID-5 姓名；PID-8 性别；PID-7 出生日期
                $pid3 = isset($f[2]) ? (string)$f[2] : '';
                if (strpos($pid3, '~') !== false) $pid3 = explode('~', $pid3);
                else $pid3 = array($pid3);
                $out['patient_no'] = self::comp1($pid3[0]);
                // PID-5 姓名：XPN.1 姓 + XPN.2 名（此前仅取名会丢失姓氏）
                if (isset($f[4])) {
                    $family = self::compN($f[4], 0);
                    $given = self::compN($f[4], 1);
                    if ($family !== '' && $given !== '' && preg_match('/^[\x00-\x7F]+$/', $family . $given)) {
                        $out['patient_name'] = $family . ' ' . $given;   // 西文姓名以空格连接
                    } else {
                        $out['patient_name'] = $family . $given;         // 中文姓名直接相连
                    }
                }
            } elseif ($name === 'OBR') {
                // OBR-2 申请单号（Placer）/ OBR-3 执行单号（Filler）；OBR-16 申请医生
                if ($orderNo === '' && isset($f[1]) && trim((string)$f[1]) !== '') $orderNo = self::comp1($f[1]);
                if (isset($f[2]) && trim((string)$f[2]) !== '') $fillerNo = self::comp1($f[2]);
                if ($orderNo === '' && $fillerNo !== '') $orderNo = $fillerNo;
            } elseif ($name === 'OBX') {
                $itemId = isset($f[2]) ? (string)$f[2] : '';
                $flag = isset($f[7]) ? trim((string)$f[7]) : '';
                $value = isset($f[4]) ? (string)$f[4] : '';
                $vtype = isset($f[1]) ? strtoupper(trim((string)$f[1])) : '';
                // 依 OBX-2 值类型解析 OBX-5（单位一律取 OBX-6，参考范围取 OBX-7）
                if ($vtype === 'SN') {                 // 结构化数值：比较符^值1^分隔^值2
                    $n1 = self::compN($value, 1);
                    $n2 = self::compN($value, 3);
                    $value = self::compN($value, 0) . $n1 . ($n2 !== '' ? ' - ' . $n2 : '');
                } elseif ($vtype === 'CQ') {           // 复合量：数值（单位见 OBX-6）
                    $value = self::compN($value, 0);
                } elseif (in_array($vtype, array('CWE', 'CE', 'CN'), true)) {   // 编码型：取文本
                    $value = self::compN($value, 1) !== '' ? self::compN($value, 1) : self::comp1($value);
                } else {
                    $value = trim($value);
                }
                $out['observations'][] = array(
                    'item_code' => self::comp1($itemId),
                    'item_name' => self::compN($itemId, 1) !== '' ? self::compN($itemId, 1) : self::comp1($itemId),
                    'value' => $value,
                    'unit' => isset($f[5]) ? trim((string)$f[5]) : '',
                    'ref_range' => isset($f[6]) ? (string)$f[6] : '',
                    'flag' => $flag,
                    'abnormal' => $flag,
                    'status' => isset($f[10]) && trim((string)$f[10]) !== '' ? trim((string)$f[10]) : 'F',
                );
            }
        }
        if ($orderNo === '') return $out;
        $out['ok'] = true;
        $out['order_no'] = $orderNo;
        $out['filler_no'] = $fillerNo;
        return $out;
    }

    /**
     * 从 MSA 段提取应答码（AA/AE/AR）
     * @param array $parsed
     * @return string 应答码；未找到返回 ''
     */
    public static function msaCode($parsed) {
        if (!$parsed || empty($parsed['segments'])) return '';
        foreach ($parsed['segments'] as $seg) {
            if ($seg[0] === 'MSA' && isset($seg[1][0])) {
                return trim($seg[1][0]);
            }
        }
        return '';
    }

    /** 从 MSA 段提取 MSA-2（应答对应的原消息控制 ID MSH-10） */
    public static function msaControl($parsed) {
        if (!$parsed || empty($parsed['segments'])) return '';
        foreach ($parsed['segments'] as $seg) {
            if ($seg[0] === 'MSA' && isset($seg[1][1])) {
                return trim($seg[1][1]);
            }
        }
        return '';
    }
}
