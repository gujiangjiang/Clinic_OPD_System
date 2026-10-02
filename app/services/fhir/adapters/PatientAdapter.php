<?php
/**
 * ============================================================
 * services/fhir/adapters/PatientAdapter.php — Patient 资源适配器
 * ============================================================
 * 映射 patients 表；搜索参数：_id、identifier、name、gender、birthdate。
 * 标识符规范：OP 门诊号（patient_no）、NI 身份证号（id_card）。
 * ============================================================ */
class PatientAdapter extends FhirAdapter {

    public static function resourceType() { return 'Patient'; }

    /** 构建资源 id：patient-{patient_no} */
    public static function resourceId($patientNo) {
        return 'patient-' . (string)$patientNo;
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        return PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($bareId));
    }

    public static function toResource($row) {
        $p = is_array($row) ? $row : array();
        $pno = isset($p['patient_no']) ? (string)$p['patient_no'] : '';
        $res = array(
            'resourceType' => 'Patient',
            'id' => self::resourceId($pno),
            'identifier' => array(
                // 门诊号：本地 system 标识来源；type 使用 v2-0203 有效码 MR（此前误用不存在的 OP）
                self::identifier('urn:clinic:identifier:op', $pno, 'MR', 'Medical Record Number'),
            ),
        );
        if (!empty($p['id_card'])) {
            // 中国居民身份证号：使用本地 system（原误用美国 SSN 的 OID）
            $res['identifier'][] = self::identifier('urn:clinic:identifier:idcard', (string)$p['id_card'], 'NI', 'National Identifier');
        }
        $name = isset($p['name']) ? trim((string)$p['name']) : '';
        if ($name !== '') {
            // HumanName：中文姓名无法可靠拆分 family/given 时，仅提供 text（完整姓名），
            // 不再把整名塞进 family（语义错位）
            $res['name'] = array(array('use' => 'official', 'text' => $name));
        }
        if (isset($p['gender']) && $p['gender'] !== '') {
            $res['gender'] = self::gender($p['gender']);
        }
        $birth = self::isoDate(isset($p['birth_date']) ? $p['birth_date'] : '');
        if ($birth !== null) $res['birthDate'] = $birth;
        if (!empty($p['phone'])) {
            $res['telecom'] = array(array('system' => 'phone', 'value' => (string)$p['phone']));
        }
        if (!empty($p['address'])) {
            $res['address'] = array(array('text' => (string)$p['address']));
        }
        return $res;
    }

    public static function search($params) {
        $where = array();
        $args = array();

        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ids = array();
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === '') continue;
                if (strpos($v, 'patient-') === 0) $v = substr($v, 8);
                $ids[] = $v;
            }
            if ($ids) {
                $where[] = 'patient_no IN (' . in_placeholders($ids) . ')';
                $args = array_merge($args, $ids);
            }
        }
        if (isset($params['identifier']) && trim((string)$params['identifier']) !== '') {
            $idv = trim((string)$params['identifier']);
            // 兼容 system|value 与裸值两种写法
            if (strpos($idv, '|') !== false) {
                list($sys, $idv) = explode('|', $idv, 2);
                $sys = trim($sys);
            }
            $where[] = '(patient_no=? OR id_card=?)';
            $args[] = $idv;
            $args[] = $idv;
        }
        if (isset($params['name']) && trim((string)$params['name']) !== '') {
            $where[] = 'name LIKE ?';
            $args[] = '%' . trim((string)$params['name']) . '%';
        }
        if (isset($params['gender']) && trim((string)$params['gender']) !== '') {
            $g = self::gender($params['gender']);
            $zh = ($g === 'male') ? array('男', 'male', 'm') : (($g === 'female') ? array('女', 'female', 'f') : array('other', 'unknown'));
            $where[] = 'gender IN (' . in_placeholders($zh) . ')';
            $args = array_merge($args, $zh);
        }
        if (isset($params['birthdate']) && trim((string)$params['birthdate']) !== '') {
            $bd = trim((string)$params['birthdate']);
            if (strlen($bd) === 4) {
                $where[] = "substr(birth_date,1,4)=?";
                $args[] = $bd;
            } else {
                $where[] = 'birth_date=?';
                $args[] = $bd;
            }
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $total = (int)PatientRepository::val('SELECT COUNT(*) FROM patients ' . $sqlWhere, $args);
        list($count, $offset) = self::paging($params);
        $order = self::sortClause($params, array('_lastUpdated' => 'id', 'birthdate' => 'birth_date', 'name' => 'name'), 'id DESC');
        $rows = PatientRepository::q(
            'SELECT * FROM patients ' . $sqlWhere . ' ORDER BY ' . $order . ' LIMIT ' . (int)$count . ' OFFSET ' . (int)$offset,
            $args
        );
        $entries = array();
        $patientRefs = array();
        foreach ($rows as $r) {
            $entries[] = self::toResource($r);
            $patientRefs[] = (string)$r['patient_no'];
        }
        return array('total' => $total, 'entries' => $entries, 'patientRefs' => $patientRefs);
    }

    public static function searchParams() {
        return array(
            array('name' => '_id', 'type' => 'token'),
            array('name' => 'identifier', 'type' => 'token'),
            array('name' => 'name', 'type' => 'string'),
            array('name' => 'gender', 'type' => 'token'),
            array('name' => 'birthdate', 'type' => 'date'),
        );
    }
}
