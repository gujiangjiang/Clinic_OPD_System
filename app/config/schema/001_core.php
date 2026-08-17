<?php
/**
 * ============================================================
 * 001_core.php — 核心库 schema
 * 说明：系统设置 / 站内消息 / 审核中心
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            skey TEXT PRIMARY KEY,
            svalue TEXT
        )",
        'messages' => "CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_name TEXT,
            to_role TEXT,
            to_user_id INTEGER,
            title TEXT,
            content TEXT,
            print_type TEXT,
            print_url TEXT,
            is_read INTEGER DEFAULT 0,
            created_at TEXT
        )",
        'audits' => "CREATE TABLE IF NOT EXISTS audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            ref_id INTEGER,
            title TEXT,
            content TEXT,
            data TEXT,
            status TEXT DEFAULT 'pending',
            proposer TEXT,
            proposer_id INTEGER,
            created_at TEXT,
            handled_by TEXT,
            handled_at TEXT,
            note TEXT
        )",
    ),
    'migrations' => array(),
    'seed' => array(),
);
