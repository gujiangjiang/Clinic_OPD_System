<?php
/**
 * ============================================================
 * DatabaseManager.php v2.0.0 — 统一数据库管理模块（单主库 + 双驱动）
 * ============================================================
 * 说明：
 * 1. 统一业务主库 clinic_main：全部业务表合并进单一主库，
 *    getMain() 返回主库 PDO（SQLite 文件 data/db/clinic_main.db
 *    或 MySQL 库 his_main，由 DB_DRIVER 切换）。
 * 2. ICD-10 独立只读字典库：getIcd10() 返回只读 SQLite PDO
 *    （data/db/icd10.db），以 PRAGMA query_only 强制只读，仅存储
 *    icd10 表，业务表仅冗余 icd10_code / diagnosis_name，不参与事务。
 * 3. 双驱动一键切换：DB_DRIVER='sqlite'|'mysql'，全量 SQL 遵循
 *    ANSI 标准，自增主键 / 布尔 / 时间 / 列存在检测由方言辅助处理。
 * 4. schema 定义：app/config/schema/main.php（主库）+ icd10.php（字典库）；
 *    旧分散式 schema 归档于 app/config/schema/legacy/（供数据迁移工具引用）。
 * 5. 兼容旧调用：DB::pdo($key)/DB::q($key,...) 等旧分散库签名仍可用，
 *    非 icd10 的 key 一律路由到主库，icd10 路由到只读字典库。
 * 6. 所有 SQL 一律使用 PDO 预处理语句，防止 SQL 注入。
 * ============================================================ */
class DatabaseManager {

    /** 主库 PDO 连接 */
    private static $main = null;

    /** ICD-10 只读 PDO 连接 */
    private static $icd10 = null;

    /** 是否已执行过主库种子数据 */
    private static $seeded = false;

    /** 旧分散库 key 白名单（兼容旧调用签名：DB::q('patient', ...)） */
    private static $legacyKeys = array(
        'core', 'user', 'dept', 'patient', 'order', 'drug', 'medical',
        'nurse', 'lab', 'disp', 'emr_templates', 'clinic_rooms',
        'consultation', 'icd10', 'admin',
    );

