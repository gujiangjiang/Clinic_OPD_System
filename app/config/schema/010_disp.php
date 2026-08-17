<?php
/**
 * ============================================================
 * 010_disp.php — 处置库 schema
 * 说明：处置项目（处置名称/费用/描述备注）
 * 新处置默认 status='pending'，管理员审核通过后（approved）方可开单
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'disposal_items' => "CREATE TABLE IF NOT EXISTS disposal_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            fee REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            created_at TEXT
        )",
    ),
    'migrations' => array(),
    'seed' => array(),
);
