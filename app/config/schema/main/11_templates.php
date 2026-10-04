<?php
/**
 * ============================================================
 * main/11_templates.php — 病历模板（旧 templates 表）
 * ============================================================ */
return array(
    'tables' => array(

        'templates' => "CREATE TABLE IF NOT EXISTS templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doctor_id INTEGER,
            name TEXT,
            scope TEXT DEFAULT 'personal',
            content TEXT,
            status TEXT DEFAULT 'approved',
            created_at TEXT
        )",
    ),
);
