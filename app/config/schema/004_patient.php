<?php
/**
 * ============================================================
 * 004_patient.php — 患者库 schema
 * 说明：患者档案 + 挂号记录
 * 患者唯一ID：年月日8位 + 当日序号2位（如 25031101），绑定身份证
 * 门诊流水号：年月日6位 + 当日序号4位（如 2503110001），每次就诊新生成
 * 门诊就诊序号：每个门诊科室每日独立3位递增（001 起，永久唯一不回收）
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 2,
    'tables' => array(
        'patients' => "CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_no TEXT UNIQUE,
            id_card TEXT UNIQUE,
            name TEXT,
            gender TEXT,
            birth_date TEXT,
            age INTEGER DEFAULT 0,
            ethnicity TEXT,
            marital TEXT,
            occupation TEXT,
            work_unit TEXT,
            address TEXT,
            phone TEXT,
            past_history_type TEXT DEFAULT '',
            past_history_detail TEXT DEFAULT '',
            allergies TEXT DEFAULT '',
            created_at TEXT
        )",
        'registrations' => "CREATE TABLE IF NOT EXISTS registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_no TEXT,
            flow_no TEXT UNIQUE,
            visit_seq INTEGER DEFAULT 0,
            first_dept_id INTEGER,
            first_dept_name TEXT,
            current_dept_id INTEGER,
            current_dept_name TEXT,
            session TEXT,
            fee_type TEXT,
            fee REAL DEFAULT 0,
            status TEXT DEFAULT 'pending',
            payment_time TEXT,
            cashier_id INTEGER,
            cashier_name TEXT,
            register_time TEXT,
            cancel_reason TEXT,
            is_extra INTEGER DEFAULT 0
        )",
    ),
    'migrations' => array(
        // v2：患者全局既往史/过敏史（跨就诊自动调用，以最新修改为准）
        2 => array(
            "ALTER TABLE patients ADD COLUMN past_history_type TEXT DEFAULT ''",
            "ALTER TABLE patients ADD COLUMN past_history_detail TEXT DEFAULT ''",
            "ALTER TABLE patients ADD COLUMN allergies TEXT DEFAULT ''",
        ),
    ),
    'seed' => array(),
);
