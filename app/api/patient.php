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
 * 数据访问统一委托 PatientRepository，本文件不含原生 SQL。
 * ============================================================ */
require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 按身份证检索患者 ---------------- */
    case 'by_card':
        $idCard = get('id_card', '');
        json_ok(array('patient' => PatientRepository::byIdCard($idCard)));
        break;

    /* ---------------- 患者查询（ID/身份证/姓名） ---------------- */
    case 'search':
        $kw = get('kw', '');
        if ($kw === '') {
            json_ok(array('list' => array()));
        }
        json_ok(array('list' => PatientRepository::search($kw)));
        break;

    /* ---------------- 患者信息修改表单（服务端渲染，字典来自 options_data.php） ---------------- */
    case 'edit_form':
        $kw = get('kw', '');
        $p = PatientRepository::byCardOrNo($kw);
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
        PatientRepository::updateProfile($patientNo, array(
            'phone' => post('phone'), 'ethnicity' => post('ethnicity'),
            'marital' => post('marital'), 'occupation' => post('occupation'),
            'work_unit' => post('work_unit'), 'address' => post('address'),
        ));
        json_ok(array(), '患者信息已更新');
        break;

    /* ---------------- 患者全部就诊历史（结构化数据：左右两栏弹窗） ----------------
     * 返回 patient（顶部信息条）+ visits[]（左侧列表：时间/科室/状态/标志位）。
     * 病历正文由前端按选中就诊另取 /api/print?action=record 只读渲染；
     * 诊断证明三态（新增/补开/查看）由前端据 has_cert/finished 判定。 */
    case 'history':
        $patientNo = get('patient_no', '');
        $u = Auth::user();   // 接诊判定需要当前医生 id
        $p = PatientRepository::byPatientNo($patientNo);
        if (!$p) json_fail('未找到该患者');
        $visits = PatientRepository::visitsOf($patientNo);
        $list = array();
        foreach ($visits as $v) {
            $list[] = array(
                'code' => oid($v['id']),
                'date' => substr($v['registered_at'], 0, 10),
                'time' => substr($v['registered_at'], 11, 5),
                'dept_name' => $v['first_dept_name'],
                'current_dept_name' => $v['current_dept_name'],
                'visit_seq' => (int)$v['visit_seq'],
                'status' => $v['status'],
                'status_name' => visit_status_name($v['status']),
                'has_record' => PatientRepository::visitHasRecord($v['id']) ? 1 : 0,
                'has_cert' => PatientRepository::visitHasCertificate($v['id']) ? 1 : 0,
                'treated' => PatientRepository::visitTreatedBy($v['id'], $u['id']) ? 1 : 0,
                'finished' => $v['status'] === 'finished' ? 1 : 0,
            );
        }
        json_ok(array(
            'patient' => array(
                'name' => $p['name'],
                'patient_no' => $p['patient_no'],
                'gender' => $p['gender'],
                'age_fmt' => age_format($p['birth_date']),
                'id_card' => $p['id_card'],
                'phone' => $p['phone'],
            ),
            'visits' => $list,
        ));
        break;

    default:
        json_fail('未知操作');
}