    /**
     * 获取统一业务主库 PDO 连接（懒加载：首次访问自动建库建表迁移种子）
     * 双驱动：sqlite 打开 clinic_main.db，mysql 连接 MYSQL_DB_NAME 库
     */
    public static function getMain() {
        if (self::$main !== null) {
            return self::$main;
        }
        if (DB_DRIVER === 'mysql') {
            $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT
                 . ';dbname=' . MYSQL_DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } else {
            // ===== SQLite 主库 =====
            $dir = DATA_DIR . '/db';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . '/clinic_main.db';
            $pdo = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            // 并发写等待（挂号/缴费等高频写场景）
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        self::$main = $pdo;
        // 建表 / 迁移（幂等，主库定义加载 main.php，缺失时聚合 legacy）
        $def = self::mainSchema();
        self::createTables(self::$main, $def);
        self::migrate(self::$main, $def);
        return self::$main;
    }

    /**
     * 获取 ICD-10 独立字典库 PDO 连接（只读）
     * 说明：首次访问若文件不存在则先读写建库建表种子，再以
     * PRAGMA query_only 强制只读重开；此后任何写操作都会被 SQLite 拒绝。
     */
    public static function getIcd10() {
        if (self::$icd10 !== null) {
            return self::$icd10;
        }
        $dir = DATA_DIR . '/db';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/icd10.db';
        // 首次：文件不存在 → 读写模式建库建表种子
        if (!file_exists($file)) {
            $rw = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            $def = self::icd10Schema();
            self::createTables($rw, $def);
            self::migrate($rw, $def);
            self::seedIcd10($rw, $def);
            unset($rw);
        }
        // 以只读模式打开（SQLite PRAGMA query_only 阻止一切写操作）
        $pdo = new PDO('sqlite:' . $file, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$icd10 = $pdo;
        return self::$icd10;
    }

    /** 初始化全部数据库（主库 + ICD-10 字典库）——入口文件启动时调用 */
    public static function initAll() {
        self::getMain();
        self::getIcd10();
        self::seedAll();
    }

    /** 旧签名兼容：pdo($key) —— 非 icd10 一律返回主库，icd10 返回只读字典库 */
    public static function pdo($key) {
        return $key === 'icd10' ? self::getIcd10() : self::getMain();
    }

    /* ==================== Schema 加载 ==================== */

    /** 主库 schema 定义：优先 main.php，缺失时聚合 legacy（兼容过渡） */
    private static function mainSchema() {
        static $def = null;
        if ($def !== null) return $def;
        $file = APP_ROOT . '/app/config/schema/main.php';
        if (is_file($file)) {
            $def = require $file;
            $def['key'] = 'main';
            return $def;
        }
        $def = self::aggregateLegacySchema();
        return $def;
    }

    /** ICD-10 字典库 schema 定义：优先 icd10.php，缺失时用 legacy 011 */
    private static function icd10Schema() {
        static $def = null;
        if ($def !== null) return $def;
        $file = APP_ROOT . '/app/config/schema/icd10.php';
        if (is_file($file)) {
            $def = require $file;
            $def['key'] = 'icd10';
            return $def;
        }
        $legacy = APP_ROOT . '/app/config/schema/legacy/011_icd10.php';
        if (is_file($legacy)) {
            $def = require $legacy;
            $def['key'] = 'icd10';
            return $def;
        }
        // 兜底：极简字典表
        $def = array(
            'key' => 'icd10',
            'version' => 1,
            'tables' => array(
                'icd10' => 'CREATE TABLE IF NOT EXISTS icd10 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code TEXT, name TEXT, pinyin TEXT)',
            ),
            'migrations' => array(),
            'seed' => array(),
        );
        return $def;
    }

    /**
     * 聚合旧分散式 schema（legacy/*.php）为统一主库定义（过渡期/迁移工具用）
     * 跳过 icd10（独立字典库）；迁移 SQL 重新连续编号；
     * 各表以 CREATE TABLE IF NOT EXISTS 合并（幂等）。
     */
    private static function aggregateLegacySchema() {
        $dir = APP_ROOT . '/app/config/schema/legacy';
        $files = glob($dir . '/*.php');
        if ($files === false) $files = array();
        sort($files);
        $tables = array();
        $migrations = array();
        $seed = array();
        $ver = 0;
        foreach ($files as $f) {
            $key = preg_replace('/^\d+_/', '', basename($f, '.php'));
            if ($key === 'icd10') continue; // 独立字典库
            $d = require $f;
            foreach ((array)$d['tables'] as $t => $sql) {
                $tables[$t] = $sql;
            }
            foreach ((array)$d['migrations'] as $mv => $sqls) {
                foreach ((array)$sqls as $sql) {
                    $ver++;
                    $migrations[$ver] = array($sql);
                }
            }
            foreach ((array)$d['seed'] as $s) {
                $seed[] = $s;
            }
        }
        return array('key' => 'main', 'version' => $ver, 'tables' => $tables, 'migrations' => $migrations, 'seed' => $seed);
    }

    /* ==================== 建表 / 迁移 / 种子 ==================== */

    /** 建表（CREATE TABLE IF NOT EXISTS，幂等；自动做方言转换） */
    private static function createTables($pdo, $def) {
        if (empty($def['tables'])) return;
        foreach ($def['tables'] as $sql) {
            try {
                $pdo->exec(self::dialectSql($sql));
            } catch (Exception $ex) {
                if (DEBUG) error_log('[DB建表失败] ' . $def['key'] . ': ' . $ex->getMessage());
            }
        }
    }

    /**
     * 版本迁移：SQLite 用 PRAGMA user_version，MySQL 用 settings 表记录，
     * 逐版本执行 migrations（幂等：ALTER ADD COLUMN 检测列已存在则跳过）
     */
    private static function migrate($pdo, $def) {
        $target = isset($def['version']) ? (int)$def['version'] : 0;
        if ($target <= 0) return;
        $current = self::schemaVersion($pdo);
        while ($current < $target) {
            $next = $current + 1;
            $sqls = isset($def['migrations'][$next]) ? $def['migrations'][$next] : array();
            $failed = false;
            foreach ($sqls as $sql) {
                try {
                    $sql = self::dialectSql($sql);
                    if (preg_match('/^ALTER TABLE\s+(\S+)\s+ADD\s+COLUMN\s+(\S+)/i', trim($sql), $mm)) {
                        if (self::columnExists($pdo, $mm[1], $mm[2])) {
                            continue;
                        }
                    }
                    $pdo->exec($sql);
                } catch (Exception $ex) {
                    $failed = true;
                    if (DEBUG) error_log('[迁移失败] ' . $def['key'] . ' v' . $next . ': ' . $ex->getMessage());
                }
            }
            if ($failed) break;
            $current = $next;
            self::setSchemaVersion($pdo, $current);
        }
    }

    /** 读取当前 schema 版本号（SQLite: user_version；MySQL: settings 表） */
    private static function schemaVersion($pdo) {
        if (DB_DRIVER === 'mysql') {
            try {
                $r = $pdo->query("SELECT svalue FROM settings WHERE skey='db_schema_version'")->fetchColumn();
                return (int)$r;
            } catch (Exception $ex) {
                return 0;
            }
        }
        return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    }

    /** 写入当前 schema 版本号 */
    private static function setSchemaVersion($pdo, $version) {
        if (DB_DRIVER === 'mysql') {
            $pdo->prepare('INSERT INTO settings(skey, svalue) VALUES(?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')
                ->execute(array('db_schema_version', (string)$version));
            return;
        }
        $pdo->exec('PRAGMA user_version = ' . (int)$version);
    }

    /** 判断表中是否存在指定列（SQLite 用 PRAGMA，MySQL 用 SHOW COLUMNS） */
    private static function columnExists($pdo, $table, $column) {
        try {
            if (DB_DRIVER === 'mysql') {
                $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $column . '\'')->fetchAll();
                return count($rows) > 0;
            }
            $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if (strcasecmp($c['name'], $column) === 0) return true;
            }
            return false;
        } catch (Exception $ex) {
            return false;
        }
    }

