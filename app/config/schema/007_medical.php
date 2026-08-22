<?php
/**
 * ============================================================
 * 007_medical.php — 病历库 schema
 * 说明：电子病历（门诊/急诊抬头）、病历模板（个人/全科/全院，
 * 全科/全院需管理员审核）、诊断证明（单次就诊一次）、转科记录
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 5,
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
        // 结构化电子病历（v3 新增，唯一真理来源）：
        // emr_data 存完整结构化 JSON；投影字段由后端从 JSON 提取供统计检索；
        // emr_print_text 为剔除占位符后的打印纯净文书。
        // 旧 records 表保留并双写扁平文本镜像，兼容就诊历史/转科引用等既有消费方。
        // v5 多医生接诊：同一次挂号流水（visit_id）1:N——首诊医生 initial，
        // 后续接诊医生 progress（续写）；parent_record_id 关联前序病历 id。
        'patient_records' => "CREATE TABLE IF NOT EXISTS patient_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            record_type TEXT DEFAULT 'initial',
            parent_record_id INTEGER DEFAULT 0,
            main_symptom TEXT,
            symptom_duration TEXT,
            symptom_unit TEXT,
            informant TEXT,
            arrival_way TEXT,
            has_past_history TEXT DEFAULT '否',
            allergies TEXT,
            is_leave_hospital TEXT DEFAULT '否',
            primary_icd10 TEXT,
            primary_diagnosis TEXT,
            emr_data TEXT NOT NULL,
            emr_print_text TEXT,
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
            created_at TEXT,
            cert_no TEXT DEFAULT ''
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
        // v3：结构化电子病历表 patient_records + 统计索引
        3 => array(
            "CREATE INDEX IF NOT EXISTS idx_patient_records_stat ON patient_records(primary_icd10, is_leave_hospital, main_symptom)",
        ),
        // v4：诊断证明号（ZM 前缀，与申请单号 JY/JC/CZ/CF 同源规则、前缀互不冲突）。
        // 存量证明回填：ZM + 开具日期(YYYYMMDD) + 4 位 ID 序号，保证唯一且可读。
        4 => array(
            "ALTER TABLE certificates ADD COLUMN cert_no TEXT DEFAULT ''",
            "UPDATE certificates SET cert_no = 'ZM' || replace(substr(created_at,1,10),'-','') || substr('0000' || id, -4, 4) WHERE cert_no IS NULL OR cert_no = ''",
        ),
        // v5：多医生接诊/续写 —— patient_records 增加 record_type（initial 首诊 /
        // progress 续写）与 parent_record_id（关联前序病历 id），
        // 并为 (visit_id)、(patient_no)、(visit_id, doctor_id) 建立索引。
        5 => array(
            "ALTER TABLE patient_records ADD COLUMN record_type TEXT DEFAULT 'initial'",
            "ALTER TABLE patient_records ADD COLUMN parent_record_id INTEGER DEFAULT 0",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit ON patient_records(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_patient ON patient_records(patient_no)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit_doctor ON patient_records(visit_id, doctor_id)",
            // 存量数据回填：升级前的病历均视为首诊记录
            "UPDATE patient_records SET record_type='initial' WHERE record_type IS NULL OR record_type=''",
        ),
    ),
    'seed' => array(),
);
