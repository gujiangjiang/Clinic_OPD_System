<?php
/**
 * ============================================================
 * package.php — 快速开单套餐接口（v1.0.0）
 * ============================================================
 * 说明：套餐 = 快速开单预置组合，把常用的检验/检查/处方/处置制作成套餐，一键添加。
 * 1. 套餐类型：lab 检验套餐 / imaging 检查套餐 / procedure 处置套餐 / prescription 处方套餐
 * 2. 范围：personal 个人（免审）/ dept 科室 / hospital 全院
 *    - 个人套餐免审即用；科室/全院套餐提交管理员审核
 *    - 审核期间个人可用；审核通过科室/全院可用；驳回后个人依然可用（降级为个人）
 * 3. 内容：content_json.items 数组，元素结构与开单 SELECTED 一致
 *    - 检验：展开为单个检验项目（组合内项目以单个检验形式入套餐）
 *    - 处方：含剂量/频次/途径/子医嘱（sub_items）
 * 4. 鉴权：非本人且非管理员严禁修改/删除。
 * ============================================================ */
require __DIR__ . '/_init.php';

$u = Auth::user();

/** 套餐类型-角色权限：医生可管理/使用全部四类；管理员全部 */
function pkg_type_allowed($role, $type) {
    return in_array($type, array('lab', 'imaging', 'procedure', 'prescription'), true);
}

/** 套餐类型校验：不满足角色权限直接拒绝 */
function pkg_assert_type($u, $type) {
    if (!pkg_type_allowed($u['role'], $type)) {
        json_fail('无权限访问该类型的套餐');
    }
}

/** 套餐类型中文名 */
function pkg_type_label($type) {
    $map = array('lab' => '检验套餐', 'imaging' => '检查套餐', 'procedure' => '处置套餐', 'prescription' => '处方套餐');
    return isset($map[$type]) ? $map[$type] : '套餐';
}

