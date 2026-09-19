<?php
/**
 * ============================================================
 * helpers.d/string.php — 字符串 / JSON / HTML 输出辅助
 * ============================================================
 * 说明：HTML 转义、统一 JSON 响应、徽章 / 列表外壳、审核记录写入、
 * 金额格式化。由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/** HTML 输出转义（防止 XSS，所有动态内容输出前必须经过 e()） */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * 统一 JSON 响应格式：{ ok, msg, data }
 * @param bool   $ok   是否成功
 * @param string $msg  提示信息
 * @param mixed  $data 业务数据
 */
function json_response($ok, $msg = '', $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'   => (bool)$ok,
        'msg'  => (string)$msg,
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/** 成功响应快捷方式 */
function json_ok($data = array(), $msg = '操作成功') {
    json_response(true, $msg, $data);
}

/**
 * 失败响应快捷方式
 * 说明：事务内调用时先自动回滚再输出——历史调用点已手动回滚的
 * 不受影响（inTransaction() 为 false 时跳过），为未来新增的事务内
 * fail 调用提供安全网（防止 MySQL 下事务悬挂）。
 */
function json_fail($msg) {
    try {
        $__db = DatabaseManager::getMain();
        if ($__db && $__db->inTransaction()) {
            $__db->rollBack();
        }
    } catch (Exception $ex) {
        // 数据库连接不可用时跳过回滚（事务本就不存在）
    }
    json_response(false, $msg);
}

/** 统一徽章 HTML（减少各处重复的 span + e() 模式） */
function badge_html($cls, $text) {
    return '<span class="badge badge-' . $cls . '">' . e($text) . '</span>';
}

/**
 * 统一列表外壳：计数行 + 空态（或表格内容）。
 * @param string $countText  计数行文案（如「共 5 个科室」）
 * @param string $emptyText  空态文案
 * @param string $tableHtml  表格内容（<thead>+<tbody>）；空串时显示空态
 * @param string $countId    计数行 id（前端局部刷新计数用，可省略）
 * @return string
 */
function render_list_wrapper($countText, $emptyText, $tableHtml = '', $countId = '') {
    $html = '<div class="fs-13 text-muted mb-8"' . ($countId !== '' ? ' id="' . e($countId) . '"' : '') . '>' . e($countText) . '</div>';
    if ($tableHtml === '') {
        $html .= '<div class="empty">' . e($emptyText) . '</div>';
    } else {
        $html .= '<div class="table-wrap"><table class="table">' . $tableHtml . '</table></div>';
    }
    return $html;
}

/**
 * 生成 IN 子句占位符串（"?,?,?"）。
 * 说明：全库 30+ 处手写 `implode(',', array_fill(0, count($ids), '?'))`，
 * 统一收敛到本函数。空数组返回 ''（调用方应自行跳过该 IN 条件）。
 * @param array $items 参数数组（仅用其长度）
 * @return string 如 "?,?,?"
 */
function in_placeholders($items) {
    return $items ? implode(',', array_fill(0, count($items), '?')) : '';
}

/**
 * 统一提交审核记录（audits 表）。消除各处重复的 INSERT 拼接（含可选
 * data / creation_source 列）。proposer 默认取当前登录用户；auth.php
 * 忘记密码等无登录场景可经 $extra['proposer']/'proposer_id' 覆盖。
 * @param string $type     审核类型（item_lab/template/drugsetting/...）
 * @param int    $refId    关联实体 ID
 * @param string $title    列表标题
 * @param string $content  详情描述
 * @param array  $extra    { data?, creation_source?, proposer?, proposer_id? }
 * @return int 新审核记录 ID
 */
function submit_audit($type, $refId, $title, $content, $extra = array()) {
    $u = Auth::user();
    $proposer = isset($extra['proposer']) ? $extra['proposer'] : ($u ? $u['name'] : '');
    $proposerId = isset($extra['proposer_id']) ? (int)$extra['proposer_id'] : ($u ? (int)$u['id'] : 0);
    $data = isset($extra['data']) ? $extra['data'] : null;
    $source = isset($extra['creation_source']) ? $extra['creation_source'] : '';
    $params = array($type, (int)$refId, $title, $content, 'pending', $proposer, $proposerId, now_str());
    $cols = 'type, ref_id, title, content, status, proposer, proposer_id, created_at';
    if ($data !== null) { $cols .= ', data'; $params[] = $data; }
    if ($source !== '') { $cols .= ', creation_source'; $params[] = $source; }
    return DB::insert('INSERT INTO audits(' . $cols . ') VALUES(' . in_placeholders($params) . ')', $params);
}

/** 金额格式化：保留两位小数 */
function money($n) {
    return number_format((float)$n, 2, '.', '');
}

/**
 * 药品规格动态拼接（全局唯一实现，展示一律调用本函数）。
 * 规则：优先按结构化字段拼接「0.35g×24粒」；剂量段为空时回退
 * spec 原文（旧数据/历史快照兼容）。所有展示规格的地方统一走这里，
 * 修改 spec_dose/spec_pack_qty 等字段后展示自动联动，无需逐处手写。
 * @param array $r 药品行（需含 spec_dose/spec_dose_unit/spec_pack_qty/spec_pack_unit/spec）
 * @return string
 */
function drug_spec_text($r) {
    $r = is_array($r) ? $r : array();
    $dose = trim((string)(isset($r['spec_dose']) ? $r['spec_dose'] : ''));
    $du   = trim((string)(isset($r['spec_dose_unit']) ? $r['spec_dose_unit'] : ''));
    $pq   = (int)(isset($r['spec_pack_qty']) ? $r['spec_pack_qty'] : 1);
    $pu   = trim((string)(isset($r['spec_pack_unit']) ? $r['spec_pack_unit'] : ''));
    if ($dose !== '' && $dose !== '0') {
        $s = rtrim(rtrim($dose, '0'), '.') . $du;
        if ($pu !== '') $s .= '×' . $pq . $pu;
        if ($s !== '') return $s;
    }
    return trim((string)(isset($r['spec']) ? $r['spec'] : ''));
}

/**
 * 药品单包装总规格量（PackCap = pack_size × min_spec_amount）：
 * 例：0.3g × 24 粒 = 单盒总规格量 7.2g；8万U × 10 支 = 单盒总规格量 80万U。
 * 开方按包装单位销售时，1 盒能否覆盖单次剂量以此为准。
 * @param array $r 药品行（需含 spec_dose/spec_pack_qty）
 * @return float
 */
function drug_pack_cap($r) {
    $r = is_array($r) ? $r : array();
    return round((float)(isset($r['spec_dose']) ? $r['spec_dose'] : 0) * max(1, (int)(isset($r['spec_pack_qty']) ? $r['spec_pack_qty'] : 1)), 4);
}

/**
 * 药品单包装售价（整包装价格，drugs.price 即包装价）。
 * @param array $r 药品行
 * @return float
 */
function drug_pack_price($r) {
    $r = is_array($r) ? $r : array();
    return round((float)(isset($r['price']) ? $r['price'] : 0), 4);
}

/**
 * 拆零单价 = 包装单价 / pack_size（每包装最小单位数），保留 4 位小数防除不尽精度丢失；
 * 结算总价统一 round(单价×数量, 2) 做金融四舍五入，各处金额核算保持一致。
 * @param array $r 药品行
 * @return float
 */
function drug_min_price($r) {
    $r = is_array($r) ? $r : array();
    $ps = max(1, (int)(isset($r['spec_pack_qty']) ? $r['spec_pack_qty'] : 1));
    return round((float)(isset($r['price']) ? $r['price'] : 0) / $ps, 4);
}

/**
 * 按开立销售单位返回「单单位销售价」：pack → 包装价；min → 拆零单价。
 * @param array  $r        药品行
 * @param string $unitType pack=包装单位 / min=最小单位
 * @return float
 */
function drug_sale_price($r, $unitType) {
    return $unitType === 'min' ? drug_min_price($r) : drug_pack_price($r);
}

/**
 * 开立销售单位名称：pack → 包装单位（盒/瓶/包/板）；min → 最小拆分单位（支/粒/片/袋/丸）。
 * @param array  $r        药品行
 * @param string $unitType pack=包装单位 / min=最小单位
 * @return string
 */
function drug_unit_name($r, $unitType) {
    $r = is_array($r) ? $r : array();
    if ($unitType === 'min') {
        $u = trim((string)(isset($r['spec_pack_unit']) ? $r['spec_pack_unit'] : ''));
        return $u !== '' ? $u : '个';
    }
    $u = trim((string)(isset($r['package_unit']) ? $r['package_unit'] : ''));
    return $u !== '' ? $u : '盒';
}

/**
 * 库存折算系数（库存统一为最小单位口径）：
 *  · pack（整盒售出）：扣减 数量 × pack_size（1 盒 = pack_size 个最小单位）；
 *  · min（拆零售出）：扣减实际支/粒/片数（系数 1）。
 * @param array  $r        药品行
 * @param string $unitType pack=包装单位 / min=最小单位
 * @return int
 */
function drug_stock_factor($r, $unitType) {
    $r = is_array($r) ? $r : array();
    if ($unitType === 'min') return 1;
    return max(1, (int)(isset($r['spec_pack_qty']) ? $r['spec_pack_qty'] : 1));
}

/**
 * 解析化验数值为浮点（危急值比对用）：
 * 容忍 "5.2"、">200"、"<0.1"、"≤5"、"≥10" 等带比较符号的写法，
 * 非数值（如「阳性」「未见异常」）返回 null。
 * @param mixed $v 化验值字符串
 * @return float|null
 */
function crit_parse_num($v) {
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^[<>≤≥]?\s*([0-9]+(?:\.[0-9]+)?)/', $v, $m)) return null;
    return (float)$m[1];
}

/**
 * 文本型危急值匹配（HIV 阳性等定性项目）：
 * 数值不可解析时，若录入值等于危急值文本（不区分大小写）即命中。
 * @param string $value        录入值
 * @param string $criticalLow  危急值下限（可能为文本，如「阳性」）
 * @param string $criticalHigh 危急值上限
 * @return bool
 */
function crit_text_match($value, $criticalLow, $criticalHigh) {
    $v = trim((string)$value);
    if ($v === '') return false;
    $lo = trim((string)$criticalLow);
    $hi = trim((string)$criticalHigh);
    return ($lo !== '' && strcasecmp($v, $lo) === 0) || ($hi !== '' && strcasecmp($v, $hi) === 0);
}

/**
 * 单行检验结果是否命中危急值（数值型 或 文本型）
 * @param string $value        录入值
 * @param string $criticalLow  危急值下限
 * @param string $criticalHigh 危急值上限
 * @return bool
 */
function crit_row_hit($value, $criticalLow, $criticalHigh) {
    $n = crit_parse_num($value);
    if ($n !== null) {
        if (trim((string)$criticalLow) !== '' && $n < (float)$criticalLow) return true;
        if (trim((string)$criticalHigh) !== '' && $n > (float)$criticalHigh) return true;
        return false;
    }
    return crit_text_match($value, $criticalLow, $criticalHigh);
}

/**
 * 检验报告单趋势标记：危 / ↑ / ↓ / ''
 * 规则：危急值 → 「危」；数值型结果且正常范围为闭合数值区间（如 4-8）时，
 * 低于下限 → ↓、高于上限 → ↑、范围内 → 空；
 * 非数值 / 未配置正常范围 / 范围非闭合（如「阴性」「>5」）→ 不显示箭头。
 * @param string $value        录入值
 * @param string $normalRange  正常范围
 * @param string $criticalLow  危急值下限
 * @param string $criticalHigh 危急值上限
 * @return string
 */
function crit_trend_mark($value, $normalRange, $criticalLow = '', $criticalHigh = '') {
    if (crit_row_hit($value, $criticalLow, $criticalHigh)) return '危';
    $n = crit_parse_num($value);
    if ($n === null) return '';
    $nr = trim((string)$normalRange);
    // 闭合数值区间（容忍尾部单位，如 "4-8 mmol/L"；「阴性」等文本不匹配）
    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*[-~]\s*([0-9]+(?:\.[0-9]+)?)/', $nr, $m)) return '';
    $lo = (float)$m[1];
    $hi = (float)$m[2];
    if ($hi <= $lo) return '';
    if ($n < $lo) return '↓';
    if ($n > $hi) return '↑';
    return '';
}