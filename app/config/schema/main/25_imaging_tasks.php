<?php
/**
 * ============================================================
 * main/25_imaging_tasks.php — 影像检查工作项（FHIR Task）
 * ============================================================
 * 影像检查的执行工作项：由门诊开单/缴费产生（requested），
 * 由 PACS 侧登记 / 摄片推进状态并经 FHIR 回写（accepted / in-progress / completed）。
 * 与 imaging_refs（摄片完成后的影像引用）分离：Task 描述流程，引用描述影像本体。
 * ============================================================ */
return array(
    'tables' => array(
        'imaging_tasks' => "CREATE TABLE IF NOT EXISTS imaging_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_item_id INTEGER NOT NULL DEFAULT 0,
            order_id INTEGER NOT NULL DEFAULT 0,
            visit_id INTEGER NOT NULL DEFAULT 0,
            patient_no TEXT NOT NULL DEFAULT '',
            flow_no TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'requested',
            business_status TEXT DEFAULT '',
            owner TEXT DEFAULT 'PACS',
            status_reason TEXT DEFAULT '',
            registered_at TEXT DEFAULT '',
            performed_at TEXT DEFAULT '',
            created_at TEXT,
            updated_at TEXT
        )",
    ),
    'migrations' => array(
        // v50：影像检查工作项（FHIR Task）——支撑「摄片登记」两步流程与退费门禁
        50 => array(
            "CREATE TABLE IF NOT EXISTS imaging_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_item_id INTEGER NOT NULL DEFAULT 0,
                order_id INTEGER NOT NULL DEFAULT 0,
                visit_id INTEGER NOT NULL DEFAULT 0,
                patient_no TEXT NOT NULL DEFAULT '',
                flow_no TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'requested',
                business_status TEXT DEFAULT '',
                owner TEXT DEFAULT 'PACS',
                status_reason TEXT DEFAULT '',
                registered_at TEXT DEFAULT '',
                performed_at TEXT DEFAULT '',
                created_at TEXT,
                updated_at TEXT
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_imaging_tasks_item ON imaging_tasks(order_item_id)",
            "CREATE INDEX IF NOT EXISTS idx_imaging_tasks_status ON imaging_tasks(status)",
            "CREATE INDEX IF NOT EXISTS idx_imaging_tasks_patient ON imaging_tasks(patient_no)",
        ),
    ),
);
