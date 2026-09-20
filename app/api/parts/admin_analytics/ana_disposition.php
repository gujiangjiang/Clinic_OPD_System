<?php
/**
 * ============================================================
 * parts/admin_analytics/ana_disposition.php — 转归查询
 * ============================================================
 * 支持分页（page/size/kw/type 过滤），滚动加载，避免一次性
 * 返回最近全部诊毕记录导致请求过大。
 * ============================================================ */

function admin_ana_disposition() {
    $type = trim((string)get('type', '全部'));
    $page = max(1, (int)get('page', 1));
    $pageSize = max(1, min(100, (int)get('size', 20)));
    $kw = trim(get('kw', ''));
    $where = "r.status='finished' AND r.disposition<>''";
    $params = array();
    if ($type !== '' && $type !== '全部') {
        $where .= ' AND r.disposition=?';
        $params[] = $type;
    }
    if ($kw !== '') {
        $where .= ' AND (p.name LIKE ? OR r.flow_no LIKE ? OR p.id_card LIKE ?)';
        $like = '%' . $kw . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int)AnalyticsRepository::val(
        "SELECT COUNT(*) FROM registrations r JOIN patients p ON p.patient_no=r.patient_no WHERE " . $where,
        $params
    );
    $sql = 'SELECT r.id AS visit_id, r.registered_at, r.flow_no, r.disposition, r.disposition_detail, ' .
        'COALESCE(NULLIF(r.current_dept_name, \'\'), r.first_dept_name) AS dept_name, ' .
        'p.name AS pname, p.gender, p.birth_date, p.id_card ' .
        'FROM registrations r JOIN patients p ON p.patient_no=r.patient_no ' .
        'WHERE ' . $where . ' ORDER BY r.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
    $rows = array();
    $vids = array();
    foreach (AnalyticsRepository::q($sql, $params) as $r) {
        $r['doctor_name'] = '';
        $vids[] = (int)$r['visit_id'];
        $rows[] = $r;
    }
    if ($vids) {
        $ph = in_placeholders($vids);
        $docMap = array();
        foreach (AnalyticsRepository::q("SELECT visit_id, doctor_name FROM patient_records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
            if (!isset($docMap[(int)$pr['visit_id']])) $docMap[(int)$pr['visit_id']] = (string)$pr['doctor_name'];
        }
        foreach ($rows as &$r) {
            $vid = (int)$r['visit_id'];
            if (!isset($docMap[$vid])) {
                foreach (AnalyticsRepository::q("SELECT visit_id, doctor_name FROM records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
                    if ((int)$pr['visit_id'] === $vid) { $docMap[$vid] = (string)$pr['doctor_name']; break; }
                }
            }
            $r['doctor_name'] = isset($docMap[$vid]) ? $docMap[$vid] : '';
        }
        unset($r);
    }
    $rowsOut = array();
    foreach ($rows as $r) {
        $rowsOut[] = array(
            'registered_at' => (string)$r['registered_at'],
            'flow_no' => (string)$r['flow_no'],
            'dept_name' => (string)$r['dept_name'],
            'doctor_name' => (string)$r['doctor_name'],
            'disposition' => (string)$r['disposition'],
            'disposition_detail' => (string)$r['disposition_detail'],
            'pname' => (string)$r['pname'],
            'gender' => (string)$r['gender'],
            'id_card' => (string)(isset($r['id_card']) ? $r['id_card'] : ''),
            'age_fmt' => age_format($r['birth_date'], $r['registered_at']),
        );
    }
    // 动态列：非「全部/自主离院」类型时追加补充信息列
    $needDetail = ($type !== '' && $type !== '全部' && $type !== '自主离院');
    $detailHead = $needDetail
        ? (isset($type) && in_array($type, array('住院', '转院', '死亡', '其他'), true)
            ? array('住院' => '住院病区', '转院' => '接收医院', '死亡' => '死亡原因', '其他' => '其他转归情况')[$type]
            : '补充信息')
        : '';
    $thead = '<thead><tr><th>就诊时间</th><th>患者</th><th>门诊号</th><th>科室</th><th>医生</th><th>离院方式</th>' .
        ($needDetail ? '<th>' . $detailHead . '</th>' : '') . '</tr></thead>';
    json_ok(array(
        'list' => $rowsOut,
        'total' => $total,
        'has_more' => ($page * $pageSize) < $total,
        'thead' => $thead,
    ));
}