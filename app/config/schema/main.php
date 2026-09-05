<?php
/**
 * ============================================================
 * main.php — 统一业务主库 schema（clinic_main）
 * ============================================================
 * 说明：本文件定义合并后的唯一业务主库（SQLite clinic_main.db
 * 或 MySQL his_main），全部业务表聚合于此，字段名已按规范化
 * 标准统一（时间 _at/_date、布尔 is_/has_、EMR 核心字段、体征 vital_*）。
 *
 * 双驱动兼容：
 * - 主键统一 INTEGER PRIMARY KEY AUTOINCREMENT（MySQL 由 DatabaseManager
 *   方言层自动转换为 AUTO_INCREMENT）
 * - 布尔统一 INTEGER 0/1（MySQL 兼容 TINYINT）
 * - 种子用 INSERT OR IGNORE（MySQL 自动转为 INSERT IGNORE）
 * - 时间默认 datetime('now','localtime')（MySQL 自动转为 NOW()）
 *
 * 旧分散式 schema 归档于 app/config/schema/legacy/，供数据迁移工具
 * （tools/migrate_split_to_unified.php）引用旧字段名与建表语句。
 * ============================================================ */
return array(
    'version' => 17,
    'tables' => array(

        /* ---------------- 系统设置 / 消息 / 审核 ---------------- */

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            skey TEXT PRIMARY KEY,
            svalue TEXT
        )",

        'messages' => "CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_name TEXT,
            from_user_id INTEGER DEFAULT 0,
            to_role TEXT,
            to_user_id INTEGER,
            title TEXT,
            content TEXT,
            print_type TEXT,
            print_url TEXT,
            is_read INTEGER DEFAULT 0,
            msg_type TEXT DEFAULT 'system',
            patient_name TEXT DEFAULT '',
            visit_id INTEGER DEFAULT 0,
            link_url TEXT DEFAULT '',
            created_at TEXT
        )",

        'sent_messages' => "CREATE TABLE IF NOT EXISTS sent_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER,
            sender_name TEXT,
            title TEXT,
            content TEXT,
            recipients TEXT,
            recipient_count INTEGER DEFAULT 0,
            created_at TEXT
        )",

        'audits' => "CREATE TABLE IF NOT EXISTS audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            ref_id INTEGER,
            title TEXT,
            content TEXT,
            data TEXT,
            status TEXT DEFAULT 'pending',
            proposer TEXT,
            proposer_id INTEGER,
            created_at TEXT,
            handled_by TEXT,
            handled_at TEXT,
            note TEXT,
            creation_source TEXT DEFAULT ''
        )",

        /* ---------------- 系统用户 ---------------- */

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            emp_no TEXT,
            username TEXT UNIQUE,
            password TEXT,
            name TEXT,
            role TEXT,
            dept_ids TEXT,
            photo TEXT,
            education TEXT,
            degree TEXT,
            title TEXT,
            position TEXT,
            intro TEXT,
            theme TEXT DEFAULT 'auto',
            sidebar TEXT DEFAULT 'expand',
            pwd_changed INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            created_at TEXT,
            last_login TEXT,
            current_dept_id INTEGER DEFAULT 0,
            print_auto INTEGER DEFAULT 0,
            queue_days INTEGER DEFAULT 3,
            login_fail_count INTEGER DEFAULT 0,
            login_locked_until TEXT
        )",

        /* ---------------- 科室 / 加号 ---------------- */

        'departments' => "CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            type TEXT DEFAULT 'clinic',
            fee REAL DEFAULT 0,
            am_quota INTEGER DEFAULT 30,
            pm_quota INTEGER DEFAULT 30,
            sort INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            created_at TEXT
        )",

        'extra_slots' => "CREATE TABLE IF NOT EXISTS extra_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER,
            reg_date TEXT,
            id_card TEXT,
            name TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            used INTEGER DEFAULT 0,
            created_at TEXT
        )",

        /* ---------------- 患者档案 / 挂号记录 ---------------- */

        'patients' => "CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_no TEXT UNIQUE,
            id_card TEXT UNIQUE,
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
            flow_no TEXT UNIQUE,
            visit_seq INTEGER DEFAULT 0,
            first_dept_id INTEGER,
            first_dept_name TEXT,
            current_dept_id INTEGER,
            current_dept_name TEXT,
            session TEXT,
            fee_type TEXT,
            fee REAL DEFAULT 0,
            status TEXT DEFAULT 'pending',
            paid_at TEXT,
            cashier_id INTEGER,
            cashier_name TEXT,
            registered_at TEXT,
            cancel_reason TEXT,
            is_extra INTEGER DEFAULT 0,
            disposition TEXT DEFAULT '',
            disposition_detail TEXT DEFAULT '',
            finished_at TEXT DEFAULT ''
        )",

        /* ---------------- 开单 / 缴费 / 退费 / 库存 ---------------- */

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
            category_name TEXT DEFAULT '',
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
            created_at TEXT,
            payment_no TEXT,
            method TEXT
        )",

        /* ---------------- 退费申请审批流（已执行项目需多方确认后放行） ---------------- */
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
            ref TEXT,
            operator TEXT,
            created_at TEXT
        )",

        /* ---------------- 药品 ---------------- */

        'drug_settings' => "CREATE TABLE IF NOT EXISTS drug_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stype TEXT,
            name TEXT,
            is_nurse INTEGER DEFAULT 0,
            bind_disposal_item_id INTEGER DEFAULT 0,
            sort INTEGER DEFAULT 0
        )",

        'drugs' => "CREATE TABLE IF NOT EXISTS drugs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            generic_name TEXT,
            category TEXT,
            vendor TEXT,
            vendor_short TEXT,
            package_unit TEXT,
            spec TEXT,
            form TEXT,
            single_dose TEXT,
            frequency TEXT,
            route TEXT,
            price REAL DEFAULT 0,
            qty INTEGER DEFAULT 0,
            is_rx INTEGER DEFAULT 0,
            is_limited INTEGER DEFAULT 0,
            note TEXT,
            is_nurse INTEGER DEFAULT 0,
            is_skin_test INTEGER DEFAULT 0,
            skin_test_item_id INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at TEXT,
            spec_dose REAL DEFAULT 0,
            spec_dose_unit TEXT,
            spec_pack_qty INTEGER DEFAULT 1,
            spec_pack_unit TEXT,
            single_use_qty REAL DEFAULT 1
        )",

        /* ---------------- 病历 ---------------- */

        'records' => "CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            chief_complaint TEXT,
            present_illness TEXT,
            past_history TEXT,
            allergy_history TEXT,
            physical_exam TEXT,
            consciousness TEXT,
            preliminary_diagnosis TEXT,
            icd10_code TEXT,
            is_observation INTEGER DEFAULT 0,
            visit_type TEXT DEFAULT '初诊',
            doctor_advice TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT,
            patient_record_id INTEGER DEFAULT 0
        )",

        'patient_records' => "CREATE TABLE IF NOT EXISTS patient_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            dept_id INTEGER,
            doctor_id INTEGER,
            doctor_name TEXT,
            record_type TEXT DEFAULT 'initial',
            parent_record_id INTEGER DEFAULT 0,
            chief_complaint TEXT,
            symptom_duration TEXT,
            symptom_unit TEXT,
            informant TEXT,
            arrival_way TEXT,
            has_past_history TEXT DEFAULT '否',
            allergy_history TEXT,
            is_leave_hospital TEXT DEFAULT '否',
            icd10_code TEXT,
            diagnosis_name TEXT,
            emr_data TEXT NOT NULL,
            emr_print_text TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT,
            consultation_id INTEGER DEFAULT 0
        )",

        'templates' => "CREATE TABLE IF NOT EXISTS templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doctor_id INTEGER,
            name TEXT,
            scope TEXT DEFAULT 'personal',
            content TEXT,
            status TEXT DEFAULT 'approved',
            created_at TEXT
        )",

        'certificates' => "CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            dept_id INTEGER DEFAULT 0,
            content TEXT,
            created_at TEXT,
            cert_no TEXT DEFAULT '',
            chief_complaint TEXT DEFAULT '',
            present_illness TEXT DEFAULT '',
            preliminary_diagnosis TEXT DEFAULT ''
        )",

        'referrals' => "CREATE TABLE IF NOT EXISTS referrals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            from_dept_id INTEGER,
            from_dept_name TEXT,
            to_dept_id INTEGER,
            to_dept_name TEXT,
            reason TEXT,
            ref_record_id INTEGER DEFAULT 0,
            doctor_id INTEGER,
            doctor_name TEXT,
            created_at TEXT
        )",

        'diag_orders' => "CREATE TABLE IF NOT EXISTS diag_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            doctor_id INTEGER,
            ord_keys TEXT DEFAULT '',
            updated_at TEXT,
            UNIQUE(visit_id, doctor_id)
        )",

        'consents' => "CREATE TABLE IF NOT EXISTS consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            title TEXT,
            content TEXT,
            doctor_id INTEGER,
            doctor_name TEXT,
            created_at TEXT,
            updated_at TEXT
        )",

        /* ---------------- 护理 ---------------- */

        'vitals' => "CREATE TABLE IF NOT EXISTS vitals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            vital_sbp INTEGER DEFAULT 0,
            vital_dbp INTEGER DEFAULT 0,
            vital_heart_rate TEXT,
            vital_pulse TEXT,
            vital_spo2 TEXT,
            vital_respiration TEXT,
            operator TEXT,
            created_at TEXT,
            record_id INTEGER DEFAULT 0
        )",

        'nursing_records' => "CREATE TABLE IF NOT EXISTS nursing_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            content TEXT,
            operator TEXT,
            created_at TEXT
        )",

        /* ---------------- 检验 / 检查 ---------------- */

        'item_categories' => "CREATE TABLE IF NOT EXISTS item_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ctype TEXT,
            name TEXT,
            sort INTEGER DEFAULT 0
        )",

        'lab_items' => "CREATE TABLE IF NOT EXISTS lab_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT,
            name TEXT,
            unit TEXT,
            price REAL DEFAULT 0,
            normal_range TEXT,
            critical_low TEXT,
            critical_high TEXT,
            description TEXT,
            status TEXT DEFAULT 'pending',
            created_at TEXT,
            is_group INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0
        )",

        'exam_items' => "CREATE TABLE IF NOT EXISTS exam_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT,
            name TEXT,
            price REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            created_at TEXT
        )",

        'lab_group_members' => "CREATE TABLE IF NOT EXISTS lab_group_members (
            group_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            PRIMARY KEY(group_id, item_id)
        )",

        'results' => "CREATE TABLE IF NOT EXISTS results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER,
            order_item_id INTEGER,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            type TEXT,
            values_json TEXT,
            findings TEXT,
            conclusion TEXT,
            executor TEXT,
            status TEXT DEFAULT 'draft',
            created_at TEXT,
            updated_at TEXT
        )",

        'reports' => "CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            result_id INTEGER,
            report_no TEXT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            type TEXT,
            content TEXT,
            doctor TEXT,
            status TEXT DEFAULT 'done',
            withdraw_reason TEXT,
            withdraw_by TEXT,
            withdraw_at TEXT,
            created_at TEXT
        )",

        /* ---------------- 处置 ---------------- */

        'disposal_items' => "CREATE TABLE IF NOT EXISTS disposal_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            fee REAL DEFAULT 0,
            description TEXT,
            status TEXT DEFAULT 'pending',
            is_nurse INTEGER DEFAULT 0,
            created_at TEXT
        )",

        /* ---------------- 病历模板（独立模板库） ---------------- */

        'emr_templates' => "CREATE TABLE IF NOT EXISTS emr_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            type TEXT DEFAULT 'medical_record',
            scope TEXT DEFAULT 'personal',
            creator_id INTEGER,
            creator_name TEXT,
            status TEXT DEFAULT 'published',
            is_system INTEGER DEFAULT 0,
            content_json TEXT DEFAULT '{}',
            created_at TEXT,
            updated_at TEXT
        )",

        'emr_template_depts' => "CREATE TABLE IF NOT EXISTS emr_template_depts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER,
            dept_id INTEGER,
            UNIQUE(template_id, dept_id)
        )",

        /* ---------------- 诊室 / 叫号大屏 ---------------- */

        'clinic_rooms' => "CREATE TABLE IF NOT EXISTS clinic_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dept_id INTEGER NOT NULL,
            room_name VARCHAR(50) NOT NULL,
            room_type VARCHAR(20) NOT NULL DEFAULT 'doctor',
            screen_token VARCHAR(64) UNIQUE NOT NULL,
            current_doctor_id INTEGER DEFAULT 0,
            current_doctor_name VARCHAR(50) DEFAULT '',
            last_heartbeat DATETIME,
            screen_last_heartbeat DATETIME,
            is_screen_online TINYINT DEFAULT 0,
            doctor_heartbeat DATETIME,
            enable_voice TINYINT DEFAULT 1,
            enable_mask TINYINT DEFAULT 1,
            screen_tips TEXT DEFAULT '',
            tip_interval INTEGER DEFAULT 5,
            current_visit_id INTEGER DEFAULT 0,
            current_flow_no TEXT DEFAULT '',
            current_called_at TEXT DEFAULT '',
            last_call_action TEXT DEFAULT '',
            last_call_at TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",

        /* ---------------- 叫号事件（多医生并发叫号防重 + 过号标记） ---------------- */

        'call_events' => "CREATE TABLE IF NOT EXISTS call_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            flow_no TEXT DEFAULT '',
            patient_no TEXT DEFAULT '',
            dept_id INTEGER DEFAULT 0,
            room_id INTEGER DEFAULT 0,
            doctor_id INTEGER DEFAULT 0,
            doctor_name TEXT DEFAULT '',
            action TEXT DEFAULT 'call',
            created_at TEXT
        )",

        /* ---------------- 会诊 ---------------- */

        'consultations' => "CREATE TABLE IF NOT EXISTS consultations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visit_id INTEGER,
            patient_no TEXT,
            flow_no TEXT,
            consult_no TEXT,
            from_dept_id INTEGER,
            from_dept_name TEXT,
            from_doctor_id INTEGER,
            from_doctor_name TEXT,
            target_dept_id INTEGER,
            target_dept_name TEXT,
            description TEXT,
            purpose TEXT,
            status TEXT DEFAULT 'pending',
            accepted_by TEXT,
            accepted_at TEXT,
            finished_by TEXT,
            finished_at TEXT,
            record_id INTEGER DEFAULT 0,
            created_at TEXT
        )",
    ),
    'migrations' => array(
        2 => array(
            "ALTER TABLE consultations ADD COLUMN finished_by TEXT",
        ),
        // v7：结构化电子病历表 patient_records 高频索引（幂等；新库建表不含索引，
        // 需显式创建。字段名已规范化：primary_icd10→icd10_code, main_symptom→chief_complaint）
        7 => array(
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit ON patient_records(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_patient ON patient_records(patient_no)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_visit_doctor ON patient_records(visit_id, doctor_id)",
            "CREATE INDEX IF NOT EXISTS idx_patient_records_stat ON patient_records(icd10_code, is_leave_hospital, chief_complaint)",
        ),
        // v8：orders / order_items / results / vitals / payments / registrations 高频查询索引
        8 => array(
            "CREATE INDEX IF NOT EXISTS idx_orders_visit ON orders(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id)",
            "CREATE INDEX IF NOT EXISTS idx_results_item ON results(order_item_id)",
            "CREATE INDEX IF NOT EXISTS idx_results_visit ON results(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_vitals_visit ON vitals(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_vitals_record ON vitals(record_id)",
            "CREATE INDEX IF NOT EXISTS idx_payments_visit ON payments(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_registrations_patient ON registrations(patient_no)",
            "CREATE INDEX IF NOT EXISTS idx_registrations_dept_date ON registrations(first_dept_id, date(registered_at))",
        ),
        // v9：存量数据回填（旧迁移数据字段可能为空，补正）
        9 => array(
            "UPDATE certificates SET cert_no = 'ZM' || replace(substr(created_at,1,10),'-','') || substr('0000' || id, -4, 4) WHERE cert_no IS NULL OR cert_no = ''",
            "UPDATE patient_records SET record_type='initial' WHERE record_type IS NULL OR record_type=''",
        ),
        // v10：诊断证明增加 dept_id 字段（记录开具时科室，用于删除权限校验）
        10 => array(
            "ALTER TABLE certificates ADD COLUMN dept_id INTEGER DEFAULT 0",
        ),
        // v11：报告编号唯一约束（报告号由 COUNT+1 生成，并发下可能重复——
        // 唯一约束 + API 层撞号重试，杜绝静默重复报告号）
        11 => array(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_reports_report_no ON reports(report_no)",
        ),
        // v12：处方审方通过时间（orders.dispensed_at）——整单发药记录独立时间，
        // 不复用 refunded_at（退费时间语义）
        12 => array(
            "ALTER TABLE orders ADD COLUMN dispensed_at TEXT",
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
        // v16：叫号大屏当前就诊状态（由医生工作站推送信号，大屏端仅按 token 读取校验）
        16 => array(
            "ALTER TABLE clinic_rooms ADD COLUMN current_visit_id INTEGER DEFAULT 0",
            "ALTER TABLE clinic_rooms ADD COLUMN current_flow_no TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN current_called_at TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN last_call_action TEXT DEFAULT ''",
            "ALTER TABLE clinic_rooms ADD COLUMN last_call_at TEXT DEFAULT ''",
        ),
        // v17：叫号事件表——多医生并发叫号防重认领 + 过号标记 + 再次叫号记录
        17 => array(
            "CREATE TABLE IF NOT EXISTS call_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visit_id INTEGER,
                flow_no TEXT DEFAULT '',
                patient_no TEXT DEFAULT '',
                dept_id INTEGER DEFAULT 0,
                room_id INTEGER DEFAULT 0,
                doctor_id INTEGER DEFAULT 0,
                doctor_name TEXT DEFAULT '',
                action TEXT DEFAULT 'call',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_call_events_visit ON call_events(visit_id)",
            "CREATE INDEX IF NOT EXISTS idx_call_events_dept_action ON call_events(dept_id, action)",
        ),
    ),
    'seed' => array(
        // 医技/其他虚拟科室（叫号大屏使用）
        "INSERT OR IGNORE INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES
            ('检验科','tech',0,0,0,90,1,datetime('now','localtime')),
            ('影像科','tech',0,0,0,91,1,datetime('now','localtime')),
            ('药房','other',0,0,0,92,1,datetime('now','localtime')),
            ('护士站','other',0,0,0,93,1,datetime('now','localtime'))",

        // 药品基础设置种子（分类/包装单位/剂型/频次/途径）
        "INSERT OR IGNORE INTO drug_settings(stype,name,is_nurse,sort) VALUES
            ('category','西药',0,1),
            ('category','中成药',0,2),
            ('category','中药',0,3),
            ('package','盒',0,1),('package','瓶',0,2),('package','板',0,3),('package','袋',0,4),
            ('package','支',0,5),('package','片',0,6),('package','粒',0,7),('package','包',0,8),
            ('package','罐',0,9),('package','贴',0,10),
            ('form','片剂',0,1),('form','胶囊',0,2),('form','颗粒剂',0,3),('form','口服液',0,4),
            ('form','注射液',0,5),('form','粉针剂',0,6),('form','软膏',0,7),('form','乳膏',0,8),
            ('form','栓剂',0,9),('form','喷雾剂',0,10),('form','滴剂',0,11),('form','贴剂',0,12),
            ('form','丸剂',0,13),('form','散剂',0,14),('form','糖浆剂',0,15),
            ('freq','每日一次',0,1),('freq','每日两次',0,2),('freq','每日三次',0,3),('freq','每日四次',0,4),
            ('freq','每6小时一次',0,5),('freq','每8小时一次',0,6),('freq','每12小时一次',0,7),
            ('freq','每晚一次',0,8),('freq','必要时(PRN)',0,9),('freq','每周一次',0,10),('freq','隔日一次',0,11),
            ('route','口服',0,1),('route','静脉注射',0,2),('route','静脉输液',1,3),
            ('route','肌肉注射',1,4),('route','皮下注射',1,5),('route','皮内注射',0,6),
            ('route','外用',0,7),('route','雾化吸入',0,8),('route','舌下含服',0,9),
            ('route','直肠给药',0,10),('route','阴道给药',0,11),('route','滴眼',0,12),
            ('route','滴耳',0,13),('route','滴鼻',0,14),('route','局部注射',1,15)",

        // 项目分类种子（检验 / 检查）
        "INSERT OR IGNORE INTO item_categories(id,ctype,name,sort) VALUES
            (1,'lab','血液检验',1),(2,'lab','生化检验',2),(3,'lab','免疫检验',3),
            (4,'lab','尿液检验',4),(5,'lab','粪便检验',5),(6,'lab','凝血功能',6),
            (7,'lab','微生物检验',7),(8,'lab','其他',99),
            (9,'exam','CT',1),(10,'exam','MR',2),(11,'exam','DR（数字化X线）',3),
            (12,'exam','超声',4),(13,'exam','内镜',5),(14,'exam','心电图',6),
            (15,'exam','病理',7),(16,'exam','其他',99)",

        // 内置系统病历模板
        "INSERT OR IGNORE INTO emr_templates(id, title, type, scope, creator_id, creator_name, status, is_system, content_json, created_at, updated_at) VALUES(1, '通用病历模板', 'medical_record', 'hospital', 0, '系统', 'published', 1, '{}', datetime('now','localtime'), datetime('now','localtime'))",
    ),
);
