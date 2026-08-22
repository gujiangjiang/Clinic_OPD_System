<?php
/**
 * ============================================================
 * 001_core.php — 核心库 schema
 * 说明：系统设置 / 站内消息 / 审核中心
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 3,
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
            msg_type TEXT DEFAULT 'system',
            patient_name TEXT DEFAULT '',
            visit_id INTEGER DEFAULT 0,
            link_url TEXT DEFAULT '',
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
            note TEXT,
            creation_source TEXT DEFAULT ''
        )",
    ),
    'migrations' => array(
        // v2：旧库升级 —— 消息增加类型（患者/系统）、患者姓名、就诊ID、跳转链接。
        // 说明：新库建表已包含这些列，DatabaseManager 迁移器会自动检测列已存在并跳过。
        2 => array(
            "ALTER TABLE messages ADD COLUMN msg_type TEXT DEFAULT 'system'",
            "ALTER TABLE messages ADD COLUMN patient_name TEXT DEFAULT ''",
            "ALTER TABLE messages ADD COLUMN visit_id INTEGER DEFAULT 0",
            "ALTER TABLE messages ADD COLUMN link_url TEXT DEFAULT ''",
        ),
        // v3：审核记录增加「创建来源」上下文（关联创建追溯，
        // 如：在维护药品[青霉素]时快捷创建皮试处置）
        3 => array(
            "ALTER TABLE audits ADD COLUMN creation_source TEXT DEFAULT ''",
        ),
    ),
    'seed' => array(),
);
