<?php
/**
 * ============================================================
 * parts/doctor/doctor_report_detail.php — 报告详情
 * ============================================================ */

function doctor_read_report_detail($u) {
    $rid = did(get('report_id'));
    $report = EmrRepository::one('SELECT * FROM reports WHERE id=?', array($rid));
    if (!$report) json_fail('报告不存在');
    // 科室数据隔离：医生仅可查看其就诊科室/本人接诊过的就诊报告
    // （已诊毕归档 visit_dept_authorized 直接放行，历史报告不受影响）
    $vRow = get_visit_row((int)$report['visit_id']);
    if (!$vRow || !visit_dept_authorized($vRow['visit'], $u)) {
        json_fail('无权限查看该报告');
    }
    $result = EmrRepository::one('SELECT * FROM results WHERE id=?', array($report['result_id']));
    $itemName = '';
    $rows = array();
    $findings = '';
    $conclusion = '';
    // 报告快照（法律合规）：出具时刻项目字典元数据优先，事后改名/改范围不影响历史报告查看
    $snapMeta = null;
    $snapRep = snapshot_get('report', (int)$report['id']);
    if ($snapRep && isset($snapRep['extra']['item_meta']) && is_array($snapRep['extra']['item_meta'])) {
        $snapMeta = $snapRep['extra']['item_meta'];
    }
    $snapItem = ($snapMeta && empty($snapMeta['group']) && isset($snapMeta['item']) && is_array($snapMeta['item'])) ? $snapMeta['item'] : null;
    $snapMembers = ($snapMeta && !empty($snapMeta['group']) && isset($snapMeta['members']) && is_array($snapMeta['members'])) ? $snapMeta['members'] : null;
    if ($result && $result['type'] === 'lab') {
        $li = EmrRepository::one('SELECT * FROM lab_items WHERE id=?', array($result['item_id']));
        $itemName = $snapItem && isset($snapItem['name']) ? $snapItem['name'] : ($li ? $li['name'] : '');
        $values = json_decode($result['values_json'], true);
        if (is_array($values) && !empty($values['group'])) {
            // 组内成员：快照优先（出具时刻定格）
            $members = $snapMembers;
            if (!$members) {
                $members = EmrRepository::q('SELECT * FROM lab_items WHERE parent_id=? AND is_group=0 ORDER BY id', array((int)$result['item_id']));
            }
            foreach ($members as $m) {
                $v = isset($values['values'][(string)$m['id']]) ? $values['values'][(string)$m['id']] : '';
                $rows[] = array(
                    'name' => $m['name'], 'value' => $v, 'unit' => isset($m['unit']) ? $m['unit'] : '',
                    'range' => isset($m['normal_range']) ? $m['normal_range'] : '',
                    'critical' => trim((isset($m['critical_low']) && $m['critical_low'] !== '' ? '低' . $m['critical_low'] : '') .
                        (isset($m['critical_high']) && $m['critical_high'] !== '' ? ' 高' . $m['critical_high'] : '')),
                );
            }
        } else {
            $rows[] = array(
                'name' => $itemName, 'value' => is_array($values) && isset($values['value']) ? $values['value'] : '',
                'unit' => $snapItem && isset($snapItem['unit']) ? $snapItem['unit'] : ($li ? $li['unit'] : ''),
                'range' => $snapItem && isset($snapItem['normal_range']) ? $snapItem['normal_range'] : ($li ? $li['normal_range'] : ''),
                'critical' => $snapItem
                    ? trim((isset($snapItem['critical_low']) && $snapItem['critical_low'] !== '' ? '低' . $snapItem['critical_low'] : '') .
                        (isset($snapItem['critical_high']) && $snapItem['critical_high'] !== '' ? ' 高' . $snapItem['critical_high'] : ''))
                    : ($li ? trim(($li['critical_low'] !== '' ? '低' . $li['critical_low'] : '') .
                        ($li['critical_high'] !== '' ? ' 高' . $li['critical_high'] : '')) : ''),
            );
        }
    }
    if ($result && $result['type'] === 'imaging') {
        $ei = EmrRepository::one('SELECT * FROM exam_items WHERE id=?', array($result['item_id']));
        $itemName = $snapItem && isset($snapItem['name']) ? $snapItem['name'] : ($ei ? $ei['name'] : '');
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