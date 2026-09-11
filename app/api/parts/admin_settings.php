<?php
/**
 * ============================================================
 * parts/admin_settings.php v1.1.0 — 管理端：系统设置/统计/打印中心
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. stats      工作台统计
 *   2. settings   系统设置保存（医院名称/第二名称/页脚/时区/HIS密钥）
 *   3. upload_logo 上传医院 LOGO（同时作为 favicon）
 *   4. print_items 打印中心：某就诊可打印单据一览
 * ============================================================ */

/**
 * 处理系统设置相关动作
 * @param string $action 动作名
 */
function admin_part_settings($action) {
    $u = Auth::user();

    /* ==================== 工作台统计 ==================== */
    if ($action === 'stats') {
        $today = today_str();
        $regToday = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status IN ('paid','visiting','finished')", array($today));
        $waiting = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status='paid'", array($today));
        $revenue = (float)AnalyticsRepository::val("SELECT COALESCE(SUM(total),0) FROM payments WHERE date(created_at)=?", array($today));
        $pendingAudits = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM audits WHERE status='pending'");
        $lowStock = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty<=10");
        $deptCount = (int)AnalyticsRepository::val("SELECT COUNT(*) FROM departments WHERE status=1 AND type IN ('clinic','emergency')");
        $userCount = (int)AnalyticsRepository::val('SELECT COUNT(*) FROM users WHERE status=1');
        $msgCount = (int)AnalyticsRepository::val('SELECT COUNT(*) FROM messages WHERE is_read=0 AND (to_user_id=? OR (to_user_id=0 AND to_role=?))', array($u['id'], $u['role']));
        // 近7天趋势（挂号人次 + 缴费金额）
        $trend = trend_7_days(array(
            'reg' => function ($day) {
                return (int)AnalyticsRepository::val("SELECT COUNT(*) FROM registrations WHERE date(registered_at)=? AND status IN ('paid','visiting','finished')", array($day));
            },
            'rev' => function ($day) {
                return round((float)AnalyticsRepository::val("SELECT COALESCE(SUM(total),0) FROM payments WHERE date(created_at)=?", array($day)), 2);
            },
        ));
        json_ok(array(
            'reg_today' => $regToday, 'waiting' => $waiting, 'revenue' => money($revenue),
            'pending_audits' => $pendingAudits, 'low_stock' => $lowStock,
            'dept_count' => $deptCount, 'user_count' => $userCount, 'msg_count' => $msgCount,
            'trend' => $trend,
        ));
    }

    /* ==================== 系统设置保存 ==================== */
    if ($action === 'settings') {
        $hospital = post('hospital_name');
        if ($hospital === '') json_fail('医院名称不能为空');
        $tz = post('timezone', 'Asia/Shanghai');
        $tzList = DateTimeZone::listIdentifiers();
        if (!in_array($tz, $tzList, true)) $tz = 'Asia/Shanghai';
        set_setting('hospital_name', $hospital);
        set_setting('hospital_name2', post('hospital_name2'));
        // 页脚版权：固定格式自动生成【© 年份 医院名称 版权所有】，不再手动保存
        set_setting('timezone', $tz);
        // HIS 预留接口密钥（可为空=关闭外部接口）
        $hisKey = post('his_api_key', '');
        set_setting('his_api_key', $hisKey);
        // 登录安全：验证码启用模式（off/auto/force）与锁定阈值（3-10）
        $captchaMode = post('login_captcha_mode', 'auto');
        if (!in_array($captchaMode, opt_list('login_captcha_mode'), true)) $captchaMode = 'auto';
        set_setting('login_captcha_mode', $captchaMode);
        $lockCount = (int)post('login_fail_lock_count', 5);
        if ($lockCount < 3 || $lockCount > 10) $lockCount = 5;
        set_setting('login_fail_lock_count', (string)$lockCount);
        date_default_timezone_set($tz);
        json_ok(array(), '系统设置已保存');
    }

    /* ==================== 作息时间设置保存（含夏令时作息） ==================== */
    if ($action === 'work_save') {
        // 时间格式校验（HH:MM）与先后关系
        $keys = array('work_am_start', 'work_am_end', 'work_pm_start', 'work_pm_end');
        $v = array();
        foreach ($keys as $k) {
            $t = post($k);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) json_fail('作息时间格式不正确（应为 HH:MM）');
            $v[$k] = $t;
        }
        if ($v['work_am_start'] >= $v['work_am_end']) json_fail('上午上班时间需早于上午下班时间');
        if ($v['work_pm_start'] >= $v['work_pm_end']) json_fail('下午上班时间需早于下午下班时间');
        // 夏令时（夏/冬季作息切换）
        $dstEnabled = post('dst_enabled') === '1' ? '1' : '0';
        $dstStart = post('dst_start', '');
        $dstEnd = post('dst_end', '');
        $mmdd = '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';
        if ($dstEnabled === '1') {
            if (!preg_match($mmdd, $dstStart) || !preg_match($mmdd, $dstEnd)) {
                json_fail('夏令时日期范围格式不正确（应为 MM-DD，如 06-01）');
            }
        }
        // 夏令时作息四要素（可留空=沿用常规作息对应项）
        $dstTimes = array();
        foreach (array('dst_am_start', 'dst_am_end', 'dst_pm_start', 'dst_pm_end') as $k) {
            $t = post($k, '');
            if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) json_fail('夏令时作息时间格式不正确（应为 HH:MM）');
            $dstTimes[$k] = $t;
        }
        foreach ($v as $k => $t) set_setting($k, $t);
        set_setting('dst_enabled', $dstEnabled);
        set_setting('dst_start', $dstEnabled === '1' ? $dstStart : '');
        set_setting('dst_end', $dstEnabled === '1' ? $dstEnd : '');
        foreach ($dstTimes as $k => $t) set_setting($k, $dstEnabled === '1' ? $t : '');
        $eff = work_schedule();
        json_ok(array('effective' => array(
            'am' => $eff['am_start'] . ' ~ ' . $eff['am_end'],
            'pm' => $eff['pm_start'] . ' ~ ' . $eff['pm_end'],
            'is_dst' => $eff['is_dst'],
        )), '作息时间已保存' . ($eff['is_dst'] === '1' ? '（当前处于夏令时区间，已按夏令时作息执行）' : ''));
    }

    /* ==================== 上传医院 LOGO（同时作为 favicon） ==================== */
    if ($action === 'upload_logo') {
        $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
        if (isset($res['error'])) json_fail($res['error']);
        set_setting('logo', $res['path']);
        json_ok(array('path' => $res['path']), 'LOGO 已上传');
    }

    /* ==================== URL 混淆密钥：状态查询 / 重置 ====================
     * 说明：密钥用于业务实体 ID（就诊/申请单/报告等）的 URL 加密防撞库；
     * 重置后所有旧链接即刻失效，系统功能不受影响（新链接按新密钥即时生成）。 */
    if ($action === 'obf_status') {
        json_ok(array(
            'configured' => IdObfuscator::configured(),
            'secret' => IdObfuscator::secret(),   // 展示供管理员核对/备份
        ));
    }
    if ($action === 'obf_reset') {
        $new = IdObfuscator::reset();
        json_ok(array('secret' => $new), 'URL 混淆密钥已重置，此前分享/收藏的链接已全部失效');
    }

    /* ==================== 打印中心：就诊记录分页检索（左栏列表） ====================
     * 关键字支持 患者姓名 / 患者ID / 门诊流水号 / 身份证号，留空返回全部；
     * 按就诊时间倒序（最新在最上），分页返回供前端滚动分段加载。 */
    if ($action === 'print_visits') {
        $kw = trim(get('kw', ''));
        $page = max(1, (int)get('page', 1));
        $pageSize = 20;
        $where = '1=1';
        $params = array();
        if ($kw !== '') {
            $where .= ' AND (p.name LIKE ? OR r.patient_no LIKE ? OR r.flow_no LIKE ? OR p.id_card LIKE ?)';
            $like = '%' . $kw . '%';
            $params = array($like, $like, $like, $like);
        }
        $total = (int)AnalyticsRepository::val(
            "SELECT COUNT(*) FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no WHERE $where",
            $params
        );
        $rows = AnalyticsRepository::q(
            "SELECT r.*, p.name AS pname, p.gender AS pgender, p.birth_date AS pbirth
             FROM registrations r LEFT JOIN patients p ON p.patient_no=r.patient_no
             WHERE $where
             ORDER BY r.registered_at DESC, r.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, array($pageSize, ($page - 1) * $pageSize))
        );
        $list = array();
        foreach ($rows as $r) {
            $list[] = array(
                'visit_id' => oid((int)$r['id']),
                'patient_name' => (string)$r['pname'],
                'gender' => (string)$r['pgender'],
                'age_fmt' => age_format($r['pbirth'], $r['registered_at']),
                'patient_no' => (string)$r['patient_no'],
                'flow_no' => (string)$r['flow_no'],
                'dept_name' => (string)$r['first_dept_name'],
                'visit_seq' => (int)$r['visit_seq'],
                'status' => (string)$r['status'],
                'registered_at' => (string)$r['registered_at'],
            );
        }
        json_ok(array('list' => $list, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
    }

    /* ==================== 打印中心：某就诊可打印单据（四子页签） ====================
     * 就诊：挂号凭条 / 电子病历 / 诊断证明（项目间虚线分隔）；
     * 开单：全部开单记录（按 检验/检查/处置/处方 分组，组间虚线分隔）；
     * 缴费：全部缴费凭条（已退费红色删除线保留溯源，不可补打）；
     * 报告：检验报告 / 检查报告（组间虚线分隔，已撤回标记保留）。 */
    if ($action === 'print_items') {
        $visitId = did(get('visit_id'));
        if ($visitId <= 0) json_fail('链接无效或已过期，请从列表重新进入');
        $row = get_visit_row($visitId);
        if (!$row) json_fail('就诊记录不存在');
        $visit = $row['visit'];
        $vOid = oid($visitId);
        $isVisitDead = in_array($visit['status'], array('refunded', 'cancelled'), true);
        $hasRecord = (int)AnalyticsRepository::val('SELECT COUNT(*) FROM records WHERE visit_id=?', array($visitId)) > 0;
        $hasCert = (int)AnalyticsRepository::val('SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($visitId)) > 0;

        /* ---------- 通用渲染助手 ---------- */
        // 单据行：标题 + 说明 + 操作区（$dead=true 红色删除线保留溯源，按钮禁用）
        $pcRow = function ($title, $sub, $btnHtml, $dead = false, $deadText = '') {
            $deadBadge = $dead ? ' <span class="badge badge-danger" style="font-size:11px">' . e($deadText ?: '已退费') . '</span>' : '';
            return '<div class="pc-row">' .
                '<div class="pc-row-info">' .
                '  <div class="pc-row-title' . ($dead ? ' pc-dead' : '') . '">' . e($title) . '</div>' .
                ($sub !== '' ? '<div class="pc-row-sub' . ($dead ? ' pc-dead' : '') . '">' . $sub . '</div>' : '') .
                '</div>' .
                '<div class="pc-row-actions">' . $btnHtml . $deadBadge . '</div>' .
                '</div>';
        };
        // 打印按钮（$dead=true → 禁用态占位，保留版面）
        $pcBtn = function ($label, $url, $sheet = 'a5', $dead = false) {
            if ($dead) return '<button class="btn btn-outline btn-sm" disabled title="已退费作废，不可补打">补打</button>';
            return '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'' . $url . '\',null,\'' . $sheet . '\')">🖨️ 补打</button>';
        };
        // 组间/项间虚线分隔
        $pcSep = function () { return '<div class="pc-sep"></div>'; };
        // 空态
        $pcEmpty = function ($text) { return '<div class="pc-empty">' . e($text) . '</div>'; };

        /* ---------- 页签一：就诊 ---------- */
        $visitRows = array();
        $visitRows[] = $pcRow('挂号凭条', '挂号费 ¥' . money($visit['fee']) . ' ｜ ' . e($visit['first_dept_name']) . ' ｜ ' . e(substr($visit['registered_at'], 0, 16)),
            $pcBtn('补打', '/api/print?action=receipt&visit_id=' . e($vOid), 'ticket', $isVisitDead),
            $isVisitDead, $visit['status'] === 'cancelled' ? '已取消' : '已退费');
        if ($hasRecord) {
            $visitRows[] = $pcRow('电子病历', '门诊电子病历（连续文书，含历次续写）',
                $pcBtn('补打', '/api/print?action=record&visit_id=' . e($vOid), 'a5'));
        }
        if ($hasCert) {
            $cert = AnalyticsRepository::one('SELECT cert_no, created_at FROM certificates WHERE visit_id=? ORDER BY id DESC LIMIT 1', array($visitId));
            $visitRows[] = $pcRow('诊断证明', $cert ? '证明号 ' . e($cert['cert_no']) . ' ｜ ' . e(substr((string)$cert['created_at'], 0, 16)) : '',
                $pcBtn('补打', '/api/print?action=certificate&visit_id=' . e($vOid), 'a5'));
        }
        $paneVisit = implode($pcSep(), $visitRows);

        /* ---------- 页签二：开单（按 检验/检查/处置/处方 分组，组间虚线） ---------- */
        $orders = AnalyticsRepository::q('SELECT * FROM orders WHERE visit_id=? ORDER BY id', array($visitId));
        $orderGroups = array(
            'lab' => array('检验申请单', array()),
            'imaging' => array('检查申请单', array()),
            'procedure' => array('处置单', array()),
            'prescription' => array('处方单', array()),
        );
        foreach ($orders as $o) {
            $t = (string)$o['order_type'];
            if (!isset($orderGroups[$t])) continue;
            $dead = ($o['status'] === 'refunded');
            $sub = e($o['order_no']) . ' ｜ ¥' . money($o['total_amount']) . ' ｜ ' . e(substr((string)$o['created_at'], 0, 16)) . ' ｜ 开单 ' . e((string)$o['doctor_name']);
            if ($dead) $sub .= '（已退费）';
            $orderGroups[$t][1][] = $pcRow($orderGroups[$t][0], $sub,
                $pcBtn('补打', '/api/print?action=order&order_id=' . e(oid((int)$o['id'])), 'a5'),
                false);
        }
        $orderSections = array();
        $orderCount = 0;
        foreach ($orderGroups as $g) {
            if (!$g[1]) continue;
            $orderCount += count($g[1]);
            $orderSections[] = '<div class="pc-group-title">' . e($g[0]) . '（' . count($g[1]) . '）</div>' . implode('', $g[1]);
        }
        $paneOrders = $orderCount ? implode($pcSep(), $orderSections) : $pcEmpty('该就诊暂无开单记录');

        /* ---------- 页签三：缴费（全部缴费凭条；退费红色删除线保留溯源） ---------- */
        $pays = AnalyticsRepository::q('SELECT * FROM payments WHERE visit_id=? ORDER BY id', array($visitId));
        $payRows = array();
        foreach ($pays as $pay) {
            $dead = false;
            $partial = false;
            if ((string)$pay['kind'] === 'visit') {
                $dead = $isVisitDead;
            } elseif (!empty($pay['payment_no'])) {
                // 批次内存活（未退费）缴费行数：0=整批退费（作废），部分=部分退费
                $batchAlive = (int)AnalyticsRepository::val(
                    "SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id=o.id AND p.kind='order' WHERE p.payment_no=? AND o.status<>'refunded'",
                    array($pay['payment_no'])
                );
                $batchAll = (int)AnalyticsRepository::val(
                    "SELECT COUNT(*) FROM payments WHERE payment_no=? AND kind='order'",
                    array($pay['payment_no'])
                );
                if ($batchAlive === 0) $dead = true;
                elseif ($batchAlive < $batchAll) $partial = true;
            }
            $title = ((string)$pay['kind'] === 'visit') ? '挂号费缴费凭条' : '开单缴费凭条';
            $sub = '¥' . money($pay['total']) . ' ｜ ' . e(substr((string)$pay['created_at'], 0, 16)) . ' ｜ 收费员 ' . e((string)$pay['cashier_name']) .
                ((int)$pay['item_count'] > 0 ? ' ｜ ' . (int)$pay['item_count'] . ' 项' : '');
            if ($partial) $sub .= '（部分退费）';
            $payRows[] = $pcRow($title, $sub,
                $pcBtn('补打', '/api/print?action=payment&payment_id=' . e(oid((int)$pay['id'])), 'ticket', $dead),
                $dead);
        }
        $panePayments = $payRows ? implode($pcSep(), $payRows) : $pcEmpty('该就诊暂无缴费记录');

        /* ---------- 页签四：报告（检验/检查分组，组间虚线；已撤回标记保留） ---------- */
        $reports = AnalyticsRepository::q('SELECT * FROM reports WHERE visit_id=? ORDER BY id', array($visitId));
        // 批量解析报告对应项目名（reports → results → lab_items/exam_items）
        $resultIds = array();
        foreach ($reports as $rp) { if ((int)$rp['result_id'] > 0) $resultIds[(int)$rp['result_id']] = 1; }
        $resultItem = array();
        if ($resultIds) {
            $ph = in_placeholders($resultIds);
            foreach (AnalyticsRepository::q("SELECT id, item_id, type FROM results WHERE id IN ($ph)", array_keys($resultIds)) as $rs) {
                $resultItem[(int)$rs['id']] = array((int)$rs['item_id'], (string)$rs['type']);
            }
            $labIds = array(); $examIds = array();
            foreach ($resultItem as $ri) { if ($ri[1] === 'lab') $labIds[$ri[0]] = 1; else $examIds[$ri[0]] = 1; }
            $nameMap = array();
            if ($labIds) {
                $ph = in_placeholders($labIds);
                foreach (AnalyticsRepository::q("SELECT id, name FROM lab_items WHERE id IN ($ph)", array_keys($labIds)) as $li) {
                    $nameMap['lab_' . $li['id']] = (string)$li['name'];
                }
            }
            if ($examIds) {
                $ph = in_placeholders($examIds);
                foreach (AnalyticsRepository::q("SELECT id, name FROM exam_items WHERE id IN ($ph)", array_keys($examIds)) as $ei) {
                    $nameMap['exam_' . $ei['id']] = (string)$ei['name'];
                }
            }
            foreach ($resultItem as $rid => $ri) {
                $key = $ri[1] . '_' . $ri[0];
                $resultItem[$rid][2] = isset($nameMap[$key]) ? $nameMap[$key] : '';
            }
        }
        $reportGroups = array('lab' => array('检验报告', array()), 'imaging' => array('检查报告', array()));
        foreach ($reports as $rp) {
            $t = (string)$rp['type'];
            if (!isset($reportGroups[$t])) continue;
            $rid = (int)$rp['result_id'];
            $itemName = (isset($resultItem[$rid]) && $resultItem[$rid][2] !== '') ? $resultItem[$rid][2] : ($t === 'lab' ? '检验项目' : '检查项目');
            $withdrawn = ((string)$rp['status'] === 'withdrawn');
            $sub = e($itemName) . ' ｜ 报告号 ' . e((string)$rp['report_no']) . ' ｜ ' . e(substr((string)$rp['created_at'], 0, 16)) . ' ｜ ' . e((string)$rp['doctor']);
            // 已撤回报告：红色删除线标记（保留展示便于溯源），按钮为「查看」仍可看原始报告
            $reportGroups[$t][1][] = $pcRow(
                ($t === 'lab' ? '检验报告' : '检查报告') . ' · ' . $itemName,
                $sub,
                $withdrawn
                    ? '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=report&report_id=' . e(oid((int)$rp['id'])) . '\',null,\'a5\')">🖨️ 查看</button>'
                    : $pcBtn('补打', '/api/print?action=report&report_id=' . e(oid((int)$rp['id'])), 'a5'),
                $withdrawn, '已撤回');
        }
        $reportSections = array();
        $reportCount = 0;
        foreach ($reportGroups as $g) {
            if (!$g[1]) continue;
            $reportCount += count($g[1]);
            $reportSections[] = '<div class="pc-group-title">' . e($g[0]) . '（' . count($g[1]) . '）</div>' . implode('', $g[1]);
        }
        $paneReports = $reportCount ? implode($pcSep(), $reportSections) : $pcEmpty('该就诊暂无检验/检查报告');

        /* ---------- 组装四页签 ---------- */
        $tabs = array(
            array('visit', '就诊', count($visitRows), $paneVisit),
            array('orders', '开单', $orderCount, $paneOrders),
            array('payments', '缴费', count($payRows), $panePayments),
            array('reports', '报告', $reportCount, $paneReports),
        );
        $tabBtns = '';
        $panes = '';
        foreach ($tabs as $i => $tb) {
            $tabBtns .= '<button type="button" class="pc-tab' . ($i === 0 ? ' active' : '') . '" data-tab="' . $tb[0] . '" onclick="pcTab(\'' . $tb[0] . '\')">' .
                e($tb[1]) . '（' . (int)$tb[2] . '）</button>';
            $panes .= '<div class="pc-tabpane" id="pcPane_' . $tb[0] . '"' . ($i === 0 ? '' : ' style="display:none"') . '>' . $tb[3] . '</div>';
        }

        $html = '<div style="padding:4px 2px">' .
            '<div class="fw-700 fs-15">' . e($row['patient']['name']) . '（' . e($visit['flow_no']) . '）</div>' .
            '<div class="fs-13 text-muted mt-4 mb-12">' . e($visit['first_dept_name']) . ' 第' . str_pad((string)$visit['visit_seq'], 3, '0', STR_PAD_LEFT) . '号 ｜ ' . e(substr($visit['registered_at'], 0, 16)) . ' ｜ ' . e(visit_status_name($visit['status'])) . '</div>' .
            '<div class="pc-tabs">' . $tabBtns . '</div>' .
            $panes .
            '</div>';
        json_ok(array('html' => $html));
    }

    json_fail('未知操作');
}
