<?php
/**
 * ============================================================
 * 003_dept.php — 科室库 schema
 * 说明：科室（门诊/急诊、挂号费、门诊号源）/ 医生加号记录
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'departments' => "CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            type TEXT DEFAULT 'clinic',
            fee REAL DEFAULT 0,
            am_quota INTEGER DEFAULT 30,
            pm_quota INTEGER DEFAULT 30,
            sort INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            created_at TEXT
        )",
        'extra_slots' => "CREATE TABLE IF NOT EXISTS extra_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER,
            reg_date TEXT,
            id_card TEXT,
            name TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            used INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'migrations' => array(),
    'seed' => array(),
);
