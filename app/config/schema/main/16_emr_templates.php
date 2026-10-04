<?php
/**
 * ============================================================
 * main/16_emr_templates.php — 病历模板独立库（emr_templates）
 * ============================================================ */
return array(
    'tables' => array(

        'emr_templates' => "CREATE TABLE IF NOT EXISTS emr_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            type TEXT DEFAULT 'medical_record',
            scope TEXT DEFAULT 'personal',
            creator_id INTEGER,
            creator_name TEXT,
            status TEXT DEFAULT 'published',
            is_system INTEGER DEFAULT 0,
            content_json TEXT DEFAULT '{}',
            created_at TEXT,
            updated_at TEXT
        )",

        'emr_template_depts' => "CREATE TABLE IF NOT EXISTS emr_template_depts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER,
            dept_id INTEGER,
            UNIQUE(template_id, dept_id)
        )",
    ),
    'migrations' => array(
        // v30：知情同意模板存量清理——早期版本保存时强制写入的 content.name
        //（"通用"等）已废弃（标题一律取模板名称原文），移除该键防旧数据干扰
        30 => array(
            "UPDATE emr_templates SET content_json = json_remove(content_json, '$.name')
             WHERE type='consent' AND json_extract(content_json, '$.name') IS NOT NULL",
        ),
    ),
    'seed' => array(
        // 内置系统病历模板
        "INSERT OR IGNORE INTO emr_templates(id, title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(1, '通用病历模板', 'medical_record', 'hospital', 0, '系统', 'published', 1, '{}', datetime('now','localtime'), datetime('now','localtime'))",
    ),
);
