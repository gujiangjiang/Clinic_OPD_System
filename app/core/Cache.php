<?php
/**
 * ============================================================
 * Cache.php — 多驱动缓存（file / apcu / redis / memcached）
 * ============================================================
 * 说明：驱动由管理员在「系统设置 → 缓存」选择（cache.driver，写入
 * config.db），本类提供统一读写接口：
 *   1. file       零依赖：键存 data/cache/<key>.cache（JSON + 过期时间）
 *   2. apcu       需 PHP apcu 扩展（进程共享内存）
 *   3. redis      需 PHP redis 扩展（连接参数 ConfigStore::redisParams）
 *   4. memcached  需 PHP memcached 扩展（连接参数 ConfigStore::memcachedParams）
 * 优雅降级：目标驱动扩展缺失 / 连接失败 → 自动降级 file 并 error_log 告警，
 * 绝不抛异常影响业务。所有驱动统一键命名空间 clinic_cache:，
 * 与 Session 键（clinic_sess:）隔离互不冲突。
 * 集成点：
 *   · 系统设置整表快照（setting()）→ cfg_settings
 *   · ICD-10 字典检索 / 层级列表（Icd10Repository）→ dict_*
 * 管理端「缓存刷新」按前缀 cfg_/dict_/call_ 分模块清空。
 * ============================================================ */
class Cache {

    const NS = 'clinic_cache:';    // 缓存键命名空间（与 Session 键隔离）
    const FILE_EXT = '.cache';     // File 驱动键文件后缀

    /** 当前实际生效驱动（file/apcu/redis/memcached，解析一次缓存） */
    private static $driver = null;
    private static $driverWhy = '';   // 降级/异常原因（cache_status 展示）
    private static $conn = null;      // redis / memcached 连接实例

    /* ==================== 驱动解析 ==================== */

    /** 解析实际生效驱动：扩展缺失 / 连接失败自动降级 file */
    private static function resolve() {
        if (self::$driver !== null) return self::$driver;
        $want = ConfigStore::cacheDriver();
        self::$driver = $want;
        if ($want === 'apcu' && !function_exists('apcu_fetch')) {
            self::$driver = 'file';
            self::$driverWhy = 'APCu 扩展未安装，已降级 File';
            @error_log('[Cache] APCu 扩展未安装，已降级 File');
        } elseif ($want === 'redis') {
            if (!self::redisConnect()) self::$driver = 'file';
        } elseif ($want === 'memcached') {
            if (!self::memcachedConnect()) self::$driver = 'file';
        }
        return self::$driver;
    }

    /** Redis 连接（失败写降级原因，返回是否可用） */
    private static function redisConnect() {
        if (!class_exists('Redis')) {
            self::$driverWhy = 'Redis 扩展未安装，已降级 File';
            @error_log('[Cache] Redis 扩展未安装，已降级 File');
            return false;
        }
        try {
            $p = ConfigStore::redisParams();
            $r = new Redis();
            $r->connect($p['host'], (int)$p['port'], (float)$p['timeout']);
            if ($p['auth'] !== '') $r->auth($p['auth']);
            self::$conn = $r;
            return true;
        } catch (Exception $e) {
            self::$driverWhy = 'Redis 连接失败：' . $e->getMessage() . '，已降级 File';
            @error_log('[Cache] Redis 连接失败，已降级 File：' . $e->getMessage());
            return false;
        }
    }

    /** Memcached 连接（失败写降级原因，返回是否可用） */
    private static function memcachedConnect() {
        if (!class_exists('Memcached')) {
            self::$driverWhy = 'Memcached 扩展未安装，已降级 File';
            @error_log('[Cache] Memcached 扩展未安装，已降级 File');
            return false;
        }
        try {
            $p = ConfigStore::memcachedParams();
            $m = new Memcached();
            $m->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            foreach (preg_split('/\s*,\s*/', trim($p['servers'])) as $one) {
                if ($one === '') continue;
                $parts = explode(':', $one, 2);
                $m->addServer(trim($parts[0]), isset($parts[1]) ? (int)$parts[1] : 11211);
            }
            if (!$m->getVersion()) {
                self::$driverWhy = 'Memcached 连接失败，已降级 File';
                @error_log('[Cache] Memcached 连接失败，已降级 File');
                return false;
            }
            self::$conn = $m;
            return true;
        } catch (Exception $e) {
            self::$driverWhy = 'Memcached 连接失败：' . $e->getMessage() . '，已降级 File';
            @error_log('[Cache] Memcached 连接失败，已降级 File：' . $e->getMessage());
            return false;
        }
    }

