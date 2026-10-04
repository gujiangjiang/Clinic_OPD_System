<?php
/**
 * ============================================================
 * main/03_audits.php — 审核中心
 * ============================================================ */
return array(
    'tables' => array(

        'audits' => "CREATE TABLE IF NOT EXISTS audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            ref_id INTEGER,
            title TEXT,
            content TEXT,
            status TEXT DEFAULT 'pending',
            proposer TEXT,
            proposer_id INTEGER,
            created_at TEXT,
            handled_by TEXT,
            handled_at TEXT,
            note TEXT,
            data_json TEXT DEFAULT '',
            creation_source TEXT DEFAULT ''
        )",
    ),
);
