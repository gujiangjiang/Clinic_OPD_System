<?php
/**
 * ============================================================
 * Session.php v2.0.0 — 会话管理器（多驱动：Files / Redis / Memcached）
 * ============================================================
 * 说明：统一初始化 PHP Session，支持三种存储驱动：
 *  1. files（默认，零依赖开箱即用）：Session 文件保存到 data/session；
 *     推荐 Linux 下用 tmpfs 内存盘挂载 Session 目录消除磁盘 I/O。
 *  2. redis：SESSION_DRIVER=redis + REDIS_HOST/PORT/AUTH/PREFIX/TIMEOUT，
 *     需 PHP redis 扩展；扩展缺失或连接不可用 → 自动平滑降级 files。
 *  3. memcached：SESSION_DRIVER=memcached + MEMCACHED_SERVERS/PREFIX，
 *     需 PHP memcached 扩展；扩展缺失或连接不可用 → 自动平滑降级 files。
 *
 * 配置优先级：环境变量（getenv）→ $_ENV。
 * Cookie 仅 HttpOnly、SameSite=Lax，降低 XSS/CSRF 风险；
 * 登录成功后由 Auth 调用 session_regenerate_id 防会话固定。
 *
 * 只读锁释放：高频轮询/心跳类只读接口在鉴权后调用 Session::closeReadOnly()
 * 立即释放 Session 独占锁，消除并发请求串行排队（详见 AGENTS.md）。
 * ============================================================ */
class Session {

    /** 当前驱动（files/redis/memcached） */
    protected static $driver = null;

    /** 读取配置（环境变量 → $_ENV） */
    protected static function env($key, $default = null) {
        $v = getenv($key);
        if ($v === false || $v === null || $v === '') {
            $v = isset($_ENV[$key]) ? $_ENV[$key] : null;
        }
        return ($v === null || $v === false) ? $default : (string)$v;
    }

    /** 目标驱动：显式 SESSION_DRIVER 优先；未配置时跟随 config.db 缓存驱动
     * （安装向导可选 file/apcu/redis 并写入 config.db；仍可被环境变量覆盖） */
    public static function driver() {
        if (self::$driver !== null) {
            return self::$driver;
        }
        $d = strtolower(trim(self::env('SESSION_DRIVER', '')));
        if ($d === '') {
            $d = ConfigStore::cacheDriver();
        }
        self::$driver = in_array($d, array('files', 'redis', 'memcached'), true) ? $d : 'files';
        return self::$driver;
    }

    /** 强制指定驱动（测试/运行时切换用） */
    public static function setDriver($driver) {
        self::$driver = in_array((string)$driver, array('files', 'redis', 'memcached'), true) ? (string)$driver : 'files';
    }

    /**
     * 配置 Redis DSN：tcp://HOST:PORT?auth=...&prefix=...&timeout=...
     * 优先读 config.db 的 redis 连接参数（安装向导可配置），环境变量可覆盖
     */
    protected static function redisDsn() {
        $p = ConfigStore::redisParams();
        $host = self::env('REDIS_HOST', $p['host']);
        $port = self::env('REDIS_PORT', $p['port']);
        $auth = self::env('REDIS_AUTH', $p['auth']);
        $prefix = self::env('REDIS_PREFIX', $p['prefix']);
        $timeout = self::env('REDIS_TIMEOUT', $p['timeout']);
        $dsn = 'tcp://' . $host . ':' . $port;
        $qs = array();
        if ($auth !== '') $qs[] = 'auth=' . rawurlencode($auth);
        $qs[] = 'prefix=' . rawurlencode($prefix);
        $qs[] = 'timeout=' . $timeout;
        return $dsn . '?' . implode('&', $qs);
    }

    /** Redis 可用性探测：扩展加载 + 连接可达 */
    protected static function redisAvailable() {
        if (!extension_loaded('redis')) return false;
        try {
            $r = new Redis();
            $ok = $r->connect(
                self::env('REDIS_HOST', '127.0.0.1'),
                (int)self::env('REDIS_PORT', '6379'),
                (float)self::env('REDIS_TIMEOUT', '2.0')
            );
            if ($ok && self::env('REDIS_AUTH', '') !== '') {
                try { $r->auth(self::env('REDIS_AUTH')); } catch (Exception $ex) { $ok = false; }
            }
            if ($r->ping()) {
                $r->close();
                return true;
            }
            $r->close();
        } catch (Exception $ex) {
            return false;
        }
        return false;
    }

    /** Memcached 可用性探测：扩展加载 + 连接可达 */
    protected static function memcachedAvailable() {
        if (!extension_loaded('memcached')) return false;
        try {
            $m = new Memcached();
            $servers = array();
            foreach (explode(',', self::env('MEMCACHED_SERVERS', '127.0.0.1:11211')) as $s) {
                $s = trim($s);
                if ($s === '') continue;
                $parts = explode(':', $s);
                $servers[] = array($parts[0], isset($parts[1]) ? (int)$parts[1] : 11211);
            }
            if (!$servers) return false;
            $m->addServers($servers);
            if (@$m->getVersion()) {
                $m->quit();
                return true;
            }
            $m->quit();
        } catch (Exception $ex) {
            return false;
        }
        return false;
    }

    /** 统一 Cookie/会话参数（驱动无关） */
    protected static function sessionParams() {
        session_name('HIS_SID');
        // secure 判定兼容反向代理（Nginx 终结 TLS 时经 X-Forwarded-Proto 传递）
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /**
     * 启动会话（幂等）。
     * 多驱动分发 + 自动优雅降级：
     *  · 配置 redis/memcached 但扩展缺失或连接失败 → error_log 告警 + 降级 files，
     *    绝不白屏/崩溃。
     */
    public static function start() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        self::sessionParams();
        $driver = self::driver();
        $started = false;
        if ($driver === 'redis') {
            if (self::redisAvailable()) {
                try {
                    ini_set('session.save_handler', 'redis');
                    ini_set('session.save_path', self::redisDsn());
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $started = true;
                } catch (Exception $ex) {
                    error_log('[Session] redis 驱动启动失败，降级 files：' . $ex->getMessage());
                }
            } else {
                error_log('[Session] 配置了 redis 但扩展/连接不可用，自动降级 files 存储');
            }
        } elseif ($driver === 'memcached') {
            if (self::memcachedAvailable()) {
                try {
                    ini_set('session.save_handler', 'memcached');
                    ini_set('session.save_path', self::env('MEMCACHED_SERVERS', '127.0.0.1:11211'));
                    // Memcached 会话关键选项：二进制协议、前缀、会话锁（保证一致性）
                    @ini_set('memcached.sess_prefix', self::env('MEMCACHED_PREFIX', 'clinic_sess:'));
                    @ini_set('memcached.sess_locking', '1');
                    @ini_set('memcached.sess_lock_wait', '2');
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $started = true;
                } catch (Exception $ex) {
                    error_log('[Session] memcached 驱动启动失败，降级 files：' . $ex->getMessage());
                }
            } else {
                error_log('[Session] 配置了 memcached 但扩展/连接不可用，自动降级 files 存储');
            }
        }
        if (!$started) {
            // 默认 files 驱动（或降级兜底）：Session 文件保存到 data/session
            $path = DATA_DIR . '/session';
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', $path);
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }
    }

    /**
     * 只读接口立即释放 Session 独占锁：
     * 轮询/大屏/心跳等只读接口在完成鉴权与身份读取后调用，根除并发请求
     * 因文件锁（files 驱动）或锁等待（memcached.sess_locking）导致的串行排队。
     */
    public static function closeReadOnly() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}