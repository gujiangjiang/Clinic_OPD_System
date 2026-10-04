<?php
/**
 * ============================================================
 * main/20_consultations.php — 会诊
 * ============================================================ */
return array(
    'tables' => array(

        'consultations' => "CREATE TABLE IF NOT EXISTS consultations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            consult_no TEXT,
            from_dept_id INTEGER,
            from_dept_name TEXT,
            from_doctor_id INTEGER,
            from_doctor_name TEXT,
            target_dept_id INTEGER,
            target_dept_name TEXT,
            description TEXT,
            purpose TEXT,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            status TEXT DEFAULT 'pending',
            accepted_by TEXT,
            accepted_at TEXT,
            finished_by TEXT,
            finished_at TEXT,
            record_id INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v2：会诊完成人
        2 => array(
            "ALTER TABLE consultations ADD COLUMN finished_by TEXT",
        ),
        // v27：业务单号存量去重（consultations.consult_no）——重复行的非首条追加 `_id` 后缀
        27 => array(
            "UPDATE consultations SET consult_no = consult_no || '_' || id
             WHERE id IN (SELECT id FROM (SELECT c.id, ROW_NUMBER() OVER (PARTITION BY c.consult_no ORDER BY c.id) rn FROM consultations c WHERE c.consult_no IS NOT NULL AND c.consult_no <> '') x WHERE x.rn > 1)",
        ),
        // v28：会诊单号唯一约束（配撞号重试，杜绝并发 TOCTOU 撞号）
        28 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_consultations_consult_no ON consultations(consult_no)",
        ),
        // v43：归档单据医院名称快照（会诊侧）
        43 => array(
            "ALTER TABLE consultations ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE consultations ADD COLUMN hospital_name2 TEXT DEFAULT ''",
        ),
    ),
);
