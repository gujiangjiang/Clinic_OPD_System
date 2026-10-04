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
    ),
);
