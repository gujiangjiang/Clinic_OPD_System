<?php
/**
 * ============================================================
 * drivers.php — 驱动选项统一注册表（数据库 / 缓存）
 * ============================================================
 * 说明：安装向导、系统设置（数据库中心/缓存与性能）共用本文件作为
 * 唯一数据源：
 *   1. 后端校验（DatabaseMigrator / install save / cache_driver_save）
 *      引用 driver_options()，驱动白名单只维护一处；
 *   2. 前端下拉框经 /api/install?action=preflight 返回本表动态渲染，
 *      安装页与系统设置页共用同一套选项，杜绝两处列表漂移。
 * ============================================================ */

return array(
    /* ==================== 数据库驱动 ==================== */
    'db' => array(
        'sqlite' => array(
            'label' => 'SQLite（零配置，单文件）',
            'extension' => 'pdo_sqlite',
            'params' => array(
                'path' => array('key' => 'db.sqlite.path', 'label' => '数据库文件路径', 'default' => '', 'placeholder' => '留空使用默认：data/db/clinic_main.db'),
            ),
        ),
        'mysql' => array(
            'label' => 'MySQL / MariaDB',
            'extension' => 'pdo_mysql',
            'params' => array(
                'host' => array('key' => 'db.mysql.host', 'label' => '主机', 'default' => '127.0.0.1'),
                'port' => array('key' => 'db.mysql.port', 'label' => '端口', 'default' => '3306'),
                'dbname' => array('key' => 'db.mysql.dbname', 'label' => '数据库名', 'default' => 'his_main'),
                'user' => array('key' => 'db.mysql.user', 'label' => '用户名', 'default' => 'root'),
                'pass' => array('key' => 'db.mysql.pass', 'label' => '密码', 'default' => ''),
            ),
        ),
        'pgsql' => array(
            'label' => 'PostgreSQL',
            'extension' => 'pdo_pgsql',
            'params' => array(
                'host' => array('key' => 'db.pgsql.host', 'label' => '主机', 'default' => '127.0.0.1'),
                'port' => array('key' => 'db.pgsql.port', 'label' => '端口', 'default' => '5432'),
                'dbname' => array('key' => 'db.pgsql.dbname', 'label' => '数据库名', 'default' => 'his_main'),
                'user' => array('key' => 'db.pgsql.user', 'label' => '用户名', 'default' => 'postgres'),
                'pass' => array('key' => 'db.pgsql.pass', 'label' => '密码', 'default' => ''),
            ),
        ),
    ),

    /* ==================== 缓存 / 会话驱动 ==================== */
    'cache' => array(
        'file' => array(
            'label' => 'File（本地文件，零依赖）',
            'extension' => '',
        ),
        'apcu' => array(
            'label' => 'APCu（内存）',
            'extension' => 'apcu',
        ),
        'redis' => array(
            'label' => 'Redis',
            'extension' => 'redis',
            'params' => array(
                'host' => array('key' => 'cache.redis.host', 'label' => '主机', 'default' => '127.0.0.1'),
                'port' => array('key' => 'cache.redis.port', 'label' => '端口', 'default' => '6379'),
                'auth' => array('key' => 'cache.redis.auth', 'label' => '密码（可选）', 'default' => ''),
                'prefix' => array('key' => 'cache.redis.prefix', 'label' => '键前缀', 'default' => 'clinic_sess:'),
                'timeout' => array('key' => 'cache.redis.timeout', 'label' => '超时（秒）', 'default' => '2.0'),
            ),
        ),
        'memcached' => array(
            'label' => 'Memcached',
            'extension' => 'memcached',
            'params' => array(
                'servers' => array('key' => 'cache.memcached.servers', 'label' => '服务器（逗号分隔 host:port）', 'default' => '127.0.0.1:11211'),
                'prefix' => array('key' => 'cache.memcached.prefix', 'label' => '键前缀', 'default' => 'clinic_sess:'),
            ),
        ),
    ),
);