    /* ==================== 键与 File 路径 ==================== */

    private static function fullKey($key) {
        return self::NS . $key;
    }

    /** File 驱动文件名：可读前缀 + 短哈希保证唯一（便于按前缀 glob 清空） */
    private static function filePath($key) {
        $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $key);
        return self::cacheDir() . '/' . $safe . '_' . substr(md5($key), 0, 6) . self::FILE_EXT;
    }

    private static function cacheDir() {
        return DATA_DIR . '/cache';
    }

    /* ==================== 读写接口 ==================== */

    /** 读取键（未命中 / 过期返回 $default） */
    public static function get($key, $default = null) {
        $d = self::resolve();
        try {
            if ($d === 'apcu') {
                $v = apcu_fetch(self::fullKey($key));
                return $v === false ? $default : $v;
            }
            if ($d === 'redis') {
                $v = self::$conn->get(self::fullKey($key));
                return $v === false ? $default : $v;
            }
            if ($d === 'memcached') {
                $v = self::$conn->get(self::fullKey($key));
                return $v === false ? $default : $v;
            }
            return self::fileGet($key, $default);
        } catch (Exception $e) {
            // 运行期驱动异常：本次读取降级 file，不抛错
            if (self::$driverWhy === '') {
                self::$driverWhy = '缓存运行期异常，本次读取降级 File：' . $e->getMessage();
                @error_log('[Cache] 运行期异常：' . $e->getMessage());
            }
            return self::fileGet($key, $default);
        }
    }

    /** 写入键（$ttl 秒后过期，默认 300s） */
    public static function set($key, $value, $ttl = 300) {
        $d = self::resolve();
        try {
            if ($d === 'apcu') return (bool)apcu_store(self::fullKey($key), $value, (int)$ttl);
            if ($d === 'redis') return (bool)self::$conn->setex(self::fullKey($key), (int)$ttl, $value);
            if ($d === 'memcached') return (bool)self::$conn->set(self::fullKey($key), $value, (int)$ttl);
            return self::fileSet($key, $value, $ttl);
        } catch (Exception $e) {
            if (self::$driverWhy === '') {
                self::$driverWhy = '缓存运行期异常，本次写入降级 File：' . $e->getMessage();
                @error_log('[Cache] 运行期异常：' . $e->getMessage());
            }
            return self::fileSet($key, $value, $ttl);
        }
    }

    /** 删除键 */
    public static function del($key) {
        $d = self::resolve();
        try {
            if ($d === 'apcu') return (bool)apcu_delete(self::fullKey($key));
            if ($d === 'redis') return (bool)self::$conn->del(self::fullKey($key));
            if ($d === 'memcached') return (bool)self::$conn->delete(self::fullKey($key));
            $f = self::filePath($key);
            return is_file($f) ? @unlink($f) : true;
        } catch (Exception $e) {
            return false;
        }
    }

    /** 按前缀清空（'' 全量清空；模块化：cfg_/dict_/call_），返回清除键数 */
    public static function flush($prefix = '') {
        $d = self::resolve();
        $count = 0;
        try {
            if ($d === 'apcu') {
                $info = apcu_cache_info(false);
                $list = isset($info['cache_list']) ? $info['cache_list'] : array();
                foreach ($list as $entry) {
                    if (isset($entry['info']) && strpos($entry['info'], self::NS . $prefix) === 0) {
                        if (apcu_delete($entry['info'])) $count++;
                    }
                }
                return $count;
            }
            if ($d === 'redis') {
                $it = null;
                do {
                    $batch = self::$conn->scan($it, self::NS . $prefix . '*', 1000);
                    if ($batch === false || !count($batch)) continue;
                    self::$conn->del($batch);
                    $count += count($batch);
                } while ((int)$it > 0);
                return $count;
            }
            if ($d === 'memcached') {
                $all = self::$conn->getAllKeys();
                if (is_array($all)) {
                    $hit = array();
                    foreach ($all as $k) {
                        if (strpos($k, self::NS . $prefix) === 0) $hit[] = $k;
                    }
                    if ($hit) {
                        self::$conn->deleteMulti($hit);
                        $count = count($hit);
                    }
                }
                return $count;
            }
            $dir = self::cacheDir();
            if (!is_dir($dir)) return 0;
            $files = glob($dir . '/' . $prefix . '*' . self::FILE_EXT);
            if ($files === false) return 0;
            foreach ($files as $f) {
                if (@unlink($f)) $count++;
            }
            return $count;
        } catch (Exception $e) {
            return $count;
        }
    }

    /** 缓存状态（管理端 cache_status）：驱动 / 键数 / 内存 / 降级说明 */
    public static function stats() {
        $want = ConfigStore::cacheDriver();
        $d = self::resolve();
        $out = array(
            'driver' => $d,
            'driver_label' => strtoupper($d),
            'desired' => $want,
            'keys' => 0,
            'memory' => '',
            'notes' => array(),
        );
        // 状态判定：期望驱动与实际生效驱动不一致（扩展缺失/连接失败自动降级）→ 不可用（已降级）
        if ($want !== $d) {
            $out['status'] = 'degraded';
            $out['status_text'] = '不可用（已降级 ' . strtoupper($d) . '）';
        } else {
            $out['status'] = 'ok';
            $out['status_text'] = '正常';
        }
        if (self::$driverWhy !== '') $out['notes'][] = self::$driverWhy;
        try {
            if ($d === 'apcu' && function_exists('apcu_cache_info')) {
                $info = apcu_cache_info(false);
                $list = isset($info['cache_list']) ? $info['cache_list'] : array();
                $keys = 0;
                foreach ($list as $entry) {
                    if (isset($entry['info']) && strpos($entry['info'], self::NS) === 0) $keys++;
                }
                $out['keys'] = $keys;
                $out['memory'] = isset($info['mem_size']) ? round($info['mem_size'] / 1048576, 2) . ' MB' : '—';
            } elseif ($d === 'redis') {
                $it = null;
                do {
                    $batch = self::$conn->scan($it, self::NS . '*', 1000);
                    if ($batch === false || !count($batch)) continue;
                    $out['keys'] += count($batch);
                } while ((int)$it > 0);
                $info = self::$conn->info();
                $out['memory'] = isset($info['used_memory']) ? round($info['used_memory'] / 1048576, 2) . ' MB' : '';
            } elseif ($d === 'memcached') {
                $all = self::$conn->getAllKeys();
                $keys = 0;
                if (is_array($all)) {
                    foreach ($all as $k) {
                        if (strpos($k, self::NS) === 0) $keys++;
                    }
                }
                $out['keys'] = $keys;
                $st = self::$conn->getStats();
                foreach ((array)$st as $s) {
                    if (isset($s['bytes'])) {
                        $out['memory'] = round($s['bytes'] / 1048576, 2) . ' MB';
                        break;
                    }
                }
            } else {
                $dir = self::cacheDir();
                $files = is_dir($dir) ? glob($dir . '/*' . self::FILE_EXT) : false;
                $out['keys'] = $files === false ? 0 : count($files);
                $out['notes'][] = 'File 驱动无命中率统计，仅显示键文件数';
            }
        } catch (Exception $e) {
            $out['notes'][] = '缓存统计失败：' . $e->getMessage();
        }
        return $out;
    }

    /* ==================== File 驱动内部实现 ==================== */

    private static function fileGet($key, $default) {
        $f = self::filePath($key);
        if (!is_file($f)) return $default;
        $data = @file_get_contents($f);
        if ($data === false) return $default;
        $row = @json_decode($data, true);
        if (!is_array($row) || !array_key_exists('v', $row) || !isset($row['e'])) {
            @unlink($f);
            return $default;
        }
        if ((int)$row['e'] > 0 && (int)$row['e'] < time()) {
            @unlink($f);
            return $default;
        }
        return $row['v'];
    }

    private static function fileSet($key, $value, $ttl) {
        $dir = self::cacheDir();
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $row = json_encode(array('e' => time() + (int)$ttl, 'v' => $value));
        return @file_put_contents(self::filePath($key), $row, LOCK_EX) !== false;
    }
}