    /**
     * 主库种子数据（幂等）：通过 settings 表标记已执行，避免重复写入
     * （首次初始化写入字典类默认数据，如药品设置、项目分类、模板等）
     */
    public static function seedAll() {
        if (self::$seeded) return;
        self::$seeded = true;
        $pdo = self::getMain();
        $doneKey = 'seed_done_v1';
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey='seed_done_v1'")->fetchColumn();
            if ($n > 0) return;
        } catch (Exception $ex) {
            return; // 表不存在时静默（由建表兜底）
        }
        $def = self::mainSchema();
        foreach ((array)$def['seed'] as $seedSql) {
            try {
                $pdo->exec(self::dialectSql($seedSql));
            } catch (Exception $ex) {
                if (DEBUG) error_log('[种子失败] main: ' . $ex->getMessage());
            }
        }
        try {
            self::setSettingRaw($pdo, $doneKey, '1');
        } catch (Exception $ex) {}
    }

    /** ICD-10 种子数据（幂等 INSERT OR IGNORE；仅首次建库时执行） */
    private static function seedIcd10($pdo, $def) {
        foreach ((array)$def['seed'] as $seedSql) {
            try {
                $pdo->exec(self::dialectSql($seedSql));
            } catch (Exception $ex) {
                if (DEBUG) error_log('[ICD10种子失败]: ' . $ex->getMessage());
            }
        }
    }

    /* ==================== 方言辅助 ==================== */

    /**
     * SQL 方言转换（SQLite → MySQL 通用写法）：
     * AUTOINCREMENT→AUTO_INCREMENT、INSERT OR IGNORE→INSERT IGNORE、
     * INSERT OR REPLACE→REPLACE INTO、datetime('now','localtime')→NOW()
     */
    private static function dialectSql($sql) {
        if (DB_DRIVER !== 'mysql') return $sql;
        $sql = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $sql);
        $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT IGNORE', $sql);
        $sql = preg_replace('/\bINSERT\s+OR\s+REPLACE\b(?=\s)/i', 'REPLACE', $sql);
        $sql = str_replace("datetime('now','localtime')", 'NOW()', $sql);
        $sql = preg_replace('/\bIFNULL\(/i', 'IFNULL(', $sql);
        return $sql;
    }

    /** 主库原始写设置（种子标记用，不依赖 helpers） */
    private static function setSettingRaw($pdo, $key, $value) {
        if (DB_DRIVER === 'mysql') {
            $pdo->prepare('INSERT INTO settings(skey, svalue) VALUES(?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')
                ->execute(array($key, (string)$value));
            return;
        }
        $pdo->prepare('INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)')->execute(array($key, (string)$value));
    }

    /* ==================== 查询门面（预处理防注入） ==================== */

    /**
     * 统一参数解析：兼容新旧两种签名
     *   新式：DB::q($sql, $params)
     *   旧式：DB::q($key, $sql, $params) / DB::q($key, $sql)
     * @return array [PDO, sql, params]
     */
    private static function resolve($a, $b, $c) {
        if (is_string($a) && in_array($a, self::$legacyKeys, true) && is_string($b)) {
            // 旧签名：第一个参数是分散库 key
            $pdo = $a === 'icd10' ? self::getIcd10() : self::getMain();
            return array($pdo, $b, is_array($c) ? $c : array());
        }
        return array(self::getMain(), $a, is_array($b) ? $b : array());
    }

    /** 查询多行 */
    public static function q($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 查询单行 */
    public static function one($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** 查询单值 */
    public static function val($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写操作，返回影响行数 */
    public static function exec($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 插入并返回自增主键 */
    public static function insert($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $pdo->prepare($sql)->execute($params);
        return (int)$pdo->lastInsertId();
    }

    /** 别名（旧代码兼容） */
    public static function query($a, $b = array(), $c = null) { return self::q($a, $b, $c); }
}

/** 短名门面：DB::q / DB::one / DB::val / DB::exec / DB::insert */
class_alias('DatabaseManager', 'DB');
