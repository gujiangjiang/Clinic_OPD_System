<?php
/**
 * ============================================================
 * 013_consultation.php — 会诊库 schema
 * 说明：科室间会诊（A 科室发起 → B 科室接收处理）。
 * 状态流转：pending 发起会诊 → doing 正在会诊 → done 会诊完毕。
 * 会诊记录本身不存病历内容——B 科室医生在患者就诊下新开的会诊病历
 * （patient_records，record_type='progress' + consultation_id 关联）。
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 2,
    'tables' => array(
        'consultations' => "CREATE TABLE IF NOT EXISTS consultations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            from_dept_id INTEGER,
            from_dept_name TEXT,
            from_doctor_id INTEGER,
            from_doctor_name TEXT,
            target_dept_id INTEGER,
            target_dept_name TEXT,
            description TEXT,
            purpose TEXT,
            status TEXT DEFAULT 'pending',
            accepted_by TEXT,
            accepted_at TEXT,
            finished_at TEXT,
            record_id INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        2 => array(
            "ALTER TABLE consultations ADD COLUMN record_id INTEGER DEFAULT 0",
        ),
    ),
    'seed' => array(),
);