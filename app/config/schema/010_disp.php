<?php
/**
 * ============================================================
 * 010_disp.php — 处置库 schema
 * 说明：处置项目（处置名称/费用/描述备注）
 * 新处置默认 status='pending'，管理员审核通过后（approved）方可开单
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 2,
    'tables' => array(
        'disposal_items' => "CREATE TABLE IF NOT EXISTS disposal_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            fee REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            need_nurse INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v2：处置项目支持「是否需护士站处置」——开单时默认按此勾选，医生可逐项修改
        2 => array(
            "ALTER TABLE disposal_items ADD COLUMN need_nurse INTEGER DEFAULT 0",
        ),
    ),
    'seed' => array(),
);
