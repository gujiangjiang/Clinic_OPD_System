<?php
/**
 * ============================================================
 * template.php — 病历模板接口
 * ============================================================
 * 说明：
 * 1. 模板范围：personal 个人 / department 全科 / hospital 全院
 * 2. 个人模板即时生效；全科/全院模板需管理员审核通过后生效
 * 3. 医生可查看：本人个人模板 + 已审核的全科/全院模板
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

switch ($action) {

    /* ==================== 可用模板列表 ==================== */
    case 'list':
        $deptId = (int)get('dept_id', 0);
        // 本人个人模板（全部）
        $list = DB::q('medical', "SELECT * FROM templates WHERE doctor_id=? AND status='approved' ORDER BY id DESC", array($u['id']));
        // 已审核的全科/全院模板
        $share = DB::q('medical', "SELECT * FROM templates WHERE doctor_id<>? AND scope IN ('department','hospital') AND status='approved' ORDER BY id DESC", array($u['id']));
        $merged = array_merge($list, $share);
        $out = array();
        foreach ($merged as $t) {
            $out[] = array(
                'id' => (int)$t['id'],
                'name' => $t['name'],
                'scope' => $t['scope'],
                'content' => $t['content'],
                'created_at' => $t['created_at'],
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ==================== 新建模板 ==================== */
    case 'save':
        $name = post('name');
        $scope = post('scope', 'personal');
        $content = post('content', '{}');
        if ($name === '') json_fail('请填写模板名称');
        if (!in_array($scope, array('personal', 'department', 'hospital'), true)) {
            $scope = 'personal';
        }
        // 个人模板即时生效；全科/全院进入审核流程
        $status = ($scope === 'personal') ? 'approved' : 'pending';
        $tplId = DB::insert('medical', 'INSERT INTO templates(doctor_id, name, scope, content, status, created_at) VALUES(?,?,?,?,?,?)', array(
            $u['id'], $name, $scope, $content, $status, now_str(),
        ));
        if ($status === 'pending') {
            $scopeNames = array('department' => '全科', 'hospital' => '全院');
            DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, data, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
                'template', $tplId,
                '病历模板审核：' . $name,
                '医生 ' . $u['name'] . ' 提交了【' . $scopeNames[$scope] . '】病历模板「' . $name . '」，请审核',
                json_encode(array('scope' => $scope), JSON_UNESCAPED_UNICODE),
                'pending', $u['name'], $u['id'], now_str(),
            ));
        }
        json_ok(array('id' => $tplId, 'status' => $status), $status === 'pending' ? '模板已提交，全科/全院模板需管理员审核后生效' : '模板已保存');
        break;

    /* ==================== 删除模板（仅本人） ==================== */
    case 'delete':
        $id = (int)post('id');
        DB::exec('medical', 'DELETE FROM templates WHERE id=? AND doctor_id=?', array($id, $u['id']));
        json_ok(array(), '模板已删除');
        break;

    default:
        json_fail('未知操作');
}
