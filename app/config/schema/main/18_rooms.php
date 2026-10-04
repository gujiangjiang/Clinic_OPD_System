<?php
/**
 * ============================================================
 * main/18_rooms.php — 诊室 / 叫号大屏 / 叫号事件
 * ============================================================ */
return array(
    'tables' => array(

        'clinic_rooms' => "CREATE TABLE IF NOT EXISTS clinic_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER NOT NULL,
            room_name VARCHAR(50) NOT NULL,
            room_type VARCHAR(20) NOT NULL DEFAULT 'doctor',
            screen_token VARCHAR(64) UNIQUE NOT NULL,
            current_doctor_id INTEGER DEFAULT 0,
            current_doctor_name VARCHAR(50) DEFAULT '',
            last_heartbeat_at DATETIME,
            screen_last_heartbeat_at DATETIME,
            is_screen_online TINYINT DEFAULT 0,
            doctor_heartbeat_at DATETIME,
            enable_voice TINYINT DEFAULT 1,
            enable_mask TINYINT DEFAULT 1,
            screen_tips TEXT DEFAULT '',
            tip_interval INTEGER DEFAULT 5,
            current_visit_id INTEGER DEFAULT 0,
            current_flow_no TEXT DEFAULT '',
            current_called_at TEXT DEFAULT '',
            last_call_action TEXT DEFAULT '',
            last_call_at TEXT DEFAULT '',
            allow_cross_day TINYINT DEFAULT 0,
            call_session_date TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",

        'call_events' => "CREATE TABLE IF NOT EXISTS call_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            flow_no TEXT DEFAULT '',
            patient_no TEXT DEFAULT '',
            dept_id INTEGER DEFAULT 0,
            room_id INTEGER DEFAULT 0,
            doctor_id INTEGER DEFAULT 0,
            doctor_name TEXT DEFAULT '',
            action TEXT DEFAULT 'call',
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v16：叫号大屏当前就诊状态（由医生工作站推送信号，大屏端仅按 token 读取校验）
        16 => array(
            "ALTER TABLE clinic_rooms ADD COLUMN current_visit_id INTEGER DEFAULT 0",
            "ALTER TABLE clinic_rooms ADD COLUMN current_flow_no TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN current_called_at TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN last_call_action TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN last_call_at TEXT DEFAULT ''",
        ),
        // v17：叫号事件表——多医生并发叫号防重认领 + 过号标记 + 再次叫号记录
        17 => array(
            "CREATE TABLE IF NOT EXISTS call_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visit_id INTEGER,
                flow_no TEXT DEFAULT '',
                patient_no TEXT DEFAULT '',
                dept_id INTEGER DEFAULT 0,
                room_id INTEGER DEFAULT 0,
                doctor_id INTEGER DEFAULT 0,
                doctor_name TEXT DEFAULT '',
                action TEXT DEFAULT 'call',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_call_events_visit ON call_events(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_call_events_dept_action ON call_events(dept_id, action)",
        ),
        // v18：叫号跨天设置——是否允许跨天叫号（急诊夜班场景）+ 叫号会话日期
        18 => array(
            "ALTER TABLE clinic_rooms ADD COLUMN allow_cross_day TINYINT DEFAULT 0",
            "ALTER TABLE clinic_rooms ADD COLUMN call_session_date TEXT DEFAULT ''",
        ),
    ),
);
