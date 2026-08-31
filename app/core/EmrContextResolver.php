<?php
/**
 * ============================================================
 * EmrContextResolver.php — 统一病历上下文解析器（SSOT）
 * ============================================================
 * 核心职责：
 * 1. 接收就诊 + 用户 + 当前文书，唯一权威计算当前上下文状态。
 * 2. 输出 Active Context 结构（含可写容器、派生能力集、熔断原因）。
 * 3. 提供 assertCanWrite 硬拦截断言，供所有写操作入口调用。
 *
 * 设计原则：
 * - 载体依赖：所有医嘱/处方/处置/诊断/会诊/证明，必须绑定到
 *   「当前处于可编辑状态的病历载体」（Active Writable Container）。
 * - 两层漏斗：第一层根判定（是否存在可写容器）→ 第二层归属判定
 *   （target.container_id === active.container_id）。
 * - 状态单向派生：UI 能力完全由本 Context 派生，不维护独立布尔状态。
 * ============================================================ */
class EmrContextResolver {

    /**
     * 获取当前活跃上下文（唯一权威入口）。
     * @param array $visit   就诊行（registrations 行，需含 id/status/current_dept_id）
     * @param array $u       当前用户（需含 id/name/role；current_dept_id 缺省自动补查）
     * @param array|null $record 当前文书行（patient_records 行），null 表示新建骨架
     * @return array{active:array,capabilities:array,lock_reason:string}
     */
    public static function resolve($visit, $u, $record = null) {
        // ===== 第一层：根判定 =====
        // 1) 诊毕归档 → 全链路熔断
        if ((string)$visit['status'] === 'finished') {
            return self::frozen('visit_finished', '该患者已诊毕，病历已归档，不可进行任何操作');
        }

        $uid = (int)$u['id'];

        // 2) 会诊处理中（本就诊存在发给当前医生科室的 pending/doing 会诊）
        $cons = get_consult_context($visit, $u);
        if ($cons) {
            $isConsultRecord = $record && (int)$record['consultation_id'] === (int)$cons['id'];
            if ($isConsultRecord) {
                // 会诊病历可编辑
                return self::writable('consultation', (int)$record['id'], array(
                    'can_write' => true, 'can_order' => true, 'can_delete_order' => true,
                    'can_consult' => false, 'can_append' => true, 'can_issue_cert' => true,
                    'can_diag' => true,
                ), '会诊病历可编辑');
            }
            // 会诊处理中但无会诊病历（尚未创建）：允许创建→可写（新建骨架状态）
            if (!$record) {
                return self::writable('consultation', null, array(
                    'can_write' => true, 'can_order' => false, 'can_delete_order' => false,
                    'can_consult' => false, 'can_append' => true, 'can_issue_cert' => false,
                    'can_diag' => true,
                ), '会诊病历编辑中（尚未保存）');
            }
            // 会诊处理中查看非会诊病历 → 只读
            return self::frozen('consult_lock', '会诊处理中，其他病历仅可查看（只读）');
        }

        // 3) 无已保存文书（新建骨架）
        if (!$record) {
            return self::writable('initial', null, array(
                'can_write' => true, 'can_order' => false, 'can_delete_order' => false,
                'can_consult' => false, 'can_append' => true, 'can_issue_cert' => false,
                'can_diag' => true,
            ), '新建病历编辑中');
        }

        $recordId = (int)$record['id'];
        $consultId = (int)($record['consultation_id'] ?? 0);

        // 4) 会诊文书：已完毕 → 永久只读
        if ($consultId > 0) {
            $cs = ConsultationRepository::statusById($consultId);
            $st = $cs ? (string)$cs['status'] : 'done';
            if ($st === 'done') {
                return self::frozen('consult_done', '该会诊已完毕，会诊病历永久只读');
            }
            // 未完毕但当前医生不在该会诊处理中 → 只读
            return self::frozen('consult_lock', '会诊病历处理中，仅目标科室医生可编辑');
        }

        // 5) 他人文书 → 只读
        if ((int)$record['doctor_id'] !== $uid) {
            return self::frozen('others', '他人文书，只读展示');
        }

        // 6) 医生当前科室判定
        $docDept = (int)($u['current_dept_id'] ?? 0);
        if ($docDept <= 0) {
            $docRow = UserRepository::currentDept($uid);
            $docDept = $docRow ? (int)$docRow['current_dept_id'] : 0;
        }
        // 医生当前科室 != 就诊当前科室 → 跨科室绝对只读
        if ($docDept <= 0 || $docDept !== (int)$visit['current_dept_id']) {
            return self::frozen('others', '跨科室病历，当前科室只读');
        }

        // 7) 转科前旧文书（书写科室 != 就诊当前科室）→ 只读
        if ((int)$record['dept_id'] !== (int)$visit['current_dept_id']) {
            return self::frozen('dept_mismatch', '转科前旧文书，当前科室只读');
        }

        // ===== 通过全部校验 → 可编辑 =====
        return self::writable('main_record', $recordId, array(
            'can_write' => true, 'can_order' => true, 'can_delete_order' => true,
            'can_consult' => true, 'can_append' => true, 'can_issue_cert' => true,
            'can_diag' => true,
        ), '当前科室文书可编辑');
    }

    /**
     * 硬拦截断言：当前是否可写，不可写则 json_fail。
     * @param array $visit   就诊行
     * @param array $u       当前用户
     * @param array|null $record 当前文书行
     * @param int|null $targetContainerId 目标操作对象的 container_id（删除/修改校验）
     */
    public static function assertCanWrite($visit, $u, $record = null, $targetContainerId = null) {
        $ctx = self::resolve($visit, $u, $record);
        if (!$ctx['active']['writable']) {
            json_fail($ctx['lock_reason']);
        }
        // 第二层：归属判定——删除/修改时必须校验 container_id 匹配
        if ($targetContainerId !== null) {
            $activeId = $ctx['active']['container_id'];
            if ((int)$targetContainerId !== (int)$activeId) {
                json_fail('该操作对象不属于当前活跃病历，不可跨段落操作');
            }
        }
        return $ctx;
    }

    // ==================== 私有辅助 ====================

    /** 构建可写上下文 */
    private static function writable($type, $containerId, $caps, $reason) {
        return array(
            'active' => array(
                'writable' => true,
                'container_type' => $type,
                'container_id' => $containerId,
            ),
            'capabilities' => $caps,
            'lock_reason' => $reason,
        );
    }

    /** 构建只读上下文（全链路熔断） */
    private static function frozen($type, $reason) {
        return array(
            'active' => array(
                'writable' => false,
                'container_type' => $type,
                'container_id' => null,
            ),
            'capabilities' => array(
                'can_write' => false, 'can_order' => false, 'can_delete_order' => false,
                'can_consult' => false, 'can_append' => false, 'can_issue_cert' => false,
                'can_diag' => false,
            ),
            'lock_reason' => $reason,
        );
    }
}