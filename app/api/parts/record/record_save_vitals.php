<?php
/**
 * ============================================================
 * parts/record/record_save_vitals.php — 生命体征录入
 * ============================================================
 * record_write.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function record_part_save_vitals($u) {
    $visitId = did(post('visit_id'));
    $row = get_visit_row($visitId);
    if (!$row) json_fail('就诊记录不存在');
    // 归档锁定：已诊毕(归档)不可再录入生命体征
    if ($row['visit']['status'] === 'finished') {
        json_fail('该患者已诊毕，病历已归档，不可再录入生命体征');
    }
    // 病历可访问天数校验
    if (!visit_access_allowed($row['visit'], $u)) {
        json_fail('该病历超出您的可查看历史天数，无法修改');
    }
    // 数值校验（与服务端同规则）：非负整数、生理合理区间；留空视为未测
    $spec = array(
        'vital_sbp'  => array(post('vital_sbp', 0), 1, 300, '收缩压'),
        'vital_dbp' => array(post('vital_dbp', 0), 1, 250, '舒张压'),
        'vital_heart_rate'   => array(post('vital_heart_rate', ''), 1, 300, '心率'),
        'vital_pulse'        => array(post('vital_pulse', ''), 1, 300, '脉搏'),
        'vital_spo2'         => array(post('vital_spo2', ''), 1, 100, '血氧饱和度'),
        'vital_respiration'  => array(post('vital_respiration', ''), 1, 100, '呼吸'),
    );
    $clean = array();
    foreach ($spec as $k => $c) {
        $raw = trim((string)$c[0]);
        if ($raw === '') { $clean[$k] = ($k === 'vital_sbp' || $k === 'vital_dbp') ? 0 : ''; continue; }
        if (!preg_match('/^\d+$/', $raw)) json_fail($c[3] . '须为非负整数（不留小数 / 负数 / 单位）');
        $n = (int)$raw;
        if ($n !== 0 && ($n < $c[1] || $n > $c[2])) json_fail($c[3] . '超出合理范围（' . $c[1] . '-' . $c[2] . '）');
        $clean[$k] = ($k === 'vital_sbp' || $k === 'vital_dbp') ? $n : (string)$n;
    }
    $recordId = (int)post('record_id', 0);
    // 记录关联：同一病历已有体征条目 → 修改（纠错不产生新记录）；
    // 无 → 新增一条（新病历首次录入 / 护士站录入）
    $existV = ($recordId > 0)
        ? EmrRepository::one('SELECT id FROM vitals WHERE visit_id=? AND record_id=? LIMIT 1', array($visitId, $recordId))
        : null;
    $now = now_str();
    if ($existV) {
        EmrRepository::exec('UPDATE vitals SET vital_sbp=?, vital_dbp=?, vital_heart_rate=?, vital_pulse=?, vital_spo2=?, vital_respiration=?, operator=?, created_at=? WHERE id=?', array(
            $clean['vital_sbp'], $clean['vital_dbp'],
            $clean['vital_heart_rate'], $clean['vital_pulse'], $clean['vital_spo2'], $clean['vital_respiration'],
            $u['name'], $now, $existV['id'],
        ));
    } else {
        EmrRepository::insert('INSERT INTO vitals(visit_id, patient_no, flow_no, vital_sbp, vital_dbp, vital_heart_rate, vital_pulse, vital_spo2, vital_respiration, operator, created_at, record_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', array(
            $visitId, $row['visit']['patient_no'], $row['visit']['flow_no'],
            $clean['vital_sbp'], $clean['vital_dbp'],
            $clean['vital_heart_rate'], $clean['vital_pulse'], $clean['vital_spo2'], $clean['vital_respiration'],
            $u['name'], $now, $recordId,
    ));
    }
    json_ok(array(), '生命体征已保存');
    return;
}