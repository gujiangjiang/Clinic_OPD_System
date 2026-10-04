<?php
/**
 * ============================================================
 * main/13_nursing.php — 生命体征 / 护理记录 / 皮试结果
 * ============================================================ */
return array(
    'tables' => array(

        'vitals' => "CREATE TABLE IF NOT EXISTS vitals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            vital_sbp INTEGER DEFAULT 0,
            vital_dbp INTEGER DEFAULT 0,
            vital_heart_rate TEXT,
            vital_pulse TEXT,
            vital_spo2 TEXT,
            vital_respiration TEXT,
            operator TEXT,
            created_at TEXT,
            record_id INTEGER DEFAULT 0
        )",

        'nursing_records' => "CREATE TABLE IF NOT EXISTS nursing_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            content TEXT,
            operator TEXT,
            created_at TEXT
        )",

        'skin_test_results' => "CREATE TABLE IF NOT EXISTS skin_test_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            drug_id INTEGER DEFAULT 0,
            drug_name TEXT,
            result TEXT,
            operator TEXT,
            created_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_skin_test_results_visit ON skin_test_results(visit_id)",
        "CREATE INDEX IF NOT EXISTS idx_skin_test_results_drug ON skin_test_results(patient_no, drug_id)",
    ),
    'migrations' => array(
        // v8：vitals 高频查询索引（原 v8 跨表索引按所属模块拆分至此）
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_vitals_visit ON vitals(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_vitals_record ON vitals(record_id)",
        ),
    ),
);
