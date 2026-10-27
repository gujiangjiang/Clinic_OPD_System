<?php
/**
 * ============================================================
 * main/06_patients.php — 患者档案 / 挂号记录
 * ============================================================ */
return array(
    'tables' => array(

        'patients' => "CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_no VARCHAR(191) UNIQUE,
            id_card VARCHAR(191) UNIQUE,
            name TEXT,
            gender TEXT,
            birth_date TEXT,
            age INTEGER DEFAULT 0,
            ethnicity TEXT,
            marital TEXT,
            occupation TEXT,
            work_unit TEXT,
            address TEXT,
            phone TEXT,
            has_past_history TEXT DEFAULT '',
            past_history TEXT DEFAULT '',
            allergy_history TEXT DEFAULT '',
            created_at TEXT
        )",

        'registrations' => "CREATE TABLE IF NOT EXISTS registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_no TEXT,
            flow_no VARCHAR(191) UNIQUE,
            visit_seq INTEGER DEFAULT 0,
            first_dept_id INTEGER,
            first_dept_name TEXT,
            current_dept_id INTEGER,
            current_dept_name TEXT,
            session TEXT,
            fee_type TEXT,
            fee REAL DEFAULT 0,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            visit_type TEXT DEFAULT 'outpatient',
            inpatient_no TEXT DEFAULT '',
            status TEXT DEFAULT 'pending',
            paid_at TEXT,
            cashier_id INTEGER,
            cashier_name TEXT,
            registered_at TEXT,
            reg_date TEXT DEFAULT '',
            cancel_reason TEXT,
            is_extra INTEGER DEFAULT 0,
            disposition TEXT DEFAULT '',
            disposition_detail TEXT DEFAULT '',
            finished_at TEXT DEFAULT ''
        )",
    ),
    'migrations' => array(
        // v8：registrations 高频查询索引（原 v8 跨表索引按所属模块拆分至此）
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_registrations_patient ON registrations(patient_no)",
            "CREATE INDEX IF NOT EXISTS idx_registrations_dept_date ON registrations(first_dept_id, date(registered_at))",
        ),
        // v25：就诊序号去重——历史数据中可能残留并发挂号造成的重复序号
        // （同一科室同日 visit_seq 相同），先为重复行的非首条重新分配
        // 「该科室当日最大序号 + 组内序次」，保证存量数据可承载下方唯一索引。
        // 仅重编号重复行，不改变既有序号；无重复的分组不受影响。
        25 => array(
            "UPDATE registrations SET visit_seq = (
                SELECT m.mx + t.rk
                FROM (
                    SELECT MAX(r2.visit_seq) AS mx
                    FROM registrations r2
                    WHERE r2.first_dept_id = registrations.first_dept_id
                      AND date(r2.registered_at) = date(registrations.registered_at)
                ) m,
                (
                    SELECT COUNT(*) AS rk
                    FROM registrations r3
                    WHERE r3.first_dept_id = registrations.first_dept_id
                      AND date(r3.registered_at) = date(registrations.registered_at)
                      AND r3.visit_seq = registrations.visit_seq
                      AND (r3.registered_at < registrations.registered_at OR (r3.registered_at = registrations.registered_at AND r3.id < registrations.id))
                ) t
            )
            WHERE id IN (
                SELECT id FROM (
                    SELECT r4.id,
                           ROW_NUMBER() OVER (PARTITION BY r4.first_dept_id, date(r4.registered_at), r4.visit_seq ORDER BY r4.registered_at, r4.id) AS rn
                    FROM registrations r4
                ) x WHERE x.rn > 1
            )",
        ),
        // v26：就诊序号唯一约束——同科室同日 visit_seq 不可重复。
        // 就诊序号由 MAX+1 生成（挂号事务内），配合本唯一索引 + 挂号撞号重试，
        // 杜绝并发挂号得到相同就诊序号（原 COUNT+1 无锁，双窗口并发可能重复）。
        // 注：SQLite 方言（ROW_NUMBER 窗口函数）仅用于 SQLite；MySQL 部署
        // 若此处失败仅停在本版本（等价于不加索引），不影响既有功能。
        26 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_registrations_dept_date_seq ON registrations(first_dept_id, date(registered_at), visit_seq)",
        ),
        // v44：挂号记录医院名称快照（挂号凭条补打沿用挂号时医院名称）
        44 => array(
            "ALTER TABLE registrations ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE registrations ADD COLUMN hospital_name2 TEXT DEFAULT ''",
        ),
        // v52：就诊类型与住院号（跨系统标识动态显示）
        //  - visit_type：outpatient（默认）/ emergency / inpatient；门诊与急诊前端
        //    统一显示「门诊号」，住院显示「住院号」，未分类回退中性「就诊号」
        //  - inpatient_no：住院患者的住院号（HIS admission_no 快照）
        52 => array(
            "ALTER TABLE registrations ADD COLUMN visit_type TEXT DEFAULT 'outpatient'",
            "ALTER TABLE registrations ADD COLUMN inpatient_no TEXT DEFAULT ''",
            "CREATE INDEX IF NOT EXISTS idx_registrations_type_inpatient ON registrations(visit_type, inpatient_no)",
        ),
        // v55：挂号业务日期列 reg_date（本地日 Y-m-d）——唯一索引/查询改用它，
        // 解除「date(registered_at) 函数索引」对 registered_at 的依赖：
        //  · 业务日语义（同科室同日序号唯一）必须定义在本地时区；
        //  · registered_at 由此可安全参与 UTC 存储切换（函数式唯一索引在平移
        //    时区时会产生唯一键冲突——见 tools/cli/tz_migrate.php 阻断规则）。
        // 回填：存量按 registered_at 的本地日期截取（date() 为纯字符串截断，
        // 不涉及时区换算，三驱动语义一致）；DROP INDEX 走跨库统一写法
        // （DatabaseManager::execSchemaSql 的存在性检查 + 各驱动语法）。
        55 => array(
            "ALTER TABLE registrations ADD COLUMN reg_date TEXT DEFAULT ''",
            "UPDATE registrations SET reg_date = date(registered_at) WHERE (reg_date IS NULL OR reg_date='') AND registered_at IS NOT NULL AND registered_at != ''",
            "DROP INDEX IF EXISTS idx_registrations_dept_date_seq ON registrations",
            "DROP INDEX IF EXISTS idx_registrations_dept_date ON registrations",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_registrations_dept_date_seq ON registrations(first_dept_id, reg_date, visit_seq)",
            "CREATE INDEX IF NOT EXISTS idx_registrations_dept_date ON registrations(first_dept_id, reg_date)",
        ),
    ),
);
