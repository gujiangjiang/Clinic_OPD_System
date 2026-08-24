<?php
/**
 * ============================================================
 * patient.php — 患者档案接口
 * ============================================================
 * 说明：三处共用（挂号管理/医生工作站/护士站）：
 * 1. 按身份证检索既往登记（挂号自动填充）
 * 2. 患者查询（ID/身份证/姓名）
 * 3. 患者信息修改弹窗（姓名/性别/身份证/出生年月不可改）
 * 4. 患者全部就诊历史（病历+开单情况）
 * ============================================================ */
require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 按身份证检索患者 ---------------- */
    case 'by_card':
        $idCard = get('id_card', '');
        $p = DB::one('patient', 'SELECT * FROM patients WHERE id_card=?', array($idCard));
        json_ok(array('patient' => $p));
        break;

    /* ---------------- 患者查询（ID/身份证/姓名） ---------------- */
    case 'search':
        $kw = get('kw', '');
        if ($kw === '') {
            json_ok(array('list' => array()));
        }
        $like = '%' . $kw . '%';
        $list = DB::q('patient', "SELECT * FROM patients WHERE patient_no LIKE ? OR id_card LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT 20",
            array($like, $like, $like));
        json_ok(array('list' => $list));
        break;

    /* ---------------- 患者信息修改表单（服务端渲染，字典来自 options_data.php） ---------------- */
    case 'edit_form':
        $kw = get('kw', '');
        $p = DB::one('patient', 'SELECT * FROM patients WHERE id_card=? OR patient_no=?', array($kw, $kw));
        if (!$p) {
            json_ok(array('html' => ''));
        }
        $html = '<input type="hidden" id="pmNo" value="' . e($p['patient_no']) . '">' .
            '<input type="hidden" id="pmCard" value="' . e($p['id_card']) . '">' .
            '<div class="fs-13 text-muted mb-12">患者：<strong>' . e($p['name']) . '</strong>（' . e($p['patient_no']) . '）—— 姓名、性别、身份证、出生年月不可修改</div>' .
            '<div class="form-row">' .
            '  <div class="form-group"><label class="form-label">联系电话</label><input class="input" id="pmPhone" value="' . e($p['phone']) . '"></div>' .
            '  <div class="form-group"><label class="form-label">民族</label><select class="select" id="pmEth">' . opt_options('ethnicity', $p['ethnicity']) . '</select></div>' .
            '</div>' .
            '<div class="form-row">' .
            '  <div class="form-group"><label class="form-label">婚姻状况</label><select class="select" id="pmMarital">' . opt_options('marital', $p['marital']) . '</select></div>' .
            '  <div class="form-group"><label class="form-label">职业</label><select class="select" id="pmOcc">' . opt_options('occupation', $p['occupation']) . '</select></div>' .
            '</div>' .
            '<div class="form-row">' .
            '  <div class="form-group"><label class="form-label">工作单位</label><input class="input" id="pmWork" value="' . e($p['work_unit']) . '"></div>' .
            '  <div class="form-group"><label class="form-label">联系地址</label><input class="input" id="pmAddr" value="' . e($p['address']) . '"></div>' .
            '</div>';
        json_ok(array('html' => $html));
        break;

    /* ---------------- 患者信息修改（除姓名/性别/身份证/出生年月外） ---------------- */
    case 'update':
        // 以患者唯一ID（patient_no）为主键更新，兼容无身份证（急诊）患者
        $patientNo = post('patient_no');
        if ($patientNo === '') {
            json_fail('缺少患者标识');
        }
        $fields = array('phone', 'ethnicity', 'marital', 'occupation', 'work_unit', 'address');
        $set = array();
        $params = array();
        foreach ($fields as $f) {
            $set[] = $f . '=?';
            $params[] = post($f);
        }
        $params[] = $patientNo;
        DB::exec('patient', 'UPDATE patients SET ' . implode(',', $set) . ' WHERE patient_no=?', $params);
        json_ok(array(), '患者信息已更新');
        break;

    /* ---------------- 患者全部就诊历史（病历 + 开单情况） ---------------- */
    case 'history':
        $patientNo = get('patient_no', '');
        $p = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($patientNo));
        if (!$p) {
            json_ok(array('html' => '<div class="empty">未找到该患者</div>'));
        }
        $visits = DB::q('patient', 'SELECT * FROM registrations WHERE patient_no=? ORDER BY id DESC', array($patientNo));
        $html = '<div class="card" style="margin-bottom:12px">' .
            '<div class="flex-between"><div class="fs-16 fw-700">患者 ' . e($p['name']) . '</div>' .
            '<span class="badge badge-primary">' . e($p['patient_no']) . '</span></div>' .
            '<div class="fs-13 text-muted mt-4">性别：' . e($p['gender']) . ' ｜ 年龄：' . age_format($p['birth_date']) . ' ｜ 身份证：' . e($p['id_card']) . '</div></div>';
        if (!$visits) {
            $html .= '<div class="empty">暂无就诊记录</div>';
        }
        foreach ($visits as $v) {
            $records = DB::q('medical', 'SELECT * FROM records WHERE visit_id=? ORDER BY id DESC', array($v['id']));
            $orders = DB::q('order', 'SELECT * FROM orders WHERE visit_id=? ORDER BY id DESC', array($v['id']));
            // 病历行仅展示「首诊医生：第一诊断」（多医生续写全列显示不下）；
            // 结构化病历表为主，旧镜像表兜底（其无 updated_at，勿再拼接时间括号）
            $prAll = DB::q('medical', 'SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC', array($v['id']));
            $recLine = '';
            if ($prAll) {
                $fr = $prAll[0];
                $recLine = e($fr['doctor_name']) . '：' . e($fr['primary_diagnosis']);
            } elseif ($records) {
                // records 按 id DESC 排列，最早一条（首诊）在末尾
                $keys = array_keys($records);
                $legacyFirst = $records[end($keys)];
                $recLine = e($legacyFirst['doctor_name']) . '：' . e($legacyFirst['initial_diagnosis']);
            }
            $hasRecord = $prAll || $records;
            $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px">';
            $html .= '<div class="flex-between">' .
                '<span class="fw-600">' . e($v['register_time']) . ' ｜ ' . e($v['first_dept_name']) .
                ' 第' . str_pad((string)$v['visit_seq'], 3, '0', STR_PAD_LEFT) . '号' .
                (isset($v['current_dept_name']) && $v['current_dept_name'] !== $v['first_dept_name'] ? '（现' . e($v['current_dept_name']) . '）' : '') . '</span>' .
                '<span class="badge ' . ($v['status'] === 'finished' ? 'badge-success' : ($v['status'] === 'refunded' ? 'badge-gray' : 'badge-warning')) . '">' . e(visit_status_name($v['status'])) . '</span></div>';
            if ($recLine !== '') {
                $html .= '<div class="fs-13 mt-8"><strong>病历：</strong>' . $recLine . '</div>';
            }
            if ($orders) {
                // 开单仅按类型徽章计数（检验x项/检查x项…），明细在病历页大纲栏查看
                $cnt = array('lab' => 0, 'imaging' => 0, 'procedure' => 0, 'prescription' => 0);
                foreach ($orders as $o) {
                    if (isset($cnt[$o['order_type']])) $cnt[$o['order_type']]++;
                }
                $typeNames = array('lab' => '检验', 'imaging' => '检查', 'procedure' => '处置', 'prescription' => '处方');
                $badges = '';
                foreach ($typeNames as $k => $label) {
                    if ($cnt[$k]) {
                        $badges .= '<span class="badge badge-gray" style="margin-right:4px">' . $label . $cnt[$k] . '项</span>';
                    }
                }
                if ($badges !== '') {
                    $html .= '<div class="mt-4"><strong>开单：</strong>' . $badges . '</div>';
                }
            }
            // 操作：查看病历 / 诊断证明三态按钮：
            // 未归档未开具=新增（直接开）｜ 归档未开具=补开（先确认归档/接诊提醒）｜ 已开具=查看
            $hasCert = (int)DB::val('medical', 'SELECT COUNT(*) FROM certificates WHERE visit_id=?', array($v['id'])) > 0;
            // 当前医生是否接诊过该次就诊（结构化病历表与旧镜像表任一有本人文书即算）
            $treated = 0;
            foreach ($records as $r) {
                if ((int)$r['doctor_id'] === (int)$u['id']) { $treated = 1; break; }
            }
            if (!$treated) {
                $treated = (int)DB::val('medical', 'SELECT COUNT(*) FROM patient_records WHERE visit_id=? AND doctor_id=?', array($v['id'], $u['id'])) > 0 ? 1 : 0;
            }
            $html .= '<div class="flex gap-8 mt-8">';
            if ($hasRecord) {
                // 病历已保存：直接打开病历打印预览页（pt_record，A5 病历纸），可再次打印
                $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.print.load(\'/api/print?action=record&visit_id=' . e(oid($v['id'])) . '\',null,\'a5\')">📋 查看病历</button>';
            } else {
                // 病历未保存：提示，不跳转编辑页
                $html .= '<button class="btn btn-outline btn-sm" onclick="Clinic.toast.warning(\'该次就诊病历尚未保存，无法查看\')">📋 查看病历</button>';
            }
            $html .=
                ($hasCert
                    ? '<button class="btn btn-outline btn-sm" onclick="printHistoryCertificate(\'' . e(oid($v['id'])) . '\')">📄 查看诊断证明</button>'
                    : ($v['status'] === 'finished'
                        ? '<button class="btn btn-outline btn-sm" data-treated="' . $treated . '" onclick="archiveCertificateConfirm(this.getAttribute(\'data-treated\')===\'1\',\'' . e(oid($v['id'])) . '\')">📄 补开诊断证明</button>'
                        : '<button class="btn btn-outline btn-sm" onclick="openHistoryCertificate(\'' . e(oid($v['id'])) . '\')">📄 新增诊断证明</button>')) .
                '</div>';
            $html .= '</div>';
        }
        json_ok(array('html' => $html));
        break;

    default:
        json_fail('未知操作');
}
