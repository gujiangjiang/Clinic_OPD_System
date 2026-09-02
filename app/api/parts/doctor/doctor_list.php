<?php
/**
 * ============================================================
 * parts/doctor/doctor_list.php — 医生候诊/就诊/完毕列表
 * ============================================================
 * doctor_read.php 拆分出的独立动作，逻辑与原文件逐字一致。
 * ============================================================ */

function doctor_read_list($u) {
    $status = get('status', 'waiting');
    $deptId = (int)get('dept_id', 0);
    if ($status === 'waiting') {
        $where = "r.status='paid'";
    } elseif ($status === 'visiting') {
        $where = "r.status='visiting'";
    } else {
        $where = "r.status='finished' AND date(r.registered_at)=?";
    }
    $where .= ' AND r.current_dept_id=' . $deptId;
    $params = array();
    if ($status === 'done') $params[] = today_str();
    $rows = EmrRepository::q("SELECT r.*, p.name AS pname, p.gender AS pgender, p.age AS page, p.birth_date AS pbirth
        FROM registrations r LEFT JOIN patients p ON p.patient_no = r.patient_no
        WHERE $where ORDER BY r.visit_seq", $params);

    $html = '';
    if (!$rows) {
        $html = '<div class="empty"><div class="empty-ico">📭</div>' . ($status === 'waiting' ? '暂无候诊患者' : ($status === 'visiting' ? '暂无就诊中患者' : '今日暂无就诊完毕患者')) . '</div>';
    }
    foreach ($rows as $r) {
        $html .= '<div class="card" style="margin-bottom:10px;padding:14px 16px">';
        $html .= '<div class="flex-between">';
        $html .= '<div class="flex gap-12" style="align-items:center;min-width:0">' .
            '<div style="width:42px;height:42px;border-radius:50%;background:var(--primary-soft);color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0">' .
            str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '</div>';
        $html .= '<div style="min-width:0">' .
            '<div class="fs-16 fw-700">' . e($r['pname']) .
            ' <span class="fs-13 text-muted fw-400">' . e($r['pgender']) . ' / ' . age_format($r['pbirth'], $r['registered_at']) . '</span>' .
            ($r['is_extra'] ? ' <span class="badge badge-warning" style="font-size:11px">加号</span>' : '') .
            '</div>' .
            '<div class="fs-12 text-muted">' . e($r['first_dept_name']) . ' 第' . str_pad((string)$r['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ 患者ID ' . e($r['patient_no']) . ' ｜ 流水号 ' . e($r['flow_no']) . '</div>' .
            '<div class="fs-12 text-muted">挂号 ' . e(substr($r['registered_at'], 5, 11)) . ' ｜ 费用类别 ' . e($r['fee_type']) . '</div>' .
            '</div></div>';
        // 操作按钮
        $html .= '<div class="flex gap-8" style="flex-shrink:0">';
        if ($status === 'waiting') {
            $html .= '<button class="btn btn-primary btn-sm" onclick="takePatient(\'' . e(oid($r['id'])) . '\')">接诊</button>';
            $html .= '<button class="btn btn-outline btn-sm" onclick="showPatientHistory(' . e($r['patient_no']) . ')">历史</button>';
        } elseif ($status === 'visiting') {
            $html .= '<button class="btn btn-primary btn-sm" onclick="location.href=\'/doctor/emr?visit_id=' . e(oid($r['id'])) . '\'">继续就诊</button>';
            $html .= '<button class="btn btn-outline btn-sm" onclick="showPatientHistory(\'' . e($r['patient_no']) . '\')">历史</button>';
        } else {
            $html .= '<button class="btn btn-outline btn-sm" onclick="location.href=\'/doctor/emr?visit_id=' . e(oid($r['id'])) . '\'">查看病历</button>';
            $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' . e(oid($r['id'])) . '\',null,\'a5\')">打印病历</button>';
        }
        $html .= '</div></div></div>';
    }
    json_ok(array('html' => $html));
    return;
}