<?php
/**
 * ============================================================
 * icd10.php — ICD10 诊断检索与字典接口
 * ============================================================
 * 说明：ICD-10 疾病编码独立字典库（完整标准编码库），通过
 * Icd10Repository 访问（内部对接 DatabaseManager::getIcd10()，
 * 独立文件隔离于业务主库）。库内每行含四级分类链：
 * 章→节→类目→亚目→最终诊断。
 * 本库为只读标准字典，接口仅提供检索 / 分页 / 层级树浏览，
 * 不含新增/编辑/删除（标准库不可由管理员维护）。
 * ============================================================ */
require __DIR__ . '/_init.php';

/* 单条诊断行的响应字段映射（含旧字段兼容 + 四级分类链） */
function icd10_row_map($row) {
    return array(
        // 新库标准字段
        'id'               => (int)$row['id'],
        'diagnosis_code'   => $row['diagnosis_code'],
        'diagnosis_name'   => $row['diagnosis_name'],
        'search_tags'      => $row['search_tags'],
        // 旧字段兼容（病历诊断联动 / 旧管理页）
        'icd10_code'       => $row['diagnosis_code'],
        'name'             => $row['diagnosis_name'],
        'pinyin'           => $row['search_tags'],
        // 四级分类链
        'chapter_code_range' => $row['chapter_code_range'],
        'chapter_name'       => $row['chapter_name'],
        'section_code_range' => $row['section_code_range'],
        'section_name'       => $row['section_name'],
        'category_code'      => $row['category_code'],
        'category_name'      => $row['category_name'],
        'subcategory_code'   => $row['subcategory_code'],
        'subcategory_name'   => $row['subcategory_name'],
    );
}

switch ($action) {

    /* ---------------- 搜索（病历诊断联动，支持分页） ---------------- */
    case 'search':
        $kw = get('kw', '');
        $limit = (int)get('limit', 50);
        if ($limit <= 0 || $limit > 200) $limit = 50;
        $offset = (int)get('offset', 0);
        if ($offset < 0) $offset = 0;
        list($rows, $total) = Icd10Repository::search($kw, $limit, $offset);
        $out = array();
        foreach ($rows as $row) {
            $out[] = icd10_row_map($row);
        }
        json_ok(array('list' => $out, 'total' => (int)$total, 'offset' => $offset, 'limit' => $limit));
        break;

    /* ---------------- 诊断列表（管理端，支持检索与层级过滤） ---------------- */
    case 'list':
        $kw = get('kw', '');
        $limit = (int)get('limit', 50);
        if ($limit <= 0 || $limit > 500) $limit = 50;
        $offset = (int)get('offset', 0);
        if ($offset < 0) $offset = 0;
        $chapter = get('chapter', '');
        $section = get('section', '');
        $category = get('category', '');
        $subcategory = get('subcategory', '');
        list($rows, $total) = Icd10Repository::paginate($kw, $limit, $offset, $chapter, $section, $category, $subcategory);
        $out = array();
        foreach ($rows as $row) {
            $out[] = icd10_row_map($row);
        }
        json_ok(array('list' => $out, 'total' => $total, 'offset' => $offset, 'limit' => $limit));
        break;

    /* ---------------- 层级树（章→节→类目→亚目） ---------------- */
    case 'tree':
        $parent = get('parent', '');
        $level = get('level', '');   // chapters / sections / categories / subcategories
        $result = array();
        switch ($level) {
            case 'chapters':
                $rows = Icd10Repository::chapters();
                foreach ($rows as $r) {
                    $result[] = array(
                        'code' => $r['chapter_code_range'], 'name' => $r['chapter_name'], 'level' => 'chapter',
                    );
                }
                break;
            case 'sections':
                if ($parent === '') json_fail('缺少章范围参数');
                foreach (Icd10Repository::sections($parent) as $r) {
                    $result[] = array(
                        'code' => $r['section_code_range'], 'name' => $r['section_name'], 'level' => 'section',
                    );
                }
                break;
            case 'categories':
                if ($parent === '') json_fail('缺少节范围参数');
                foreach (Icd10Repository::categories($parent) as $r) {
                    $result[] = array(
                        'code' => $r['category_code'], 'name' => $r['category_name'], 'level' => 'category',
                    );
                }
                break;
            case 'subcategories':
                if ($parent === '') json_fail('缺少类目参数');
                foreach (Icd10Repository::subcategories($parent) as $r) {
                    $result[] = array(
                        'code' => $r['subcategory_code'], 'name' => $r['subcategory_name'], 'level' => 'subcategory',
                    );
                }
                break;
            default:
                json_fail('未知层级');
        }
        json_ok(array('list' => $result, 'level' => $level));
        break;

    default:
        json_fail('未知操作');
}
