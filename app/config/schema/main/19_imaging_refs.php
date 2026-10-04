<?php
/**
 * ============================================================
 * main/19_imaging_refs.php — 影像引用表（PACS/三单匹配）
 * ============================================================ */
return array(
    'tables' => array(),
    'migrations' => array(
        // v34：影像引用表（PACS/三单匹配，优化项1/2）——平台只存影像引用与元数据，
        // 不存影像二进制；study_uid 唯一，region 指向区域影像存储（Orthanc + 对象存储等）
        34 => array(
            "CREATE TABLE IF NOT EXISTS imaging_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_item_id INTEGER NOT NULL DEFAULT 0,
                order_id INTEGER NOT NULL DEFAULT 0,
                visit_id INTEGER NOT NULL DEFAULT 0,
                patient_no TEXT NOT NULL DEFAULT '',
                flow_no TEXT NOT NULL DEFAULT '',
                study_uid TEXT NOT NULL DEFAULT '',
                series_uids TEXT DEFAULT '',
                instance_count INTEGER DEFAULT 0,
                modality TEXT DEFAULT '',
                region TEXT DEFAULT 'region-pacs',
                meta_json TEXT DEFAULT '{}',
                created_by TEXT DEFAULT '',
                created_at TEXT,
                updated_at TEXT
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_imaging_refs_study ON imaging_refs(study_uid)",
            "CREATE INDEX IF NOT EXISTS idx_imaging_refs_order_item ON imaging_refs(order_item_id)",
            "CREATE INDEX IF NOT EXISTS idx_imaging_refs_flow ON imaging_refs(flow_no)",
            "CREATE INDEX IF NOT EXISTS idx_imaging_refs_patient ON imaging_refs(patient_no)",
        ),
    ),
);
