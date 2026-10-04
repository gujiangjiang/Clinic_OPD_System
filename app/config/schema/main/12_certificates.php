<?php
/**
 * ============================================================
 * main/12_certificates.php — 诊断证明 / 转诊 / 诊断医嘱 / 知情同意书
 * ============================================================ */
return array(
    'tables' => array(

        'certificates' => "CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            dept_id INTEGER DEFAULT 0,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            content TEXT,
            created_at TEXT,
            cert_no TEXT DEFAULT '',
            chief_complaint TEXT DEFAULT '',
            present_illness TEXT DEFAULT '',
            preliminary_diagnosis TEXT DEFAULT '',
            evid_hash TEXT DEFAULT '',
            evid_algo TEXT DEFAULT 'SHA-256',
            evid_token TEXT DEFAULT '',
            evid_signer TEXT DEFAULT '',
            evid_time TEXT DEFAULT ''
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

        'diag_orders' => "CREATE TABLE IF NOT EXISTS diag_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            doctor_id INTEGER,
            order_keys TEXT DEFAULT '',
            updated_at TEXT,
            UNIQUE(visit_id, doctor_id)
        )",

        'consents' => "CREATE TABLE IF NOT EXISTS consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            title TEXT,
            content TEXT,
            notice TEXT DEFAULT '',
            emr_snapshot TEXT DEFAULT '',
            doctor_id INTEGER,
            doctor_name TEXT,
            dept_id INTEGER DEFAULT 0,
            dept_name TEXT,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            created_at TEXT,
            updated_at TEXT
        )",
    ),
    'migrations' => array(
        // v9：存量数据回填（certificates.cert_no 旧数据可能为空，补正）
        9 => array(
            "UPDATE certificates SET cert_no = 'ZM' || replace(substr(created_at,1,10),'-','') || substr('0000' || id, -4, 4) WHERE cert_no IS NULL OR cert_no = ''",
        ),
        // v10：诊断证明增加 dept_id 字段（记录开具时科室，用于删除权限校验）
        10 => array(
            "ALTER TABLE certificates ADD COLUMN dept_id INTEGER DEFAULT 0",
        ),
        // v19：知情同意书固定开具科室（dept_id/dept_name）——就诊科室随创建时
        // 固化，转科/会诊后打印与展示仍显示开具时的科室
        19 => array(
            "ALTER TABLE consents ADD COLUMN dept_id INTEGER DEFAULT 0",
            "ALTER TABLE consents ADD COLUMN dept_name TEXT",
        ),
        // v27：业务单号存量去重（certificates.cert_no）——重复行的非首条追加 `_id` 后缀
        27 => array(
            "UPDATE certificates SET cert_no = cert_no || '_' || id
             WHERE id IN (SELECT id FROM (SELECT c.id, ROW_NUMBER() OVER (PARTITION BY c.cert_no ORDER BY c.id) rn FROM certificates c WHERE c.cert_no IS NOT NULL AND c.cert_no <> '') x WHERE x.rn > 1)",
        ),
        // v28：证明号唯一约束（配撞号重试，杜绝并发 TOCTOU 撞号）
        28 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_certificates_cert_no ON certificates(cert_no)",
        ),
        // v29：知情同意书升级——告知内容（notice，可自定义话术，签名区上方显示）
        // 与病历内容快照（emr_snapshot：勾选节 + 各节文本 JSON），开具即固化，
        // 后续病历修改不影响已开具文书；编辑重存时随当前病历重新快照
        29 => array(
            "ALTER TABLE consents ADD COLUMN notice TEXT DEFAULT ''",
            "ALTER TABLE consents ADD COLUMN emr_snapshot TEXT DEFAULT ''",
        ),
        // v36：存证/电子签名扩展列（certificates）：
        // 开具时自动计算内容哈希（SHA-256）并视模式对接外部 CA/时间戳服务，记录存证凭据
        36 => array(
            "ALTER TABLE certificates ADD COLUMN evid_hash TEXT DEFAULT ''",
            "ALTER TABLE certificates ADD COLUMN evid_algo TEXT DEFAULT 'SHA-256'",
            "ALTER TABLE certificates ADD COLUMN evid_token TEXT DEFAULT ''",
            "ALTER TABLE certificates ADD COLUMN evid_signer TEXT DEFAULT ''",
            "ALTER TABLE certificates ADD COLUMN evid_time TEXT DEFAULT ''",
        ),
        // v43：归档单据医院名称快照（证明/同意书侧）
        43 => array(
            "ALTER TABLE certificates ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE certificates ADD COLUMN hospital_name2 TEXT DEFAULT ''",
            "ALTER TABLE consents ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE consents ADD COLUMN hospital_name2 TEXT DEFAULT ''",
        ),
    ),
);
