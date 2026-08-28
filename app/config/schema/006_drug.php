<?php
/**
 * ============================================================
 * 006_drug.php — 药品库 schema
 * 说明：药品基础设置（分类/包装单位/剂型/用药频次/给药途径，
 * 给药途径含【需护士站处理】选项）+ 药品信息
 * 【MySQL 切换】把建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT 即可
 * ============================================================ */
return array(
    'version' => 4,
    'tables' => array(
        'drug_settings' => "CREATE TABLE IF NOT EXISTS drug_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stype TEXT,
            name TEXT,
            need_nurse INTEGER DEFAULT 0,
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
            frequency_name TEXT,
            route_name TEXT,
            price REAL DEFAULT 0,
            qty INTEGER DEFAULT 0,
            is_rx INTEGER DEFAULT 0,
            is_limited INTEGER DEFAULT 0,
            note TEXT,
            need_nurse INTEGER DEFAULT 0,
            need_skin_test INTEGER DEFAULT 0,
            skin_test_item_id INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at TEXT,
            spec_dose REAL DEFAULT 0,
            spec_dose_unit TEXT,
            spec_pack_qty INTEGER DEFAULT 1,
            spec_pack_unit TEXT,
            single_use_qty REAL DEFAULT 1
        )",
    ),
    'migrations' => array(
        // v2：皮试联动 —— 药品标记需皮试并关联皮试处置项目；
        // 给药途径绑定计费处置（如静脉输液 → 静脉输液费），开方自动联动
        2 => array(
            "ALTER TABLE drugs ADD COLUMN need_skin_test INTEGER DEFAULT 0",
            "ALTER TABLE drugs ADD COLUMN skin_test_item_id INTEGER DEFAULT 0",
            "ALTER TABLE drug_settings ADD COLUMN bind_disposal_item_id INTEGER DEFAULT 0",
        ),
        // v3：规格结构化 —— 0.35g×24粒 拆为 剂量0.35/单位g/包装数量24/包装单位粒；
        // 单次使用剂量改为数量（默认1，即单次1粒/1袋）
        3 => array(
            "ALTER TABLE drugs ADD COLUMN spec_dose REAL DEFAULT 0",
            "ALTER TABLE drugs ADD COLUMN spec_dose_unit TEXT",
            "ALTER TABLE drugs ADD COLUMN spec_pack_qty INTEGER DEFAULT 1",
            "ALTER TABLE drugs ADD COLUMN spec_pack_unit TEXT",
            "ALTER TABLE drugs ADD COLUMN single_use_qty REAL DEFAULT 1",
        ),
        // v4：幂等补列 —— 修复历史库 user_version=3 但列缺失的损坏状态
        4 => array(
            "ALTER TABLE drugs ADD COLUMN spec_dose REAL DEFAULT 0",
            "ALTER TABLE drugs ADD COLUMN spec_dose_unit TEXT",
            "ALTER TABLE drugs ADD COLUMN spec_pack_qty INTEGER DEFAULT 1",
            "ALTER TABLE drugs ADD COLUMN spec_pack_unit TEXT",
            "ALTER TABLE drugs ADD COLUMN single_use_qty REAL DEFAULT 1",
        ),
    ),
    'seed' => array(
        // 药品基础设置种子（分类/包装单位/剂型/频次/途径）
        "INSERT OR IGNORE INTO drug_settings(stype,name,need_nurse,sort) VALUES
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
    ),
);
