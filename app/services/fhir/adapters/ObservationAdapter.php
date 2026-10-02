<?php
/**
 * ============================================================
 * services/fhir/adapters/ObservationAdapter.php — Observation 资源适配器
 * ============================================================
 * 映射检验结果（results，type=lab）与生命体征（vitals）。
 * 搜索参数：_id、patient/subject、category、date。
 * id 规则：observation-{results.id} / observation-vital-{vitals.id}。
 * category=laboratory（默认）/ vital-signs。
 * ============================================================ */
class ObservationAdapter extends FhirAdapter {

    public static function resourceType() { return 'Observation'; }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if (strpos($bareId, 'vital-') === 0) {
            $id = substr($bareId, 6);
            if (!ctype_digit($id)) return null;
            $row = PatientRepository::one('SELECT * FROM vitals WHERE id=?', array((int)$id));
            if ($row) $row['__kind'] = 'vital';
            return $row;
        }
        if (!ctype_digit($bareId)) return null;
        $row = PatientRepository::one('SELECT * FROM results WHERE id=?', array((int)$bareId));
        if ($row) $row['__kind'] = 'lab';
        return $row;
    }

    /** 检验结果 → Observation（组结果展开为 component[]） */
    public static function toResource($row) {
        if (is_array($row) && isset($row['__kind']) && $row['__kind'] === 'vital') {
            return self::vitalResource($row);
        }
        return self::labResource($row);
    }

    private static function labResource($row) {
        $r = is_array($row) ? $row : array();
        $res = array(
            'resourceType' => 'Observation',
            'id' => 'observation-' . (int)(isset($r['id']) ? $r['id'] : 0),
            'status' => (isset($r['status']) && $r['status'] === 'done') ? 'final' : 'preliminary',
            'category' => array(self::codeable(
                'http://terminology.hl7.org/CodeSystem/observation-category', 'laboratory', 'Laboratory')),
        );
        $item = null;
        if (!empty($r['item_id'])) {
            $item = PatientRepository::one('SELECT * FROM lab_items WHERE id=?', array((int)$r['item_id']));
        }
        $itemName = $item ? (string)$item['name'] : '检验结果';
        $res['code'] = array('text' => $itemName);
        $subj = self::patientRef(isset($r['patient_no']) ? $r['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        $eff = self::isoDateTime(isset($r['updated_at']) && $r['updated_at'] !== '' ? $r['updated_at'] : (isset($r['created_at']) ? $r['created_at'] : ''));
        if ($eff !== null) $res['effectiveDateTime'] = $eff;

        $values = json_decode(isset($r['values_json']) ? (string)$r['values_json'] : '', true);
        if (!is_array($values)) $values = array();

        if (!empty($values['group'])) {
            $members = array();
            if ($item) {
                $members = PatientRepository::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$item['id']));
            }
            $map = isset($values['values']) && is_array($values['values']) ? $values['values'] : array();
            $meta = isset($values['meta']) && is_array($values['meta']) ? $values['meta'] : array();
            $components = array();
            foreach ($members as $m) {
                $mid = (int)$m['id'];
                $components[] = self::component(
                    (string)$m['name'], (string)$m['unit'],
                    isset($map[(string)$mid]) ? (string)$map[(string)$mid] : (isset($map[$mid]) ? (string)$map[$mid] : ''),
                    (string)$m['normal_range'],
                    isset($meta[(string)$mid]['flag']) ? (string)$meta[(string)$mid]['flag'] : (isset($meta[$mid]['flag']) ? (string)$meta[$mid]['flag'] : '')
                );
            }
            if ($components) $res['component'] = $components;
        } else {
            $val = isset($values['value']) ? (string)$values['value'] : '';
            $unit = isset($values['unit']) ? (string)$values['unit'] : ($item ? (string)$item['unit'] : '');
            $ref = isset($values['ref_range']) ? (string)$values['ref_range'] : ($item ? (string)$item['normal_range'] : '');
            $flag = isset($values['flag']) ? (string)$values['flag'] : '';
            self::applyValue($res, $val, $unit, $ref, $flag);
        }
        return $res;
    }

    /** 单个数值/文本写入 value[x] 与参考区间/解释 */
    private static function applyValue(&$res, $val, $unit, $ref, $flag) {
        if ($val === '') return;
        if (is_numeric($val)) {
            $q = array('value' => (float)$val);
            if ($unit !== '') { $q['unit'] = $unit; }
            $res['valueQuantity'] = $q;
        } else {
            $res['valueString'] = $val;
        }
        if ($ref !== '') {
            $res['referenceRange'] = array(array('text' => $ref));
        }
        $interp = self::interpretation($flag);
        if ($interp) $res['interpretation'] = array($interp);
    }

    /** 组内单项 → component */
    private static function component($name, $unit, $val, $ref, $flag) {
        $c = array('code' => array('text' => $name));
        if ($val !== '') {
            if (is_numeric($val)) {
                $q = array('value' => (float)$val);
                if ($unit !== '') $q['unit'] = $unit;
                $c['valueQuantity'] = $q;
            } else {
                $c['valueString'] = $val;
            }
        }
        if ($ref !== '') $c['referenceRange'] = array(array('text' => $ref));
        $interp = self::interpretation($flag);
        if ($interp) $c['interpretation'] = array($interp);
        return $c;
    }

    /** 异常标志 → FHIR interpretation（N/H/L/HH/LL/CRIT/PANIC） */
    private static function interpretation($flag) {
        $flag = strtoupper(trim((string)$flag));
        if ($flag === '') return null;
        $map = array('N' => 'N', 'H' => 'H', 'L' => 'L', 'HH' => 'HH', 'LL' => 'LL',
            'CRIT' => 'AA', 'PANIC' => 'AA', 'A' => 'A', '高' => 'H', '低' => 'L');
        if (!isset($map[$flag])) return null;
        $code = $map[$flag];
        $display = array('N' => 'Normal', 'H' => 'High', 'L' => 'Low', 'HH' => 'Critical high',
            'LL' => 'Critical low', 'AA' => 'Critical abnormal', 'A' => 'Abnormal');
        return self::codeable('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', $code, isset($display[$code]) ? $display[$code] : $code);
    }

    /** 生命体征 → Observation */
    private static function vitalResource($row) {
        $v = is_array($row) ? $row : array();
        $res = array(
            'resourceType' => 'Observation',
            'id' => 'observation-vital-' . (int)(isset($v['id']) ? $v['id'] : 0),
            'status' => 'final',
            'category' => array(self::codeable(
                'http://terminology.hl7.org/CodeSystem/observation-category', 'vital-signs', 'Vital Signs')),
            'code' => array('text' => '生命体征'),
        );
        $subj = self::patientRef(isset($v['patient_no']) ? $v['patient_no'] : '');
        if ($subj) $res['subject'] = $subj;
        $eff = self::isoDateTime(isset($v['created_at']) ? $v['created_at'] : '');
        if ($eff !== null) $res['effectiveDateTime'] = $eff;
        $comp = array();
        if ((int)(isset($v['vital_sbp']) ? $v['vital_sbp'] : 0) > 0) {
            $comp[] = array(
                'code' => array('text' => '收缩压'),
                'valueQuantity' => array('value' => (float)$v['vital_sbp'], 'unit' => 'mmHg'),
            );
        }
        if ((int)(isset($v['vital_dbp']) ? $v['vital_dbp'] : 0) > 0) {
            $comp[] = array(
                'code' => array('text' => '舒张压'),
                'valueQuantity' => array('value' => (float)$v['vital_dbp'], 'unit' => 'mmHg'),
            );
        }
        foreach (array('vital_heart_rate' => array('心率', '次/分'), 'vital_spo2' => array('血氧饱和度', '%'),
            'vital_respiration' => array('呼吸', '次/分'), 'vital_pulse' => array('脉搏', '次/分')) as $k => $meta) {
            if (isset($v[$k]) && trim((string)$v[$k]) !== '') {
                $val = (string)$v[$k];
                $c = array('code' => array('text' => $meta[0]));
                if (is_numeric($val)) $c['valueQuantity'] = array('value' => (float)$val, 'unit' => $meta[1]);
                else $c['valueString'] = $val;
                $comp[] = $c;
            }
        }
        if ($comp) $res['component'] = $comp;
        return $res;
    }

    public static function search($params) {
        $category = isset($params['category']) ? strtolower(trim((string)$params['category'])) : '';
        // 标准语义：未指定 category 时返回全部 Observation（检验 + 体征），不默认只查实验室
        if ($category === '') return self::searchAll($params);
        if (strpos($category, 'vital') !== false) return self::searchVitals($params);
        if (strpos($category, 'laboratory') !== false) return self::searchLabs($params);
        return self::searchAll($params);
    }

    /**
     * 无 category：合并检验（results）+ 生命体征（vitals），按时间倒序后统一分页。
     * 说明：两源各自最多取 200 条再合并分页（门诊量级足够；如需超大数据集再升级为 SQL UNION）。
     */
    private static function searchAll($params) {
        $p = $params;
        $p['_count'] = 200;
        unset($p['_page'], $p['_offset']);
        $labs = self::searchLabs($p);
        $vitals = self::searchVitals($p);
        $entries = array_merge($labs['entries'], $vitals['entries']);
        $refs = array_merge($labs['patientRefs'], $vitals['patientRefs']);
        // 按 effectiveDateTime 倒序
        $idx = array();
        foreach ($entries as $i => $e) {
            $t = isset($e['effectiveDateTime']) ? strtotime($e['effectiveDateTime']) : 0;
            $idx[] = array('i' => $i, 't' => $t ? $t : 0);
        }
        usort($idx, function ($a, $b) { return $b['t'] - $a['t']; });
        $sorted = array();
        $sortedRefs = array();
        foreach ($idx as $it) { $sorted[] = $entries[$it['i']]; $sortedRefs[] = $refs[$it['i']]; }
        list($count, $offset) = self::paging($params);
        return array(
            'total' => self::countAll($params),   // 真实匹配总数（检验+体征）
            'entries' => array_slice($sorted, $offset, $count),
            'patientRefs' => array_slice($sortedRefs, $offset, $count),
        );
    }

    /** 无 category 时检验 + 体征的真实匹配总数（Bundle.total 语义） */
    private static function countAll($params) {
        $wl = array("type='lab'"); $al = array();
        self::patientFilter($wl, $al, $params); self::dateFilter($wl, $al, $params);
        $labs = (int)PatientRepository::val('SELECT COUNT(*) FROM results WHERE ' . implode(' AND ', $wl), $al);
        $wv = array('1=1'); $av = array();
        self::patientFilter($wv, $av, $params); self::dateFilter($wv, $av, $params);
        $vitals = (int)PatientRepository::val('SELECT COUNT(*) FROM vitals WHERE ' . implode(' AND ', $wv), $av);
        return $labs + $vitals;
    }

    private static function patientFilter(&$where, &$args, $params) {
        $pno = isset($params['patient']) ? (string)$params['patient'] : (isset($params['subject']) ? (string)$params['subject'] : '');
        $pno = trim($pno);
        if ($pno !== '') {
            if (strpos($pno, 'Patient/') === 0) $pno = substr($pno, 8);
            if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
            $where[] = 'patient_no=?';
            $args[] = $pno;
        }
    }

    private static function dateFilter(&$where, &$args, $params) {
        if (isset($params['date']) && trim((string)$params['date']) !== '') {
            $d = trim((string)$params['date']);
            if (strpos($d, ',') !== false) $d = explode(',', $d);
            else $d = array($d);
            foreach ($d as $one) {
                $one = trim($one);
                if ($one === '') continue;
                if (strlen($one) === 10) { $where[] = 'date(created_at)=?'; $args[] = $one; }
                elseif (strlen($one) === 7) { $where[] = 'substr(created_at,1,7)=?'; $args[] = $one; }
            }
        }
    }

    private static function searchLabs($params) {
        $where = array("type='lab'");
        $args = array();
        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'observation-') === 0) $v = substr($v, 12);
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }
        self::patientFilter($where, $args, $params);
        self::dateFilter($where, $args, $params);
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM results ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'id', 'date' => 'created_at'), 'id DESC');
        $rows = PatientRepository::q('SELECT * FROM results ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset, $args);
        $entries = array();
        $patientRefs = array();
        foreach ($rows as $r) {
            $entries[] = self::labResource($r);
            $patientRefs[] = (string)$r['patient_no'];
        }
        return array('total' => $total, 'entries' => $entries, 'patientRefs' => $patientRefs);
    }

    private static function searchVitals($params) {
        $where = array('1=1');
        $args = array();
        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'observation-vital-') === 0) $v = substr($v, 18);   // 仅剥体征前缀，避免与检验 id 混淆
                if (ctype_digit($v)) $ids[] = (int)$v;
            }
            if ($ids) { $where[] = 'id IN (' . in_placeholders($ids) . ')'; $args = array_merge($args, $ids); }
        }
        self::patientFilter($where, $args, $params);
        self::dateFilter($where, $args, $params);
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM vitals ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'id', 'date' => 'created_at'), 'id DESC');
        $rows = PatientRepository::q('SELECT * FROM vitals ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset, $args);
        $entries = array();
        $patientRefs = array();
        foreach ($rows as $r) {
            $entries[] = self::vitalResource($r);
            $patientRefs[] = (string)$r['patient_no'];
        }
        return array('total' => $total, 'entries' => $entries, 'patientRefs' => $patientRefs);
    }

    public static function searchParams() {
        return array(
            array('name' => '_id', 'type' => 'token'),
            array('name' => 'patient', 'type' => 'reference'),
            array('name' => 'subject', 'type' => 'reference'),
            array('name' => 'category', 'type' => 'token'),
            array('name' => 'date', 'type' => 'date'),
        );
    }
}
