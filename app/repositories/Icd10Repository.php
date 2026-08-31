<?php
/**
 * ============================================================
 * Icd10Repository.php — ICD-10 诊断字典仓库
 * ============================================================
 * 说明：对接独立 ICD-10 字典库（DatabaseManager::getIcd10()），
 * 提供疾病编码/名称/拼音首字母检索、管理端分页列表、
 * 新增/编辑/删除维护。仅操作 icd10 表，不参与主库事务。
 * ============================================================ */
class Icd10Repository extends BaseRepository {

    /**
     * 关键字检索（病历诊断联动）：按编码/名称/拼音首字母模糊匹配
     * @param string $kw 关键字
     * @param int $limit 返回条数（默认 20）
     * @return array [{id, code, name, pinyin}]
     */
    public static function search($kw, $limit = 20) {
        if ($kw === '') return array();
        $limit = max(1, min(50, (int)$limit));
        $like = '%' . $kw . '%';
        return self::icd10q(
            "SELECT id, code, name, pinyin FROM icd10
             WHERE code LIKE ? OR name LIKE ? OR pinyin LIKE ? ORDER BY id LIMIT $limit",
            array($like, $like, strtoupper($like))
        );
    }

    /**
     * 分页列表（管理端）：可选关键字，按编码升序
     * @param string $kw 关键字（可为空）
     * @param int $limit 每页条数
     * @param int $offset 偏移
     * @return array [rows, total]
     */
    public static function paginate($kw, $limit, $offset) {
        $where = '';
        $params = array();
        if ($kw !== '') {
            $like = '%' . $kw . '%';
            $where = ' WHERE code LIKE ? OR name LIKE ? OR pinyin LIKE ?';
            $params = array($like, $like, strtoupper($like));
        }
        $total = (int)self::icd10val('SELECT COUNT(*) FROM icd10' . $where, $params);
        $rows = self::icd10q(
            "SELECT id, code, name, pinyin FROM icd10$where ORDER BY code ASC LIMIT $limit OFFSET $offset",
            $params
        );
        return array($rows, $total);
    }

    /** 按编码或名称查重（排除指定 id） */
    public static function findDuplicate($code, $name, $excludeId = 0) {
        return self::icd10one(
            'SELECT id FROM icd10 WHERE (code=? OR name=?) AND id<>?',
            array($code, $name, (int)$excludeId)
        );
    }

    /** 新增诊断 */
    public static function create($code, $name, $pinyin) {
        self::icd10exec('INSERT INTO icd10(code, name, pinyin) VALUES(?,?,?)', array($code, $name, $pinyin));
    }

    /** 更新诊断 */
    public static function update($id, $code, $name, $pinyin) {
        self::icd10exec('UPDATE icd10 SET code=?, name=?, pinyin=? WHERE id=?', array($code, $name, $pinyin, (int)$id));
    }

    /** 删除诊断 */
    public static function remove($id) {
        self::icd10exec('DELETE FROM icd10 WHERE id=?', array((int)$id));
    }
}