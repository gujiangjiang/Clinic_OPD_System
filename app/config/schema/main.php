<?php
/**
 * ============================================================
 * main.php — 统一业务主库 schema（聚合入口）
 * ============================================================
 * 说明：定义合并后的唯一业务主库（SQLite clinic_main.db
 * 或 MySQL his_main）。为便于维护与排查，schema 已按业务模块彻底分散到
 * app/config/schema/main/ 目录，本文件仅负责按文件名顺序聚合成统一结构：
 *   - 建表：01_system.php … 24_integration.php（每模块自带 tables）
 *   - 迁移：各模块内 migrations（键为版本号）——同一版本号可跨模块合并，
 *           例如 v8 的索引按所属表分散到各模块，聚合时同版本数组拼接
 *   - 种子：各模块内 seed
 * 返回结构（version/tables/migrations/seed）与 DatabaseManager::mainSchema()
 * 契约保持不变。
 *
 * 多驱动兼容：
 * - 主键统一 INTEGER PRIMARY KEY AUTOINCREMENT（MySQL 由 DatabaseManager
 *   方言层自动转换为 AUTO_INCREMENT）
 * - 参与主键/唯一约束的标识列统一 VARCHAR(191)（MySQL 对 TEXT 键要求前缀长度；
 *   SQLite/PostgreSQL 对该类型无差异）；普通长文本列保持 TEXT
 * - 布尔统一 INTEGER 0/1（PostgreSQL 由方言层转 SMALLINT）
 * - 时间默认 datetime('now','localtime')（MySQL 自动转为 NOW()）
 * - 种子用 INSERT OR IGNORE（MySQL 自动转为 INSERT IGNORE）
 * - CREATE INDEX IF NOT EXISTS：MySQL/MariaDB 由 DatabaseManager 先做存在性检查，
 *   TEXT/BLOB 列索引自动补 (191) 前缀；函数键部件 date(col) 需 MySQL 8.0.13+
 * ============================================================ */
$__schemaDir = __DIR__ . '/main';
$__def = array(
    'version'    => 0,
    'tables'     => array(),
    'migrations' => array(),
    'seed'       => array(),
);
$__files = glob($__schemaDir . '/*.php');
if ($__files === false) $__files = array();
sort($__files);
foreach ($__files as $__file) {
    $__part = require $__file;
    if (!is_array($__part)) continue;
    if (isset($__part['version'])) {
        $__def['version'] = (int)$__part['version'];
    }
    if (!empty($__part['tables']) && is_array($__part['tables'])) {
        $__def['tables'] = array_merge($__def['tables'], $__part['tables']);
    }
    if (!empty($__part['migrations']) && is_array($__part['migrations'])) {
        foreach ($__part['migrations'] as $__ver => $__sqls) {
            if (!isset($__def['migrations'][$__ver])) $__def['migrations'][$__ver] = array();
            $__def['migrations'][$__ver] = array_merge($__def['migrations'][$__ver], (array)$__sqls);
        }
    }
    if (!empty($__part['seed']) && is_array($__part['seed'])) {
        $__def['seed'] = array_merge($__def['seed'], $__part['seed']);
    }
}
ksort($__def['migrations']);
unset($__schemaDir, $__files, $__file, $__part, $__ver, $__sqls);
return $__def;
