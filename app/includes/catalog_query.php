<?php
/**
 * ============================================================
 * includes/catalog_query.php — 四类项目目录分页查询（共享模块）
 * ============================================================
 * 说明：开单目录（order_read.php catalog）/ 套餐目录（package.php
 * catalog）/ 管理端项目列表（admin_item.php item_list）三处共用：
 * 检验（含组合）/ 检查 / 处置 / 处方（药品）的分页 + 搜索 + 筛选 +
 * 行映射逻辑统一在本模块，避免三处同构复制漂移。
 * 用法：
 *   $r = catalog_paged_query('lab', array('kw' => $kw, 'f' => 'group'));
 *   $r = catalog_paged_rows('lab', array('status_sql' => '', 'cat' => $cat));  // 管理端原始行
 *   $dicts = catalog_link_dicts(true);       // 联动字典（频次/途径/分类/皮试）
 *   $labMap = catalog_lab_map();             // 检验组合/成员关系全量映射
 * ============================================================ */

/** 目录类型 → 数据表名（白名单，防注入）
 * 检查项目存在两种类型名：业务/开单目录用 imaging（与角色名一致）、
 * 管理端项目列表用 exam（表名 exam_items）——统一映射到同一张表 */
function catalog_table_name($type) {
    $map = array(
        'lab' => 'lab_items',
        'imaging' => 'exam_items',
        'exam' => 'exam_items',
        'procedure' => 'disposal_items',
        'prescription' => 'drugs',
    );
    return isset($map[$type]) ? $map[$type] : '';
}

/**
 * 目录分页查询（原始行）：WHERE 构造 + COUNT + LIMIT/OFFSET
 * @param string $type lab/imaging/procedure/prescription
 * @param array  $opts {
 *   kw          关键字（默认按名称 LIKE；prescription 额外匹配 vendor_short）
 *   cat         分类精确筛选（处方药品分类 / 管理端项目分类；空=全部）
 *   is_group    lab 专用：0=仅单项 / 1=仅组合 / 不传=全部
 *   status_sql  状态 WHERE 前缀（默认 "status='approved'"；管理端传 '' 不过滤）
 *   page/pageSize 分页（默认 1 / 20，size 钳制 1-100）
 * }
 * @return array { rows: 原始行, total: 总数 }
 */
function catalog_paged_rows($type, $opts = array()) {
    $table = catalog_table_name($type);
    if ($table === '') return array('rows' => array(), 'total' => 0);
    $kw = isset($opts['kw']) ? trim((string)$opts['kw']) : '';
    $cat = isset($opts['cat']) ? trim((string)$opts['cat']) : '';
    $statusSql = isset($opts['status_sql']) ? (string)$opts['status_sql'] : "status='approved'";
    $page = max(1, (int)(isset($opts['page']) ? $opts['page'] : 1));
    $pageSize = max(1, min(100, (int)(isset($opts['pageSize']) ? $opts['pageSize'] : 20)));

    $where = $statusSql !== '' ? $statusSql : '1=1';
    $params = array();
    if ($type === 'lab' && isset($opts['is_group']) && $opts['is_group'] !== null && $opts['is_group'] !== '') {
        $where .= ' AND is_group=' . ((int)$opts['is_group']);
    }
    if ($cat !== '') { $where .= ' AND category=?'; $params[] = $cat; }
    if ($kw !== '') {
        $where .= $type === 'prescription' ? ' AND (name LIKE ? OR vendor_short LIKE ?)' : ' AND name LIKE ?';
        $like = '%' . $kw . '%';
        $params[] = $like;
        if ($type === 'prescription') $params[] = $like;
    }
    $total = (int)OrderRepository::val("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    // 处置表无 category 列按 id 排序，其余按 分类+id
    $orderExpr = $type === 'procedure' ? 'id' : 'category, id';
    $rows = OrderRepository::q("SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderExpr} LIMIT ? OFFSET ?",
        array_merge($params, array($pageSize, ($page - 1) * $pageSize)));
    return array('rows' => $rows, 'total' => $total);
}

/**
 * 目录行映射（原始行 → 前端列表结构）
 * @param string $type lab/imaging/procedure/prescription
 * @param array  $rows catalog_paged_rows 返回的原始行
 * @param array  $opts { with_members: lab 组合返回 member_items 明细（套餐编辑器展开用） }
 * @return array
 */
function catalog_map_list($type, $rows, $opts = array()) {
    $withMembers = !empty($opts['with_members']);
    $list = array();
    foreach ($rows as $r) {
        if ($type === 'lab') {
            if (!(int)$r['is_group']) {
                $list[] = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'unit' => $r['unit'], 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
                    'is_group' => 0, 'members' => '', 'member_ids' => '',
                );
            } else {
                $mNames = array(); $mIds = array(); $mItems = array();
                foreach (OrderRepository::q('SELECT id, name, price, unit, category FROM lab_items WHERE id IN (SELECT item_id FROM lab_group_members WHERE group_id=?) ORDER BY id', array($r['id'])) as $m) {
                    $mNames[] = $m['name'];
                    $mIds[] = (int)$m['id'];
                    $mItems[] = array(
                        'id' => (int)$m['id'], 'name' => $m['name'], 'price' => (float)$m['price'],
                        'unit' => $m['unit'], 'category_name' => $m['category'], 'spec' => '', 'stock' => 0,
                        'is_group' => 0, 'members' => '', 'member_ids' => '', 'member_items' => array(),
                    );
                }
                $group = array(
                    'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                    'unit' => '', 'category_name' => $r['category'],
                    'spec' => implode('、', $mNames), 'stock' => 0,
                    'is_group' => 1, 'members' => implode('、', $mNames),
                    'member_ids' => implode(',', $mIds),   // 组合包含的单项 ID，供前端互斥判断
                );
                if ($withMembers) $group['member_items'] = $mItems;
                $list[] = $group;
            }
        } elseif ($type === 'imaging' || $type === 'exam') {
            $list[] = array(
                'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                'unit' => '', 'category_name' => $r['category'], 'spec' => '', 'stock' => 0,
            );
        } elseif ($type === 'procedure') {
            $list[] = array(
                'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['fee'],
                'unit' => '次', 'category_name' => '', 'spec' => '', 'stock' => 0,
                'nurse_required' => (int)$r['is_nurse'],
            );
        } else {
            $list[] = array(
                'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'],
                'spec' => $r['spec'], 'unit' => $r['package_unit'],
                'company_short' => $r['vendor_short'], 'category_name' => $r['category'],
                'single_dose' => $r['single_dose'], 'frequency' => $r['frequency'],
                'route' => $r['route'], 'route_nurse_required' => (int)$r['is_nurse'],
                'stock' => (int)$r['qty'], 'nurse_required' => (int)$r['is_nurse'],
                // 规格结构化：单剂量值/单位 + 包装数量/单位 + 单次使用数量
                'spec_dose' => (float)$r['spec_dose'],
                'spec_dose_unit' => $r['spec_dose_unit'],
                'spec_pack_qty' => (int)$r['spec_pack_qty'],
                'spec_pack_unit' => $r['spec_pack_unit'],
                'single_use_qty' => (float)$r['single_use_qty'],
                // v8.17 拆零销售语义：allow_split 允许拆零；pack_unit 包装单位；min_unit 最小单位；
                // pack_size 每包装最小单位数；库存 drugs.qty 已统一为「最小单位」口径
                'allow_split' => (int)(isset($r['allow_split']) ? $r['allow_split'] : 0),
                'pack_unit' => $r['package_unit'],
                'min_unit' => $r['spec_pack_unit'],
                'pack_size' => max(1, (int)$r['spec_pack_qty']),
                'min_spec_amount' => (float)$r['spec_dose'],
                'min_spec_unit' => $r['spec_dose_unit'],
                // 皮试联动：开方时前端据此弹确认框并标注
                'is_skin_test' => (int)(isset($r['is_skin_test']) ? $r['is_skin_test'] : 0),
                'skin_test_item_id' => (int)(isset($r['skin_test_item_id']) ? $r['skin_test_item_id'] : 0),
            );
        }
    }
    return $list;
}

