<?php
/**
 * ============================================================
 * main/07_orders.php — 开单 / 开单明细
 * ============================================================ */
return array(
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
            hospital_name TEXT DEFAULT '',
            hospital_name2 TEXT DEFAULT '',
            total_amount REAL DEFAULT 0,
            status TEXT DEFAULT 'open',
            created_at TEXT,
            paid_at TEXT,
            refunded_at TEXT,
            executed_by TEXT,
            category_name TEXT DEFAULT '',
            source_order_id INTEGER DEFAULT 0,
            review_by TEXT,
            reviewed_at TEXT,
            is_skin_test INTEGER DEFAULT 0
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
            unit TEXT,
            company_short TEXT,
            price REAL DEFAULT 0,
            quantity INTEGER DEFAULT 1,
            single_dose TEXT,
            frequency TEXT,
            route TEXT,
            is_nurse INTEGER DEFAULT 0,
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
    ),
    'migrations' => array(
        // v8：orders / order_items 高频查询索引（原 v8 跨表索引按所属模块拆分至此）
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_orders_visit ON orders(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id)",
        ),
        // v12：处方审方通过时间（orders.dispensed_at）——整单发药记录独立时间，
        // 不复用 refunded_at（退费时间语义）
        12 => array(
            "ALTER TABLE orders ADD COLUMN dispensed_at TEXT",
        ),
        // v20：检验登记时间（order_items.registered_at）——报告单「检验时间」列展示
        20 => array(
            "ALTER TABLE order_items ADD COLUMN registered_at TEXT",
        ),
        // v23：处方审方拆分——审方通过（review_by/reviewed_at，待发药）与发药（done_by/
        // dispensed_at）分离，支持审方人≠发药人（orders.status='reviewed' 为审方通过待发药）
        23 => array(
            "ALTER TABLE orders ADD COLUMN review_by TEXT",
            "ALTER TABLE orders ADD COLUMN reviewed_at TEXT",
        ),
        // v24：需皮试药品开单拆分——orders.is_skin_test 标记皮试单（皮试处方/皮试处置），
        // 皮试结果表（skin_test_results）记录阳性/阴性；皮试阴性前正式处方/处置不可缴费
        24 => array(
            "ALTER TABLE orders ADD COLUMN is_skin_test INTEGER DEFAULT 0",
        ),
        // v27：业务单号存量去重——orders.order_no 历史数据可能残留并发撞号产生的
        // 重复单号，为重复行的非首条追加 `_id` 后缀使其唯一（仅动重复行）
        27 => array(
            "UPDATE orders SET order_no = order_no || '_' || id
             WHERE id IN (SELECT id FROM (SELECT o.id, ROW_NUMBER() OVER (PARTITION BY o.order_no ORDER BY o.id) rn FROM orders o WHERE o.order_no IS NOT NULL AND o.order_no <> '') x WHERE x.rn > 1)",
        ),
        // v28：申请单号唯一约束（配撞号重试，杜绝并发 TOCTOU 撞号）
        28 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_order_no ON orders(order_no)",
        ),
        // v38：门诊拆零销售与数量自动计算重构（order_items 侧）——
        //  · order_items.unit_type 开立销售单位（pack=包装单位盒/瓶 / min=最小单位支/粒/片），
        //    打印/药房发药/退费库存恢复均按此区分（历史数据回退 pack=整盒）；
        //  · order_items.pack_size 每包装含最小单位数快照——整盒售出时库存扣减
        //    数量×pack_size、拆零按实际支/粒数扣减，退费/审方驳回恢复口径一致；
        //  · 历史处方明细 pack_size 回填：迁移前开立均为整包装（pack）口径，
        //    按当前药品每包装最小单位数补齐快照——退费/审方驳回恢复库存口径一致。
        38 => array(
            "ALTER TABLE order_items ADD COLUMN unit_type TEXT DEFAULT 'pack'",
            "ALTER TABLE order_items ADD COLUMN pack_size INTEGER DEFAULT 1",
            "UPDATE order_items SET pack_size = COALESCE((SELECT spec_pack_qty FROM drugs WHERE drugs.id = order_items.item_id), 1) WHERE item_type='prescription' AND (pack_size IS NULL OR pack_size < 1)",
        ),
        // v43：归档单据医院名称快照（orders 侧）
        43 => array(
            "ALTER TABLE orders ADD COLUMN hospital_name TEXT DEFAULT ''",
            "ALTER TABLE orders ADD COLUMN hospital_name2 TEXT DEFAULT ''",
        ),
    ),
);
