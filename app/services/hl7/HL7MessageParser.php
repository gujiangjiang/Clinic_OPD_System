<?php
/**
 * ============================================================
 * services/hl7/HL7MessageParser.php — HL7 v2.x 消息解析器
 * ============================================================
 * 说明：解析标准 HL7 消息：
 *  - parse()：按 \r 分段、| 分字段，提取 MSH（消息类型/控制 ID/收发应用）
 *  - oruExtract()：从 ORU^R01 提取 OBR（申请单识别）与 OBX 观察结果明细
 *  - 兼容 \n 换行（部分网关以 \n 结尾）
 * ============================================================ */
class HL7MessageParser {

    /**
     * 解析 HL7 消息为段结构
     * @param string $raw
     * @return array { raw, msh:{type, control_id, sending_app, receiving_app}, segments:[[名称, 字段...]] }
     */
    public static function parse($raw) {
        $raw = trim((string)$raw);
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
                );
            }
        }
        return $result;
    }

    /**
     * 提取 ORU^R01 观察结果
     * @param array $parsed parse() 结果
     * @return array { ok:bool, msg_type:string, control_id:string, order_no:string,
     *                 patient_no:string, observations:[] }
     * observations 项：{ item_code, item_name, value, unit, ref_range, flag, abnormal, status }
     */
    public static function oruExtract($parsed) {
        $out = array(
            'ok' => false, 'msg_type' => '', 'control_id' => '', 'order_no' => '',
            'patient_no' => '', 'observations' => array(),
        );
        if (!$parsed || empty($parsed['msh'])) return $out;
        $msh = $parsed['msh'];
        $out['msg_type'] = $msh['type'];
        $out['control_id'] = $msh['control_id'];
        if (strpos($msh['type'], 'ORU^') !== 0 && strpos($msh['type'], 'ORU') !== 0) {
            return $out;
        }
        $orderNo = '';
        $patientNo = '';
        $obs = array();
        foreach ($parsed['segments'] as $seg) {
            $name = $seg[0];
            $f = $seg[1];
            if ($name === 'PID') {
                $patientNo = isset($f[2]) ? $f[2] : (isset($f[3]) ? $f[3] : '');
            } elseif ($name === 'OBR') {
                $orderNo = isset($f[1]) ? $f[1] : '';
                if ($orderNo === '' && isset($f[2])) $orderNo = $f[2];
            } elseif ($name === 'OBX') {
                $comp = function ($v) { return strpos($v, '^') !== false ? explode('^', $v) : array($v, $v); };
                $itemId = isset($f[2]) ? $f[2] : '';
                $idc = $comp($itemId);
                $obs[] = array(
                    'item_code' => isset($idc[0]) ? trim($idc[0]) : '',
                    'item_name' => isset($idc[1]) ? trim($idc[1]) : '',
                    'value' => isset($f[4]) ? $f[4] : '',
                    'unit' => isset($f[5]) ? trim($f[5]) : '',
                    'ref_range' => isset($f[6]) ? $f[6] : '',
                    'flag' => isset($f[7]) ? $f[7] : '',
                    'abnormal' => isset($f[7]) ? $f[7] : '',
                    'status' => isset($f[10]) ? $f[10] : 'F',
                );
            }
        }
        if ($orderNo === '') return $out;
        $out['ok'] = true;
        $out['order_no'] = $orderNo;
        $out['patient_no'] = $patientNo;
        $out['observations'] = $obs;
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
}