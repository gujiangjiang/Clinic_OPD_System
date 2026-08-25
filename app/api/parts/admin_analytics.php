<?php
/**
 * ============================================================
 * parts/admin_analytics.php v1.0.0 — 管理端：医院运营分析
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分。口径统一为【已缴费】：
 *   · 项目收入：orders（status NOT IN refunded/cancelled），按 paid_at 落账日归属；
 *     order_type 映射——prescription 药费 / lab 检验费 / imaging 检查费 / procedure 处置费
 *   · 挂号费：payments(kind='visit')，按 created_at 落账日归属；
 *   · 门诊人次：registrations（status IN paid/visiting/finished）按 payment_time 归属；
 *   · 医生接诊人次：medical.patient_records 按 created_at（接诊时间）归属，谁接诊算谁。
 * 动作：
 *   1. ana_overview  运营总览（KPI + 日趋势序列）
 *   2. ana_dept      科室维度统计（人次/挂号费/四类项目费/合计）
 *   3. ana_doctor    医生维度统计（接诊人次/分类型开单缴费收入）
 *   4. ana_custom    自定义统计（日/月/年 × 科室/医生 维度 + 指标选择）
 * ============================================================ */

/**
 * 处理运营分析动作
 * @param string $action 动作名
 */
function admin_part_analytics($action) {

    /** 校验并规范化日期范围（缺省=今天；start>end 自动交换）；
     *  用 req() 同时兼容 GET（前端 Clinic.get）与 POST 参数 */
    function ana_range() {
        $tz = new DateTimeZone(date_default_timezone_get());
        $end = req('end', date('Y-m-d'));
        $start = req('start', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
        if ($start > $end) { $t = $start; $start = $end; $end = $t; }
        // 防御性上限：最多跨 366 天（自定义趋势图可读性 & 性能）
        try {
            $ds = new DateTime($start, $tz); $de = new DateTime($end, $tz);
            if ((int)$ds->diff($de)->format('%a') > 366) {
                $start = $de->modify('-366 days')->format('Y-m-d');
            }
            return array($ds->format('Y-m-d'), $end);
        } catch (Exception $e) {
            return array(date('Y-m-d'), date('Y-m-d'));
        }
    }

    /** 项目费按类型汇总（SQL 片段复用）：返回 [type => SUM] */
    function ana_order_sums($start, $end, $extraWhere = '', $extraParams = array(), $groupExpr = '') {
        $sql = "SELECT order_type AS t" . ($groupExpr !== '' ? ',' . $groupExpr . ' AS g' : '') .
            ", COALESCE(SUM(total_amount),0) AS s FROM orders
              WHERE status NOT IN ('refunded','cancelled')
              AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?" .
              ($extraWhere !== '' ? ' AND ' . $extraWhere : '');
        if ($groupExpr !== '') $sql .= ' GROUP BY order_type' . ($groupExpr !== '' ? ',' . $groupExpr : '');
        else $sql .= ' GROUP BY order_type';
        $rows = DB::q('order', $sql, array_merge(array($start, $end), $extraParams));
        return $rows;
    }

    /* ==================== 运营总览 ==================== */
    if ($action === 'ana_overview') {
        list($start, $end) = ana_range();

        // KPI：门诊人次 + 挂号费
        $patients = (int)DB::val('patient', "SELECT COUNT(*) FROM registrations
            WHERE status IN ('paid','visiting','finished') AND payment_time IS NOT NULL AND date(payment_time) BETWEEN ? AND ?",
            array($start, $end));
        $regFee = (float)DB::val('order', "SELECT COALESCE(SUM(total),0) FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ?", array($start, $end));

        // 四类项目费 + 合计
        $sums = array('prescription' => 0, 'lab' => 0, 'imaging' => 0, 'procedure' => 0);
        foreach (ana_order_sums($start, $end) as $r) {
            if (isset($sums[$r['t']])) $sums[$r['t']] = (float)$r['s'];
        }
        $projTotal = array_sum($sums);

        json_ok(array(
            'range' => array('start' => $start, 'end' => $end),
            'kpi' => array(
                'patients' => $patients,
                'reg_fee' => round($regFee, 2),
                'drug' => round($sums['prescription'], 2),
                'lab' => round($sums['lab'], 2),
                'imaging' => round($sums['imaging'], 2),
                'procedure' => round($sums['procedure'], 2),
                'proj_total' => round($projTotal, 2),
                'total' => round($regFee + $projTotal, 2),
            ),
        ));
    }

    /* ==================== 总览趋势（日序列，供点线图） ==================== */
    if ($action === 'ana_trend') {
        list($start, $end) = ana_range();
        // 日期轴（含无数据日补零）
        $labels = array();
        $days = array();
        try {
            $d = new DateTime($start);
            $de = new DateTime($end);
            while ($d <= $de) {
                $k = $d->format('Y-m-d');
                $days[$k] = count($labels);
                $labels[] = $d->format('m-d');
                $d->modify('+1 day');
            }
        } catch (Exception $e) { }

        $blank = array_fill(0, count($labels), 0);
        $series = array(
            'total' => $blank, 'drug' => $blank, 'lab' => $blank,
            'imaging' => $blank, 'procedure' => $blank, 'patients' => $blank,
        );
        // 项目费日序列
        foreach (ana_order_sums($start, $end, '', array(), "strftime('%Y-%m-%d', paid_at)") as $r) {
            $g = $r['g']; if (!isset($days[$g])) continue;
            $i = $days[$g];
            if ($r['t'] === 'prescription') $series['drug'][$i] += round((float)$r['s'], 2);
            elseif ($r['t'] === 'lab') $series['lab'][$i] += round((float)$r['s'], 2);
            elseif ($r['t'] === 'imaging') $series['imaging'][$i] += round((float)$r['s'], 2);
            elseif ($r['t'] === 'procedure') $series['procedure'][$i] += round((float)$r['s'], 2);
            $series['total'][$i] += round((float)$r['s'], 2);
        }
        // 挂号费日序列（并入 total，不单列折线避免过密）
        foreach (DB::q('order', "SELECT strftime('%Y-%m-%d', created_at) AS g, COALESCE(SUM(total),0) AS s
            FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
            if (!isset($days[$r['g']])) continue;
            $series['total'][$days[$r['g']]] += round((float)$r['s'], 2);
        }
        // 人次日序列
        foreach (DB::q('patient', "SELECT date(payment_time) AS g, COUNT(*) AS c FROM registrations
            WHERE status IN ('paid','visiting','finished') AND payment_time IS NOT NULL AND date(payment_time) BETWEEN ? AND ? GROUP BY g",
            array($start, $end)) as $r) {
            if (!isset($days[$r['g']])) continue;
            $series['patients'][$days[$r['g']]] = (int)$r['c'];
        }

        json_ok(array('range' => array('start' => $start, 'end' => $end), 'labels' => $labels, 'series' => $series));
    }

    /* ==================== 科室维度统计 ==================== */
    if ($action === 'ana_dept') {
        list($start, $end) = ana_range();
        // 人次与挂号费：按就诊当前科室归集
        $regs = DB::q('patient', "SELECT current_dept_id AS d, COUNT(*) AS c, COALESCE(SUM(fee),0) AS f
            FROM registrations WHERE status IN ('paid','visiting','finished') AND payment_time IS NOT NULL
            AND date(payment_time) BETWEEN ? AND ? GROUP BY current_dept_id", array($start, $end));
        // 项目费：orders → visit_id → 就诊科室（分散库不能 JOIN，PHP 内存映射）
        $vids = array();
        $oidMap = array();
        $ordRows = DB::q('order', "SELECT visit_id, order_type, COALESCE(SUM(total_amount),0) AS s FROM orders
            WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?
            GROUP BY visit_id, order_type", array($start, $end));
        foreach ($ordRows as $r) { $vids[(int)$r['visit_id']] = true; $oidMap[] = $r; }
        // 批量取这些就诊的科室
        $visitDept = array();
        if ($vids) {
            $ids = array_keys($vids);
            foreach (array_chunk($ids, 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                foreach (DB::q('patient', "SELECT id, current_dept_id FROM registrations WHERE id IN ($ph)", $chunk) as $v) {
                    $visitDept[(int)$v['id']] = (int)$v['current_dept_id'];
                }
            }
        }
        // 科室名
        $deptNames = array();
        foreach (DB::q('dept', 'SELECT id, name FROM departments') as $dd) $deptNames[(int)$dd['id']] = $dd['name'];

        $stat = array();
        $initRow = function () { return array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0); };
        foreach ($regs as $r) {
            $d = (int)$r['d'];
            if (!isset($stat[$d])) $stat[$d] = $initRow();
            $stat[$d]['patients'] += (int)$r['c'];
            $stat[$d]['reg_fee'] += (float)$r['f'];
        }
        $typeKey = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
        foreach ($oidMap as $r) {
            $vid = (int)$r['visit_id'];
            $d = isset($visitDept[$vid]) ? $visitDept[$vid] : 0;
            if (!isset($stat[$d])) $stat[$d] = $initRow();
            $k = isset($typeKey[$r['order_type']]) ? $typeKey[$r['order_type']] : null;
            if ($k) $stat[$d][$k] += (float)$r['s'];
        }
        $rows = array();
        foreach ($stat as $d => $v) {
            $v['dept_id'] = $d;
            $v['dept_name'] = isset($deptNames[$d]) ? $deptNames[$d] : '未知科室';
            $v['total'] = round($v['reg_fee'] + $v['drug'] + $v['lab'] + $v['imaging'] + $v['procedure'], 2);
            foreach (array('reg_fee', 'drug', 'lab', 'imaging', 'procedure') as $kk) $v[$kk] = round($v[$kk], 2);
            $rows[] = $v;
        }
        usort($rows, function ($a, $b) { return $b['total'] <=> $a['total']; });
        json_ok(array('range' => array('start' => $start, 'end' => $end), 'rows' => $rows));
    }

    /* ==================== 医生维度统计 ==================== */
    if ($action === 'ana_doctor') {
        list($start, $end) = ana_range();
        $deptId = (int)req('dept_id', 0); // 可选：仅看某科室医生

        // 接诊人次：本人病历创建数（每次接诊一条文书）
        $visits = array();
        foreach (DB::q('medical', "SELECT doctor_id, doctor_name, COUNT(*) AS c FROM patient_records
            WHERE date(created_at) BETWEEN ? AND ? GROUP BY doctor_id, doctor_name", array($start, $end)) as $r) {
            $visits[(int)$r['doctor_id']] = array('name' => $r['doctor_name'], 'c' => (int)$r['c']);
        }
        // 开单缴费收入：按医生 + 类型
        $revRows = ana_order_sums($start, $end, 'doctor_id > 0', array(), 'doctor_id');
        $stat = array();
        $initRow = function () { return array('visits' => 0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0); };
        $names = array();
        foreach ($visits as $did => $v2) {
            if (!isset($stat[$did])) $stat[$did] = $initRow();
            $stat[$did]['visits'] = $v2['c'];
            $names[$did] = $v2['name'];
        }
        $typeKey = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
        $docDept = array();
        foreach ($revRows as $r) {
            $did = (int)$r['g'];
            if (!isset($stat[$did])) $stat[$did] = $initRow();
            $k = isset($typeKey[$r['t']]) ? $typeKey[$r['t']] : null;
            if ($k) $stat[$did][$k] += (float)$r['s'];
            if (!isset($names[$did])) $names[$did] = ''; // 仅开单未建文书（历史数据兼容）
        }
        // 补齐姓名与科室关联（user 库）
        $uids = array_keys($stat);
        if ($uids) {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            foreach (DB::q('user', "SELECT id, name, emp_no, dept_ids, title FROM users WHERE id IN ($ph)", $uids) as $u2) {
                if (empty($names[(int)$u2['id']])) $names[(int)$u2['id']] = $u2['name'];
                $docDept[(int)$u2['id']] = array('title' => $u2['title'], 'dept_ids' => (string)$u2['dept_ids'], 'emp_no' => (string)$u2['emp_no']);
            }
        }
        $rows = array();
        foreach ($stat as $did => $v2) {
            $row = array_merge(array(
                'doctor_id' => $did,
                'doctor_name' => isset($names[$did]) ? $names[$did] : ('医生#' . $did),
                'emp_no' => isset($docDept[$did]) ? $docDept[$did]['emp_no'] : '',
            ), $v2);
            $row['total'] = round($v2['drug'] + $v2['lab'] + $v2['imaging'] + $v2['procedure'], 2);
            foreach (array('drug', 'lab', 'imaging', 'procedure') as $kk) $row[$kk] = round($row[$kk], 2);
            // 科室过滤（按用户-科室多选关联）
            if ($deptId > 0) {
                $ids = array();
                foreach (explode(',', isset($docDept[$did]) ? $docDept[$did]['dept_ids'] : '') as $x) if ((int)$x > 0) $ids[] = (int)$x;
                if (!in_array($deptId, $ids, true)) continue;
            }
            $row['title'] = isset($docDept[$did]) ? $docDept[$did]['title'] : '';
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) { return $b['total'] <=> $a['total']; });
        json_ok(array('range' => array('start' => $start, 'end' => $end), 'rows' => $rows));
    }

    /* ==================== 自定义统计 ==================== */
    if ($action === 'ana_custom') {
        list($start, $end) = ana_range();
        $groupBy = post('group_by', 'day');           // day | month | year | dept | doctor
        if (!in_array($groupBy, array('day', 'month', 'year', 'dept', 'doctor'), true)) $groupBy = 'day';
        $metricList = explode(',', post('metrics', 'patients,total'));
        $allow = array('patients', 'reg_fee', 'drug', 'lab', 'imaging', 'procedure', 'total');
        $metrics = array_values(array_intersect($allow, array_map('trim', $metricList)));
        if (!$metrics) $metrics = array('patients', 'total');

        // 时间分组表达式
        $fmtMap = array('day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y');
        $timeExpr = function ($col) use ($fmtMap, $groupBy) {
            $fmt = $fmtMap[$groupBy];
            return "strftime('$fmt', $col)";
        };
        if ($groupBy === 'day' || $groupBy === 'month' || $groupBy === 'year') {
            $tePaid = $timeExpr('paid_at');
            $tePay = $timeExpr('created_at');
            $teReg = $timeExpr('payment_time');
            // 收集所有分组标签
            $labelSet = array();
            foreach (DB::q('order', "SELECT DISTINCT $tePaid AS g FROM orders WHERE paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
            foreach (DB::q('order', "SELECT DISTINCT $tePay AS g FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
            foreach (DB::q('patient', "SELECT DISTINCT $teReg AS g FROM registrations WHERE payment_time IS NOT NULL AND status IN ('paid','visiting','finished') AND date(payment_time) BETWEEN ? AND ?", array($start, $end)) as $r) $labelSet[$r['g']] = true;
            ksort($labelSet);
            $labels = array_keys($labelSet);
            $idx = array_flip($labels);
            $cols = array(); // metric => [values]
            foreach ($metrics as $mk) $cols[$mk] = array_fill(0, count($labels), 0);

            $add = function ($metric, $g, $v) use (&$cols, $idx) {
                if (!isset($cols[$metric]) || !isset($idx[$g])) return;
                $cols[$metric][$idx[$g]] += $v;
            };
            $needOrder = array_intersect($metrics, array('drug', 'lab', 'imaging', 'procedure', 'total'));
            if ($needOrder) {
                $ge = $timeExpr('paid_at');
                foreach (DB::q('order', "SELECT order_type AS t, $ge AS g, COALESCE(SUM(total_amount),0) AS s FROM orders
                    WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY t, g",
                    array($start, $end)) as $r) {
                    $k = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
                    $tk = isset($k[$r['t']]) ? $k[$r['t']] : null;
                    foreach ($metrics as $mk) {
                        if ($mk === 'total' || $mk === $tk) $add($mk, $r['g'], (float)$r['s']);
                    }
                }
            }
            if (in_array('reg_fee', $metrics, true)) {
                foreach (DB::q('order', "SELECT $tePay AS g, COALESCE(SUM(total),0) AS s FROM payments WHERE kind='visit' AND date(created_at) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
                    $add('reg_fee', $r['g'], (float)$r['s']);
                }
            }
            if (in_array('patients', $metrics, true)) {
                foreach (DB::q('patient', "SELECT $teReg AS g, COUNT(*) AS c FROM registrations WHERE payment_time IS NOT NULL AND status IN ('paid','visiting','finished') AND date(payment_time) BETWEEN ? AND ? GROUP BY g", array($start, $end)) as $r) {
                    $add('patients', $r['g'], (int)$r['c']);
                }
            }
            $rows = array();
            foreach ($labels as $li => $lb) {
                $row = array('label' => $lb);
                foreach ($metrics as $mk) $row[$mk] = in_array($mk, array('patients'), true) ? (int)$cols[$mk][$li] : round((float)$cols[$mk][$li], 2);
                $rows[] = $row;
            }
            json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $rows));
        }

        // 科室 / 医生 维度：复用 dept / doctor 统计再投影指标
        if ($groupBy === 'dept') {
            $rows = array();
            // 直接调用内部逻辑：复制 ana_dept 输出结构（此处重新查询）
            $regs = DB::q('patient', "SELECT current_dept_id AS d, COUNT(*) AS c, COALESCE(SUM(fee),0) AS f
                FROM registrations WHERE status IN ('paid','visiting','finished') AND payment_time IS NOT NULL
                AND date(payment_time) BETWEEN ? AND ? GROUP BY current_dept_id", array($start, $end));
            $deptNames = array();
            foreach (DB::q('dept', 'SELECT id, name FROM departments') as $dd) $deptNames[(int)$dd['id']] = $dd['name'];
            $stat = array();
            foreach ($regs as $r) {
                $d = (int)$r['d'];
                if (!isset($stat[$d])) $stat[$d] = array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
                $stat[$d]['patients'] += (int)$r['c'];
                $stat[$d]['reg_fee'] += (float)$r['f'];
            }
            $vd = array(); $map = array();
            foreach (DB::q('order', "SELECT visit_id, order_type, COALESCE(SUM(total_amount),0) AS s FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at) BETWEEN ? AND ? GROUP BY visit_id, order_type", array($start, $end)) as $r) { $vd[(int)$r['visit_id']] = true; $map[] = $r; }
            $vdept = array();
            if ($vd) {
                $ids = array_keys($vd);
                foreach (array_chunk($ids, 400) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    foreach (DB::q('patient', "SELECT id, current_dept_id FROM registrations WHERE id IN ($ph)", $chunk) as $v3) $vdept[(int)$v3['id']] = (int)$v3['current_dept_id'];
                }
            }
            $tk = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
            foreach ($map as $r) {
                $vid = (int)$r['visit_id'];
                $d = isset($vdept[$vid]) ? $vdept[$vid] : 0;
                if (!isset($stat[$d])) $stat[$d] = array('patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0);
                $k = isset($tk[$r['order_type']]) ? $tk[$r['order_type']] : null;
                if ($k) $stat[$d][$k] += (float)$r['s'];
            }
            foreach ($stat as $d => $v) {
                $v['total'] = $v['reg_fee'] + $v['drug'] + $v['lab'] + $v['imaging'] + $v['procedure'];
                $row = array('label' => isset($deptNames[$d]) ? $deptNames[$d] : '未知科室');
                foreach ($metrics as $mk) $row[$mk] = $mk === 'patients' ? (int)$v[$mk] : round((float)$v[$mk], 2);
                $rows[] = $row;
            }
            usort($rows, function ($a, $b) { return ($b['total'] ?? 0) <=> ($a['total'] ?? 0); });
            json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $rows));
        }

        // doctor 维度
        $rows = array();
        foreach (DB::q('medical', "SELECT doctor_id, doctor_name, COUNT(*) AS c FROM patient_records WHERE date(created_at) BETWEEN ? AND ? GROUP BY doctor_id, doctor_name", array($start, $end)) as $r) {
            $rows[(int)$r['doctor_id']] = array('label' => $r['doctor_name'], 'patients' => (int)$r['c'], 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0, 'total' => 0.0);
        }
        foreach (ana_order_sums($start, $end, 'doctor_id > 0', array(), 'doctor_id') as $r) {
            $did = (int)$r['g'];
            if (!isset($rows[$did])) $rows[$did] = array('label' => '医生#' . $did, 'patients' => 0, 'reg_fee' => 0.0, 'drug' => 0.0, 'lab' => 0.0, 'imaging' => 0.0, 'procedure' => 0.0, 'total' => 0.0);
            $k = array('prescription' => 'drug', 'lab' => 'lab', 'imaging' => 'imaging', 'procedure' => 'procedure');
            if (isset($k[$r['t']])) $rows[$did][$k[$r['t']]] += (float)$r['s'];
            if (isset($k[$r['t']])) $rows[$did]['total'] += (float)$r['s'];
        }
        $out = array();
        foreach ($rows as $did => $v) {
            $row = array('label' => $v['label']);
            foreach ($metrics as $mk) $row[$mk] = $mk === 'patients' ? (int)$v[$mk] : round((float)$v[$mk], 2);
            $out[] = $row;
        }
        usort($out, function ($a, $b) { return ($b['total'] ?? $b['patients'] ?? 0) <=> ($a['total'] ?? $a['patients'] ?? 0); });
        json_ok(array('range' => array('start' => $start, 'end' => $end), 'group_by' => $groupBy, 'metrics' => $metrics, 'rows' => $out));
    }

    /* ==================== 转归查询（诊毕离院方式统计） ==================== */
    if ($action === 'ana_disposition') {
        $type = trim((string)get('type', '全部'));
        $sql = 'SELECT r.id AS visit_id, r.register_time, r.flow_no, r.disposition, r.disposition_detail, ' .
            'COALESCE(NULLIF(r.current_dept_name, \'\'), r.first_dept_name) AS dept_name, ' .
            'p.name AS pname, p.gender, p.birth_date, p.id_card ' .
            'FROM registrations r JOIN patients p ON p.patient_no=r.patient_no ' .
            "WHERE r.status='finished' AND r.disposition<>''";
        $params = array();
        if ($type !== '' && $type !== '全部') {
            $sql .= ' AND r.disposition=?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT 200';
        $rows = array();
        $vids = array();
        foreach (DB::q('patient', $sql, $params) as $r) {
            $r['doctor_name'] = '';   // 医生在 medical 库，第二段查询回填（首诊医生）
            $vids[] = (int)$r['visit_id'];
            $rows[] = $r;
        }
        // 回填首诊医生（medical 与 patient 分库不可 JOIN，按 visit_id 批量查）
        if ($vids) {
            $ph = implode(',', array_fill(0, count($vids), '?'));
            $docMap = array();
            foreach (DB::q('medical', "SELECT visit_id, doctor_name FROM patient_records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
                if (!isset($docMap[(int)$pr['visit_id']])) $docMap[(int)$pr['visit_id']] = (string)$pr['doctor_name'];
            }
            foreach ($rows as &$r) {
                $vid = (int)$r['visit_id'];
                if (!isset($docMap[$vid])) {
                    // 结构化表无记录时回退旧镜像表
                    foreach (DB::q('medical', "SELECT visit_id, doctor_name FROM records WHERE visit_id IN ($ph) ORDER BY id ASC", $vids) as $pr) {
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
                'register_time' => (string)$r['register_time'],
                'flow_no' => (string)$r['flow_no'],
                'dept_name' => (string)$r['dept_name'],
                'doctor_name' => (string)$r['doctor_name'],
                'disposition' => (string)$r['disposition'],
                'disposition_detail' => (string)$r['disposition_detail'],
                'pname' => (string)$r['pname'],
                'gender' => (string)$r['gender'],
                'id_card' => (string)(isset($r['id_card']) ? $r['id_card'] : ''),
                'age_fmt' => age_format($r['birth_date'], $r['register_time']),
            );
        }
        json_ok(array('list' => $rowsOut));
    }

    json_fail('未知操作');
}
