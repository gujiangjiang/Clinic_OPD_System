<?php
/**
 * ============================================================
 * DatabaseManager.php v1.0.0 — 统一数据库管理模块（分散式数据库）
 * ============================================================
 * 说明：
 * 1. 每个业务模块一个独立数据库文件（app/config/schema/*.php 定义），
 *    如 core(设置/审核)、user(用户)、dept(科室)、patient(患者/挂号)、
 *    order(开单/缴费)、drug(药品)、medical(病历)、nurse(护理)、
 *    lab(检验检查项目)、disp(处置)、icd10(疾病诊断)
 * 2. 首次访问自动建库建表；schema 中 version 用于版本迁移，
 *    通过 PRAGMA user_version 记录已迁移版本（兼容旧库平滑升级）
 * 3. 提供 MySQL 预留接口：切换 DB_DRIVER='mysql' 后，
 *    pdo() 返回 MySQL PDO，业务查询代码无需改动
 * 4. 所有 SQL 一律使用 PDO 预处理语句，防止 SQL 注入
 * ============================================================ */
class DatabaseManager {

    /** PDO 连接缓存：key => PDO */
    private static $pdo = array();

    /** schema 定义缓存：key => array(version, tables, migrations, seed) */
    private static $schemas = null;

    /** 是否已执行过种子数据 */
    private static $seeded = false;

    /**
     * 加载全部 schema 定义（app/config/schema/*.php）
     * 文件名形如：001_core.php → key 为 core
     */
    private static function schemas() {
        if (self::$schemas === null) {
            self::$schemas = array();
            $files = glob(APP_ROOT . '/app/config/schema/*.php');
            if ($files === false) {
                $files = array();
            }
            sort($files);
            foreach ($files as $f) {
                $key = preg_replace('/^\d+_/', '', basename($f, '.php'));
                $def = require $f;
                $def['key'] = $key;
                self::$schemas[$key] = $def;
            }
        }
        return self::$schemas;
    }

    /** 初始化所有数据库（建库、建表、迁移、种子）——入口文件启动时调用 */
    public static function initAll() {
        foreach (self::schemas() as $key => $def) {
            self::pdo($key);
        }
        self::seedAll();
    }

