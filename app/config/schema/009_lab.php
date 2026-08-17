<?php
/**
 * ============================================================
 * 009_lab.php — 检验/检查库 schema
 * 说明：检验项目（单位/正常范围/危急值上下限）、检查项目
 * （分类 CT/MR 等）、项目分类、检验结果、检验/检查报告
 * 新项目默认 status='pending'，管理员审核通过后（approved）方可开单
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'item_categories' => "CREATE TABLE IF NOT EXISTS item_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ctype TEXT,
            name TEXT,
            sort INTEGER DEFAULT 0
        )",
        'lab_items' => "CREATE TABLE IF NOT EXISTS lab_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT,
            name TEXT,
            unit TEXT,
            price REAL DEFAULT 0,
            normal_range TEXT,
            critical_low TEXT,
            critical_high TEXT,
            description TEXT,
            status TEXT DEFAULT 'pending',
            created_at TEXT
        )",
        'exam_items' => "CREATE TABLE IF NOT EXISTS exam_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT,
            name TEXT,
            price REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            created_at TEXT
        )",
        'results' => "CREATE TABLE IF NOT EXISTS results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER,
            order_item_id INTEGER,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            type TEXT,
            values_json TEXT,
            findings TEXT,
            conclusion TEXT,
            executor TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT
        )",
        'reports' => "CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            result_id INTEGER,
            report_no TEXT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            type TEXT,
            content TEXT,
            doctor TEXT,
            status TEXT DEFAULT 'done',
            withdraw_reason TEXT,
            withdraw_by TEXT,
            withdraw_at TEXT,
            created_at TEXT
        )",
    ),
    'migrations' => array(),
    'seed' => array(
        // 项目分类种子（检验 / 检查）
        "INSERT OR IGNORE INTO item_categories(id,ctype,name,sort) VALUES
            (1,'lab','血液检验',1),(2,'lab','生化检验',2),(3,'lab','免疫检验',3),
            (4,'lab','尿液检验',4),(5,'lab','粪便检验',5),(6,'lab','凝血功能',6),
            (7,'lab','微生物检验',7),(8,'lab','其他',99),
            (9,'exam','CT',1),(10,'exam','MR',2),(11,'exam','DR（数字化X线）',3),
            (12,'exam','超声',4),(13,'exam','内镜',5),(14,'exam','心电图',6),
            (15,'exam','病理',7),(16,'exam','其他',99)",
    ),
);
