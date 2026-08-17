<?php
/**
 * ============================================================
 * 008_nurse.php — 护理库 schema
 * 说明：生命体征（血压/心率/脉搏/血氧/呼吸，医生站与护士站
 * 共用接口双向同步）+ 护理记录
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'vitals' => "CREATE TABLE IF NOT EXISTS vitals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            bp_systolic INTEGER DEFAULT 0,
            bp_diastolic INTEGER DEFAULT 0,
            heart_rate TEXT,
            pulse TEXT,
            spo2 TEXT,
            respiration TEXT,
            operator TEXT,
            created_at TEXT
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
    ),
    'migrations' => array(),
    'seed' => array(),
);
