<?php
/**
 * ============================================================
 * 005_order.php — 开单/缴费库 schema
 * 说明：开单（检验/检查/处置/处方）、开单明细（含流程状态）、
 * 缴费记录、退费记录、药品库存流水
 * 明细流程状态：open 开单 → paid 缴费 → registered 登记 →
 *              executing 执行中 → done 完成（处方：dispensed 已发药）
 *              refunded 退费 / cancelled 取消
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 6,
    'tables' => array(
        'orders' => "CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            order_type TEXT,
            order_no TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            record_id INTEGER DEFAULT 0,
            dept_id INTEGER DEFAULT 0,
            dept_name TEXT DEFAULT '',
            total_amount REAL DEFAULT 0,
            status TEXT DEFAULT 'open',
            created_at TEXT,
            paid_at TEXT,
            refunded_at TEXT,
            done_by TEXT,
            cat_name TEXT DEFAULT '',
            source_order_id INTEGER DEFAULT 0
        )",
        'order_items' => "CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            item_type TEXT,
            item_id INTEGER,
            item_name TEXT,
            spec TEXT,
            unit_name TEXT,
            company_short TEXT,
            price REAL DEFAULT 0,
            quantity INTEGER DEFAULT 1,
            single_dose TEXT,
            frequency_name TEXT,
            route_name TEXT,
            need_nurse INTEGER DEFAULT 0,
            sub_of INTEGER DEFAULT 0,
            group_no INTEGER DEFAULT 0,
            is_parent INTEGER DEFAULT 1,
            parent_item_id INTEGER DEFAULT 0,
            status TEXT DEFAULT 'open',
            doctor_id INTEGER,
            doctor_name TEXT,
            executed_by TEXT,
            executed_at TEXT,
            result_id INTEGER DEFAULT 0,
            created_at TEXT
        )",
        'payments' => "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            order_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            kind TEXT DEFAULT 'visit',
            total REAL DEFAULT 0,
            item_count INTEGER DEFAULT 0,
            cashier_id INTEGER,
            cashier_name TEXT,
            created_at TEXT
        )",
        'refunds' => "CREATE TABLE IF NOT EXISTS refunds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            order_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            total REAL DEFAULT 0,
            reason TEXT,
            cashier_id INTEGER,
            cashier_name TEXT,
            created_at TEXT
        )",
        'inventory_trans' => "CREATE TABLE IF NOT EXISTS inventory_trans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            drug_id INTEGER,
            qty_change INTEGER,
            type TEXT,
            ref TEXT,
            operator TEXT,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        // v2：旧库升级 —— 给 order_items 增加 result_id（关联检验/检查报告结果）。
        // 说明：新库建表已包含该列，DatabaseManager 迁移器会自动检测列已存在并跳过；
        //       MySQL 下该语句同样会被跳过（列已存在），如需增量升级可手动执行。
        2 => array(
            "ALTER TABLE order_items ADD COLUMN result_id INTEGER DEFAULT 0",
        ),
        // v3：检查申请单按「检查分类」拆分——orders 记录所属分类名称快照，
        //     用于打印标题动态显示（如 CT申请单 / DR（数字化X线）申请单）
        3 => array(
            "ALTER TABLE orders ADD COLUMN cat_name TEXT DEFAULT ''",
        ),
        // v4：成组医嘱（子医嘱/输液组方）——组号、主/子药标记、关联主药条目。
        // group_no：同一组的药品共享组号；is_parent：1=主药 0=子药；
        // parent_item_id：子药关联的主药条目 ID（本单内，0=主药自身）
        4 => array(
            "ALTER TABLE order_items ADD COLUMN group_no INTEGER DEFAULT 0",
            "ALTER TABLE order_items ADD COLUMN is_parent INTEGER DEFAULT 1",
            "ALTER TABLE order_items ADD COLUMN parent_item_id INTEGER DEFAULT 0",
        ),
        // v5：联动处置来源处方——orders.source_order_id 记录该处置单是由哪个处方
        // 自动生成的，删除处方时级联删除联动处置单（独立删除不反删处方）。
        5 => array(
            "ALTER TABLE orders ADD COLUMN source_order_id INTEGER DEFAULT 0",
        ),
        // v6：开单与病历文书强关联——record_id 记录开单时所在的病历（首诊/续写/会诊），
        // dept_id/dept_name 固化开单科室（打印/展示不再随转科漂移）。
        6 => array(
            "ALTER TABLE orders ADD COLUMN record_id INTEGER DEFAULT 0",
            "ALTER TABLE orders ADD COLUMN dept_id INTEGER DEFAULT 0",
            "ALTER TABLE orders ADD COLUMN dept_name TEXT DEFAULT ''",
        ),
    ),
    'seed' => array(),
);
