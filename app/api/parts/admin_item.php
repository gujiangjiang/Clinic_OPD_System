<?php
/**
 * ============================================================
 * parts/admin_item.php v1.1.0 — 管理端：检验/检查项目与分类
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分：
 *   1. item_list / item_form / item_save / item_delete  项目管理
 *   2. cat_list / cat_add / cat_delete                  项目分类管理
 * 项目表单由 includes/forms.php 统一渲染（检验科/影像科共用）。
 * 新增项目默认待审核（pending），在审核中心通过后方可开单。
 * ============================================================ */

/**
 * 处理检验/检查项目管理动作
 * @param string $action 动作名
 */
function admin_part_item($action) {
    $u = Auth::user();

    /* ==================== 项目列表 ==================== */
    if ($action === 'item_list') {
        $type = get('type', 'lab');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $rows = DB::q('lab', "SELECT * FROM $table ORDER BY category, id");
        $html = '<div class="fs-13 text-muted mb-8">' . ($type === 'lab' ? '检验项目' : '检查项目') . '共 ' . count($rows) . ' 项</div>';
        if (!$rows) {
            $html .= '<div class="empty">暂无项目，请先添加</div>';
        } else {
            $html .= '<div class="table-wrap"><table class="table"><thead><tr>' .
                '<th>名称</th><th>分类</th><th>价格</th>' .
                ($type === 'lab' ? '<th>单位</th><th>正常范围</th><th>危急值</th>' : '') .
                '<th>描述</th><th>状态</th><th>操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>' .
                    '<td class="fw-600">' . e($r['name']) . '</td>' .
                    '<td>' . e($r['category']) . '</td>' .
                    '<td>¥' . money($r['price']) . '</td>' .
                    ($type === 'lab' ? '<td>' . e($r['unit']) . '</td><td class="fs-12">' . e($r['normal_range']) . '</td>' .
                        '<td class="fs-12">' . e(($r['critical_low'] !== '' ? '低' . $r['critical_low'] . ' ' : '') . ($r['critical_high'] !== '' ? '高' . $r['critical_high'] : '')) . '</td>' : '') .
                    '<td class="fs-12 text-muted">' . e(mb_substr($r['description'], 0, 20)) . '</td>' .
                    '<td>' . ($r['status'] === 'approved' ? '<span class="badge badge-success">可用</span>' : '<span class="badge badge-warning">待审核</span>') . '</td>' .
                    '<td><div class="flex gap-4">' .
                    '<button class="btn btn-outline btn-sm" onclick="loadModal(\'/api/admin\',{action:\'item_form\',type:\'' . $type . '\',id:' . (int)$r['id'] . '},\'编辑项目\')">编辑</button>' .
                    '<button class="btn btn-outline btn-sm" onclick="delItem(\'' . $type . '\',' . (int)$r['id'] . ')">删除</button></div></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        json_ok(array('html' => $html));
    }

    /* ==================== 项目表单（共享模块渲染） ==================== */
    if ($action === 'item_form') {
        $type = get('type', 'lab');
        $id = (int)get('id', 0);
        json_ok(array('html' => form_item($type, $id)));
    }

    /* ==================== 保存项目 ==================== */
    if ($action === 'item_save') {
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        $name = post('name');
        $category = post('category');
        $price = (float)post('price', 0);
        if ($name === '') json_fail('请填写项目名称');
        if ($id > 0) {
            if ($type === 'lab') {
                DB::exec('lab', 'UPDATE lab_items SET category=?, name=?, unit=?, price=?, normal_range=?, critical_low=?, critical_high=?, description=? WHERE id=?', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), $id,
                ));
            } else {
                DB::exec('lab', 'UPDATE exam_items SET category=?, name=?, price=?, description=? WHERE id=?', array($category, $name, $price, post('description'), $id));
            }
        } else {
            // 新增项目默认待审核
            if ($type === 'lab') {
                $newId = DB::insert('lab', 'INSERT INTO lab_items(category, name, unit, price, normal_range, critical_low, critical_high, description, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', array(
                    $category, $name, post('unit'), $price, post('normal_range'), post('critical_low'), post('critical_high'), post('description'), 'pending', now_str(),
                ));
            } else {
                $newId = DB::insert('lab', 'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)', array(
                    $category, $name, $price, post('description'), 'pending', now_str(),
                ));
            }
            DB::insert('core', 'INSERT INTO audits(type, ref_id, title, content, status, proposer, proposer_id, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
                $type === 'lab' ? 'item_lab' : 'item_exam', $newId,
                ($type === 'lab' ? '检验项目添加' : '检查项目添加') . '：' . $name,
                '新增项目「' . $name . '」（分类：' . $category . '，价格：¥' . money($price) . '），请审核',
                'pending', $u['name'], $u['id'], now_str(),
            ));
            json_ok(array(), '项目已添加，请到【审核中心】审核后即可使用');
        }
        json_ok(array(), '项目已保存');
    }

    /* ==================== 删除项目 ==================== */
    if ($action === 'item_delete') {
        $type = post('type', 'lab');
        $id = (int)post('id');
        $table = $type === 'lab' ? 'lab_items' : 'exam_items';
        DB::exec('lab', "DELETE FROM $table WHERE id=?", array($id));
        json_ok(array(), '项目已删除');
    }

    /* ==================== 项目分类管理 ==================== */
    if ($action === 'cat_list') {
        $type = get('type', 'lab');
        $rows = DB::q('lab', 'SELECT * FROM item_categories WHERE ctype=? ORDER BY sort, id', array($type));
        json_ok(array('list' => array_map(function ($c) {
            return array('id' => (int)$c['id'], 'name' => $c['name']);
        }, $rows)));
    }

    if ($action === 'cat_add') {
        $type = post('type', 'lab');
        $name = post('name');
        if ($name === '') json_fail('请输入分类名称');
        DB::insert('lab', 'INSERT INTO item_categories(ctype, name, sort) VALUES(?,?,0)', array($type, $name));
        json_ok(array(), '分类已添加');
    }

    if ($action === 'cat_delete') {
        $id = (int)post('id');
        DB::exec('lab', 'DELETE FROM item_categories WHERE id=?', array($id));
        json_ok(array(), '分类已删除');
    }

    json_fail('未知操作');
}
