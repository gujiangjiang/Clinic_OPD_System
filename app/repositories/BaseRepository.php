<?php
/**
 * ============================================================
 * BaseRepository.php — 数据仓库基础类
 * ============================================================
 * 说明：所有业务 Repository 的基类，封装通用 PDO 辅助方法：
 * 快速查询、计数、插入、事务控制等，统一通过 DatabaseManager
 * 获取主库（getMain）或 ICD-10 字典库（getIcd10）连接。
 * 所有 SQL 严格使用预编译参数绑定，防 SQL 注入。
 * ============================================================ */
class BaseRepository {

    /** 获取主库 PDO */
    protected static function db() {
        return DatabaseManager::getMain();
    }

    /** 获取 ICD-10 字典库 PDO */
    protected static function icd10Db() {
        return DatabaseManager::getIcd10();
    }

    // ==================== 查询 ====================

    /** 查询多行，返回数组 */
    public static function q($sql, $params = array()) {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 查询单行，返回关联数组或 null */
    public static function one($sql, $params = array()) {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** 查询单值，返回标量或 null */
    public static function val($sql, $params = array()) {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写操作，返回影响行数 */
    public static function exec($sql, $params = array()) {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 插入并返回自增主键 */
    public static function insert($sql, $params = array()) {
        return self::execInsert(self::db(), $sql, $params);
    }

    // ==================== ICD-10 字典库查询 ====================

    /** ICD-10 多行查询 */
    public static function icd10q($sql, $params = array()) {
        $st = self::icd10Db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ICD-10 单行查询 */
    public static function icd10one($sql, $params = array()) {
        $st = self::icd10Db()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** ICD-10 单值查询 */
    public static function icd10val($sql, $params = array()) {
        $st = self::icd10Db()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** ICD-10 写操作 */
    public static function icd10exec($sql, $params = array()) {
        $st = self::icd10Db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 按库名动态查询（数据导入等场景：$db='main' 或 'icd10'） */
    public static function dbVal($db, $sql, $params = array()) {
        $pdo = ($db === 'icd10') ? self::icd10Db() : self::db();
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 通用动态执行（供 API 层在事务中通过 Repository 门面执行动态 SQL） */
    public static function prepareExec($sql, $params = array()) {
        self::db()->prepare($sql)->execute($params);
    }

    /** 通用动态查询（返回多行，供 API 层在事务中通过 Repository 门面执行动态 SQL） */
    public static function prepareQ($sql, $params = array()) {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 通用动态插入并返回 lastInsertId（供 API 层在事务中使用） */
    public static function prepareInsert($sql, $params = array()) {
        self::db()->prepare($sql)->execute($params);
        return (int)self::db()->lastInsertId();
    }

    // ==================== 事务辅助 ====================

    /** 开启主库事务 */
    protected static function begin() {
        self::db()->beginTransaction();
    }

    /** 提交主库事务 */
    protected static function commit() {
        self::db()->commit();
    }

    /** 回滚主库事务 */
    protected static function rollBack() {
        if (self::db()->inTransaction()) {
            self::db()->rollBack();
        }
    }

    // ==================== 内部辅助 ====================

    /** 插入并返回自增主键（指定 PDO 连接） */
    private static function execInsert($pdo, $sql, $params) {
        $pdo->prepare($sql)->execute($params);
        return (int)$pdo->lastInsertId();
    }

    // ==================== 通用查询模式 ====================

    /** 按 ID 查询单行 */
    protected static function findById($table, $id) {
        return self::one("SELECT * FROM \"$table\" WHERE id=?", array((int)$id));
    }

    /** 按条件查询多行 */
    protected static function findAll($table, $where = '', $params = array(), $order = '', $limit = '') {
        $sql = "SELECT * FROM \"$table\"";
        if ($where) $sql .= " WHERE $where";
        if ($order) $sql .= " ORDER BY $order";
        if ($limit) $sql .= " LIMIT $limit";
        return self::q($sql, $params);
    }

    /** 计数 */
    protected static function count($table, $where = '', $params = array()) {
        $sql = "SELECT COUNT(*) FROM \"$table\"";
        if ($where) $sql .= " WHERE $where";
        return (int)self::val($sql, $params);
    }

    /** 删除 */
    protected static function delete($table, $id) {
        return self::exec("DELETE FROM \"$table\" WHERE id=?", array((int)$id));
    }

    // ==================== 通用 CRUD 助手（消除子类重复） ====================

    /**
     * 通用插入：INSERT INTO $table(cols) VALUES(...)，返回自增主键
     * @param string $table 表名（白名单：仅允许标识符字符）
     * @param array $data 关联数组 [列 => 值]
     */
    protected static function insertRow($table, $data) {
        self::assertTable($table);
        $cols = implode(',', array_keys($data));
        $phs = implode(',', array_fill(0, count($data), '?'));
        return self::insert("INSERT INTO \"$table\"($cols) VALUES($phs)", array_values($data));
    }

    /**
     * 通用更新：UPDATE $table SET col=?,... WHERE id=?，返回影响行数
     * @param string $table 表名
     * @param int $id 主键
     * @param array $data 关联数组 [列 => 值]
     */
    protected static function updateRow($table, $id, $data) {
        self::assertTable($table);
        $set = array();
        $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (int)$id;
        return self::exec("UPDATE \"$table\" SET " . implode(',', $set) . ' WHERE id=?', $params);
    }

    /**
     * 通用条件更新：UPDATE $table SET col=?,... WHERE <where>，返回影响行数
     * @param string $table 表名
     * @param array $data 关联数组 [列 => 值]
     * @param string $where WHERE 子句（含占位符）
     * @param array $whereParams WHERE 参数
     */
    protected static function updateWhere($table, $data, $where, $whereParams = array()) {
        self::assertTable($table);
        $set = array();
        $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params = array_merge($params, $whereParams);
        return self::exec("UPDATE \"$table\" SET " . implode(',', $set) . " WHERE $where", $params);
    }

    /** 表名白名单校验（防注入：仅允许字母数字下划线） */
    private static function assertTable($table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table)) {
            throw new Exception('非法表名: ' . $table);
        }
    }
}