<?php
/**
 * ============================================================
 * Icd10Repository.php — ICD-10 诊断字典仓库
 * ============================================================
 * 说明：对接独立 ICD-10 诊断字典库（DatabaseManager::getIcd10()）。
 * 新版库为完整标准编码库（医保版 ICD-10），每行含四级分类链：
 *   章(chapter_code_range/chapter_name)
 *   → 节(section_code_range/section_name)
 *   → 类目(category_code/category_name)
 *   → 亚目(subcategory_code/subcategory_name)
 *   以及最终诊断(diagnosis_code=ICD10编码 / diagnosis_name=诊断名称 /
 *   search_tags=拼音检索码)。
 * 本库为只读标准字典，不参与主库事务；管理端仅浏览检索。
 * ============================================================ */
class Icd10Repository extends BaseRepository {

    /** 查询用字段集（四级分类链 + 最终诊断） */
    private static function cols() {
        return 'id, diagnosis_code, diagnosis_name, search_tags,
                chapter_code_range, chapter_name, section_code_range, section_name,
                category_code, category_name, subcategory_code, subcategory_name';
    }

    /**
     * 关键字检索（病历诊断联动）：按诊断码/名称/拼音检索码模糊匹配
     * 相关度排序（让核心诊断优先）：
     *   0 名称完全等于关键词 → 1 名称以关键词开头 → 2 编码以关键词开头 →
     *   3 拼音首字母开头 → 4 名称包含关键词；同级按名称长度升序（更简洁的更靠前）。
     * 支持 offset 分页（前端无限滚动分段加载，直至全部结果加载完成，无总条数上限）。
     * @param string $kw 关键字
     * @param int $limit 每页条数
     * @param int $offset 偏移量
     * @return array [rows, total] 完整行（含四级分类链） + 匹配总数
     */
    public static function search($kw, $limit = 20, $offset = 0) {
        if ($kw === '') return array(array(), 0);
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        $kw = trim((string)$kw);
        $contains = '%' . $kw . '%';
        $prefix = $kw . '%';
        $upperContains = '%' . strtoupper($kw) . '%';
        $whereSql = 'diagnosis_code LIKE ? OR diagnosis_name LIKE ? OR search_tags LIKE ?';
        $total = (int)self::icd10val('SELECT COUNT(*) FROM icd10 WHERE ' . $whereSql, array($contains, $contains, $upperContains));
        $rows = self::icd10q(
            "SELECT " . self::cols() . " FROM icd10 WHERE $whereSql
             ORDER BY
               CASE
                 WHEN diagnosis_name = ? THEN 0
                 WHEN diagnosis_name LIKE ? THEN 1
                 WHEN diagnosis_code LIKE ? THEN 2
                 WHEN search_tags LIKE ? THEN 3
                 ELSE 4
               END,
               LENGTH(diagnosis_name) ASC,
               diagnosis_code ASC
             LIMIT $limit OFFSET $offset",
            array($contains, $contains, $upperContains, $kw, $prefix, $prefix, $upperContains)
        );
        return array($rows, $total);
    }

    /**
     * 管理端分页列表：支持关键字 + 四级层级过滤
     * @param string $kw 关键字（可为空）
     * @param int $limit 每页条数
     * @param int $offset 偏移
     * @param string $chapter 章范围过滤（可为空）
     * @param string $section 节范围过滤（可为空）
     * @param string $category 类目过滤（可为空）
     * @param string $subcategory 亚目过滤（可为空）
     * @return array [rows, total]
     */
    public static function paginate($kw, $limit, $offset, $chapter = '', $section = '', $category = '', $subcategory = '') {
        $where = array();
        $params = array();
        $kw = trim((string)$kw);
        if ($kw !== '') {
            $like = '%' . $kw . '%';
            $where[] = '(diagnosis_code LIKE ? OR diagnosis_name LIKE ? OR search_tags LIKE ?)';
            $params = array_merge($params, array($like, $like, strtoupper($like)));
        }
        if ($chapter !== '')  { $where[] = 'chapter_code_range=?';   $params[] = $chapter; }
        if ($section !== '')  { $where[] = 'section_code_range=?';   $params[] = $section; }
        if ($category !== '') { $where[] = 'category_code=?';        $params[] = $category; }
        if ($subcategory !== '') { $where[] = 'subcategory_code=?';  $params[] = $subcategory; }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $total = (int)self::icd10val('SELECT COUNT(*) FROM icd10' . $whereSql, $params);
        // 关键字检索时按相关度排序（与 search 同规则）；无关键字（层级浏览）按编码升序稳定分页
        $orderSql = ' ORDER BY diagnosis_code ASC';
        if ($kw !== '') {
            $kwPrefix = $kw . '%';
            $orderSql = " ORDER BY CASE
                WHEN diagnosis_name = ? THEN 0
                WHEN diagnosis_name LIKE ? THEN 1
                WHEN diagnosis_code LIKE ? THEN 2
                WHEN search_tags LIKE ? THEN 3
                ELSE 4 END, LENGTH(diagnosis_name) ASC, diagnosis_code ASC";
            $params = array_merge($params, array($kw, $kwPrefix, $kwPrefix, strtoupper($kwPrefix)));
        }
        $rows = self::icd10q(
            "SELECT " . self::cols() . " FROM icd10$whereSql$orderSql LIMIT $limit OFFSET $offset",
            $params
        );
        return array($rows, $total);
    }

    /* ==================== 层级树（章→节→类目→亚目） ==================== */

    /** 章列表 */
    public static function chapters() {
        return self::icd10q(
            "SELECT DISTINCT chapter_code_range, chapter_name
             FROM icd10 WHERE chapter_code_range<>'' ORDER BY chapter_code_range"
        );
    }

    /** 某章下的节列表 */
    public static function sections($chapter) {
        return self::icd10q(
            "SELECT DISTINCT section_code_range, section_name
             FROM icd10 WHERE chapter_code_range=? AND section_code_range<>'' ORDER BY section_code_range",
            array($chapter)
        );
    }

    /** 某节下的类目列表 */
    public static function categories($section) {
        return self::icd10q(
            "SELECT DISTINCT category_code, category_name
             FROM icd10 WHERE section_code_range=? AND category_code<>'' ORDER BY category_code",
            array($section)
        );
    }

    /** 某类目下的亚目列表 */
    public static function subcategories($category) {
        return self::icd10q(
            "SELECT DISTINCT subcategory_code, subcategory_name
             FROM icd10 WHERE category_code=? AND subcategory_code<>'' ORDER BY subcategory_code",
            array($category)
        );
    }
}
