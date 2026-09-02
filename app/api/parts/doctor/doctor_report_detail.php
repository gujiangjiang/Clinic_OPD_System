<?php
/**
 * ============================================================
 * parts/doctor/doctor_report_detail.php — 报告详情
 * ============================================================ */

function doctor_read_report_detail($u) {
    $rid = did(get('report_id'));
    $report = EmrRepository::one('SELECT * FROM reports WHERE id=?', array($rid));
    if (!$report) json_fail('报告不存在');
    $result = EmrRepository::one('SELECT * FROM results WHERE id=?', array($report['result_id']));
    $itemName = '';
    $rows = array();
    $findings = '';
    $conclusion = '';
    if ($result && $result['type'] === 'lab') {
        $li = EmrRepository::one('SELECT * FROM lab_items WHERE id=?', array($result['item_id']));
        $itemName = $li ? $li['name'] : '';
        $values = json_decode($result['values_json'], true);
        if (is_array($values) && !empty($values['group'])) {
            $members = EmrRepository::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$result['item_id']));
            foreach ($members as $m) {
                $v = isset($values['values'][(string)$m['id']]) ? $values['values'][(string)$m['id']] : '';
                $rows[] = array(
                    'name' => $m['name'], 'value' => $v, 'unit' => $m['unit'],
                    'range' => $m['normal_range'],
                    'critical' => trim(($m['critical_low'] !== '' ? '低' . $m['critical_low'] : '') .
                        ($m['critical_high'] !== '' ? ' 高' . $m['critical_high'] : '')),
                );
            }
        } else {
            $rows[] = array(
                'name' => $itemName, 'value' => is_array($values) && isset($values['value']) ? $values['value'] : '',
                'unit' => $li ? $li['unit'] : '',
                'range' => $li ? $li['normal_range'] : '',
                'critical' => $li ? trim(($li['critical_low'] !== '' ? '低' . $li['critical_low'] : '') .
                    ($li['critical_high'] !== '' ? ' 高' . $li['critical_high'] : '')) : '',
            );
        }
    }
    if ($result && $result['type'] === 'imaging') {
        $ei = EmrRepository::one('SELECT * FROM exam_items WHERE id=?', array($result['item_id']));
        $itemName = $ei ? $ei['name'] : '';
        $findings = (string)$result['findings'];
        $conclusion = (string)$result['conclusion'];
    }
    json_ok(array(
        'type' => $result ? $result['type'] : 'lab',
        'item_name' => $itemName,
        'rows' => $rows,
        'findings' => $findings,
        'conclusion' => $conclusion,
        'executor' => $report['doctor'],
        'time' => $report['created_at'],
    ));
    return;
}