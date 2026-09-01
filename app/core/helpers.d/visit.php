<?php
/**
 * ============================================================
 * helpers.d/visit.php — 就诊记录 / 患者档案 / 状态名
 * ============================================================
 * 说明：就诊记录联查、诊断证明快照回退、患者就诊搜索、挂号/开单
 * 明细/订单聚合状态中文名。由 helpers.php 统一加载，拆分后引用
 * 方式不变。
 * ============================================================ */

/* ============================================================
 * 业务辅助：就诊记录/患者档案联查、状态中文名
 * ============================================================ */

/** 按就诊ID联查 挂号记录 + 患者档案 */
function get_visit_row($visitId) {
    $v = DB::one('SELECT * FROM registrations WHERE id=?', array((int)$visitId));
    if (!$v) {
        return null;
    }
    $p = DB::one('SELECT * FROM patients WHERE patient_no=?', array($v['patient_no']));
    return array('visit' => $v, 'patient' => $p);
}

/**
 * 诊断证明固化快照回退：证书存有开具时的病历摘要则原样使用——
 * 补打/展示内容与开具时完全一致，不随后续续写漂移。
 * @param array|null $record 病历数据（可为 null）
 * @param array      $cert   诊断证明行
 * @return array 合并快照后的病历数据
 */
function cert_fallback_snapshot($record, $cert) {
    $record = is_array($record) ? $record : array();
    if ((isset($cert['chief_complaint']) && $cert['chief_complaint'] !== '') ||
        (isset($cert['present_illness']) && $cert['present_illness'] !== '') ||
        (isset($cert['preliminary_diagnosis']) && $cert['preliminary_diagnosis'] !== '')) {
        $record['chief_complaint'] = $cert['chief_complaint'];
        $record['present_illness'] = $cert['present_illness'];
        $record['preliminary_diagnosis'] = $cert['preliminary_diagnosis'];
    }
    return $record;
}

/**
 * 患者就诊搜索（统一规则，收费处/护士站共用）：
 * 1. 按身份证/患者编号查患者 → 该患者全部就诊；
 * 2. 未命中则按患者编号/流水号直接查单条就诊。
 * @return array [ ['visit'=>行,'patient'=>行], ... ]（visit.id 已混淆）
 */
function search_visit_records($kw) {
    $kw = trim((string)$kw);
    if ($kw === '') return array();
    $list = array();
    $patient = PatientRepository::byCardOrNo($kw);
    if ($patient) {
        $visits = CashierRepository::visitsOfPatient($patient['patient_no']);
        foreach ($visits as $v) {
            $v['id'] = oid($v['id']);
            $list[] = array('visit' => $v, 'patient' => $patient);
        }
    } else {
        $v = CashierRepository::one('SELECT * FROM registrations WHERE patient_no=? OR flow_no=? ORDER BY registered_at DESC, id DESC LIMIT 1', array($kw, $kw));
        if ($v) {
            $v['id'] = oid($v['id']);
            $list[] = array('visit' => $v, 'patient' => PatientRepository::byPatientNo($v['patient_no']));
        }
    }
    return $list;
}

/** 挂号状态中文名 */
function visit_status_name($s) {
    $map = array(
        'pending'   => '待缴费',
        'paid'      => '待就诊',
        'visiting'  => '就诊中',
        'finished'  => '就诊完毕',
        'refunded'  => '已退费',
        'cancelled' => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 开单明细流程状态中文名 */
function item_status_name($s) {
    $map = array(
        'open'       => '待缴费',
        'paid'       => '已缴费',
        'registered' => '已登记',
        'executing'  => '执行中',
        'done'       => '已完成',
        'dispensing' => '发药中',
        'dispensed'  => '已发药',
        'refunded'   => '已退费',
        'cancelled'  => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 计算订单聚合状态（open/paid/registered/in_progress/done/dispensed/refunded/cancelled） */
function order_agg_status($orderType, $items) {
    $sts = array();
    foreach ($items as $it) $sts[] = $it['status'];
    if (!$sts) return 'open';
    if (count(array_unique($sts)) === 1) {
        $only = $sts[0];
        if ($only === 'refunded') return 'refunded';
        if ($only === 'cancelled') return 'cancelled';
        if ($only === 'dispensed') return 'dispensed';
        if ($only === 'done') return 'done';
    }
    if (in_array('open', $sts, true)) return 'open';
    if (in_array('paid', $sts, true)) return 'paid';
    if ($orderType === 'prescription') {
        if (in_array('dispensed', $sts, true)) return 'dispensed';
        return 'paid';
    }
    if (in_array('executing', $sts, true)) return 'in_progress';
    if (in_array('registered', $sts, true)) return 'registered';
    if (in_array('done', $sts, true)) return 'done';
    return 'open';
}