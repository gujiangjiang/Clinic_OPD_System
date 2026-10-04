<?php
/**
 * ============================================================
 * main/08_billing.php — 缴费 / 退费 / 退费审批流 / 库存流水
 * ============================================================ */
return array(
    'tables' => array(

        'payments' => "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            order_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            kind TEXT DEFAULT 'visit',
            total_amount REAL DEFAULT 0,
            item_count INTEGER DEFAULT 0,
            cashier_id INTEGER,
            cashier_name TEXT,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            created_at TEXT
        )",

        'refunds' => "CREATE TABLE IF NOT EXISTS refunds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            order_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            total_amount REAL DEFAULT 0,
            reason TEXT,
            cashier_id INTEGER,
            cashier_name TEXT,
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            created_at TEXT,
            payment_no TEXT,
            method TEXT
        )",

        // 退费申请审批流（已执行项目需多方确认后放行）
        'refund_requests' => "CREATE TABLE IF NOT EXISTS refund_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            payment_no TEXT,
            order_ids TEXT,
            reason TEXT,
            status TEXT DEFAULT 'pending',
            created_by INTEGER,
            created_at TEXT
        )",
        'refund_approvals' => "CREATE TABLE IF NOT EXISTS refund_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER,
            role TEXT,
            user_id INTEGER,
            user_name TEXT,
            verdict TEXT DEFAULT 'pending',
            note TEXT,
            decided_at TEXT
        )",

        'inventory_trans' => "CREATE TABLE IF NOT EXISTS inventory_trans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            drug_id INTEGER,
            qty_change INTEGER,
            type TEXT,
            ref_no TEXT,
            operator TEXT,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v8：payments 高频查询索引（原 v8 跨表索引按所属模块拆分至此）
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_payments_visit ON payments(visit_id)",
        ),
        // v13：缴费流水号（payments.payment_no）——每次缴费（含批量合并）生成唯一编号，
        // 打印凭条/补打/退费批次判定（同批次不可单独退费）统一以此关联
        13 => array(
            "ALTER TABLE payments ADD COLUMN payment_no TEXT",
            "ALTER TABLE refunds ADD COLUMN payment_no TEXT",
        ),
        // v14：支付方式（现金/医保/银行卡/扫码）——缴费与退费记录支付方式
        14 => array(
            "ALTER TABLE payments ADD COLUMN method TEXT",
            "ALTER TABLE refunds ADD COLUMN method TEXT",
        ),
        // v15：退费申请审批流——已开始执行的项目不可直接退费，
        // 需由开单医生/检验/影像/药房/护士站等经站内消息逐级确认后放行
        15 => array(
            "CREATE TABLE IF NOT EXISTS refund_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visit_id INTEGER,
                patient_no TEXT,
                flow_no TEXT,
                payment_no TEXT,
                order_ids TEXT,
                reason TEXT,
                status TEXT DEFAULT 'pending',
                requested_by TEXT,
                created_at TEXT,
                updated_at TEXT
            )",
            "CREATE TABLE IF NOT EXISTS refund_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id INTEGER,
                role TEXT,
                user_id INTEGER,
                user_name TEXT,
                verdict TEXT DEFAULT 'pending',
                note TEXT,
                decided_at TEXT
            )",
        ),
        // v43：归档单据医院名称快照（缴费/退费侧）
        43 => array(
            "ALTER TABLE payments ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE payments ADD COLUMN hospital_name2 TEXT DEFAULT ''",
            "ALTER TABLE refunds ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE refunds ADD COLUMN hospital_name2 TEXT DEFAULT ''",
        ),
    ),
);