switch ($action) {

    /* ==================== 可用套餐列表（分页检索，滚动动态加载） ====================
     * 医生可见：本人个人套餐（任意状态）+ 本人待审核套餐 + 已发布全院 + 已发布本人科室套餐
     * 管理员可见全部
     */
    case 'list':
        $type = get('type', 'lab');
        $page = max(1, (int)get('page', 1));
        $pageSize = max(1, min(100, (int)get('size', 20)));
        $kw = trim(get('kw', ''));
        if (!pkg_type_allowed($u['role'], $type)) $type = 'lab';
        pkg_assert_type($u, $type);
        $like = $kw !== '' ? '%' . $kw . '%' : '';
        $isAdmin = ($u['role'] === 'admin');
        // 可见性过滤条件（独立拼装，供总数与列表共用）
        $visConds = array("type=?");
        $params = array($type);
        if (!$isAdmin) {
            $myDepts = user_dept_ids($u);
            $visConds[] = "((scope='personal' AND creator_id=?))";
            $params[] = $u['id'];
            $visConds[] = "((status='pending_review' AND creator_id=?))";
            $params[] = $u['id'];
            $visConds[] = "((scope='hospital' AND status='published'))";
            if ($myDepts) {
                $ph = in_placeholders($myDepts);
                $visConds[] = "((scope='dept' AND status='published' AND id IN (SELECT package_id FROM package_depts WHERE dept_id IN ($ph))))";
                foreach ($myDepts as $d) $params[] = $d;
            }
        }
        $where = implode(' AND ', $visConds);
        if ($kw !== '') {
            $where .= " AND title LIKE ?";
            $params[] = $like;
        }
        $total = (int)OrderRepository::val("SELECT COUNT(*) FROM packages WHERE " . $where, $params);
        $rows = OrderRepository::q("SELECT * FROM packages WHERE " . $where . " ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
        $out = array();
        foreach ($rows as $t) {
            $deptNames = array();
            $links = OrderRepository::q('SELECT dept_id FROM package_depts WHERE package_id=?', array((int)$t['id']));
            if ($links) {
                $dids = array();
                foreach ($links as $l) $dids[] = (int)$l['dept_id'];
                $ph2 = in_placeholders($dids);
                foreach (OrderRepository::q("SELECT id, name FROM departments WHERE id IN ($ph2)", $dids) as $dn) {
                    $deptNames[] = $dn['name'];
                }
            }
            $content = json_decode((string)$t['content_json'], true) ?: array();
            $items = isset($content['items']) && is_array($content['items']) ? $content['items'] : array();
            $out[] = array(
                'id' => (int)$t['id'],
                'title' => (string)$t['title'],
                'type' => (string)$t['type'],
                'scope' => (string)$t['scope'],
                'creator_id' => (int)$t['creator_id'],
                'creator_name' => (string)$t['creator_name'],
                'status' => (string)$t['status'],
                'dept_names' => $deptNames,
                'item_count' => count($items),
                'total_price' => array_reduce($items, function ($c, $it) {
                    return $c + (float)(isset($it['price']) ? $it['price'] : 0) * max(1, (int)(isset($it['quantity']) ? $it['quantity'] : 1));
                }, 0),
                'created_at' => (string)$t['created_at'],
            );
        }
        json_ok(array('list' => $out, 'total' => $total, 'has_more' => ($page * $pageSize) < $total));
        break;

    /* ==================== 单条套餐详情（编辑回填 / 应用加载） ==================== */
    case 'get':
        $id = (int)get('id');
        $t = OrderRepository::one('SELECT * FROM packages WHERE id=?', array($id));
        if (!$t) json_fail('套餐不存在');
        pkg_assert_type($u, (string)$t['type']);
        $forApply = (int)get('for_apply', 0);
        if ($forApply === 1) {
            // 应用套餐：可见性过滤须与 list 一致（防越权读取他人私有套餐）
            $isVisible = false;
            $myDepts = user_dept_ids($u);
            if ((int)$t['creator_id'] === (int)$u['id'] && $t['scope'] === 'personal') $isVisible = true;
            elseif ((int)$t['creator_id'] === (int)$u['id'] && $t['status'] === 'pending_review') $isVisible = true;
            elseif ((int)$t['creator_id'] === (int)$u['id'] && $t['status'] === 'rejected' && $t['scope'] === 'dept') $isVisible = true;   // 驳回降级为个人可用
            elseif ($t['scope'] === 'hospital' && $t['status'] === 'published') $isVisible = true;
            elseif ($t['scope'] === 'dept' && $t['status'] === 'published' && $myDepts) {
                $ph = in_placeholders($myDepts);
                $cnt = (int)OrderRepository::val("SELECT COUNT(*) FROM package_depts WHERE package_id=? AND dept_id IN ($ph)", array_merge(array($id), $myDepts));
                if ($cnt > 0) $isVisible = true;
            }
            if (!$isVisible) json_fail('无权使用该套餐');
        } else {
            if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') {
                json_fail('无权编辑该套餐');
            }
        }
        $links = OrderRepository::q('SELECT dept_id FROM package_depts WHERE package_id=?', array($id));
        $deptIds = array();
        foreach ($links as $l) $deptIds[] = (int)$l['dept_id'];
        $content = json_decode((string)$t['content_json'], true) ?: array();
        $items = isset($content['items']) && is_array($content['items']) ? $content['items'] : array();
        json_ok(array(
            'package' => array(
                'id' => (int)$t['id'],
                'title' => (string)$t['title'],
                'type' => (string)$t['type'],
                'scope' => (string)$t['scope'],
                'status' => (string)$t['status'],
                'items' => $items,
                'dept_ids' => $deptIds,
            ),
        ));
        break;

    /* ==================== 保存套餐（新建/编辑） ==================== */
    case 'save':
        $id = (int)post('id', 0);
        $title = trim((string)post('title', ''));
        $type = post('type', 'lab');
        $scope = post('scope', 'personal');
        $items = post('items', '[]');
        if (!pkg_type_allowed($u['role'], $type)) json_fail('无权限保存该类型套餐');
        pkg_assert_type($u, $type);
        if (!in_array($scope, array('personal', 'dept', 'hospital'), true)) $scope = 'personal';
        if ($title === '') json_fail('请填写套餐名称');
        $itemsArr = json_decode((string)$items, true);
        if (!is_array($itemsArr)) $itemsArr = array();
        // 内容消毒：仅保留套餐字段白名单（防止注入/冗余字段）
        $clean = array();
        foreach ($itemsArr as $it) {
            if (!is_array($it)) continue;
            $itemId = (int)(isset($it['item_id']) ? $it['item_id'] : 0);
            $subOf = (int)(isset($it['sub_of']) ? $it['sub_of'] : 0);
            $clean[] = array(
                'item_id' => $itemId,
                'sub_of' => $subOf,
                'item_name' => (string)(isset($it['item_name']) ? $it['item_name'] : ''),
                'spec' => (string)(isset($it['spec']) ? $it['spec'] : ''),
                'unit' => (string)(isset($it['unit']) ? $it['unit'] : ''),
                'company_short' => (string)(isset($it['company_short']) ? $it['company_short'] : ''),
                'price' => (float)(isset($it['price']) ? $it['price'] : 0),
                'quantity' => max(1, (int)(isset($it['quantity']) ? $it['quantity'] : 1)),
                'single_dose' => (string)(isset($it['single_dose']) ? $it['single_dose'] : ''),
                'frequency' => (string)(isset($it['frequency']) ? $it['frequency'] : ''),
                'route' => (string)(isset($it['route']) ? $it['route'] : ''),
                'nurse_required' => (int)(isset($it['nurse_required']) ? $it['nurse_required'] : 0),
                'is_skin_test' => (int)(isset($it['is_skin_test']) ? $it['is_skin_test'] : 0),
                'skin_test_item_id' => (int)(isset($it['skin_test_item_id']) ? $it['skin_test_item_id'] : 0),
                'spec_dose' => (float)(isset($it['spec_dose']) ? $it['spec_dose'] : 0),
                'spec_dose_unit' => (string)(isset($it['spec_dose_unit']) ? $it['spec_dose_unit'] : ''),
                'spec_pack_qty' => (int)(isset($it['spec_pack_qty']) ? $it['spec_pack_qty'] : 1),
                'spec_pack_unit' => (string)(isset($it['spec_pack_unit']) ? $it['spec_pack_unit'] : ''),
                'single_use_qty' => (float)(isset($it['single_use_qty']) ? $it['single_use_qty'] : 1),
            );
        }
        if (!$clean) json_fail('请先添加套餐项目');
        // 处方套餐校验：主药需剂量/频次/途径；子药需剂量
        if ($type === 'prescription') {
            foreach ($clean as $i => $it) {
                if ((int)$it['sub_of'] === 0) {
                    if (trim((string)$it['single_dose']) === '') json_fail('处方套餐第 ' . ($i + 1) . ' 项请填写剂量');
                    if (trim((string)$it['frequency']) === '') json_fail('处方套餐第 ' . ($i + 1) . ' 项请选择用药频次');
                    if (trim((string)$it['route']) === '') json_fail('处方套餐第 ' . ($i + 1) . ' 项请选择使用途径');
                } else {
                    if (trim((string)$it['single_dose']) === '') json_fail('处方套餐子医嘱【' . $it['item_name'] . '】请填写剂量');
                }
            }
        }

        $isAdmin = ($u['role'] === 'admin');
        // 管理员创建：仅限 hospital/dept
        if ($isAdmin && $scope === 'personal') json_fail('管理员套餐适用范围仅限全院或科室');
        $status = $isAdmin ? 'published' : ($scope === 'personal' ? 'published' : 'pending_review');
        // 编辑：越权防护
        if ($id > 0) {
            $old = OrderRepository::one('SELECT * FROM packages WHERE id=?', array($id));
            if (!$old) json_fail('套餐不存在');
            if ((int)$old['creator_id'] !== (int)$u['id'] && !$isAdmin) json_fail('无权修改该套餐');
            if ($old['status'] === 'pending_review') json_fail('套餐正在审核中，审核通过或驳回后方可修改');
            // 驳回后个人可继续编辑：保持驳回状态，重新保存后再进审核
            if ($old['status'] === 'rejected') $status = ($scope === 'personal') ? 'published' : 'pending_review';
            OrderRepository::exec('UPDATE packages SET title=?, type=?, scope=?, status=?, content_json=?, updated_at=? WHERE id=?',
                array($title, $type, $scope, $status, json_encode(array('items' => $clean), JSON_UNESCAPED_UNICODE), now_str(), $id));
            $pkgId = $id;
        } else {
            $pkgId = OrderRepository::insert('INSERT INTO packages(title, type, scope, creator_id, creator_name, status, content_json, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
                $title, $type, $scope, $u['id'], $u['name'], $status,
                json_encode(array('items' => $clean), JSON_UNESCAPED_UNICODE), now_str(), now_str(),
            ));
        }
        // 科室关联（仅 dept 范围）
        $deptIds = array();
        OrderRepository::exec('DELETE FROM package_depts WHERE package_id=?', array($pkgId));
        if ($scope === 'dept') {
            foreach (explode(',', (string)post('dept_ids', '')) as $d) {
                $d = (int)$d;
                if ($d > 0) $deptIds[$d] = true;
            }
            if ($deptIds) {
                $ph = in_placeholders($deptIds);
                foreach (OrderRepository::q("SELECT id FROM departments WHERE status=1 AND type IN ('clinic','emergency') AND id IN ($ph)", array_keys($deptIds)) as $dd) {
                    OrderRepository::insert('INSERT OR IGNORE INTO package_depts(package_id, dept_id) VALUES(?,?)', array($pkgId, (int)$dd['id']));
                }
            }
        }
        // 非管理员提交的 dept/hospital 套餐进入审核中心
        $auditType = 'package';
        if ($status === 'pending_review') {
            $scopeName = $scope === 'hospital' ? '全院' : '科室';
            $existing = OrderRepository::one("SELECT id FROM audits WHERE type=? AND ref_id=? AND status='pending'", array($auditType, $pkgId));
            $auditData = json_encode(array(
                'title' => $title, 'scope' => $scope, 'dept_ids' => $deptIds ? array_keys($deptIds) : array(),
                'type' => $type,
            ), JSON_UNESCAPED_UNICODE);
            $typeLabel = pkg_type_label($type);
            if ($existing) {
                OrderRepository::exec('UPDATE audits SET title=?, content=?, data=?, proposer=?, proposer_id=?, created_at=? WHERE id=?', array(
                    $typeLabel . '待审核：' . $title, '提交' . $scopeName . $typeLabel . '「' . $title . '」，请在审核中心查看详情并审核', $auditData, $u['name'], $u['id'], now_str(), (int)$existing['id'],
                ));
            } else {
                submit_audit($auditType, $pkgId, $typeLabel . '待审核：' . $title,
                    '提交' . $scopeName . $typeLabel . '「' . $title . '」，请在审核中心查看详情并审核',
                    array('data' => $auditData));
            }
            send_msg('admin', 0, '待审核提醒',
                '医生 ' . $u['name'] . ' 提交了' . $scopeName . $typeLabel . '「' . $title . '」待审核，请前往审核中心处理',
                '', '', array('msg_type' => 'system', 'link_url' => '/admin/review'));
        } else {
            OrderRepository::exec("UPDATE audits SET status='handled', handled_by=?, handled_at=? WHERE type=? AND ref_id=? AND status='pending'", array($u['name'], now_str(), $auditType, $pkgId));
        }
        json_ok(array('id' => $pkgId, 'status' => $status),
            $status === 'pending_review' ? '套餐已提交，科室/全院套餐需管理员在【审核中心】审核后生效' : '套餐已保存');
        break;

    /* ==================== 删除套餐（越权防护） ==================== */
    case 'delete':
        $id = (int)post('id');
        $t = OrderRepository::one('SELECT * FROM packages WHERE id=?', array($id));
        if (!$t) json_fail('套餐不存在');
        pkg_assert_type($u, (string)$t['type']);
        if ((int)$t['creator_id'] !== (int)$u['id'] && $u['role'] !== 'admin') json_fail('无权删除该套餐');
        if ($t['status'] === 'pending_review') json_fail('套餐正在审核中，审核通过或驳回后方可删除');
        OrderRepository::exec('DELETE FROM packages WHERE id=?', array($id));
        OrderRepository::exec('DELETE FROM package_depts WHERE package_id=?', array($id));
        json_ok(array(), '套餐已删除');
        break;

    /* ==================== 套餐内容目录检索（新建/编辑套餐弹窗搜索栏用，分页滚动加载） ====================
     * 与开单目录同一数据源/结构：lab 含单个+组合、imaging/procedure 项目、prescription 药品
     * （检验组合返回 is_group=1 + member_ids，前端点击组合时展开为单个检验项目）
     */
    case 'catalog':
        // 复用开单目录接口逻辑（order_read.php catalog 已支持分页+搜索+筛选）
        // 本动作实现与 order catalog 一致，但返回按套餐用途加工：无需要联动字典
        $type = get('type', 'lab');
        if (!pkg_type_allowed($u['role'], $type)) $type = 'lab';
        pkg_assert_type($u, $type);
        $page = max(1, (int)get('page', 1));
        $pageSize = max(1, min(100, (int)get('size', 20)));
        $kw = trim(get('kw', ''));
        $like = $kw !== '' ? '%' . $kw . '%' : '';
        $list = array();
        $total = 0;
        $dicts = array('frequencies' => array(), 'routes' => array());
        if ($type === 'lab') {
            $where = "status='approved'";
            $params = array();
            if ($kw !== '') { $where .= " AND name LIKE ?"; $params[] = $like; }
            $total = (int)OrderRepository::val("SELECT COUNT(*) FROM lab_items WHERE $where", $params);
            $rows = OrderRepository::q("SELECT * FROM lab_items WHERE $where ORDER BY category, id LIMIT ? OFFSET ?",
                array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
            foreach ($rows as $r) {
                if (!(int)$r['is_group']) {
                    $list[] = array(
                        'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                        'unit' => $r['unit'], 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
                        'is_group' => 0, 'members' => '', 'member_ids' => '',
                    );
                } else {
                    $mNames = array();
                    $mIds = array();
                    $mItems = array();
                    foreach (OrderRepository::q('SELECT id, name, price, unit, category FROM lab_items WHERE id IN (SELECT item_id FROM lab_group_members WHERE group_id=?) ORDER BY id', array($r['id'])) as $m) {
                        $mNames[] = $m['name'];
                        $mIds[] = (int)$m['id'];
                        $mItems[] = array(
                            'id' => (int)$m['id'], 'name' => $m['name'], 'price' => (float)$m['price'],
                            'unit' => $m['unit'], 'category_name' => $m['category'], 'spec' => '', 'stock' => 0,
                            'is_group' => 0, 'members' => '', 'member_ids' => '', 'member_items' => array(),
                        );
                    }
                    $list[] = array(
                        'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                        'unit' => '', 'category_name' => $r['category'],
                        'spec' => implode('、', $mNames), 'stock' => 0,
                        'is_group' => 1, 'members' => implode('、', $mNames),
                        'member_ids' => implode(',', $mIds),
                        'member_items' => $mItems,
                    );
                }
            }
        } elseif ($type === 'imaging') {
            $where = "status='approved'";
            $params = array();
            if ($kw !== '') { $where .= " AND name LIKE ?"; $params[] = $like; }
            $total = (int)OrderRepository::val("SELECT COUNT(*) FROM exam_items WHERE $where", $params);
            $rows = OrderRepository::q("SELECT * FROM exam_items WHERE $where ORDER BY category, id LIMIT ? OFFSET ?",
                array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'unit' => '', 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
                );
            }
        } elseif ($type === 'procedure') {
            $where = "status='approved'";
            $params = array();
            if ($kw !== '') { $where .= " AND name LIKE ?"; $params[] = $like; }
            $total = (int)OrderRepository::val("SELECT COUNT(*) FROM disposal_items WHERE $where", $params);
            $rows = OrderRepository::q("SELECT * FROM disposal_items WHERE $where ORDER BY id LIMIT ? OFFSET ?",
                array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['fee'],
                    'unit' => '次', 'category_name' => '', 'spec' => '', 'stock' => 0,
                    'nurse_required' => (int)$r['is_nurse'],
                );
            }
        } elseif ($type === 'prescription') {
            $where = "status='approved' AND qty > 0";
            $params = array();
            if ($kw !== '') { $where .= " AND (name LIKE ? OR vendor_short LIKE ?)"; $params = array($like, $like); }
            $total = (int)OrderRepository::val("SELECT COUNT(*) FROM drugs WHERE $where", $params);
            $rows = OrderRepository::q("SELECT * FROM drugs WHERE $where ORDER BY category, id LIMIT ? OFFSET ?",
                array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'spec' => $r['spec'], 'unit' => $r['package_unit'],
                    'company_short' => $r['vendor_short'], 'category_name' => $r['category'],
                    'single_dose' => $r['single_dose'], 'frequency' => $r['frequency'],
                    'route' => $r['route'], 'route_nurse_required' => (int)$r['is_nurse'],
                    'stock' => (int)$r['qty'], 'nurse_required' => (int)$r['is_nurse'],
                    'spec_dose' => (float)$r['spec_dose'],
                    'spec_dose_unit' => $r['spec_dose_unit'],
                    'spec_pack_qty' => (int)$r['spec_pack_qty'],
                    'spec_pack_unit' => $r['spec_pack_unit'],
                    'single_use_qty' => (float)$r['single_use_qty'],
                    'is_skin_test' => (int)(isset($r['is_skin_test']) ? $r['is_skin_test'] : 0),
                    'skin_test_item_id' => (int)(isset($r['skin_test_item_id']) ? $r['skin_test_item_id'] : 0),
                );
            }
        }
        $resp = array('list' => $list, 'total' => $total, 'has_more' => ($page * $pageSize) < $total);
        // 联动字典：频次/途径选项（处方套餐编辑下拉用，仅首页携带）
        if ($page <= 1) {
            foreach (OrderRepository::q("SELECT name FROM drug_settings WHERE stype='freq' ORDER BY sort, id") as $fq) {
                $dicts['frequencies'][] = $fq['name'];
            }
            foreach (OrderRepository::q("SELECT name FROM drug_settings WHERE stype='route' ORDER BY sort, id") as $rt) {
                $dicts['routes'][] = $rt['name'];
            }
            $resp['link_dicts'] = $dicts;
        }
        json_ok($resp);
        break;

    default:
        json_fail('未知操作');
}