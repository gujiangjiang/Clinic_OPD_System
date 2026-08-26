<?php
/**
 * ============================================================
 * 010_emr_templates.php — 病历模板库 schema
 * ============================================================
 * 说明：独立于旧 medical 库的 templates 表，重新设计：
 *   - emr_templates：模板主表（含内容 JSON + 审核状态）
 *   - emr_template_depts：适用范围关联科室（多对多）
 * 内置一条 is_system=1 的「通用病历模板」，全院可用、不可修改删除。
 * is_system 模板由 seed 创建，建表时云同步检测。
 * ============================================================ */
return array(
    'version' => 1,
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
        1 => array(
            "INSERT OR IGNORE INTO emr_templates(id, title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(1, '通用病历模板', 'medical_record', 'hospital', 0, '系统', 'published', 1, '{}', datetime('now','localtime'), datetime('now','localtime'))",
        ),
    ),
    'seed' => array(
        "INSERT OR IGNORE INTO emr_templates(id, title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(1, '通用病历模板', 'medical_record', 'hospital', 0, '系统', 'published', 1, '{}', datetime('now','localtime'), datetime('now','localtime'))",
    ),
);