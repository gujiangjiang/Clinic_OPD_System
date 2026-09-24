<?php
/**
 * ============================================================
 * parts/admin_sysinfo.php v1.0.0 — 系统信息：数据库中心 / 缓存管理
 * ============================================================
 * 说明：admin.php 按功能拆分的一部分（仅管理员）：
 *   1. db_status      当前数据库连接状态（驱动/延迟/表数量/库大小）+ 表清单
 *   2. db_table_data  数据表查看（字段属性 + 分页行数据）
 *   3. cache_status   缓存驱动状态与键统计
 *   4. cache_flush    模块化缓存刷新（配置/字典/叫号/全量）
 * ============================================================ */

function admin_part_sysinfo($action) {
    $u = Auth::user();
    if ($u['role'] !== 'admin') {
        json_fail('无权限访问该功能（仅管理员）');
    }

    /* ==================== 数据库连接状态 ==================== */
    if ($action === 'db_status') {
        $driver = DatabaseManager::driver();
        // 连接延迟测试
        $delay = 0;
        try {
            $t0 = microtime(true);
            DatabaseManager::getMain()->query('SELECT 1');
            $delay = (int)round((microtime(true) - $t0) * 1000);
        } catch (Exception $ex) {
            json_fail('主库连接失败：' . $ex->getMessage());
        }
        $pdo = DatabaseManager::getMain();
        // 表清单 + 行数 + 大小
        $tables = array();
        $totalRows = 0;
        try {
            if ($driver === 'sqlite') {
                $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
                foreach ($rows as $r) {
                    $t = $r['name'];
                    try { $n = (int)$pdo->query("SELECT COUNT(*) FROM " . $t)->fetchColumn(); } catch (Exception $ex) { $n = 0; }
                    $totalRows += $n;
                    $tables[] = array('name' => $t, 'rows' => $n, 'size' => self_table_size_sqlite($t));
                }
} else {
                // MySQL/PostgreSQL：information_schema 取表与行数
                if ($driver === 'pgsql') {
                    $rows = $pdo->query("SELECT table_name, 0 AS table_rows FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name")->fetchAll();
                } else {
                    $rows = $pdo->query('SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll();
                }
                foreach ($rows as $r) {
                    $t = $r['table_name'];
                    $n = (int)$r['table_rows'];
                    $totalRows += $n;
                    $tables[] = array('name' => $t, 'rows' => $n, 'size' => 0);
                }
            }
        } catch (Exception $ex) {
            // 表清单获取失败不阻塞状态展示
        }
        // 库物理大小
        $sizeBytes = 0;
        if ($driver === 'sqlite') {
            $f = DATA_DIR . '/db/clinic_main.db';
            if (is_file($f)) $sizeBytes = (int)filesize($f);
        }
        json_ok(array(
            'driver' => $driver,
            'driver_label' => strtoupper($driver),
            'delay_ms' => $delay,
            'table_count' => count($tables),
            'total_rows' => $totalRows,
            'size_bytes' => $sizeBytes,
            'size_human' => $sizeBytes > 0 ? round($sizeBytes / 1048576, 2) . ' MB' : '—',
            'tables' => $tables,
            'config_exists' => ConfigStore::exists(),
            'config_available' => ConfigStore::available(),
            'config_path' => ConfigStore::path(),
            // 驱动选项注册表（app/config/drivers.php 唯一数据源，与安装向导共用）
            'drivers' => ConfigStore::driverOptionsPublic(),
        ));
    }

    /* ==================== 数据表查看（字段 + 分页行） ==================== */
    if ($action === 'db_table_data') {
        $table = trim((string)req('table', ''));
        $page = max(1, (int)get('page', 1));
        $size = min(100, max(10, (int)get('size', 50)));
        if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            json_fail('非法表名');
        }
        $pdo = DatabaseManager::getMain();
        $driver = DatabaseManager::driver();
        // 字段属性（名称/类型/是否主键/可空/默认值）
        $cols = array();
        try {
            if ($driver === 'sqlite') {
                foreach ($pdo->query("PRAGMA table_info(" . $table . ")") as $c) {
                    $cols[] = array('name' => $c['name'], 'type' => $c['type'], 'pk' => (int)$c['pk'] === 1, 'notnull' => (int)$c['notnull'] === 1, 'default' => $c['dflt_value']);
                }
            } elseif ($driver === 'pgsql') {
                foreach ($pdo->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name='" . $table . "' ORDER BY ordinal_position") as $c) {
                    $cols[] = array(
                        'name' => $c['column_name'], 'type' => $c['data_type'],
                        'pk' => false, 'notnull' => $c['is_nullable'] === 'NO', 'default' => $c['column_default'],
                    );
                }
            } else {
                foreach ($pdo->query("SHOW FULL COLUMNS FROM " . $table) as $c) {
                    $cols[] = array('name' => $c['Field'], 'type' => $c['Type'], 'pk' => strpos((string)$c['Key'], 'PRI') !== false, 'notnull' => $c['Null'] === 'NO', 'default' => $c['Default']);
                }
            }
        } catch (Exception $ex) {
            json_fail('读取表结构失败：' . $ex->getMessage());
        }
        // 分页行数据
        $total = 0;
        $rows = array();
        try {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM " . $table)->fetchColumn();
            $offset = ($page - 1) * $size;
            $st = $pdo->query("SELECT * FROM " . $table . " LIMIT " . $size . " OFFSET " . $offset);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            json_fail('读取表数据失败：' . $ex->getMessage());
        }
        json_ok(array(
            'table' => $table,
            'cols' => $cols,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'has_more' => ($page * $size) < $total,
        ));
    }

    /* ==================== 缓存状态 ==================== */
    if ($action === 'cache_status') {
        $driver = ConfigStore::cacheDriver();
        $status = array('driver' => $driver, 'driver_label' => strtoupper($driver), 'keys' => 0, 'notes' => array());
        if ($driver === 'apcu' && function_exists('apcu_cache_info')) {
            try {
                $info = apcu_cache_info(true);
                $status['keys'] = isset($info['num_entries']) ? (int)$info['num_entries'] : 0;
                $status['memory'] = isset($info['mem_size']) ? round($info['mem_size'] / 1048576, 2) . ' MB' : '—';
            } catch (Exception $ex) {}
        } elseif ($driver === 'redis' && extension_loaded('redis')) {
            try {
                $p = ConfigStore::redisParams();
                $r = new Redis();
                $r->connect($p['host'], (int)$p['port'], 2.0);
                if ($p['auth'] !== '') $r->auth($p['auth']);
                $info = $r->info();
                $status['keys'] = isset($info['db0']['keys']) ? (int)$info['db0']['keys'] : 0;
                $r->close();
            } catch (Exception $ex) {
                $status['notes'][] = 'Redis 连接失败：' . $ex->getMessage();
            }
        } else {
            // File 驱动：统计 data/cache 目录键文件数
            $cacheDir = DATA_DIR . '/cache';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*.cache');
                $status['keys'] = $files === false ? 0 : count($files);
            }
            $status['notes'][] = 'File 驱动无命中率统计，仅显示键文件数';
        }
        json_ok($status);
    }

    /* ==================== 模块化缓存刷新 ==================== */
    if ($action === 'cache_flush') {
        $scope = req('scope', 'all');
        $cacheDir = DATA_DIR . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        $count = 0;
        $map = array(
            'config' => 'cfg_*',        // 系统配置缓存（setting 请求级缓存 + 配置文件缓存）
            'dict' => 'dict_*',         // ICD-10 与公共字典缓存
            'call' => 'call_*',         // 排班叫号临时缓存
            'all' => '*',
        );
        $pattern = isset($map[$scope]) ? $map[$scope] : '*';
        $files = glob($cacheDir . '/' . $pattern);
        if ($files === false) $files = array();
        foreach ($files as $f) {
            if (@unlink($f)) $count++;
        }
        // settings 请求级缓存标记清除（setting() 的静态缓存）
        json_ok(array('scope' => $scope, 'count' => $count), '已刷新缓存 ' . $count . ' 个文件');
    }

    /* ==================== 数据库迁移（SQLite ↔ MySQL 双向） ==================== */
    if ($action === 'db_migrate') {
        $toDriver = post('to_driver', '');
        // 白名单统一走 ConfigStore 驱动注册表（sqlite/mysql/pgsql）
        $toDriver = ConfigStore::dbDriverValid($toDriver) ? $toDriver : '';
        if ($toDriver === '') json_fail('请选择目标数据库驱动');
        $toParams = array(
            'path' => post('to_sqlite_path', ''),
            'host' => post('to_db_host', '127.0.0.1'),
            'port' => post('to_db_port', $toDriver === 'pgsql' ? '5432' : '3306'),
            'dbname' => post('to_db_name', ''),
            'user' => post('to_db_user', ''),
            'pass' => post('to_db_pass', ''),
        );
        // 后台任务启动（MigrationRunner）：迁移独立进程执行，刷新不中断；
        // 全站锁定 + 进度条 + 管理员取消 + 成功确认切换
        require_once APP_ROOT . '/app/core/MigrationRunner.php';
        $cur = DatabaseManager::driver();
        if ($cur === $toDriver) json_fail('目标驱动与当前驱动相同，无需迁移');
        $r = MigrationRunner::start($cur, $toDriver, $toParams);
        if (!$r['ok']) json_fail($r['msg']);
        json_ok(array('token' => $r['token']), $r['msg']);
    }

    /* ==================== 直接切换主库（不迁移数据，目标库须已有完整数据） ==================== */
    if ($action === 'db_switch') {
        $toDriver = post('to_driver', '');
        $toDriver = ConfigStore::dbDriverValid($toDriver) ? $toDriver : '';
        if ($toDriver === '') json_fail('请选择目标数据库驱动');
        $toParams = array(
            'path' => post('to_sqlite_path', ''),
            'host' => post('to_db_host', '127.0.0.1'),
            'port' => post('to_db_port', $toDriver === 'pgsql' ? '5432' : '3306'),
            'dbname' => post('to_db_name', ''),
            'user' => post('to_db_user', ''),
            'pass' => post('to_db_pass', ''),
        );
        $cur = DatabaseManager::driver();
        if ($cur === $toDriver) json_fail('目标驱动与当前驱动相同');
        require_once APP_ROOT . '/app/core/MigrationRunner.php';
        $r = MigrationRunner::switchDirect($toDriver, $toParams);
        if ($r['ok']) json_ok(array(), $r['msg']);
        json_fail($r['msg']);
    }

    /* ==================== 备份库配置保存 ==================== */
    if ($action === 'backup_save') {
        $driver = post('backup_driver', '');
        $driver = ConfigStore::dbDriverValid($driver) ? $driver : '';
        if ($driver === '') json_fail('请选择备份库驱动');
        $params = array(
            'path' => post('backup_sqlite_path', ''),
            'host' => post('backup_db_host', '127.0.0.1'),
            'port' => post('backup_db_port', $driver === 'pgsql' ? '5432' : '3306'),
            'dbname' => post('backup_db_name', ''),
            'user' => post('backup_db_user', ''),
            'pass' => post('backup_db_pass', ''),
        );
        ConfigStore::set('backup.driver', $driver);
        foreach (array('path', 'host', 'port', 'dbname', 'user', 'pass') as $k) {
            ConfigStore::set('backup.' . $driver . '.' . $k, isset($params[$k]) ? $params[$k] : '');
        }
        ConfigStore::set('backup.' . $driver . '.path', $params['path']);
        ConfigStore::resetCache();
        json_ok(array('driver' => $driver), '备份库配置已保存（' . strtoupper($driver) . '），可执行备份');
    }

    /* ==================== 执行备份（同步当前主库 → 备份库，不动主库指针） ==================== */
    if ($action === 'backup_run') {
        $driver = ConfigStore::get('backup.driver', '');
        $driver = ConfigStore::dbDriverValid($driver) ? $driver : '';
        if ($driver === '') json_fail('请先保存备份库配置');
        $params = array(
            'path' => ConfigStore::get('backup.' . $driver . '.path', ''),
            'host' => ConfigStore::get('backup.' . $driver . '.host', '127.0.0.1'),
            'port' => ConfigStore::get('backup.' . $driver . '.port', $driver === 'pgsql' ? '5432' : '3306'),
            'dbname' => ConfigStore::get('backup.' . $driver . '.dbname', ''),
            'user' => ConfigStore::get('backup.' . $driver . '.user', ''),
            'pass' => ConfigStore::get('backup.' . $driver . '.pass', ''),
        );
        require_once APP_ROOT . '/app/core/DatabaseMigrator.php';
        try {
            $r = DatabaseMigrator::backupTo($driver, $params);
            json_ok(array('tables' => count($r['tables']), 'rows' => $r['rows'], 'driver' => $driver),
                '备份完成：共 ' . count($r['tables']) . ' 张表、' . $r['rows'] . ' 行数据已同步到备份库（' . strtoupper($driver) . '）');
        } catch (Exception $ex) {
            json_fail('备份失败：' . $ex->getMessage());
        }
    }

    /* ==================== 缓存驱动切换（写入 config.db） ==================== */
    if ($action === 'cache_driver_save') {
        $driver = post('driver', '');
        // 白名单统一走 ConfigStore 驱动注册表（file/apcu/redis/memcached）
        $driver = ConfigStore::cacheDriverValid($driver) ? $driver : '';
        if ($driver === '') json_fail('请选择缓存驱动');
        $opt = ConfigStore::driverOptions();
        $needExt = $opt['cache'][$driver]['extension'];
        if ($needExt !== '' && !extension_loaded($needExt)) {
            json_fail('当前 PHP 未安装 ' . strtoupper($needExt) . ' 扩展，无法使用 ' . $opt['cache'][$driver]['label'] . ' 缓存');
        }
        ConfigStore::set('cache.driver', $driver);
        if ($driver === 'redis') {
            ConfigStore::set('cache.redis.host', post('redis_host', '127.0.0.1'));
            ConfigStore::set('cache.redis.port', post('redis_port', '6379'));
            ConfigStore::set('cache.redis.auth', post('redis_auth', ''));
            ConfigStore::set('cache.redis.prefix', post('redis_prefix', 'clinic_sess:'));
            ConfigStore::set('cache.redis.timeout', post('redis_timeout', '2.0'));
        } elseif ($driver === 'memcached') {
            ConfigStore::set('cache.memcached.servers', post('memcached_servers', '127.0.0.1:11211'));
            ConfigStore::set('cache.memcached.prefix', post('memcached_prefix', 'clinic_sess:'));
        }
        ConfigStore::resetCache();
        json_ok(array('driver' => $driver), '缓存驱动已切换为 ' . strtoupper($driver) . '，会话驱动将同步生效');
    }

    json_fail('未知操作');
}

/** SQLite 单表占用估算（页数 × 页大小） */
function self_table_size_sqlite($table) {
    try {
        $pdo = DatabaseManager::getMain();
        $n = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        if ($n === 0) return 0;
        $pageCount = (int)ceil($n / 100);   // 粗略估算（每页约百行量级）
        return $pageCount * 4096;
    } catch (Exception $ex) {
        return 0;
    }
}