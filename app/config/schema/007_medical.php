<?php
/**
 * ============================================================
 * 007_medical.php — 病历库 schema
 * 说明：电子病历（门诊/急诊抬头）、病历模板（个人/全科/全院，
 * 全科/全院需管理员审核）、诊断证明（单次就诊一次）、转科记录
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 2,
    'tables' => array(
        'records' => "CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            chief_complaint TEXT,
            present_illness TEXT,
            past_history TEXT,
            allergy_history TEXT,
            physical_exam TEXT,
            consciousness TEXT,
            initial_diagnosis TEXT,
            diagnosis_code TEXT,
            is_observation INTEGER DEFAULT 0,
            visit_type TEXT DEFAULT '初诊',
            advice TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT
        )",
        'templates' => "CREATE TABLE IF NOT EXISTS templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doctor_id INTEGER,
            name TEXT,
            scope TEXT DEFAULT 'personal',
            content TEXT,
            status TEXT DEFAULT 'approved',
            created_at TEXT
        )",
        'certificates' => "CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            content TEXT,
            created_at TEXT
        )",
        'referrals' => "CREATE TABLE IF NOT EXISTS referrals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            from_dept_id INTEGER,
            from_dept_name TEXT,
            to_dept_id INTEGER,
            to_dept_name TEXT,
            reason TEXT,
            ref_record_id INTEGER DEFAULT 0,
            doctor_id INTEGER,
            doctor_name TEXT,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v2：旧库升级 —— records 增加初复诊字段（visit_type），默认初诊。
        // 说明：新库建表已包含该列，DatabaseManager 迁移器会自动检测列已存在并跳过。
        2 => array(
            "ALTER TABLE records ADD COLUMN visit_type TEXT DEFAULT '初诊'",
        ),
    ),
    'seed' => array(),
);
