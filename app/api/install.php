<?php
/**
 * ============================================================
 * install.php — 首次安装接口（5 步向导后端）
 * ============================================================
 * 说明：安装向导的后端支撑：
 * 1. preflight   环境巡检（PHP 版本/扩展/目录权限/已有数据检测）
 * 2. test_db     测试数据库连接（SQLite 文件有效性 / MySQL 连通性）
 * 3. test_redis  测试 Redis 连通性
 * 4. save        执行安装：写入 config.db（基础设施配置）→
 *                全新安装建库建表种子 + 创建管理员；或关联现有库
 *                （仅重写配置，不破坏已有数据）
 * ============================================================ */

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

/* ==================== 环境巡检 ==================== */
if ($action === 'preflight') {
    // PHP 版本
    $phpVersion = PHP_VERSION;
    // 扩展检查
    $needExts = array('pdo_sqlite' => 'PDO SQLite（默认主库）', 'mbstring' => '多字节字符串', 'openssl' => '加密/安全', 'curl' => 'HTTP 客户端');
    $extensions = array();
    foreach ($needExts as $ext => $label) {
        $extensions[] = array('name' => $label . '（' . $ext . '）', 'ok' => extension_loaded($ext));
    }
    if (extension_loaded('pdo_mysql')) {
        $extensions[] = array('name' => 'PDO MySQL（可选驱动）', 'ok' => true);
    } else {
        $extensions[] = array('name' => 'PDO MySQL（可选驱动）', 'ok' => false);
    }
    if (extension_loaded('redis')) {
        $extensions[] = array('name' => 'Redis 扩展（可选缓存）', 'ok' => true);
    }
    if (extension_loaded('apcu')) {
        $extensions[] = array('name' => 'APCu 扩展（可选缓存）', 'ok' => true);
    }
    // 目录权限
    $dirs = array();
    $checkDirs = array(DATA_DIR, DATA_DIR . '/db', APP_ROOT . '/public/uploads');
    foreach ($checkDirs as $d) {
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        $dirs[] = array('path' => str_replace(APP_ROOT, '.', $d), 'ok' => is_dir($d) && is_writable($d));
    }
    // 已有数据检测（默认驱动下主库是否存在且已有管理员）
    $existingMain = '';
    $existingInstalled = false;
    try {
        $mainFile = DATA_DIR . '/db/clinic_main.db';
        if (is_file($mainFile) && ConfigStore::isSqliteFile($mainFile)) {
            $existingMain = $mainFile;
        }
    } catch (Exception $ex) {}
    try {
        $existingInstalled = ConfigStore::isSystemInstalled();
    } catch (Exception $ex) {}
    json_ok(array(
        'php_version' => $phpVersion,
        'extensions' => $extensions,
        'dirs' => $dirs,
        'existing_main' => $existingMain,
        'existing_installed' => $existingInstalled,
    ));
}

/* ==================== 数据库连接测试 ==================== */
if ($action === 'test_db') {
    $driver = req('driver', 'sqlite');
    if ($driver === 'mysql') {
        $host = req('host', '127.0.0.1');
        $port = req('port', '3306');
        $dbname = req('dbname', '');
        $user = req('user', '');
        $pass = req('pass', '');
        if ($dbname === '') json_fail('请填写数据库名');
        try {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_CONNECT_TIMEOUT => 5,
            ));
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            json_ok(array(), '连接成功（MySQL ' . $ver . '）');
        } catch (Exception $ex) {
            json_fail('连接失败：' . $ex->getMessage());
        }
    }
    // SQLite：校验文件头 + 打开
    $path = req('path', '');
    if ($path === '') {
        $path = DATA_DIR . '/db/clinic_main.db';
    }
    // 规范化路径（相对项目根）
    if ($path[0] !== '/' && $path[0] !== '.') {
        $path = APP_ROOT . '/' . ltrim($path, '/');
    }
    if (!is_file($path)) {
        // 文件不存在：检查父目录可写（安装时自动创建）
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (!is_dir($dir) || !is_writable($dir)) json_fail('目录不可写：' . $dir);
        json_ok(array(), '可用（目录可写，安装时将自动创建 SQLite 数据库）');
    }
    if (!ConfigStore::isSqliteFile($path)) {
        json_fail('该文件不是有效的 SQLite 数据库（Magic Header 校验失败）');
    }
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->query('SELECT 1');
        json_ok(array(), '连接成功（有效 SQLite 数据库）');
    } catch (Exception $ex) {
        json_fail('连接失败：' . $ex->getMessage());
    }
}

