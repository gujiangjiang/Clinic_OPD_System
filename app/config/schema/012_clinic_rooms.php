<?php
/**
 * ============================================================
 * 012_clinic_rooms.php — 叫号大屏·诊室管理 schema
 * 说明：大屏作为物理显示终端，拥有独立持久化免登 Token；
 * 医生/护士工作台选择坐诊诊室后建立「人员 - 诊室 - 大屏」绑定映射。
 * ============================================================ */
return array(
    'version' => 1,
    'tables' => array(
        'clinic_rooms' => "CREATE TABLE IF NOT EXISTS clinic_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER NOT NULL,
            room_name VARCHAR(50) NOT NULL,
            room_type VARCHAR(20) NOT NULL DEFAULT 'doctor',
            screen_token VARCHAR(64) UNIQUE NOT NULL,
            current_doctor_id INTEGER DEFAULT 0,
            current_doctor_name VARCHAR(50) DEFAULT '',
            last_heartbeat DATETIME,
            screen_last_heartbeat DATETIME,
            is_screen_online TINYINT DEFAULT 0,
            doctor_heartbeat DATETIME,
            enable_voice TINYINT DEFAULT 1,
            enable_mask TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
    ),
    'migrations' => array(),
    'seed' => array(),
);