    /**
     * 获取指定模块的 PDO 连接（懒加载：首次访问自动建库建表迁移）
     * 【MySQL 预留】DB_DRIVER='mysql' 时按 MYSQL_* 配置连接
     */
    public static function pdo($key) {
        if (isset(self::$pdo[$key])) {
            return self::$pdo[$key];
        }
        $def = self::schemas();
        if (!isset($def[$key])) {
            throw new Exception('数据库模块不存在: ' . $key);
        }
        if (DB_DRIVER === 'mysql') {
            // ===== MySQL 预留接口 =====
            // 注意：MySQL 下需将建表语句中 AUTOINCREMENT 改为 AUTO_INCREMENT；
            // 各分散库对应数据库名：MYSQL_DB_PREFIX + key
            $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB_PREFIX . $key . ';charset=utf8mb4';
            $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } else {
            // ===== SQLite =====
            $dir = DATA_DIR . '/db';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . '/' . $key . '.db';
            $pdo = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            // 并发写等待（挂号/缴费等高频写场景）
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        self::$pdo[$key] = $pdo;
        self::createTables($key, $def[$key]);
        self::migrate($key, $def[$key]);
        return $pdo;
    }

    /** 建表（CREATE TABLE IF NOT EXISTS，幂等） */
    private static function createTables($key, $def) {
        if (empty($def['tables'])) return;
        foreach ($def['tables'] as $sql) {
            try {
                self::$pdo[$key]->exec($sql);
            } catch (Exception $ex) {
                if (DEBUG) error_log('[DB建表失败] ' . $key . ': ' . $ex->getMessage());
            }
        }
    }

    /**
     * 版本迁移：PRAGMA user_version 记录当前版本，
     * 逐版本执行 migrations 中定义的增量 SQL（旧库平滑升级）
     */
    private static function migrate($key, $def) {
        $target = isset($def['version']) ? (int)$def['version'] : 0;
        if ($target <= 0) return;
        $current = (int)self::$pdo[$key]->query('PRAGMA user_version')->fetchColumn();
        while ($current < $target) {
            $current++;
            if (!empty($def['migrations'][$current])) {
                foreach ($def['migrations'][$current] as $sql) {
                    try {
                        // ALTER TABLE ... ADD COLUMN 幂等守卫：
                        // 新库建表已含新列时自动跳过，旧库正常增量升级（兼容旧库平滑迁移）
                        if (preg_match('/^ALTER TABLE\s+(\S+)\s+ADD\s+COLUMN\s+(\S+)/i', trim($sql), $mm)) {
                            if (self::columnExists($key, $mm[1], $mm[2])) {
                                continue;
                            }
                        }
                        self::$pdo[$key]->exec($sql);
                    } catch (Exception $ex) {
                        if (DEBUG) error_log('[迁移失败] ' . $key . ' v' . $current . ': ' . $ex->getMessage());
                    }
                }
            }
            self::$pdo[$key]->exec('PRAGMA user_version = ' . $current);
        }
    }

    /** 判断表中是否存在指定列（SQLite 用 PRAGMA，MySQL 用 SHOW COLUMNS，异常时保守返回 false 交给建表/迁移容错） */
    private static function columnExists($key, $table, $column) {
        try {
            if (DB_DRIVER === 'mysql') {
                $rows = self::$pdo[$key]->query('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $column . '\'')->fetchAll();
                return count($rows) > 0;
            }
            $cols = self::$pdo[$key]->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if (strcasecmp($c['name'], $column) === 0) {
                    return true;
                }
            }
            return false;
        } catch (Exception $ex) {
            return false;
        }
    }

    /**
     * 种子数据（幂等）：通过 settings 表标记已执行，避免重复写入
     * （首次初始化写入字典类默认数据，如 ICD10、药品设置、项目分类）
     */
    public static function seedAll() {
        if (self::$seeded) return;
        self::$seeded = true;
        $doneKey = 'seed_done_v1';
        if (DB::val('core', 'SELECT COUNT(*) FROM settings WHERE skey=?', array($doneKey)) > 0) {
            return;
        }
        foreach (self::schemas() as $key => $def) {
            if (empty($def['seed'])) continue;
            foreach ($def['seed'] as $seedSql) {
                try {
                    self::$pdo[$key]->exec($seedSql);
                } catch (Exception $ex) {
                    if (DEBUG) error_log('[种子失败] ' . $key . ': ' . $ex->getMessage());
                }
            }
        }
        DB::exec('core', 'INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)', array($doneKey, '1'));
    }

    /* ==================== 查询门面（预处理防注入） ==================== */

    /** 查询多行 */
    public static function q($key, $sql, $params = array()) {
        $st = self::pdo($key)->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 查询单行 */
    public static function one($key, $sql, $params = array()) {
        $r = self::q($key, $sql, $params);
        return isset($r[0]) ? $r[0] : null;
    }

    /** 查询单值 */
    public static function val($key, $sql, $params = array()) {
        $st = self::pdo($key)->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写操作，返回影响行数 */
    public static function exec($key, $sql, $params = array()) {
        $st = self::pdo($key)->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 插入并返回自增主键 */
    public static function insert($key, $sql, $params = array()) {
        self::pdo($key)->prepare($sql)->execute($params);
        return (int)self::pdo($key)->lastInsertId();
    }

    /** 别名（旧代码兼容） */
    public static function query($key, $sql, $params = array()) { return self::q($key, $sql, $params); }
}

/** 短名门面：DB::q / DB::one / DB::val / DB::exec / DB::insert */
class_alias('DatabaseManager', 'DB');
