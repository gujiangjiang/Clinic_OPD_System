<?php
/**
 * ============================================================
 * services/his/HisInboundRead.php — HIS 入向只读查询服务
 * ============================================================
 * 说明：供外部 HIS/医保/BI 调用的只读查询端点业务逻辑
 * （原 app/api/his.php 内联逻辑下沉至此，统一由 /api/external/his/read 路由调用）。
 * ============================================================ */
class HisInboundRead {

    /** 连通性自检（接口管理页测试按钮使用，无需业务参数） */
    public static function ping() {
        // 医疗机构代码（org_code，安装/系统设置配置的医保结算/监管报送唯一标识）
        // 随自检返回，供外部系统（HIS/医保/BI）联调确认机构归属
        return array(
            'pong' => true,
            'system' => 'Clinic OPD System',
            'system_code' => (string)integration_cfg('outbound.his.hospital_code', '', 'his_system_code'),
            'org_code' => (string)setting('org_code', ''),
            'server_time' => now_iso_utc(),   // 对外接口契约：标准 UTC（ISO 8601 带 Z）
        );
    }

    /** 患者档案查询（按身份证 / 患者ID） */
    public static function patientGet($idCard, $patientNo) {
        $idCard = strtoupper(trim((string)$idCard));
        $patientNo = trim((string)$patientNo);
        if ($idCard === '' && $patientNo === '') {
            json_fail('请提供 id_card 或 patient_no 参数');
        }
        $p = $idCard !== ''
            ? PatientRepository::one('SELECT * FROM patients WHERE id_card=?', array($idCard))
            : PatientRepository::one('SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$p) json_fail('未检索到患者');
        unset($p['id']);
        return array('patient' => self::utcRow($p));
    }

    /** 就诊记录列表（按患者ID） */
    public static function visitList($patientNo) {
        $patientNo = trim((string)$patientNo);
        if ($patientNo === '') json_fail('请提供 patient_no 参数');
        $visits = PatientRepository::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
        $out = array();
        foreach ($visits as $v) {
            unset($v['id']);
            $out[] = self::utcRow($v);
        }
        return array('visits' => $out);
    }

    /** 就诊状态查询（按门诊流水号） */
    public static function visitStatus($flowNo) {
        $flowNo = trim((string)$flowNo);
        if ($flowNo === '') json_fail('请提供 flow_no 参数');
        $v = PatientRepository::one('SELECT * FROM registrations WHERE flow_no=?', array($flowNo));
        if (!$v) json_fail('未检索到该就诊记录');
        $p = PatientRepository::one('SELECT name, gender, age FROM patients WHERE patient_no=?', array($v['patient_no']));
        return array(
            'flow_no' => $v['flow_no'],
            'patient_no' => $v['patient_no'],
            'patient_name' => $p ? $p['name'] : '',
            'first_dept' => $v['first_dept_name'],
            'current_dept' => $v['current_dept_name'],
            'visit_seq' => (int)$v['visit_seq'],
            'status' => $v['status'],
            'status_name' => visit_status_name($v['status']),
            'registered_at' => iso_utc($v['registered_at']),
        );
    }

    /** 开单明细（按就诊ID） */
    public static function orderList($visitId) {
        $visitId = (int)$visitId;
        if ($visitId <= 0) json_fail('请提供 visit_id 参数');
        $orders = PatientRepository::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($visitId));
        // 开单类型中文名统一走 order_type_name()（helpers.d/visit.php）
        $out = array();
        foreach ($orders as $o) {
            $items = PatientRepository::q('SELECT item_name, price, quantity, single_dose, frequency, route, is_nurse, status FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
            $out[] = array(
                'order_no' => $o['order_no'],
                'order_type' => $o['order_type'],
                'order_type_name' => order_type_name($o['order_type']),
                'doctor_name' => $o['doctor_name'],
                'total_amount' => (float)$o['total_amount'],
                'status' => $o['status'],
                'created_at' => iso_utc($o['created_at']),
                'paid_at' => iso_utc($o['paid_at']),
                'items' => $items,
            );
        }
        return array('orders' => $out);
    }

    /** 项目字典（供 HIS 直接取用：检验/检查/药品/处置，仅审核通过项；检验组合含成员） */
    public static function catalogList($type) {
        $type = trim((string)$type);
        if ($type === 'lab_items') {
            $rows = PatientRepository::q(
                "SELECT id, category, name, unit, price, normal_range, critical_low, critical_high, description, is_group
                 FROM lab_items WHERE status='approved' ORDER BY category, id");
            $members = array();
            foreach (PatientRepository::q("SELECT group_id, item_id FROM lab_group_members") as $m) {
                $members[(int)$m['group_id']][] = (int)$m['item_id'];
            }
            $byId = array();
            foreach ($rows as $r) {
                $byId[(int)$r['id']] = array(
                    'name' => $r['name'],
                    'unit' => (string)$r['unit'],
                    'normal_range' => (string)$r['normal_range'],
                );
            }
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['price'] = (float)$r['price'];
                $r['is_group'] = (int)$r['is_group'];
                $r['members'] = array();
                if ($r['is_group'] === 1 && isset($members[$r['id']])) {
                    foreach ($members[$r['id']] as $mid) {
                        $r['members'][] = array(
                            'id' => $mid,
                            'name' => isset($byId[$mid]) ? $byId[$mid]['name'] : '',
                            'unit' => isset($byId[$mid]) ? $byId[$mid]['unit'] : '',
                            'normal_range' => isset($byId[$mid]) ? $byId[$mid]['normal_range'] : '',
                        );
                    }
                }
            }
            unset($r);
            return array('type' => 'lab_items', 'count' => count($rows), 'list' => $rows);
        }
        if ($type === 'exam_items') {
            $rows = PatientRepository::q("SELECT id, category, name, price, description FROM exam_items WHERE status='approved' ORDER BY category, id");
        } elseif ($type === 'drugs') {
            $rows = PatientRepository::q("SELECT id, name, generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency, route, price FROM drugs WHERE status='approved' ORDER BY id");
        } elseif ($type === 'disposal_items') {
            $rows = PatientRepository::q("SELECT id, name, price, description FROM disposal_items WHERE status='approved' ORDER BY id");
        } else {
            json_fail('未知字典类型（可用：lab_items / exam_items / drugs / disposal_items）');
        }
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            if (isset($r['price'])) $r['price'] = (float)$r['price'];
        }
        unset($r);
        return array('type' => $type, 'count' => count($rows), 'list' => $rows);
    }

