<?php
/**
 * ============================================================
 * DatabaseManager.php v3.0.0 — 统一数据库管理模块（单主库 + 多驱动）
 * ============================================================
 * 说明：
 * 1. 统一业务主库 clinic_main：全部业务表合并进单一主库，
 *    getMain() 返回主库 PDO，由 DB_DRIVER 切换：
 *      sqlite  → data/db/clinic_main.db
 *      mysql   → MySQL/MariaDB 库（MYSQL_* 常量）
 *      pgsql   → PostgreSQL 库（PGSQL_* 常量）
 * 2. ICD-10 独立字典库：getIcd10() 返回独立 SQLite PDO
 *    （data/db/icd10.db），独立存储 icd10 表。
 * 3. 多驱动一键切换：DB_DRIVER='sqlite'|'mysql'|'pgsql'，全量 SQL 遵循
 *    ANSI 标准，自增主键 / 布尔 / 时间 / upsert / 列存在检测由方言翻译层
 *    （dialectSql / upsertSetting / columnExists）按驱动统一处理。
 * 4. schema 定义：app/config/schema/main.php（主库）+ icd10.php（字典库）；
 *    旧分散式 schema 归档于 app/config/schema/legacy/。
 * 5. 兼容旧调用：DB::pdo($key)/DB::q($key,...) 等旧分散库签名仍可用。
 * 6. 所有 SQL 一律使用 PDO 预处理语句，防止 SQL 注入。
 * ============================================================ */
class DatabaseManager {

    /** 主库 PDO 连接 */
    private static $main = null;

    /** ICD-10 独立 PDO 连接 */
    private static $icd10 = null;

    /** 是否已执行过主库种子数据 */
    private static $seeded = false;

    /** 当前驱动名缓存（sqlite/mysql/pgsql） */
    private static $driver = null;

    /** 双写备份库 PDO（RAID1 式实时镜像，同驱动可靠） */
    private static $backupPdo = null;
    private static $dualWrite = null;   // -1 未探测 / 0 关 / 1 开

    /** 旧分散库 key 白名单（兼容旧调用签名：DB::q(...)） */
    private static $legacyKeys = array(
        'core', 'user', 'dept', 'patient', 'order', 'drug', 'medical',
        'nurse', 'lab', 'disp', 'emr_templates', 'clinic_rooms',
        'consultation', 'icd10', 'admin', 'main',
    );

    /** 当前驱动名 */
    public static function driver() {
        if (self::$driver !== null) return self::$driver;
        self::$driver = ConfigStore::driver();   // 优先读 config.db，回退 DB_DRIVER 默认常量
        return self::$driver;
    }

    /** 解析 SQLite 库文件路径（config.db 配置优先，相对路径按项目根补全） */
    public static function sqlitePath($configKey, $defaultName) {
        $p = ConfigStore::get($configKey, '');
        if ($p === '') return DATA_DIR . '/db/' . $defaultName;
        if ($p[0] !== '/' && $p[0] !== '.') $p = APP_ROOT . '/' . ltrim($p, '/');
        return $p;
    }

    /** 标识符加引号（MySQL/MariaDB 用反引号，PostgreSQL 用双引号，SQLite 原样） */
    private static function qi($id) {
        if (self::driver() === 'pgsql') return '"' . $id . '"';
        if (self::driver() === 'mysql') return '`' . $id . '`';
        return $id;
    }

