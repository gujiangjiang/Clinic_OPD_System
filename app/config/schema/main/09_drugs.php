<?php
/**
 * ============================================================
 * main/09_drugs.php — 药品设置 / 药品档案
 * ============================================================ */
return array(
    'tables' => array(

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
            single_use_qty REAL DEFAULT 1,
            allow_split INTEGER DEFAULT 0,
            warn_qty INTEGER DEFAULT 0
        )",
    ),
    'migrations' => array(
        // v38：门诊拆零销售（drugs 侧）——allow_split 允许拆零零售开关
        // （0=仅整包装销售 / 1=允许按最小单位销售）；存量库存统一为「最小单位」
        // 口径（整盒数量 × 每盒最小单位数），开方数量校验与发药扣减不再因单位混淆。
        38 => array(
            "ALTER TABLE drugs ADD COLUMN allow_split INTEGER DEFAULT 0",
            "UPDATE drugs SET qty = qty * spec_pack_qty WHERE spec_pack_qty IS NOT NULL AND spec_pack_qty > 1",
        ),
        // v39：警戒库存（drugs.warn_qty）——后台录入按「包装单位」输入盒数/瓶数，
        // 存库时换算为「最小单位」绝对警戒阈值（输入盒数 × pack_size）；
        // 低库存报表/报警判定统一以最小单位绝对值对比，杜绝大包装药品（如 100 粒/瓶）
        // 因单位混淆导致断货前夕才预警。
        39 => array(
            "ALTER TABLE drugs ADD COLUMN warn_qty INTEGER DEFAULT 0",
        ),
    ),
    'seed' => array(
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
    ),
);
