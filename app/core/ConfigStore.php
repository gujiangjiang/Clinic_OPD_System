<?php
/**
 * ============================================================
 * ConfigStore.php v1.0.0 — 基础设施配置存储（config.db 独立配置库）
 * ============================================================
 * 说明：
 * 1. config.db 仅存储「基础设施配置」：主库驱动类型与连接凭证、
 *    缓存驱动配置、App Key/Pepper、维护模式等；与主业务库完全解耦。
 * 2. 健壮性保障（Phase 1 核心）：
 *    - 打开前校验文件头部 16 字节 Magic Header（SQLite format 3\0）；
 *    - 打开过程捕获 PDOException/SQLite3 异常，绝不 500；
 *    - 检测到损坏/非法文件时自动重命名备份为 config.db.corrupt.[ts]，
 *      记录日志并优雅降级（返回不可用，由安装/修复引导接管）。
 * 3. 向后兼容：config.db 不存在时系统按 bootstrap.php 默认常量运行
 *    （旧版行为），首次安装向导写入 config.db 后启用配置库。
 * 4. 所有 SQL 使用 PDO 预处理，键值表 config(ckey, cvalue)。
 * ============================================================ */

class ConfigStore {

    /** 当前 PDO 连接（null = 未打开/不可用） */
    private static $pdo = null;

    /** 本次请求内已读配置缓存 */
    private static $cache = array();

    /** 可用性探测结果缓存（-1 未探测 / 0 不可用 / 1 可用） */
    private static $available = -1;

    /** config.db 文件路径 */
    public static function path() {
        return DATA_DIR . '/config.db';
    }

    /** 校验文件头 16 字节是否为 SQLite Magic Header（"SQLite format 3\000"） */
    public static function isSqliteFile($path) {
        if (!is_file($path)) return false;
        $fp = @fopen($path, 'rb');
        if ($fp === false) return false;
        $head = fread($fp, 16);
        fclose($fp);
        return $head === "SQLite format 3\x00";
    }

    /** 检测 config.db 是否可用（存在 + 合法 SQLite 头 + 可打开） */
    public static function available() {
        if (self::$available !== -1) return self::$available === 1;
        $path = self::path();
        if (!is_file($path)) {
            self::$available = 0;
            return false;
        }
        if (!self::isSqliteFile($path)) {
            self::quarantineCorrupt($path);
            self::$available = 0;
            return false;
        }
        try {
            $pdo = self::openRaw($path);
            $pdo->query('SELECT ckey FROM config LIMIT 1');
            self::$pdo = $pdo;
            self::$available = 1;
            return true;
        } catch (Exception $ex) {
            error_log('[ConfigStore] config.db 打开失败，将按默认配置降级运行：' . $ex->getMessage());
            self::quarantineCorrupt($path);
            self::$available = 0;
            return false;
        }
    }

    /** 损坏文件隔离备份：config.db.corrupt.[timestamp] */
    private static function quarantineCorrupt($path) {
        if (!is_file($path)) return;
        $backup = $path . '.corrupt.' . date('YmdHis');
        if (@rename($path, $backup)) {
            error_log('[ConfigStore] 检测到损坏的 config.db，已备份为 ' . basename($backup) . '，系统将以默认配置降级运行');
        } else {
            error_log('[ConfigStore] config.db 损坏且备份失败：' . $path);
        }
    }

