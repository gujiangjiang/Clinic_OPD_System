<?php
/**
 * ============================================================
 * emr_rules.php — 病历状态机与操作权限统一规则引擎
 * ============================================================
 * 集中定义「当前文书/就诊处于何种状态、能否书写/保存/开单/发会诊/加诊断」，
 * 作为后端各接口判定的【唯一权威来源】；前端 emr_rules.js 为同规则镜像
 * （语义一致，展示层兜底）。后续所有病历相关判定一律调用本引擎，
 * 避免各接口散落判断导致状态区分不一致（如会诊完毕误拦续写保存）。
 *
 * 状态枚举（state）：
 *   visit_finished  诊毕归档，一切只读
 *   consult_editing 会诊处理中且当前文书即该会诊病历（可写）
 *   consult_lock    会诊处理中但当前文书非会诊病历（只读）
 *   consult_done    会诊已完毕的会诊病历（永久只读）
 *   editable        本人当前科室普通文书（可写/可开单/可会诊）
 *   dept_mismatch   本人转科前旧文书（只读，须续写）
 *   others          他人文书（只读）
 *   new             新建中（record_id=0，编辑器已渲染）
 *
 * 前端镜像：public/assets/js/components/emr_rules.js（Clinic.emr.rules）
 * ============================================================ */

/**
 * 计算当前文书状态与操作权限（唯一权威判定）。
 * @param array  $visit  就诊行（registrations 行）
 * @param array  $u      当前用户（需含 id、name；current_dept_id 缺省自动补查）
 * @param array|null $record 当前文书行（patient_records 行）；null 表示新建骨架
 * @return array{state:string,can_write:bool,can_order:bool,can_consult:bool,can_diag:bool,reason:string}
 */
function emr_record_state($visit, $u, $record = null) {
    $state = array(
        'state' => 'others', 'can_write' => false, 'can_order' => false,
        'can_consult' => false, 'can_diag' => false, 'reason' => '',
    );

    // 1) 诊毕归档：一切只读
    if ((string)$visit['status'] === 'finished') {
        return array('state' => 'visit_finished', 'can_write' => false, 'can_order' => false,
            'can_consult' => false, 'can_diag' => false, 'reason' => '该患者已诊毕，病历已归档');
    }

    $uid = (int)$u['id'];

    // 2) 会诊处理中：本就诊存在发给当前医生科室的进行中/待处理会诊
    $cons = get_consult_context($visit, $u);
    if ($cons) {
        if ($record && (int)$record['consultation_id'] === (int)$cons['id']) {
            return array('state' => 'consult_editing', 'can_write' => true, 'can_order' => true,
                'can_consult' => false, 'can_diag' => true, 'reason' => '会诊病历可编辑');
        }
        return array('state' => 'consult_lock', 'can_write' => false, 'can_order' => false,
            'can_consult' => false, 'can_diag' => false, 'reason' => '会诊处理中，其他病历仅只读');
    }

    // 3) 新建骨架（record_id=0）
    if (!$record) {
        return array('state' => 'new', 'can_write' => true, 'can_order' => false,
            'can_consult' => false, 'can_diag' => true, 'reason' => '新建病历编辑中');
    }

    $consultId = (int)(isset($record['consultation_id']) ? $record['consultation_id'] : 0);

    // 4) 会诊文书：未完毕可编辑（他科遗留/非当前科室会诊），已完毕永久只读
    if ($consultId > 0) {
        $cs = DB::one('consultation', 'SELECT status FROM consultations WHERE id=?', array($consultId));
        $st = $cs ? (string)$cs['status'] : 'done';
        if ($st === 'done') {
            return array('state' => 'consult_done', 'can_write' => false, 'can_order' => false,
                'can_consult' => false, 'can_diag' => false, 'reason' => '该会诊已完毕，会诊病历永久只读');
        }
        // 未完毕（pending/doing）但当前医生不在该会诊处理中（如查看者非目标科室）
        // → 会诊文书仍只读（会诊由目标科室处理）；本人若为目标科室则走 consult_editing
        return array('state' => 'consult_lock', 'can_write' => false, 'can_order' => false,
            'can_consult' => false, 'can_diag' => false, 'reason' => '会诊病历处理中，仅目标科室医生可编辑');
    }

    // 5) 普通文书：本人 + 书写科室==就诊当前科室 → 可编辑
    $isMine = (int)$record['doctor_id'] === $uid;
    if (!$isMine) {
        return array('state' => 'others', 'can_write' => false, 'can_order' => false,
            'can_consult' => false, 'can_diag' => false, 'reason' => '他人文书，只读展示');
    }
    $deptMatch = (int)$record['dept_id'] === (int)$visit['current_dept_id'];
    if (!$deptMatch) {
        return array('state' => 'dept_mismatch', 'can_write' => false, 'can_order' => false,
            'can_consult' => false, 'can_diag' => false, 'reason' => '转科前旧文书，当前科室只读');
    }
    return array('state' => 'editable', 'can_write' => true, 'can_order' => true,
        'can_consult' => true, 'can_diag' => true, 'reason' => '当前科室文书可编辑');
}

/**
 * 当前医生在本就诊下是否有可编辑的【已保存】文书（开单/发会诊等需绑定场景）。
 * 规则：调用 emr_record_state 后取 get_editable_record 语义——
 * 会诊处理中 → 仅会诊病历；普通模式 → 书写科室==当前科室（或未完毕会诊文书）。
 * @return array|null 可编辑文书行（含 id），无则 null
 */
function emr_find_editable_record($visit, $u) {
    return get_editable_record($visit, $u);
}
