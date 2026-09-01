<?php
/**
 * ============================================================
 * helpers.d/oid.php — 业务实体 ID 混淆
 * ============================================================
 * 说明：整数 ID ↔ URL 安全混淆串编解码（基于 IdObfuscator）。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/* ============================================================
 * 业务实体 ID 混淆快捷函数（全站统一入口，详见 core/IdObfuscator.php）
 * ------------------------------------------------------------
 * oid($id)  输出侧：整数 ID → URL 安全混淆串（HTML/JSON/链接拼接用）
 * did($code) 输入侧：混淆串 → 整数（GET/POST 接收；失败返回 0，
 *            调用方按「记录不存在」处理，严禁回退接受明文数字）
 * 适用：visit_id / order_id(s) / item_id(明细) / report_id /
 *       payment_id / ref 等患者级实体；dept_id 与管理端字典 id 不适用。
 * ============================================================ */
function oid($id) {
    return IdObfuscator::encode($id);
}

function did($code) {
    return IdObfuscator::decode($code);
}

/** 批量解码（逗号分隔混淆串 → 整数数组；任一失败返回空数组） */
function did_list($codes) {
    return IdObfuscator::decodeList($codes);
}