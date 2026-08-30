<?php
/**
 * ============================================================
 * 003_dept.php — 科室库 schema
 * 说明：科室（门诊/急诊、挂号费、门诊号源）/ 医生加号记录
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 2,
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
    'migrations' => array(
        // v2：叫号大屏增加医技（检验科/影像科）与其他（药房/护士站）虚拟科室，
        // 类型 tech/other；仅叫号管理可见，医生/挂号/转科等业务过滤。
        2 => array(
            "INSERT OR IGNORE INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES
                ('检验科','tech',0,0,0,90,1,datetime('now','localtime')),
                ('影像科','tech',0,0,0,91,1,datetime('now','localtime')),
                ('药房','other',0,0,0,92,1,datetime('now','localtime')),
                ('护士站','other',0,0,0,93,1,datetime('now','localtime'))",
        ),
    ),
    'seed' => array(),
);
