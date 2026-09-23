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
require_once APP_ROOT . '/app/includes/catalog_query.php';

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

/**
 * 套餐项目失效校验（应用套餐时逐项对比当前目录快照）：
 * - 检验/检查/处置：项目须存在且已审核；名称发生变更 → 失效
 * - 处方药品：存在且已审核；名称/规格/厂家/剂量/频次/途径/结构化规格 任一变更 → 失效；
 *   库存为 0 → 失效（缺货不可开）
 * 返回原 items 数组，每项追加 valid（0/1）+ invalid_reason（失效原因）。
 */
function pkg_validate_items($type, $items) {
    if (!is_array($items)) return array();
    $type = (string)$type;
    // 批量取主药（sub_of=0）当前行：id → 行
    $mainIds = array();
    foreach ($items as $it) {
        if ((int)(isset($it['sub_of']) ? $it['sub_of'] : 0) === 0) {
            $mainIds[(int)(isset($it['item_id']) ? $it['item_id'] : 0)] = 1;
        }
    }
    $current = array();
    if ($mainIds) {
        $ph = in_placeholders(array_keys($mainIds));
        $tables = array(
            'lab' => array('lab_items', 'name'),
            'imaging' => array('exam_items', 'name'),
            'procedure' => array('disposal_items', 'name'),
            'prescription' => array('drugs', 'name'),
        );
        if (isset($tables[$type])) {
            list($table, $nameCol) = $tables[$type];
            $sel = "id, status, " . $nameCol . " AS name";
            if ($type === 'prescription') {
                $sel .= ", spec, vendor_short AS company_short, single_dose, frequency, route, qty, spec_dose, spec_dose_unit, spec_pack_qty, spec_pack_unit, single_use_qty, package_unit, allow_split";
            }
            foreach (OrderRepository::q("SELECT $sel FROM $table WHERE id IN ($ph)", array_keys($mainIds)) as $row) {
                $current[(int)$row['id']] = $row;
            }
        }
    }
    // 逐项判定
    $out = array();
    foreach ($items as $it) {
        $item = $it;
        $item['valid'] = 1;
        $item['invalid_reason'] = '';
        $id = (int)(isset($it['item_id']) ? $it['item_id'] : 0);
        $isSub = (int)(isset($it['sub_of']) ? $it['sub_of'] : 0) > 0;
        if ($isSub) {
            // 子医嘱同样校验存在性/审核/库存（名称等比对与主药一致；剂量文本不比对——医生可自定）
            $subRow = isset($current[$id]) ? $current[$id] : null;
            if (!$subRow) {
                $item['valid'] = 0; $item['invalid_reason'] = '子医嘱项目已不存在或未通过审核';
            } elseif ($type === 'prescription' && (int)$subRow['qty'] <= 0) {
                $item['valid'] = 0; $item['invalid_reason'] = '子医嘱药品已缺货';
            }
            $out[] = $item;
            continue;
        }
        $row = isset($current[$id]) ? $current[$id] : null;
        if (!$row) {
            $item['valid'] = 0;
            $item['invalid_reason'] = '项目已不存在或未通过审核';
            $out[] = $item;
            continue;
        }
        if ($row['status'] !== 'approved') {
            $item['valid'] = 0;
            $item['invalid_reason'] = '项目已未通过审核';
            $out[] = $item;
            continue;
        }
        // 名称比对（检验/检查/处置：仅名称变更判失效；处方：名称/规格/厂家/剂量/频次/途径 任一变更判失效）
        $storedName = (string)(isset($it['item_name']) ? $it['item_name'] : '');
        if ($row['name'] !== $storedName) {
            $item['valid'] = 0;
            $item['invalid_reason'] = '项目名称已变更（原「' . $storedName . '」→ 现「' . $row['name'] . '」）';
            $out[] = $item;
            continue;
        }
        if ($type === 'prescription') {
            // 药品：库存 + 关键字段任一变更即失效
            if ((int)$row['qty'] <= 0) {
                $item['valid'] = 0;
                $item['invalid_reason'] = '药品已缺货（库存为 0）';
                $out[] = $item;
                continue;
            }
            $fieldMap = array(
                'spec' => '规格', 'company_short' => '厂家',
                'package_unit' => '包装单位',
                // 频次/途径为处方开具时可自定义项（医生用法，套餐编辑器下拉可选），不作为药品身份变更判据；
                // 价格改变不属于规格变动，不参与比对（价格不影响套餐有效性）
            );
            $changed = array();
            foreach ($fieldMap as $col => $label) {
                $curV = (string)(isset($row[$col]) ? $row[$col] : '');
                // 存储字段名映射：包装单位存 pack_unit（旧数据回退 unit）
                $storedKey = ($col === 'package_unit') ? 'pack_unit' : $col;
                $storedV = (string)(isset($it[$storedKey]) ? $it[$storedKey] : (isset($it[$col]) ? $it[$col] : ''));
                if ($curV !== $storedV) $changed[] = $label . '（' . $storedV . '→' . $curV . '）';
            }
            // 结构化规格数值比对（浮点宽松）：单剂量值 / 剂量单位 / 每包装数量 / 最小单位 均属「规格」
            $specDoseCur = (float)(isset($row['spec_dose']) ? $row['spec_dose'] : 0);
            $specDoseOld = (float)(isset($it['spec_dose']) ? $it['spec_dose'] : 0);
            if (abs($specDoseCur - $specDoseOld) > 0.0001) $changed[] = '单剂量值';
            $sduCur = (string)(isset($row['spec_dose_unit']) ? $row['spec_dose_unit'] : '');
            $sduOld = (string)(isset($it['spec_dose_unit']) ? $it['spec_dose_unit'] : '');
            if ($sduCur !== $sduOld) $changed[] = '剂量单位';
            $spqCur = (int)(isset($row['spec_pack_qty']) ? $row['spec_pack_qty'] : 1);
            $spqOld = (int)(isset($it['spec_pack_qty']) ? $it['spec_pack_qty'] : 1);
            if ($spqCur !== $spqOld) $changed[] = '每包装数量';
            $puCur = (string)(isset($row['spec_pack_unit']) ? $row['spec_pack_unit'] : '');
            $puOld = (string)(isset($it['spec_pack_unit']) ? $it['spec_pack_unit'] : '');
            if ($puCur !== $puOld) $changed[] = '最小单位';
            if ($changed) {
                $item['valid'] = 0;
                $item['invalid_reason'] = '药品信息已变更：' . implode('、', array_slice($changed, 0, 3)) . (count($changed) > 3 ? ' 等' : '');
            }
        }
        $out[] = $item;
    }
    return $out;
}

