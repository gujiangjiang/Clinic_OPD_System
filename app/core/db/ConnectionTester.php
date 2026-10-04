<?php
/**
 * ============================================================
 * ConnectionTester.php — 连接测试统一组件（数据库 / 缓存）
 * ============================================================
 * 说明：安装向导与系统设置（数据库中心 / 缓存与性能）的连接探测逻辑
 * 收敛到此处唯一实现，避免各页重复编写：
 *   - ConnectionTester::db($driver, $params)     数据库连接/可用性
 *   - ConnectionTester::cache($driver, $params)  缓存连接/可用性
 * 返回统一结构 array('ok' => bool, 'msg' => string)。
 * 「应用前必测」：迁移/切换主库、保存缓存驱动前均先调用本组件校验，
 * 不可用则拒绝应用。
 * ============================================================ */

class ConnectionTester {

    /** 测试数据库连接/可用性（sqlite 校验文件与目录；mysql/pgsql 实际连接） */
    public static function db($driver, $params = array()) {
        $o = ConfigStore::driverOptions();
        if (!isset($o['db'][$driver])) return self::fail('未知的数据库驱动');
        $ext = isset($o['db'][$driver]['extension']) ? $o['db'][$driver]['extension'] : '';
        if ($ext !== '' && !extension_loaded($ext)) {
            return self::fail('PHP 未安装 ' . strtoupper($ext) . ' 扩展，无法使用该数据库驱动');
        }
        if ($driver === 'sqlite') return self::dbSqlite($params);

        $host = trim((string)self::p($params, 'host', ''));
        $port = trim((string)self::p($params, 'port', $driver === 'pgsql' ? '5432' : '3306'));
        $dbname = trim((string)self::p($params, 'dbname', ''));
        $user = trim((string)self::p($params, 'user', ''));
        $pass = (string)self::p($params, 'pass', '');
        if ($host === '') return self::fail('请填写数据库主机');
        if ($port === '') return self::fail('请填写数据库端口');
        if ($dbname === '') return self::fail('请填写数据库名');
        if ($user === '') return self::fail('请填写数据库用户名');
        if ($pass === '') return self::fail('请填写数据库密码');
        try {
            $dsn = $driver === 'pgsql'
                ? 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname
                : 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_CONNECT_TIMEOUT => 5,
            ));
            $ver = $driver === 'pgsql'
                ? $pdo->query('SELECT version()')->fetchColumn()
                : $pdo->query('SELECT VERSION()')->fetchColumn();
            return self::ok('连接成功（' . strtoupper($driver) . ' ' . $ver . '）');
        } catch (Exception $ex) {
            return self::fail(strtoupper($driver) . ' 连接失败：' . $ex->getMessage());
        }
    }

    /** SQLite：校验路径、目录可写、文件为有效 SQLite 后可打开 */
    private static function dbSqlite($params) {
        $path = trim((string)self::p($params, 'path', ''));
        if ($path === '') $path = DATA_DIR . '/db/clinic_main.db';
        elseif (strpos($path, '/') !== 0) $path = APP_ROOT . '/' . $path;
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (!is_dir($dir) || !is_writable($dir)) {
            return self::fail('目录不可写：' . str_replace(APP_ROOT, '.', $dir));
        }
        if (is_file($path) && !ConfigStore::isSqliteFile($path)) {
            return self::fail('该文件已存在且不是有效的 SQLite 数据库');
        }
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $pdo->query('SELECT 1');
            unset($pdo);
            return self::ok('连接成功（SQLite 单文件数据库）');
        } catch (Exception $ex) {
            return self::fail('SQLite 连接失败：' . $ex->getMessage());
        }
    }

    /** 测试缓存驱动可用性（file/apcu/redis/memcached 均做真实读写探测） */
    public static function cache($driver, $params = array()) {
        $o = ConfigStore::driverOptions();
        if (!isset($o['cache'][$driver])) return self::fail('未知的缓存驱动');
        $ext = isset($o['cache'][$driver]['extension']) ? $o['cache'][$driver]['extension'] : '';
        if ($ext !== '' && !extension_loaded($ext)) {
            return self::fail('PHP 未安装 ' . strtoupper($ext) . ' 扩展，无法使用该缓存驱动');
        }
        if ($driver === 'file') return self::cacheFile();
        if ($driver === 'apcu') return self::cacheApcu();
        if ($driver === 'redis') return self::cacheRedis($params);
        if ($driver === 'memcached') return self::cacheMemcached($params);
        return self::fail('未知的缓存驱动');
    }

    private static function cacheFile() {
        $dir = DATA_DIR . '/cache';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (!is_dir($dir) || !is_writable($dir)) {
            return self::fail('缓存目录不可写：' . str_replace(APP_ROOT, '.', $dir));
        }
        $probe = $dir . '/__probe_' . substr(md5(uniqid('', true)), 0, 8) . '.tmp';
        if (@file_put_contents($probe, 'ok') === false) return self::fail('缓存目录写入失败');
        $read = @file_get_contents($probe);
        @unlink($probe);
        return $read === 'ok' ? self::ok('File 缓存可用') : self::fail('缓存目录读写校验失败');
    }

    private static function cacheApcu() {
        if (!function_exists('apcu_store')) return self::fail('APCu 扩展不可用');
        $k = '__probe_' . substr(md5(uniqid('', true)), 0, 8);
        if (!apcu_store($k, 'ok', 60)) return self::fail('APCu 写入失败');
        $v = apcu_fetch($k);
        apcu_delete($k);
        return $v === 'ok' ? self::ok('APCu 缓存可用') : self::fail('APCu 读写校验失败');
    }

    private static function cacheRedis($params) {
        $cfg = array_merge(ConfigStore::redisParams(), is_array($params) ? $params : array());
        try {
            $r = new Redis();
            $r->connect(trim((string)$cfg['host']), (int)$cfg['port'], (float)($cfg['timeout'] !== '' ? $cfg['timeout'] : 2.0));
            if ((string)$cfg['auth'] !== '' && !$r->auth($cfg['auth'])) {
                return self::fail('Redis 认证失败');
            }
            $pong = $r->ping();
            $r->close();
            return self::ok('Redis 连接成功（' . $pong . '）');
        } catch (Exception $ex) {
            return self::fail('Redis 连接失败：' . $ex->getMessage());
        }
    }

    private static function cacheMemcached($params) {
        $cfg = array_merge(ConfigStore::memcachedParams(), is_array($params) ? $params : array());
        try {
            $m = new Memcached();
            $servers = preg_split('/[\s,]+/', (string)$cfg['servers'], -1, PREG_SPLIT_NO_EMPTY);
            if (!$servers) return self::fail('请填写 Memcached 服务器地址（host:port）');
            foreach ($servers as $s) {
                $parts = explode(':', $s);
                $m->addServer(trim($parts[0]), isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 11211);
            }
            $k = '__probe_' . substr(md5(uniqid('', true)), 0, 8);
            if (!$m->set($k, 'ok', 60)) return self::fail('Memcached 连接失败或不可写');
            $v = $m->get($k);
            $m->delete($k);
            return $v === 'ok' ? self::ok('Memcached 连接成功') : self::fail('Memcached 读写校验失败');
        } catch (Exception $ex) {
            return self::fail('Memcached 连接失败：' . $ex->getMessage());
        }
    }

    private static function p($a, $k, $d = '') { return is_array($a) && isset($a[$k]) ? $a[$k] : $d; }
    private static function ok($m) { return array('ok' => true, 'msg' => $m); }
    private static function fail($m) { return array('ok' => false, 'msg' => $m); }
}