/** 目录分页查询（完整）：原始行 + 行映射 → { list, total } */
function catalog_paged_query($type, $opts = array()) {
    $r = catalog_paged_rows($type, $opts);
    return array('list' => catalog_map_list($type, $r['rows'], $opts), 'total' => $r['total']);
}

/**
 * 联动字典（开单/套餐编辑下拉用）：频次/途径/分类选项列表；
 * $withSkin=true 时附带 给药途径绑定计费处置，及 $skinIds 引用的皮试处置详情
 * （id→名称/费用；开单目录传当前页处方引用的皮试 ID）
 */
function catalog_link_dicts($withSkin = false, $skinIds = array()) {
    $dicts = array('frequencies' => array(), 'routes' => array(), 'categories' => array());
    if ($withSkin) $dicts['skin_tests'] = array();
    if ($withSkin) $dicts['route_bindings'] = array();
    foreach (OrderRepository::q("SELECT name FROM drug_settings WHERE stype='freq' ORDER BY sort, id") as $fq) {
        $dicts['frequencies'][] = $fq['name'];
    }
    foreach (OrderRepository::q("SELECT name FROM drug_settings WHERE stype='route' ORDER BY sort, id") as $rt) {
        $dicts['routes'][] = $rt['name'];
    }
    foreach (OrderRepository::q("SELECT name FROM drug_settings WHERE stype='category' ORDER BY sort, id") as $cg) {
        $dicts['categories'][] = $cg['name'];
    }
    if ($withSkin && $skinIds) {
        $ph = in_placeholders($skinIds);
        foreach (OrderRepository::q("SELECT id, name, fee FROM disposal_items WHERE id IN ($ph)", array_values($skinIds)) as $d) {
            $dicts['skin_tests'][(int)$d['id']] = array('name' => $d['name'], 'fee' => (float)$d['fee']);
        }
    }
    if ($withSkin) {
        foreach (OrderRepository::q("SELECT name, bind_disposal_item_id FROM drug_settings WHERE stype='route' AND bind_disposal_item_id > 0") as $rb) {
            $dd = OrderRepository::one('SELECT id, name, fee FROM disposal_items WHERE id=?', array((int)$rb['bind_disposal_item_id']));
            if ($dd) $dicts['route_bindings'][$rb['name']] = array('id' => (int)$dd['id'], 'name' => $dd['name'], 'fee' => (float)$dd['fee']);
        }
    }
    return $dicts;
}

/** 检验组合/成员关系全量映射（互斥/共享成员提醒、套餐编辑器解析用） */
function catalog_lab_map() {
    $labMap = array('groups' => array(), 'members' => array(), 'names' => array());
    foreach (OrderRepository::q("SELECT id, name FROM lab_items WHERE status='approved'") as $it) {
        $labMap['names'][(int)$it['id']] = $it['name'];
    }
    foreach (OrderRepository::q("SELECT gm.group_id, gm.item_id FROM lab_group_members gm
        JOIN lab_items g ON g.id = gm.group_id AND g.status='approved' AND g.is_group=1") as $m) {
        $gid = (int)$m['group_id'];
        $mid = (int)$m['item_id'];
        $labMap['groups'][$gid][] = $mid;
        $labMap['members'][$mid][] = $gid;
    }
    return $labMap;
}