switch ($action) {

    /* ==================== 可用套餐列表（分页检索，滚动动态加载） ====================
     * 医生可见：本人个人套餐（任意状态）+ 本人待审核套餐 + 已发布全院 + 已发布本人科室套餐
     * 管理员可见全部
     */
    case 'list':
        $type = get('type', 'lab');
        list($page, $pageSize) = paged_params(20);
        $kw = trim(get('kw', ''));
        $scope = trim(get('scope', ''));
        if (!pkg_type_allowed($u['role'], $type)) $type = 'lab';
        pkg_assert_type($u, $type);
        $like = $kw !== '' ? '%' . $kw . '%' : '';
        $isAdmin = ($u['role'] === 'admin');
        // 可见性过滤条件（独立拼装，供总数与列表共用）
        $visConds = array("type=?");
        $params = array($type);
        if (!$isAdmin) {
            $myDepts = user_dept_ids($u);
            $orConds = array("(scope='personal' AND creator_id=?)", "(status='pending_review' AND creator_id=?)", "(scope='hospital' AND status='published')");
            $params[] = $u['id'];
            $params[] = $u['id'];
            if ($myDepts) {
                $ph = in_placeholders($myDepts);
                $orConds[] = "(scope='dept' AND status='published' AND id IN (SELECT package_id FROM package_depts WHERE dept_id IN ($ph)))";
                foreach ($myDepts as $d) $params[] = $d;
            }
            $visConds[] = '(' . implode(' OR ', $orConds) . ')';
        }
        // 范围筛选（全部=不限制；个人/科室/全院在可见范围内再收窄）
        if (in_array($scope, array('personal', 'dept', 'hospital'), true)) {
            $visConds[] = "scope=?";
            $params[] = $scope;
        }
        $where = implode(' AND ', $visConds);
        if ($kw !== '') {
            $where .= " AND title LIKE ?";
            $params[] = $like;
        }
        $total = (int)OrderRepository::val("SELECT COUNT(*) FROM packages WHERE " . $where, $params);
        $rows = OrderRepository::q("SELECT * FROM packages WHERE " . $where . " ORDER BY id DESC LIMIT ? OFFSET ?",
            paged_suffix($params, $page, $pageSize));
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
        $thead = '<thead><tr><th>套餐名称</th><th>适用范围</th><th>项目 / 合计</th><th>创建人</th><th>审核状态</th><th>操作</th></tr></thead>';
        json_ok(array('list' => $out, 'total' => $total, 'has_more' => paged_has_more($page, $pageSize, $total), 'thead' => $thead));
        break;

    /* ==================== 单条套餐详情（编辑回填 / 应用加载） ==================== */
    case 'get':
        // 兼容 GET（编辑回填 Clinic.modal.load）/ POST（开单应用 Clinic.ajax）两种调用
        $id = (int)req('id');
        $t = OrderRepository::one('SELECT * FROM packages WHERE id=?', array($id));
        if (!$t) json_fail('套餐不存在');
        pkg_assert_type($u, (string)$t['type']);
        $forApply = (int)req('for_apply', 0);
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
        // 逐项与当前目录快照对比，标记失效项目（改名/删除/缺货/信息变更）：
        // 应用套餐（for_apply）与编辑回填（编辑模态框需禁用失效项）都返回 valid 标记
        $items = pkg_validate_items((string)$t['type'], $items);
        $resp = array(
            'package' => array(
                'id' => (int)$t['id'],
                'title' => (string)$t['title'],
                'type' => (string)$t['type'],
                'scope' => (string)$t['scope'],
                'status' => (string)$t['status'],
                'items' => $items,
                'dept_ids' => $deptIds,
            ),
        );
        // 检验套餐：随响应返回组合/成员映射，前端按 ID 解析组合显示（不依赖快照字段）
        if ((string)$t['type'] === 'lab') {
            $labMap = array('groups' => array(), 'members' => array(), 'names' => array());
            foreach (OrderRepository::q("SELECT id, name FROM lab_items WHERE status='approved'") as $it) {
                $labMap['names'][(int)$it['id']] = $it['name'];
            }
            foreach (OrderRepository::q("SELECT gm.group_id, gm.item_id FROM lab_group_members gm
                JOIN lab_items g ON g.id = gm.group_id AND g.status='approved' AND g.is_group=1") as $m) {
                $labMap['groups'][(int)$m['group_id']][] = (int)$m['item_id'];
                $labMap['members'][(int)$m['item_id']][] = (int)$m['group_id'];
            }
            $resp['lab_map'] = $labMap;
        }
        json_ok($resp);
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
        // 名称重复校验：同类型下不允许同名套餐
        $dupPkg = OrderRepository::one('SELECT id FROM packages WHERE type=? AND title=? AND id<>?', array($type, $title, (int)$id));
        if ($dupPkg) json_fail('已存在同名套餐「' . $title . '」，请更换名称');
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
                // 包装单价（整盒售价）一并固化：min 单位时 price 为拆零单价，pack_price 保持包装价
                'pack_price' => (float)(isset($it['pack_price']) ? $it['pack_price'] : (isset($it['price']) ? $it['price'] : 0)),
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
                // v8.17 拆零销售：套餐保存时一同固化开立单位（pack/min）、包装单位、拆零标记，
                // 套餐导入开处方时保持单位选项与数量口径一致，不覆盖成错误盒数
                'unit_type' => (string)(isset($it['unit_type']) ? $it['unit_type'] : 'pack'),
                'pack_unit' => (string)(isset($it['pack_unit']) ? $it['pack_unit'] : ''),
                'allow_split' => (int)(isset($it['allow_split']) ? $it['allow_split'] : 0),
                // 检验组合字段（组合保持组合实体保存）
                'is_group' => (int)(isset($it['is_group']) ? $it['is_group'] : 0),
                'members' => (string)(isset($it['members']) ? $it['members'] : ''),
                'member_ids' => (string)(isset($it['member_ids']) ? $it['member_ids'] : ''),
            );
        }
        if (!$clean) json_fail('请先添加套餐项目');
        // 失效项目拦截：保存前逐项与当前目录对比，任一主项失效（改名/删除/未审核/缺货/信息变更）
        // 均拒绝保存，提示先在编辑弹窗中删除或更换该失效项目
        $validated = pkg_validate_items($type, $clean);
        $invalidMains = array();
        foreach ($validated as $it) {
            if ((int)(isset($it['sub_of']) ? $it['sub_of'] : 0) === 0 && (int)$it['valid'] !== 1) {
                $invalidMains[] = (string)$it['item_name'] . '（' . (string)$it['invalid_reason'] . '）';
            }
        }
        if ($invalidMains) {
            json_fail('套餐含失效项目，请先删除或更换后再保存：' . implode('、', array_slice($invalidMains, 0, 5)) . (count($invalidMains) > 5 ? ' 等' : ''));
        }
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
        $typeLabel = pkg_type_label($type);
        // 管理员新建：仅限 hospital/dept（不可新建个人）；编辑他人已存在的个人套餐可保存（维护场景）
        if ($isAdmin && $scope === 'personal' && $id <= 0) json_fail('管理员新建套餐适用范围仅限全院或科室');
        $authorId = 0;   // 原创建人（管理员编辑他人套餐时用于站内信告知）
        $oldScope = '';
        $status = $isAdmin ? 'published' : ($scope === 'personal' ? 'published' : 'pending_review');
        // 编辑：越权防护
        if ($id > 0) {
            $old = OrderRepository::one('SELECT * FROM packages WHERE id=?', array($id));
            if (!$old) json_fail('套餐不存在');
            if ((int)$old['creator_id'] !== (int)$u['id'] && !$isAdmin) json_fail('无权修改该套餐');
            if ($old['status'] === 'pending_review') json_fail('套餐正在审核中，审核通过或驳回后方可修改');
            // 记录原创建人：管理员编辑他人套餐时站内信告知作者
            $authorId = (int)$old['creator_id'];
            $oldScope = (string)$old['scope'];
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
            // 审核预览快照（audits.data）：保存提交时的完整内容（项目明细 + 适用范围），
            // 已处理审核即使套餐被删除仍可按原始内容预览追溯
            $auditData = json_encode(array(
                'title' => $title, 'type' => $type, 'scope' => $scope,
                'dept_ids' => $deptIds ? array_keys($deptIds) : array(),
                'items' => $clean,
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
        // 管理员编辑他人套餐：站内信告知原作者（含适用范围变更说明）
        if ($isAdmin && $authorId > 0 && $authorId !== (int)$u['id']) {
            $scopeNameNow = $scope === 'hospital' ? '全院' : ($scope === 'dept' ? '科室' : '个人');
            $msg = '管理员 ' . $u['name'] . ' 修改了您的' . $typeLabel . '「' . $title . '」（当前适用范围：' . $scopeNameNow . '），请及时查看';
            if ($oldScope !== '' && $oldScope !== $scope) {
                $msg .= '。适用范围已由「' . ($oldScope === 'hospital' ? '全院' : ($oldScope === 'dept' ? '科室' : '个人')) . '」变更为「' . $scopeNameNow . '」';
            }
            send_msg('user', $authorId, $typeLabel . '被修改',
                $msg,
                '', '', array('msg_type' => 'system', 'link_url' => '/doctor/packages'));
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
        // 共享目录查询（includes/catalog_query.php，与开单目录同一数据源/结构）：
        // 套餐用途加工：lab 组合附带 member_items 明细（编辑器展开用），字典不带皮试
        $type = get('type', 'lab');
        if (!pkg_type_allowed($u['role'], $type)) $type = 'lab';
        pkg_assert_type($u, $type);
        list($page, $pageSize) = paged_params(20);
        $opts = array(
            'kw' => get('kw', ''),
            'cat' => get('cat', ''),
            'page' => $page,
            'pageSize' => $pageSize,
            'with_members' => true,
        );
        if ($type === 'lab') {
            $f = get('f', '');   // lab: single=单个 / group=组合（空=全部）
            if ($f === 'single') $opts['is_group'] = 0;
            elseif ($f === 'group') $opts['is_group'] = 1;
        } elseif ($type === 'prescription') {
            $opts['status_sql'] = "status='approved' AND qty > 0";
        }
        $r = catalog_paged_query($type, $opts);
        $resp = array('list' => $r['list'], 'total' => $r['total'], 'has_more' => ($page * $pageSize) < $r['total']);
        // 联动字典：频次/途径选项（处方套餐编辑下拉用，仅首页携带）
        if ($page <= 1) {
            $resp['link_dicts'] = catalog_link_dicts(false);
            // 检验组合/成员关系全量映射（套餐编辑器按 ID 解析组合显示，避免依赖快照字段）
            if ($type === 'lab') $resp['lab_map'] = catalog_lab_map();
        }
        json_ok($resp);
        break;

    default:
        json_fail('未知操作');
}