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

/** 失败响应快捷方式 */
function json_fail($msg) {
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