    /** ICD-10 检索（标准编码库，供 HIS 诊断模块动态取用；支持 offset 分页） */
    public static function icdSearch($kw, $limit, $offset = 0) {
        $kw = trim((string)$kw);
        $limit = max(1, min(50, (int)$limit ? (int)$limit : 20));
        $offset = max(0, (int)$offset);
        if ($kw === '') return array('list' => array(), 'total' => 0);
        list($rows, $total) = Icd10Repository::search($kw, $limit, $offset);
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('code' => $r['diagnosis_code'], 'name' => $r['diagnosis_name']);
        }
        return array('list' => $out, 'total' => (int)$total);
    }

    /** 存证校验（按记录ID/证明号核验指纹与凭据，外部机构验真用） */
    public static function evidenceVerify($recId, $certNo) {
        $recId = (int)$recId;
        $certNo = trim((string)$certNo);
        if ($recId > 0) {
            $r = EmrRepository::one(
                "SELECT id AS rid, visit_id, patient_no, flow_no, record_type, evid_hash, evid_algo, evid_token, evid_signer, evid_time, created_at
                 FROM patient_records WHERE id=?", array($recId));
            if (!$r) json_fail('未检索到该病历存证记录');
            return array(
                'type' => 'record', 'record_id' => $recId, 'patient_no' => $r['patient_no'], 'flow_no' => $r['flow_no'],
                'record_type' => $r['record_type'], 'evid_hash' => $r['evid_hash'], 'evid_algo' => $r['evid_algo'],
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => iso_utc($r['evid_time']),
                'created_at' => iso_utc($r['created_at']),
            );
        }
        if ($certNo !== '') {
            $r = EmrRepository::one(
                "SELECT id, visit_id, patient_no, flow_no, cert_no, content, evid_hash, evid_algo, evid_token, evid_signer, evid_time, created_at
                 FROM certificates WHERE cert_no=?", array($certNo));
            if (!$r) json_fail('未检索到该证明的存证记录');
            return array(
                'type' => 'certificate', 'cert_no' => $certNo, 'patient_no' => $r['patient_no'], 'flow_no' => $r['flow_no'],
                'content' => $r['content'], 'evid_hash' => $r['evid_hash'], 'evid_algo' => $r['evid_algo'],
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => iso_utc($r['evid_time']),
                'created_at' => iso_utc($r['created_at']),
            );
        }
        json_fail('请提供 record_id 或 cert_no 参数');
    }

    /**
     * 对外接口单点（整行输出）：存储时间列（*_at）统一输出标准 UTC（ISO 8601 带 Z）；
     * 业务日期列（*_date / reg_date）不转换（业务日期直通）；
     * 空值经 iso_utc 原样返回。
     */
    private static function utcRow($row) {
        foreach ($row as $k => $v) {
            if (preg_match('/_at$/', $k)) $row[$k] = iso_utc($v);
        }
        return $row;
    }
}
