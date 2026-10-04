<?php
/**
 * ============================================================
 * main/14_lab_exam.php — 检验 / 检查（项目字典 / 组合 / 结果 / 报告）
 * ============================================================ */
return array(
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
            executed_by TEXT,
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
            doctor_name TEXT,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            status TEXT DEFAULT 'done',
            withdraw_reason TEXT,
            withdraw_by TEXT,
            withdraw_at TEXT,
            category_name TEXT,
            pdf_url TEXT DEFAULT '',
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v8：results 高频查询索引（原 v8 跨表索引按所属模块拆分至此）
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_results_item ON results(order_item_id)",
            "CREATE INDEX IF NOT EXISTS idx_results_visit ON results(visit_id)",
        ),
        // v11：报告编号唯一约束（报告号由 COUNT+1 生成，并发下可能重复——
        // 唯一约束 + API 层撞号重试，杜绝静默重复报告号）
        11 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_reports_report_no ON reports(report_no)",
        ),
        // v21：检验报告快照固化（生成时定格申请科室/医生/临床诊断/申请时间/检验时间，
        // 后期调阅不受病历/转科等后续变化影响）
        21 => array(
            "ALTER TABLE reports ADD COLUMN apply_dept_name TEXT",
            "ALTER TABLE reports ADD COLUMN apply_doctor_name TEXT",
            "ALTER TABLE reports ADD COLUMN clinical_diagnosis TEXT",
            "ALTER TABLE reports ADD COLUMN applied_at TEXT",
            "ALTER TABLE reports ADD COLUMN registered_at TEXT",
        ),
        // v22：报告检查分类快照（CT/DR/超声…）——检查报告单标题动态前缀
        22 => array(
            "ALTER TABLE reports ADD COLUMN category_name TEXT",
        ),
        // v41：检验报告 PDF 附件地址（reports.pdf_url）——LIS 报告回传/自动回填
        41 => array(
            "ALTER TABLE reports ADD COLUMN pdf_url TEXT DEFAULT ''",
        ),
        // v43：归档单据医院名称快照（报告侧）
        43 => array(
            "ALTER TABLE reports ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE reports ADD COLUMN hospital_name2 TEXT DEFAULT ''",
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