/* ==================== Redis 连接测试 ==================== */
if ($action === 'test_redis') {
    if (!extension_loaded('redis')) {
        json_fail('PHP 未安装 redis 扩展，请使用 File/APCu 缓存或在服务器安装扩展');
    }
    $host = req('host', '127.0.0.1');
    $port = (int)req('port', 6379);
    $auth = req('auth', '');
    try {
        $r = new Redis();
        $r->connect($host, $port, 3.0);
        if ($auth !== '') {
            if (!$r->auth($auth)) json_fail('Redis 认证失败');
        }
        $pong = $r->ping();
        $r->close();
        json_ok(array(), 'Redis 连接成功（' . $pong . '）');
    } catch (Exception $ex) {
        json_fail('Redis 连接失败：' . $ex->getMessage());
    }
}

/* ==================== 执行安装 ==================== */
if ($action === 'save') {
    CSRF::check();
    // 安装门：已安装（config.db 有效且主库有管理员）则拒绝
    if (ConfigStore::exists() && ConfigStore::available() && ConfigStore::isSystemInstalled()) {
        json_fail('系统已安装，无需重复初始化');
    }

    $mode = post('mode', 'fresh');
    $mode = in_array($mode, array('fresh', 'attach'), true) ? $mode : 'fresh';
    $dbDriver = post('db_driver', 'sqlite');
    $dbDriver = in_array($dbDriver, array('sqlite', 'mysql'), true) ? $dbDriver : 'sqlite';
    $cacheDriver = post('cache_driver', 'file');
    $cacheDriver = in_array($cacheDriver, array('file', 'apcu', 'redis'), true) ? $cacheDriver : 'file';
    $timezone = post('timezone', 'Asia/Shanghai');
    $tzList = DateTimeZone::listIdentifiers();
    if (!in_array($timezone, $tzList, true)) $timezone = 'Asia/Shanghai';

    // ===== 基础字段校验 =====
    $hospital = post('hospital_name');
    $orgCode = post('org_code');
    if ($hospital === '') json_fail('请填写医院名称');
    if (trim((string)$orgCode) === '') json_fail('请填写机构代码');

    $username = trim((string)post('username', 'admin'));
    $realname = post('realname', '系统管理员');
    $password = post_raw('password');
    $password2 = post_raw('password2');
    $adminEmail = post('admin_email');

    if ($mode === 'fresh') {
        if ($username === '' || !preg_match('/^[A-Za-z]/', $username)) json_fail('管理员用户名必须以英文字母开头（默认 admin，可修改）');
        if (strlen($username) > 50) json_fail('管理员用户名过长');
        if ($password === '' || strlen($password) < 6) json_fail('管理员密码不能少于6位（当前输入 ' . strlen($password) . ' 位）');
        if ($password !== $password2) json_fail('两次输入的密码不一致，请重新输入');
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) json_fail('安全邮箱格式不正确');
    }

    // ===== 数据库参数校验 =====
    $sqlitePath = '';
    $dbParams = array('host' => '127.0.0.1', 'port' => '3306', 'dbname' => '', 'user' => '', 'pass' => '');
    if ($dbDriver === 'sqlite') {
        $sqlitePath = trim((string)post('sqlite_path', ''));
        if ($sqlitePath !== '' && !is_file($sqlitePath)) {
            // 相对路径转绝对
            if ($sqlitePath[0] !== '/' && $sqlitePath[0] !== '.') $sqlitePath = APP_ROOT . '/' . ltrim($sqlitePath, '/');
        }
    } else {
        $dbParams = array(
            'host' => post('db_host', '127.0.0.1'),
            'port' => post('db_port', '3306'),
            'dbname' => post('db_name', ''),
            'user' => post('db_user', ''),
            'pass' => post('db_pass', ''),
        );
        if ($dbParams['dbname'] === '') json_fail('请填写数据库名');
        // 安装前验证 MySQL 连通性
        try {
            $dsn = 'mysql:host=' . $dbParams['host'] . ';port=' . $dbParams['port'] . ';dbname=' . $dbParams['dbname'] . ';charset=utf8mb4';
            $tp = new PDO($dsn, $dbParams['user'], $dbParams['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_CONNECT_TIMEOUT => 8));
        } catch (Exception $ex) {
            json_fail('MySQL 连接失败：' . $ex->getMessage());
        }
    }

    // ===== 写入 config.db（基础设施配置库） =====
    $cfgPath = ConfigStore::path();
    $cfgDir = dirname($cfgPath);
    if (!is_dir($cfgDir)) { @mkdir($cfgDir, 0777, true); }
    $isNewCfg = !is_file($cfgPath);
    $needInitCfg = true;
    if ($isNewCfg) {
        // 新建 config.db 并建表
        $pdo = new PDO('sqlite:' . $cfgPath, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->exec('CREATE TABLE IF NOT EXISTS config(ckey TEXT PRIMARY KEY, cvalue TEXT)');
        unset($pdo);
    }
    ConfigStore::resetCache();
    ConfigStore::set('db.driver', $dbDriver);
    if ($dbDriver === 'mysql') {
        ConfigStore::set('db.mysql.host', $dbParams['host']);
        ConfigStore::set('db.mysql.port', $dbParams['port']);
        ConfigStore::set('db.mysql.dbname', $dbParams['dbname']);
        ConfigStore::set('db.mysql.user', $dbParams['user']);
        ConfigStore::set('db.mysql.pass', $dbParams['pass']);
        ConfigStore::set('db.sqlite.path', '');
    } else {
        ConfigStore::set('db.sqlite.path', $sqlitePath);
    }
    ConfigStore::set('cache.driver', $cacheDriver);
    ConfigStore::set('cache.redis.host', post('redis_host', '127.0.0.1'));
    ConfigStore::set('cache.redis.port', post('redis_port', '6379'));
    ConfigStore::set('cache.redis.auth', post('redis_auth', ''));
    if (ConfigStore::get('app.key', '') === '') {
        ConfigStore::set('app.key', bin2hex(random_bytes(32)));
    }
    ConfigStore::resetCache();

    // ===== 连接并初始化主库 =====
    try {
        $main = DatabaseManager::getMain();   // 建表/迁移/种子（幂等）
    } catch (Exception $ex) {
        json_fail('主数据库初始化失败：' . $ex->getMessage());
    }

    if ($mode === 'attach') {
        // 关联现有库：校验主库结构完整（users 表 + settings 表存在）且已有管理员
        try {
            $hasUsers = (int)$main->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (Exception $ex) {
            $hasUsers = false;
        }
        if (!$hasUsers) {
            json_fail('所选数据库不存在已安装的数据（users 表为空）。若需全新安装请选择「全新安装」');
        }
        // 写入系统设置（机构信息），不动业务数据
        set_setting('hospital_name', $hospital);
        set_setting('org_code', trim((string)$orgCode));
        set_setting('hospital_name2', post('hospital_name2'));
        set_setting('contact_phone', post('contact_phone'));
        set_setting('contact_addr', post('contact_addr'));
        set_setting('timezone', $timezone);
        set_setting('install_time', now_str());
        $logo = '';
        if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
            if (!isset($res['error'])) $logo = $res['path'];
        }
        if ($logo !== '') set_setting('logo', $logo);
        date_default_timezone_set($timezone);
        json_ok(array('mode' => 'attach'), '已关联现有数据库，系统安装完成');
    }

    // ===== 全新安装：创建管理员 + 系统设置 =====
    $logo = '';
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $res = Upload::save('logo', 'logo', array('jpg', 'jpeg', 'png', 'gif', 'webp'), 2097152);
        if (!isset($res['error'])) $logo = $res['path'];
    }
    try {
        $main->beginTransaction();
        if ((int)$main->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            $main->rollBack();
            json_fail('主数据库已存在管理员，请选择「关联现有数据库」');
        }
        $adminId = UserRepository::insert('INSERT INTO users(emp_no, username, password, name, role, theme, status, created_at) VALUES(?,?,?,?,?,?,?,?)', array(
            '0001', $username, password_hash($password, PASSWORD_DEFAULT), $realname !== '' ? $realname : '系统管理员', 'admin', 'auto', 1, now_str(),
        ));
        $main->commit();
        // 安全邮箱暂存 settings（users 表无 email 列，避免 schema 变更）
        if ($adminEmail !== '') set_setting('admin_email', $adminEmail);
    } catch (Exception $ex) {
        if ($main->inTransaction()) $main->rollBack();
        json_fail('创建管理员失败：' . $ex->getMessage());
    }
    set_setting('hospital_name', $hospital);
    set_setting('org_code', trim((string)$orgCode));
    set_setting('hospital_name2', post('hospital_name2'));
    set_setting('contact_phone', post('contact_phone'));
    set_setting('contact_addr', post('contact_addr'));
    set_setting('timezone', $timezone);
    set_setting('logo', $logo);
    set_setting('install_time', now_str());
    date_default_timezone_set($timezone);

    json_ok(array('admin_id' => isset($adminId) ? $adminId : 0, 'mode' => 'fresh'), '系统安装成功，请使用管理员账号登录');
}

json_fail('未知操作');