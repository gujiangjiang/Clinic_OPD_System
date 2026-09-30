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
            'server_time' => now_str(),
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
        return array('patient' => $p);
    }

    /** 就诊记录列表（按患者ID） */
    public static function visitList($patientNo) {
        $patientNo = trim((string)$patientNo);
        if ($patientNo === '') json_fail('请提供 patient_no 参数');
        $visits = PatientRepository::q('SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
        $out = array();
        foreach ($visits as $v) {
            unset($v['id']);
            $out[] = $v;
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
            'registered_at' => $v['registered_at'],
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
                'created_at' => $o['created_at'],
                'paid_at' => $o['paid_at'],
                'items' => $items,
            );
        }
        return array('orders' => $out);
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
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => $r['evid_time'],
                'created_at' => $r['created_at'],
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
                'evid_token' => $r['evid_token'], 'evid_signer' => $r['evid_signer'], 'evid_time' => $r['evid_time'],
                'created_at' => $r['created_at'],
            );
        }
        json_fail('请提供 record_id 或 cert_no 参数');
    }
}