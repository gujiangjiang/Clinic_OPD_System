<?php
/**
 * ============================================================
 * main/17_packages.php — 快速开单套餐
 * ============================================================ */
return array(
    'tables' => array(

        'packages' => "CREATE TABLE IF NOT EXISTS packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            type TEXT DEFAULT 'lab',
            scope TEXT DEFAULT 'personal',
            creator_id INTEGER,
            creator_name TEXT,
            status TEXT DEFAULT 'published',
            content_json TEXT DEFAULT '{}',
            created_at TEXT,
            updated_at TEXT
        )",

        'package_depts' => "CREATE TABLE IF NOT EXISTS package_depts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER,
            dept_id INTEGER,
            UNIQUE(package_id, dept_id)
        )",
    ),
);
