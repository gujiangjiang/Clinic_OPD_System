<?php
/**
 * ============================================================
 * main/21_critical_values.php — 危急值
 * ============================================================ */
return array(
    'tables' => array(

        'critical_values' => "CREATE TABLE IF NOT EXISTS critical_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT,                      -- 'lab' 检验科 / 'imaging' 影像科
            report_id INTEGER DEFAULT 0,      -- 关联报告（快照已固化，撤回修改不影响）
            result_id INTEGER DEFAULT 0,
            visit_id INTEGER DEFAULT 0,
            patient_no TEXT,
            flow_no TEXT,
            patient_name TEXT,
            patient_gender TEXT,
            birth_date TEXT,
            patient_age TEXT,
            item_name TEXT,                   -- 展示标题：检验项目名 / 影像手输危急值项目名
            items_json TEXT,                  -- 危急值明细 JSON（检验=检出危急值项目；影像=手输项目）
            snapshot_json TEXT,               -- 报告完整快照（检验=全部检验结果；影像=所见+结论）
            from_dept_name TEXT,              -- 发起科室
            from_user_id INTEGER DEFAULT 0,   -- 发起人（检验技师/影像技师）
            from_user_name TEXT,
            to_doctor_id INTEGER DEFAULT 0,   -- 接收医生
            to_doctor_name TEXT,
            status TEXT DEFAULT 'pending',    -- 'pending' 待处理 / 'done' 已处理
            match_status TEXT,                -- 'match' 符合病情 / 'mismatch' 不符合病情
            treatment TEXT,                   -- 处理措施
            processed_by INTEGER DEFAULT 0,   -- 处理医生 id
            processed_at TEXT,                -- 处理时间
            record_id INTEGER DEFAULT 0,      -- 处理时自动插入的病历续写记录 id
            created_at TEXT                   -- 发送时间（= 接收时间）
        )",
        "CREATE INDEX IF NOT EXISTS idx_critical_visit ON critical_values(visit_id)",
        "CREATE INDEX IF NOT EXISTS idx_critical_to_doctor ON critical_values(to_doctor_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_critical_source ON critical_values(source, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_critical_created ON critical_values(created_at)",
    ),
    'migrations' => array(
        // v32：危急值体系——危急值记录表（检验科自动检测 / 影像科手动上报）。
        // 记录一经创建不可删除/修改（无任何删除修改接口），处理仅推进
        // status/match/treatment/processed_* 状态字段；报告快照独立固化
        // 在 snapshot_json，检验科后期撤回修改数据不影响已处理危急值展示。
        // 注：v32 中 patient_records.is_critical 列归属病历模块（见 10_records.php）。
        32 => array(
            "CREATE TABLE IF NOT EXISTS critical_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT,
                report_id INTEGER DEFAULT 0,
                result_id INTEGER DEFAULT 0,
                visit_id INTEGER DEFAULT 0,
                patient_no TEXT,
                flow_no TEXT,
                patient_name TEXT,
                patient_gender TEXT,
                birth_date TEXT,
                patient_age TEXT,
                item_name TEXT,
                items_json TEXT,
                snapshot_json TEXT,
                from_dept_name TEXT,
                from_user_id INTEGER DEFAULT 0,
                from_user_name TEXT,
                to_doctor_id INTEGER DEFAULT 0,
                to_doctor_name TEXT,
                status TEXT DEFAULT 'pending',
                match_status TEXT,
                treatment TEXT,
                processed_by INTEGER DEFAULT 0,
                processed_at TEXT,
                record_id INTEGER DEFAULT 0,
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_critical_visit ON critical_values(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_critical_to_doctor ON critical_values(to_doctor_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_critical_source ON critical_values(source, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_critical_created ON critical_values(created_at)",
        ),
    ),
);
