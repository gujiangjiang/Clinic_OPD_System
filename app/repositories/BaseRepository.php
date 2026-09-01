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
    // 通用查询统一委托 DatabaseManager（DB 门面，唯一的底层 PDO 执行入口），
    // 消除两套重复的 prepare/execute/fetch 实现。非 icd10 的 key 一律路由主库。

    /** 查询多行，返回数组 */
    public static function q($sql, $params = array()) {
        return DatabaseManager::q($sql, $params);
    }

    /** 查询单行，返回关联数组或 null */
    public static function one($sql, $params = array()) {
        return DatabaseManager::one($sql, $params);
    }

    /** 查询单值，返回标量或 null */
    public static function val($sql, $params = array()) {
        return DatabaseManager::val($sql, $params);
    }

    /** 执行写操作，返回影响行数 */
    public static function exec($sql, $params = array()) {
        return DatabaseManager::exec($sql, $params);
    }

    /** 插入并返回自增主键 */
    public static function insert($sql, $params = array()) {
        return DatabaseManager::insert($sql, $params);
    }

    // ==================== ICD-10 字典库查询（委托 DatabaseManager 路由） ====================

    /** ICD-10 多行查询 */
    public static function icd10q($sql, $params = array()) {
        return DatabaseManager::q('icd10', $sql, $params);
    }

    /** ICD-10 单值查询 */
    public static function icd10val($sql, $params = array()) {
        return DatabaseManager::val('icd10', $sql, $params);
    }

    /** 按库名动态查询（数据导入等场景：$db='main' 或 'icd10'） */
    public static function dbVal($db, $sql, $params = array()) {
        return DatabaseManager::val($db, $sql, $params);
    }

    /** 通用动态执行（供 API 层在事务中使用，委托 self::exec） */
    public static function prepareExec($sql, $params = array()) {
        return self::exec($sql, $params);
    }

    /** 通用动态插入（供 API 层在事务中使用，委托 self::insert） */
    public static function prepareInsert($sql, $params = array()) {
        return self::insert($sql, $params);
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

    /** 按单字段等值过滤查询 */
    protected static function findAllByField($table, $field, $value, $order = 'id') {
        return self::findAll($table, ($value !== null && $value !== '') ? "$field=?" : '', ($value !== null && $value !== '') ? array($value) : array(), $order);
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