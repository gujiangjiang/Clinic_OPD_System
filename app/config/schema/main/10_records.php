<?php
/**
 * ============================================================
 * main/10_records.php — 病历（简版 records + 结构化 patient_records）
 * ============================================================ */
return array(
    'tables' => array(

        'records' => "CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            dept_name TEXT DEFAULT '',
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            chief_complaint TEXT,
            present_illness TEXT,
            past_history TEXT,
            allergy_history TEXT,
            physical_exam TEXT,
            consciousness TEXT,
            preliminary_diagnosis TEXT,
            icd10_code TEXT,
            is_observation INTEGER DEFAULT 0,
            visit_type TEXT DEFAULT '初诊',
            doctor_advice TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT,
            patient_record_id INTEGER DEFAULT 0
        )",

        'patient_records' => "CREATE TABLE IF NOT EXISTS patient_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            dept_name TEXT DEFAULT '',
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            record_type TEXT DEFAULT 'initial',
            parent_record_id INTEGER DEFAULT 0,
            chief_complaint TEXT,
            symptom_duration TEXT,
            symptom_unit TEXT,
            informant TEXT,
            arrival_way TEXT,
            has_past_history TEXT DEFAULT '否',
            allergy_history TEXT,
            is_leave_hospital TEXT DEFAULT '否',
            icd10_code TEXT,
            diagnosis_name TEXT,
            emr_data TEXT NOT NULL,
            emr_print_text TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT,
            consultation_id INTEGER DEFAULT 0,
            is_critical INTEGER DEFAULT 0,
            evid_hash TEXT DEFAULT '',
            evid_algo TEXT DEFAULT 'SHA-256',
            evid_token TEXT DEFAULT '',
            evid_signer TEXT DEFAULT '',
            evid_time TEXT DEFAULT ''
        )",
    ),
    'migrations' => array(
        // v7：结构化电子病历表 patient_records 高频索引（幂等；新库建表不含索引，
        // 需显式创建。字段名已规范化：primary_icd10→icd10_code, main_symptom→chief_complaint）
        7 => array(
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit ON patient_records(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_patient ON patient_records(patient_no)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit_doctor ON patient_records(visit_id, doctor_id)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_stat ON patient_records(icd10_code, is_leave_hospital, chief_complaint)",
        ),
        // v9：存量数据回填（patient_records.record_type 旧数据可能为空，补正）
        9 => array(
            "UPDATE patient_records SET record_type='initial' WHERE record_type IS NULL OR record_type=''",
        ),
        // v32：病历危急值只读标记（is_critical，随危急值体系引入）
        32 => array(
            "ALTER TABLE patient_records ADD COLUMN is_critical INTEGER DEFAULT 0",
        ),
        // v33：病历危急值只读标记（is_critical）——系统自动插入的「危急值记录」
        // 续写文书全局只读，不占用医生编辑位、任何人不可删除/修改。
        // 独立版本：v32 早期运行环境可能已在 v32 阶段建库完成，须补此列。
        33 => array(
            "ALTER TABLE patient_records ADD COLUMN is_critical INTEGER DEFAULT 0",
        ),
        // v36：存证/电子签名扩展列（patient_records）：
        // 保存时自动计算内容哈希（SHA-256）并视模式对接外部 CA/时间戳服务，记录存证凭据
        36 => array(
            "ALTER TABLE patient_records ADD COLUMN evid_hash TEXT DEFAULT ''",
            "ALTER TABLE patient_records ADD COLUMN evid_algo TEXT DEFAULT 'SHA-256'",
            "ALTER TABLE patient_records ADD COLUMN evid_token TEXT DEFAULT ''",
            "ALTER TABLE patient_records ADD COLUMN evid_signer TEXT DEFAULT ''",
            "ALTER TABLE patient_records ADD COLUMN evid_time TEXT DEFAULT ''",
        ),
        // v43：归档单据医院名称快照（病历侧）——医院改名后历史归档仍显示开具时名称；
        // 病历另固化 dept_name（科室改名/撤并不影响已归档文书展示）。
        43 => array(
            "ALTER TABLE patient_records ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE patient_records ADD COLUMN hospital_name2 TEXT DEFAULT ''",
            "ALTER TABLE patient_records ADD COLUMN dept_name TEXT DEFAULT ''",
            "ALTER TABLE records ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE records ADD COLUMN hospital_name2 TEXT DEFAULT ''",
            "ALTER TABLE records ADD COLUMN dept_name TEXT DEFAULT ''",
        ),
    ),
);
