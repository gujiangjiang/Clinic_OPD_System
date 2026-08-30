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
    'version' => 3,
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
            created_at TEXT,
            is_group INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0
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
        // 检验组合成员关联（多对多：一个检验项目可加入多个组合）
        'lab_group_members' => "CREATE TABLE IF NOT EXISTS lab_group_members (
            group_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            PRIMARY KEY(group_id, item_id)
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
    'migrations' => array(
        // v2：检验项目支持「组合检验」——is_group=1 表示检验组（如血细胞分析），
        // parent_id 记录该检验项目所属的组 ID（0 为独立项目）。
        // 说明：新库建表已含新列，DatabaseManager 迁移器会自动检测列已存在并跳过。
        2 => array(
            "ALTER TABLE lab_items ADD COLUMN is_group INTEGER DEFAULT 0",
            "ALTER TABLE lab_items ADD COLUMN parent_id INTEGER DEFAULT 0",
        ),
        // v3：组合成员改为多对多关联表（一个项目可加入多个组合）；
        // 迁移时从旧 parent_id 回填关联，保留 parent_id 列兼容历史数据
        3 => array(
            "CREATE TABLE IF NOT EXISTS lab_group_members (
                group_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                PRIMARY KEY(group_id, item_id)
            )",
            "INSERT OR IGNORE INTO lab_group_members(group_id, item_id) SELECT parent_id, id FROM lab_items WHERE is_group=0 AND parent_id>0",
        ),
    ),
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
