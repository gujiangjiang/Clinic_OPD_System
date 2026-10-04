<?php
/**
 * ============================================================
 * main/02_messages.php — 站内消息
 * ============================================================ */
return array(
    'tables' => array(

        'messages' => "CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_name TEXT,
            from_user_id INTEGER DEFAULT 0,
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

        'sent_messages' => "CREATE TABLE IF NOT EXISTS sent_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER,
            sender_name TEXT,
            title TEXT,
            content TEXT,
            recipients TEXT,
            recipient_count INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
);