    /** 原始 SQLite 连接（不校验，供内部使用） */
    private static function openRaw($path) {
        return new PDO('sqlite:' . $path, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }

    /** 打开（若未打开则尝试），返回 PDO 或 null */
    private static function open() {
        if (self::$pdo !== null) return self::$pdo;
        if (!self::available()) return null;
        return self::$pdo;
    }

    /**
     * 读取配置键（本次请求缓存）
     * @param string $key 键名
     * @param mixed  $default 默认值
     */
    public static function get($key, $default = null) {
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        $pdo = self::open();
        if (!$pdo) return $default;
        try {
            $st = $pdo->prepare('SELECT cvalue FROM config WHERE ckey=?');
            $st->execute(array($key));
            $v = $st->fetchColumn();
            if ($v === false) return $default;
            self::$cache[$key] = $v;
            return $v;
        } catch (Exception $ex) {
            error_log('[ConfigStore] 读取配置失败 ' . $key . '：' . $ex->getMessage());
            return $default;
        }
    }

    /** 写入配置键（空值=删除键） */
    public static function set($key, $value) {
        $pdo = self::open();
        if (!$pdo) return false;
        try {
            if ($value === null || $value === '') {
                $st = $pdo->prepare('DELETE FROM config WHERE ckey=?');
                $st->execute(array($key));
            } else {
                $st = $pdo->prepare('INSERT OR REPLACE INTO config(ckey, cvalue) VALUES(?, ?)');
                $st->execute(array($key, (string)$value));
            }
            self::$cache[$key] = $value;
            return true;
        } catch (Exception $ex) {
            error_log('[ConfigStore] 写入配置失败 ' . $key . '：' . $ex->getMessage());
            return false;
        }
    }

    /** 批量读取配置（返回关联数组） */
    public static function getAll() {
        $pdo = self::open();
        if (!$pdo) return array();
        try {
            $out = array();
            foreach ($pdo->query('SELECT ckey, cvalue FROM config') as $r) {
                $out[$r['ckey']] = $r['cvalue'];
                self::$cache[$r['ckey']] = $r['cvalue'];
            }
            return $out;
        } catch (Exception $ex) {
            return array();
        }
    }

    /** 删除全部配置（重置系统时使用） */
    public static function wipe() {
        $pdo = self::open();
        if ($pdo) {
            try { $pdo->exec('DELETE FROM config'); } catch (Exception $ex) {}
        }
        self::$cache = array();
        self::$available = -1;
        self::$pdo = null;
    }

    /** 配置库是否存在（无论是否可用） */
    public static function exists() {
        return is_file(self::path());
    }

    /**
     * 强制确保 config.db 存在且可用（系统设置等写入配置的前置条件）：
     * 缺失/损坏时自动创建并回填 bootstrap 默认常量配置（保证系统行为不变），
     * 绝不出现「无处保存配置」的降级空转状态。
     * @return bool 是否可用
     */
    public static function ensure() {
        if (self::available()) return true;
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (!is_file($path) || !self::isSqliteFile($path)) {
            // 缺失或损坏（损坏文件已由 available 隔离备份）→ 新建
            try {
                $pdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                $pdo->exec('CREATE TABLE IF NOT EXISTS config(ckey TEXT PRIMARY KEY, cvalue TEXT)');
                unset($pdo);
            } catch (Exception $ex) {
                error_log('[ConfigStore] 创建 config.db 失败：' . $ex->getMessage());
                return false;
            }
        }
        ConfigStore::resetCache();
        // 回填默认配置（从 bootstrap 常量），保证此后读写一致
        if (ConfigStore::get('db.driver', '') === '') {
            ConfigStore::set('db.driver', defined('DB_DRIVER') ? DB_DRIVER : 'sqlite');
            if (defined('MYSQL_HOST')) {
                ConfigStore::set('db.mysql.host', MYSQL_HOST);
                ConfigStore::set('db.mysql.port', MYSQL_PORT);
                ConfigStore::set('db.mysql.dbname', MYSQL_DB_NAME);
                ConfigStore::set('db.mysql.user', MYSQL_USER);
                ConfigStore::set('db.mysql.pass', MYSQL_PASS);
            }
            if (defined('PGSQL_HOST')) {
                ConfigStore::set('db.pgsql.host', PGSQL_HOST);
                ConfigStore::set('db.pgsql.port', PGSQL_PORT);
                ConfigStore::set('db.pgsql.dbname', PGSQL_DB_NAME);
                ConfigStore::set('db.pgsql.user', PGSQL_USER);
                ConfigStore::set('db.pgsql.pass', PGSQL_PASS);
            }
            ConfigStore::set('db.sqlite.path', '');
            ConfigStore::set('cache.driver', 'file');
        }
        ConfigStore::resetCache();
        return self::available();
    }

    /** 重置进程内探测缓存（外部改动 config.db 后强制重新探测） */
    public static function resetCache() {
        self::$available = -1;
        self::$pdo = null;
        self::$cache = array();
    }

    /** 主数据库驱动（读配置，回退默认常量） */
    public static function driver() {
        $d = self::get('db.driver', '');
        return $d !== '' ? $d : (defined('DB_DRIVER') ? DB_DRIVER : 'sqlite');
    }

    /** 主数据库连接参数（驱动相关，回退默认常量） */
    public static function dbParams() {
        $driver = self::driver();
        if ($driver === 'mysql') {
            return array(
                'host' => self::get('db.mysql.host', defined('MYSQL_HOST') ? MYSQL_HOST : '127.0.0.1'),
                'port' => self::get('db.mysql.port', defined('MYSQL_PORT') ? MYSQL_PORT : '3306'),
                'dbname' => self::get('db.mysql.dbname', defined('MYSQL_DB_NAME') ? MYSQL_DB_NAME : 'his_main'),
                'user' => self::get('db.mysql.user', defined('MYSQL_USER') ? MYSQL_USER : 'root'),
                'pass' => self::get('db.mysql.pass', defined('MYSQL_PASS') ? MYSQL_PASS : ''),
            );
        }
        if ($driver === 'pgsql') {
            return array(
                'host' => self::get('db.pgsql.host', defined('PGSQL_HOST') ? PGSQL_HOST : '127.0.0.1'),
                'port' => self::get('db.pgsql.port', defined('PGSQL_PORT') ? PGSQL_PORT : '5432'),
                'dbname' => self::get('db.pgsql.dbname', defined('PGSQL_DB_NAME') ? PGSQL_DB_NAME : 'his_main'),
                'user' => self::get('db.pgsql.user', defined('PGSQL_USER') ? PGSQL_USER : 'postgres'),
                'pass' => self::get('db.pgsql.pass', defined('PGSQL_PASS') ? PGSQL_PASS : ''),
            );
        }
        return array();
    }

    /** 驱动选项注册表（唯一数据源：app/config/drivers.php，安装向导/系统设置/后端校验共用） */
    public static function driverOptions() {
        static $opts = null;
        if ($opts === null) {
            $opts = require APP_ROOT . '/app/config/drivers.php';
        }
        return $opts;
    }

    /** 数据库驱动是否受支持 */
    public static function dbDriverValid($d) {
        $o = self::driverOptions();
        return isset($o['db'][$d]);
    }

    /** 缓存驱动是否受支持 */
    public static function cacheDriverValid($d) {
        $o = self::driverOptions();
        return isset($o['cache'][$d]);
    }

    /** 驱动注册表（供前端动态渲染，含扩展可用性） */
    public static function driverOptionsPublic() {
        $o = self::driverOptions();
        $out = array('db' => array(), 'cache' => array());
        foreach ($o['db'] as $k => $v) {
            $out['db'][$k] = array(
                'label' => $v['label'],
                'extension' => $v['extension'],
                'installed' => $v['extension'] === '' || extension_loaded($v['extension']),
                'params' => isset($v['params']) ? $v['params'] : array(),
            );
        }
        foreach ($o['cache'] as $k => $v) {
            $out['cache'][$k] = array(
                'label' => $v['label'],
                'extension' => $v['extension'],
                'installed' => $v['extension'] === '' || extension_loaded($v['extension']),
                'params' => isset($v['params']) ? $v['params'] : array(),
            );
        }
        return $out;
    }

    /** 缓存驱动（file/apcu/redis/memcached，回退 file） */
    public static function cacheDriver() {
        $d = self::get('cache.driver', '');
        if ($d === '' || !self::cacheDriverValid($d)) return 'file';
        return $d;
    }

    /** 缓存 Redis 连接参数 */
    public static function redisParams() {
        return array(
            'host' => self::get('cache.redis.host', '127.0.0.1'),
            'port' => self::get('cache.redis.port', '6379'),
            'auth' => self::get('cache.redis.auth', ''),
            'prefix' => self::get('cache.redis.prefix', 'clinic_sess:'),
            'timeout' => self::get('cache.redis.timeout', '2.0'),
        );
    }

    /** 缓存 Memcached 连接参数 */
    public static function memcachedParams() {
        return array(
            'servers' => self::get('cache.memcached.servers', '127.0.0.1:11211'),
            'prefix' => self::get('cache.memcached.prefix', 'clinic_sess:'),
        );
    }

    /** App Key / Pepper */
    public static function appKey() {
        return self::get('app.key', '');
    }

    /** 维护模式（1=开启） */
    public static function maintenance() {
        return (int)self::get('app.maintenance', 0) === 1;
    }

    /** 系统是否已安装（config.db 存在且可用、主库有管理员）：
     *  由 Router 调用。config.db 不可用时回退旧行为（直接查主库）。 */
    public static function isSystemInstalled() {
        if (self::exists() && self::available()) {
            // config.db 存在且有效：以主库管理员为准
            try {
                $main = DatabaseManager::getMain();
                return (int)$main->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
            } catch (Exception $ex) {
                return false;
            }
        }
        // 无 config.db（旧版/首次）：回退查询默认驱动主库
        try {
            return (int)DatabaseManager::val('SELECT COUNT(*) FROM users') > 0;
        } catch (Exception $ex) {
            return false;
        }
    }
}