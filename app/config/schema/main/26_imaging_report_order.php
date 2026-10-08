<?php
/**
 * ============================================================
 * main/26_imaging_report_order.php — 影像报告按申请单（v51）
 * ============================================================
 * A2 模型改造：
 *   · reports/results 增加 order_id：影像报告以「申请单」为单位（报告号与检查号 1:1）；
 *   · reports 增加 version：报告修订沿用同一报告号，版本递增；
 *   · 新增 report_versions：保留历史版本（修订溯源）；
 *   · critical_values 增加 order_id：危急值可关联申请单（多检查项目多选）。
 * ============================================================ */
return array(
    'tables' => array(),
    'migrations' => array(
        51 => array(
            "ALTER TABLE results ADD COLUMN order_id INTEGER DEFAULT 0",
            "ALTER TABLE reports ADD COLUMN order_id INTEGER DEFAULT 0",
            "ALTER TABLE reports ADD COLUMN version INTEGER DEFAULT 1",
            "CREATE INDEX IF NOT EXISTS idx_reports_order ON reports(order_id)",
            "CREATE INDEX IF NOT EXISTS idx_results_order ON results(order_id)",
            "ALTER TABLE critical_values ADD COLUMN order_id INTEGER DEFAULT 0",
            "CREATE INDEX IF NOT EXISTS idx_critical_order ON critical_values(order_id)",
            "CREATE TABLE IF NOT EXISTS report_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id INTEGER NOT NULL DEFAULT 0,
                report_no TEXT DEFAULT '',
                version INTEGER DEFAULT 1,
                content TEXT DEFAULT '',
                findings TEXT DEFAULT '',
                conclusion TEXT DEFAULT '',
                doctor_name TEXT DEFAULT '',
                status TEXT DEFAULT 'superseded',
                reason TEXT DEFAULT '',
                created_by TEXT DEFAULT '',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_report_versions_report ON report_versions(report_id)",
        ),
    ),
);
