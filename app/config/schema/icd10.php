<?php
/**
 * ============================================================
 * icd10.php — ICD-10 诊断字典库 schema（完整标准编码库）
 * ============================================================
 * 说明：ICD-10 疾病编码独立数据库（data/db/icd10.db），仅供只读访问。
 * 新版库为医保版完整标准库，每行含四级分类链：
 *   章(chapter_code_range/chapter_name)
 *   → 节(section_code_range/section_name)
 *   → 类目(category_code/category_name)
 *   → 亚目(subcategory_code/subcategory_name)
 *   → 最终诊断(diagnosis_code/diagnosis_name/search_tags=拼音码)
 * 由 DatabaseManager::getIcd10() 打开，独立于业务主库。
 * 业务表仅冗余 diagnosis_code 与 diagnosis_name，不参与跨库事务。
 * ============================================================ */
return array(
    'version' => 2,
    'tables' => array(
        'icd10' => "CREATE TABLE IF NOT EXISTS icd10 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chapter_code_range TEXT,
            chapter_name TEXT,
            section_code_range TEXT,
            section_name TEXT,
            category_code TEXT,
            category_name TEXT,
            subcategory_code TEXT,
            subcategory_name TEXT,
            diagnosis_code TEXT,
            diagnosis_name TEXT,
            search_tags TEXT
        )",
    ),
    'migrations' => array(),
    'seed' => array(),
);