    /**
     * 获取统一业务主库 PDO 连接（懒加载：首次访问自动建库建表迁移种子）
     * 多驱动：sqlite 打开 clinic_main.db，mysql 连接 MYSQL_* 库，pgsql 连接 PGSQL_* 库
     */
    public static function getMain() {
        if (self::$main !== null) {
            return self::$main;
        }
        $driver = self::driver();
        if ($driver === 'mysql') {
            $p = ConfigStore::dbParams();
            $dsn = 'mysql:host=' . $p['host'] . ';port=' . $p['port']
                 . ';dbname=' . $p['dbname'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $p['user'], $p['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } elseif ($driver === 'pgsql') {
            $p = ConfigStore::dbParams();
            $dsn = 'pgsql:host=' . $p['host'] . ';port=' . $p['port']
                 . ';dbname=' . $p['dbname'];
            $pdo = new PDO($dsn, $p['user'], $p['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } else {
            // ===== SQLite 主库 =====
            $file = self::sqlitePath('db.sqlite.path', 'clinic_main.db');
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
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
        // 运行时关键列自愈：版本迁移链若因历史版本失败而中断（如旧 SQLite
        // 缺 JSON1 扩展卡在 json_extract 迁移），后续版本的 ALTER 不会执行，
        // 新代码引用缺失列将直接 500。此处对登录安全等关键列做幂等补齐，
        // 与版本迁移解耦——保证任何存量库都能平滑升级。
        self::ensureRuntimeColumns(self::$main);
        return self::$main;
    }

    /**
     * 获取 ICD-10 独立字典库 PDO 连接（独立库）
     * 说明：首次访问若文件不存在则先读写建库建表种子；库以读写模式打开，
     * 管理端可维护诊断（新增/编辑/删除均走 icd10 库）。
     */
    public static function getIcd10() {
        if (self::$icd10 !== null) {
            return self::$icd10;
        }
        $file = self::sqlitePath('db.icd10.path', 'icd10.db');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
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
        // ICD-10 独立字典库：与管理库隔离，但允许管理端新增/编辑/删除诊断维护
        $pdo = new PDO('sqlite:' . $file, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
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

    /**
     * 清空主库全部业务数据（安装向导「全新安装」确认后使用）：
     *   - SQLite：直接删除库文件（含 -wal/-shm），下次 getMain 重新建库；
     *   - MySQL/PostgreSQL：按 schema 逆序 DROP TABLE IF EXISTS（关闭外键约束）。
     * 注意：仅清空主业务库，不动独立 ICD-10 字典库与 config.db。
     */
    public static function wipeMain() {
        self::getMain();   // 确保主库可达并已知 schema
        if (self::driver() === 'sqlite') {
            $file = self::sqlitePath('db.sqlite.path', 'clinic_main.db');
            self::$main = null;
            @unlink($file);
            @unlink($file . '-wal');
            @unlink($file . '-shm');
            return;
        }
        $def = self::mainSchema();
        $pdo = self::getMain();
        try {
            if (self::driver() === 'mysql') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            }
        } catch (Exception $ex) {}
        foreach (array_reverse(array_keys((array)$def['tables'])) as $table) {
            try {
                $sql = 'DROP TABLE IF EXISTS ' . self::qi($table);
                if (self::driver() === 'pgsql') $sql .= ' CASCADE';
                $pdo->exec($sql);
            } catch (Exception $ex) {
                if (DEBUG) error_log('[清空主库] 删除表失败 ' . $table . ': ' . $ex->getMessage());
            }
        }
        try {
            if (self::driver() === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Exception $ex) {}
        // 重新建表迁移种子
        self::createTables($pdo, $def);
        self::migrate($pdo, $def);
        self::seedAll();
    }

    /** 旧签名兼容：pdo($key) —— 非 icd10 一律返回主库，icd10 返回字典库 */
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
     * 版本迁移：SQLite 用 PRAGMA user_version，MySQL/PostgreSQL 用 settings 表记录，
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

    /** 读取当前 schema 版本号（SQLite: user_version；MySQL/PostgreSQL: settings 表） */
    private static function schemaVersion($pdo) {
        if (self::driver() !== 'sqlite') {
            try {
                $r = $pdo->query("SELECT svalue FROM settings WHERE skey='db_schema_version'")->fetchColumn();
                return (int)$r;
            } catch (Exception $ex) {
                return 0;
            }
        }
        return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    }

    /** 写入当前 schema 版本号（SQLite 写 PRAGMA user_version；MySQL/PostgreSQL 写 settings 表） */
    private static function setSchemaVersion($pdo, $version) {
        if (self::driver() === 'sqlite') {
            $pdo->exec('PRAGMA user_version = ' . (int)$version);
            return;
        }
        self::upsertSetting($pdo, 'db_schema_version', (string)$version);
    }

    /**
     * settings 键值 upsert（多驱动统一：SQLite INSERT OR REPLACE；
     * MySQL ON DUPLICATE KEY UPDATE；PostgreSQL ON CONFLICT(skey)）
     */
    public static function upsertSetting($pdo, $key, $value) {
        $value = (string)$value;
        if (self::driver() === 'mysql') {
            $pdo->prepare('INSERT INTO settings(skey, svalue) VALUES(?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')
                ->execute(array($key, $value));
            return;
        }
        if (self::driver() === 'pgsql') {
            $pdo->prepare('INSERT INTO settings(skey, svalue) VALUES(?, ?) ON CONFLICT (skey) DO UPDATE SET svalue=EXCLUDED.svalue')
                ->execute(array($key, $value));
            return;
        }
        $pdo->prepare('INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)')->execute(array($key, $value));
    }

    /** 判断表中是否存在指定列（SQLite 用 PRAGMA；MySQL 用 SHOW COLUMNS；PostgreSQL 用 information_schema） */
    private static function columnExists($pdo, $table, $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }
        try {
            $driver = self::driver();
            if ($driver === 'mysql') {
                $rows = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetchAll();
                return count($rows) > 0;
            }
            if ($driver === 'pgsql') {
                $rows = $pdo->query(
                    "SELECT 1 FROM information_schema.columns WHERE table_name='$table' AND column_name='$column'"
                )->fetchAll();
                return count($rows) > 0;
            }
            $cols = $pdo->query("SELECT * FROM pragma_table_info('$table') WHERE name='$column'")->fetchAll(PDO::FETCH_ASSOC);
            return count($cols) > 0;
        } catch (Exception $ex) {
            return false;
        }
    }

    /**
     * 运行时关键列自愈（与版本迁移解耦的兜底保障）：
     * 逐列检测缺失即补齐（ALTER ADD COLUMN，幂等）。覆盖登录安全体系
     * 依赖的列——若迁移链中断（历史版本失败卡版本号），登录页将因
     * UPDATE/SELECT 引用缺失列而 500，任何用户无法登录。此处保证
     * 首次访问即完成补齐，无需人工干预。
     * 列定义与 main.php 建表语句/v31 迁移保持一致。
     */
    private static function ensureRuntimeColumns($pdo) {
        // 表 => 列 => 类型（方言无关：各驱动均支持 ADD COLUMN 语法）
        $need = array(
            'users' => array(
                'lock_reason'      => 'TEXT DEFAULT NULL',
                'locked_at'        => 'TEXT DEFAULT NULL',
                'lock_ip'          => "TEXT DEFAULT ''",
                'login_fail_count' => 'INTEGER DEFAULT 0',
                'login_locked_until' => 'TEXT',
                'status'           => 'INTEGER DEFAULT 1',
                'email'            => 'TEXT',
            ),
        );
        foreach ($need as $table => $cols) {
            $t = self::qi($table);
            foreach ($cols as $col => $type) {
                if (!self::columnExists($pdo, $table, $col)) {
                    try {
                        $pdo->exec("ALTER TABLE $t ADD COLUMN " . self::qi($col) . " $type");
                    } catch (Exception $ex) {
                        if (DEBUG) error_log('[运行时列自愈失败] ' . $table . '.' . $col . ': ' . $ex->getMessage());
                    }
                }
            }
        }
    }

    /**
     * 主库种子数据（幂等）：通过 settings 表标记已执行，避免重复写入
     * （首次初始化写入字典类默认数据，如药品设置、项目分类、模板等）
     * 并发首启防护：事务内完成「检查 + 执行 + 写标记」，避免两个并发
     * 首次请求都通过检查重复执行种子。
     */
    public static function seedAll() {
        if (self::$seeded) return;
        self::$seeded = true;
        $pdo = self::getMain();
        $doneKey = 'seed_done_v1';
        try {
            $pdo->beginTransaction();
            $n = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey='seed_done_v1'")->fetchColumn();
            if ($n > 0) {
                $pdo->rollBack();
                return;
            }
            $def = self::mainSchema();
            foreach ((array)$def['seed'] as $seedSql) {
                try {
                    $pdo->exec(self::dialectSql($seedSql));
                } catch (Exception $ex) {
                    if (DEBUG) error_log('[种子失败] main: ' . $ex->getMessage());
                }
            }
            self::setSettingRaw($pdo, $doneKey, '1');
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (DEBUG) error_log('[种子异常] main: ' . $ex->getMessage());
        }
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
     * SQL 方言翻译（SQLite 源 → MySQL/MariaDB / PostgreSQL 通用写法）：
     * 仅在有目标驱动（非 sqlite）时执行，覆盖：
     *  - 时间函数：datetime('now','localtime') → NOW()；strftime epoch/日期 → EXTRACT/TO_CHAR
     *  - 自增主键：AUTOINCREMENT → AUTO_INCREMENT / SERIAL
     *  - 幂等插入：INSERT OR IGNORE → INSERT IGNORE / INSERT ... ON CONFLICT DO NOTHING
     *  - 替换插入：INSERT OR REPLACE → REPLACE INTO（MySQL；settings 键值由 upsertSetting 按 PG 处理）
     */
    private static function dialectSql($sql) {
        $driver = self::driver();
        if ($driver === 'sqlite') return $sql;
        $sql = str_replace("datetime('now','localtime')", 'NOW()', $sql);
        $sql = str_replace("strftime('%s','now','localtime')", 'EXTRACT(EPOCH FROM now())', $sql);
        $sql = preg_replace("/strftime\('%s',\s*([a-zA-Z0-9_\.]+)\)/i", 'EXTRACT(EPOCH FROM $1)', $sql);
        $sql = preg_replace("/strftime\('%Y-%m-%d',\s*([a-zA-Z0-9_\.]+)\)/i", "TO_CHAR($1, 'YYYY-MM-DD')", $sql);
        if ($driver === 'mysql') {
            $sql = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $sql);
            $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT IGNORE', $sql);
            $sql = preg_replace('/\bINSERT\s+OR\s+REPLACE\b(?=\s)/i', 'REPLACE', $sql);
            return $sql;
        }
        // PostgreSQL
        $sql = preg_replace('/INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i', 'SERIAL PRIMARY KEY', $sql);
        $wasIgnore = (bool)preg_match('/\bINSERT\s+OR\s+IGNORE\b/i', $sql);
        $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT', $sql);
        if ($wasIgnore) {
            $sql = rtrim($sql, ";\s ");
            $sql .= ' ON CONFLICT DO NOTHING;';
        }
        return $sql;
    }

    /** 主库原始写设置（种子标记用，不依赖 helpers） */
    private static function setSettingRaw($pdo, $key, $value) {
        self::upsertSetting($pdo, $key, $value);
    }

    /* ==================== 双写镜像（RAID1 式实时同步，与备份功能分离） ==================== */

    /** 双写是否开启（config.db dual_write.enabled=1 且备份库配置有效且同驱动） */
    public static function dualWriteEnabled() {
        if (self::$dualWrite !== null) return self::$dualWrite === 1;
        $on = false;
        try {
            $on = ConfigStore::get('dual_write.enabled', '') === '1'
                && ConfigStore::get('backup.driver', '') !== ''
                && ConfigStore::driver() === ConfigStore::get('backup.driver', '');
        } catch (Exception $ex) { $on = false; }
        self::$dualWrite = $on ? 1 : 0;
        return $on;
    }

    /** 双写备份库连接（复用 backup 配置；仅同驱动可靠） */
    public static function getBackupPdo() {
        if (self::$backupPdo !== null) return self::$backupPdo;
        $driver = ConfigStore::get('backup.driver', '');
        if ($driver === '' || $driver !== self::driver()) return null;
        try {
            if ($driver === 'mysql') {
                $p = array('host' => ConfigStore::get('backup.mysql.host', '127.0.0.1'), 'port' => ConfigStore::get('backup.mysql.port', '3306'), 'dbname' => ConfigStore::get('backup.mysql.dbname', ''), 'user' => ConfigStore::get('backup.mysql.user', ''), 'pass' => ConfigStore::get('backup.mysql.pass', ''));
                $dsn = 'mysql:host=' . $p['host'] . ';port=' . $p['port'] . ';dbname=' . $p['dbname'] . ';charset=utf8mb4';
                self::$backupPdo = new PDO($dsn, $p['user'], $p['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
            } elseif ($driver === 'pgsql') {
                $p = array('host' => ConfigStore::get('backup.pgsql.host', '127.0.0.1'), 'port' => ConfigStore::get('backup.pgsql.port', '5432'), 'dbname' => ConfigStore::get('backup.pgsql.dbname', ''), 'user' => ConfigStore::get('backup.pgsql.user', ''), 'pass' => ConfigStore::get('backup.pgsql.pass', ''));
                $dsn = 'pgsql:host=' . $p['host'] . ';port=' . $p['port'] . ';dbname=' . $p['dbname'];
                self::$backupPdo = new PDO($dsn, $p['user'], $p['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
            } else {
                $path = ConfigStore::get('backup.sqlite.path', '');
                if ($path === '') return null;
                if ($path[0] !== '/' && $path[0] !== '.') $path = APP_ROOT . '/' . ltrim($path, '/');
                // 镜像前置检查：文件不存在或非有效 SQLite 时不创建/不连接（避免空文件污染，
                // 由 backup_run 负责建表初始化；双写在备份库未就绪时静默跳过）
                if (!is_file($path) || !ConfigStore::isSqliteFile($path)) return null;
                self::$backupPdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                self::$backupPdo->exec('PRAGMA busy_timeout = 5000');
            }
        } catch (Exception $ex) {
            error_log('[双写] 备份库连接失败：' . $ex->getMessage());
            return null;
        }
        return self::$backupPdo;
    }

    /** 写操作镜像到备份库（RAID1 式实时同步；失败仅记日志，绝不影响主库体验） */
    public static function mirrorWrite($sql, $params) {
        if (!self::dualWriteEnabled()) return;
        if (!preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) return;
        try {
            $bp = self::getBackupPdo();
            if (!$bp) return;
            $bp->prepare($sql)->execute($params);
            // 更新最近一次同步时间（30 秒节流，避免高频写 config.db）
            try {
                $lastDual = ConfigStore::get('dual.last_at', '');
                if ($lastDual === '' || (time() - strtotime((string)$lastDual)) > 30) {
                    ConfigStore::set('dual.last_at', now_str());
                }
            } catch (Exception $ex3) {}
        } catch (Exception $ex) {
            error_log('[双写] 备份库写入失败（不影响主库）：' . $ex->getMessage());
            try {
                require_once APP_ROOT . '/app/core/MigrationRunner.php';
                MigrationRunner::log('dual', '镜像写入失败（不影响主库）：' . $ex->getMessage() . ' SQL=' . substr($sql, 0, 80));
            } catch (Exception $ex2) {}
        }
    }

    /* ==================== 查询门面（预处理防注入） ==================== */

    /**
     * 统一参数解析：兼容新旧两种签名
     *   新式：DB::q($sql, $params)
     *   旧式：DB::q($key, $sql, $params) / DB::q($key, $sql)
     * 判定顺序：第二个参数是 SQL（SELECT/INSERT/... 开头）才按旧签名，
     * 避免新式调用中 SQL 恰为 legacy key 字面量（极罕见）时的误路由。
     * @return array [PDO, sql, params]
     */
    private static function resolve($a, $b, $c) {
        if (is_string($a) && in_array($a, self::$legacyKeys, true) && is_string($b)
            && preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|PRAGMA)\b/i', $b)) {
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
        self::mirrorWrite($sql, $params);   // RAID1 式实时双写（同驱动镜像，失败降级不影响主库）
        return $st->rowCount();
    }

    /** 插入并返回自增主键 */
    public static function insert($a, $b = array(), $c = null) {
        list($pdo, $sql, $params) = self::resolve($a, $b, $c);
        $pdo->prepare($sql)->execute($params);
        $id = (int)$pdo->lastInsertId();
        self::mirrorWrite($sql, $params);   // RAID1 式实时双写（同驱动镜像，失败降级不影响主库）
        return $id;
    }

    /** 别名（旧代码兼容） */
    public static function query($a, $b = array(), $c = null) { return self::q($a, $b, $c); }
}

/** 短名门面：DB::q / DB::one / DB::val / DB::exec / DB::insert */
class_alias('DatabaseManager', 'DB');
