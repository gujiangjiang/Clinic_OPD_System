<?php
/**
 * ============================================================
 * main/15_disposal.php — 处置项目
 * ============================================================ */
return array(
    'tables' => array(

        'disposal_items' => "CREATE TABLE IF NOT EXISTS disposal_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            price REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            is_nurse INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
);
