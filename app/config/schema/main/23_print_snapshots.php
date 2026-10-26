<?php
/**
 * ============================================================
 * main/23_print_snapshots.php — 单据打印快照
 * ============================================================ */
return array(
    'tables' => array(

        'print_snapshots' => "CREATE TABLE IF NOT EXISTS print_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            biz_type TEXT NOT NULL,
            biz_id INTEGER NOT NULL,
            patient_no TEXT DEFAULT '',
            patient_name TEXT DEFAULT '',
            gender TEXT DEFAULT '',
            birth_date TEXT DEFAULT '',
            id_card TEXT DEFAULT '',
            ethnicity TEXT DEFAULT '',
            job TEXT DEFAULT '',
            marital TEXT DEFAULT '',
            phone TEXT DEFAULT '',
            extra_json TEXT DEFAULT '{}',
            -- 时区固化（法律合规）：开具/生成时刻的站点时区名与 UTC 偏移，
            -- 历史单据重打的时间显示一律按该时区渲染，不随全站时区修改/存储
            -- 方案切换而漂移（见 AGENTS.md「时间与时区铁律」）
            tz TEXT DEFAULT '',
            tz_offset TEXT DEFAULT '',
            created_at TEXT
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_print_snapshot_biz ON print_snapshots(biz_type, biz_id)",
    ),
    'migrations' => array(
        // v37：单据打印快照表（法律合规：申请单/缴费凭条/报告单/诊断证明/病历打印时
        // 优先使用开单/出具/保存时刻的当事人与上下文快照，杜绝事后改字典/患者资料
        // 改变历史单据显示；biz_type+UNIQUE 行级快照，extra 存报告项目元数据等）
        37 => array(
            "CREATE TABLE IF NOT EXISTS print_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                biz_type TEXT NOT NULL,
                biz_id INTEGER NOT NULL,
                patient_no TEXT DEFAULT '',
                patient_name TEXT DEFAULT '',
                gender TEXT DEFAULT '',
                birth_date TEXT DEFAULT '',
                id_card TEXT DEFAULT '',
                ethnicity TEXT DEFAULT '',
                job TEXT DEFAULT '',
                marital TEXT DEFAULT '',
                phone TEXT DEFAULT '',
                extra_json TEXT DEFAULT '{}',
                created_at TEXT
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_print_snapshot_biz ON print_snapshots(biz_type, biz_id)",
        ),
        // v54：快照时区固化——记录开具/生成时刻的站点时区名与 UTC 偏移，
        // 保障历史单据重打的时间显示不随全站时区修改而漂移（法律合规）；
        // ALTER ADD COLUMN 由 DatabaseManager 做列存在性幂等跳过（新库已含列）
        54 => array(
            "ALTER TABLE print_snapshots ADD COLUMN tz TEXT DEFAULT ''",
            "ALTER TABLE print_snapshots ADD COLUMN tz_offset TEXT DEFAULT ''",
        ),
    ),
);
