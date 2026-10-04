<?php
/**
 * ============================================================
 * main/05_departments.php — 科室 / 加号
 * ============================================================ */
return array(
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
            is_used INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'seed' => array(
        // 医技/其他虚拟科室（叫号大屏使用）
        "INSERT OR IGNORE INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES
            ('检验科','tech',0,0,0,90,1,datetime('now','localtime')),
            ('影像科','tech',0,0,0,91,1,datetime('now','localtime')),
            ('药房','other',0,0,0,92,1,datetime('now','localtime')),
            ('护士站','other',0,0,0,93,1,datetime('now','localtime'))",
    ),
);
