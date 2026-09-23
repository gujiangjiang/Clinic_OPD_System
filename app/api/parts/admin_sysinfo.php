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
                $rows = $pdo->query('SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll();
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
        $toDriver = in_array($toDriver, array('sqlite', 'mysql'), true) ? $toDriver : '';
        if ($toDriver === '') json_fail('请选择目标数据库驱动');
        $toParams = array(
            'path' => post('to_sqlite_path', ''),
            'host' => post('to_db_host', '127.0.0.1'),
            'port' => post('to_db_port', '3306'),
            'dbname' => post('to_db_name', ''),
            'user' => post('to_db_user', ''),
            'pass' => post('to_db_pass', ''),
        );
        require_once APP_ROOT . '/app/core/DatabaseMigrator.php';
        try {
            $r = DatabaseMigrator::migrate($toDriver, $toParams);
            json_ok(array('tables' => $r['tables'], 'rows' => $r['rows'], 'target' => $r['target']),
                '迁移完成：共 ' . count($r['tables']) . ' 张表、' . $r['rows'] . ' 行数据已同步到 ' . strtoupper($r['target']) . '，主库指针已更新');
        } catch (Exception $ex) {
            json_fail('迁移失败：' . $ex->getMessage());
        }
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