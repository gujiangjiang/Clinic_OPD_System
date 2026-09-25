<?php
/**
 * ============================================================
 * install.php — 首次安装接口（6 步向导后端）
 * ============================================================
 * 说明：安装向导的后端支撑：
 * 1. preflight   环境巡检（PHP 版本/扩展/目录权限）
 * 2. test_db     测试数据库连接（仅 MySQL/PostgreSQL；SQLite 无需测试）
 * 3. check_db    数据库就绪校验（第 2 步下一步：必填+连接+重复安装检测）
 * 4. test_redis  测试 Redis 连通性
 * 5. save        执行安装：写入 config.db（基础设施配置）→
 *                全新安装建库建表种子 + 创建管理员；或关联现有库
 *                （仅重写配置，不破坏已有数据）；全新安装遇已有数据则清空重建
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
    json_ok(array(
        'php_version' => $phpVersion,
        'extensions' => $extensions,
        'dirs' => $dirs,
        // 驱动选项注册表（app/config/drivers.php 唯一数据源）：安装向导动态渲染下拉，
        // 与系统设置（数据库中心/缓存与性能）共用同一套选项
        'drivers' => ConfigStore::driverOptionsPublic(),
    ));
}

/* ==================== 安装向导辅助函数 ==================== */

/**
 * 解析 SQLite 数据库名称 → 统一存放于 data/db/ 的相对路径。
 * 自动补全 .db 后缀（输入 123 或 123.db 均识别为 123.db）；禁止路径穿越。
 * @return array [相对路径|null, 错误信息]
 */
function install_sqlite_name_path($name) {
    $name = trim((string)$name);
    if ($name === '') $name = 'clinic_main';
    $name = basename(str_replace('\\', '/', $name));
    if (!preg_match('/\.db$/i', $name)) $name .= '.db';
    if (!preg_match('/^[A-Za-z0-9_\-\x{4e00}-\x{9fa5}]+\.db$/u', $name)) {
        return array(null, '数据库名称仅允许字母、数字、下划线、中划线或中文');
    }
    return array('data/db/' . $name, '');
}

/**
 * 建立 MySQL / PostgreSQL 连接并强校验必填项。
 * @return array [PDO|null, 错误信息]
 */
function install_remote_pdo($driver, $host, $port, $dbname, $user, $pass, $timeout = 5) {
    if (trim((string)$host) === '') return array(null, '请填写数据库主机');
    if (trim((string)$port) === '') return array(null, '请填写数据库端口');
    if (trim((string)$dbname) === '') return array(null, '请填写数据库名');
    if (trim((string)$user) === '') return array(null, '请填写数据库用户名');
    if ((string)$pass === '') return array(null, '请填写数据库密码');
    try {
        if ($driver === 'pgsql') {
            $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname;
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
        }
        $pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_CONNECT_TIMEOUT => $timeout,
        ));
        return array($pdo, '');
    } catch (Exception $ex) {
        return array(null, strtoupper($driver) . ' 连接失败：' . $ex->getMessage());
    }
}

/** 检测目标主库是否已安装（users 表存在且非空，即安装完成标记） */
function install_db_installed($pdo) {
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Exception $ex) {
        return false;
    }
}

/** 统计目标库已有业务表数量（排除 SQLite 系统表），用于识别「非本系统库」 */
function install_db_table_count($pdo, $driver) {
    try {
        if ($driver === 'sqlite') {
            return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
        }
        if ($driver === 'pgsql') {
            return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn();
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
    } catch (Exception $ex) {
        return 0;
    }
}

/**
 * 校验 ICD-10 诊断库完整性（仅校验结构，不校验内容）：
 * 文件不存在视为待创建（返回空串）；存在则必须为有效 SQLite 且 icd10 表字段齐全。
 * @return string 错误信息（空串=通过）
 */
function install_validate_icd10($rel) {
    $file = APP_ROOT . '/' . $rel;
    if (!is_file($file)) return '';
    if (!ConfigStore::isSqliteFile($file)) return 'ICD-10 诊断库文件不是有效的 SQLite 数据库';
    $need = array('id', 'chapter_code_range', 'chapter_name', 'section_code_range', 'section_name',
        'category_code', 'category_name', 'subcategory_code', 'subcategory_name',
        'diagnosis_code', 'diagnosis_name', 'search_tags');
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $rows = $pdo->query("PRAGMA table_info('icd10')")->fetchAll(PDO::FETCH_ASSOC);
        if (!count($rows)) return 'ICD-10 诊断库不完整：缺少 icd10 表';
        $cols = array();
        foreach ($rows as $r) { $cols[] = $r['name']; }
        $missing = array_values(array_diff($need, $cols));
        if ($missing) return 'ICD-10 诊断库字段不完整：缺少 ' . implode('、', $missing);
    } catch (Exception $ex) {
        return 'ICD-10 诊断库校验失败：' . $ex->getMessage();
    }
    return '';
}

/* ==================== 数据库连接测试（仅 MySQL/PostgreSQL） ==================== */
if ($action === 'test_db') {
    $driver = req('driver', 'sqlite');
    if ($driver === 'sqlite') {
        json_fail('SQLite 为本地文件数据库，无需测试连接');
    }
    if ($driver !== 'mysql' && $driver !== 'pgsql') json_fail('未知的数据库驱动');
    list($pdo, $err) = install_remote_pdo(
        $driver,
        req('host', ''),
        req('port', $driver === 'pgsql' ? '5432' : '3306'),
        req('dbname', ''),
        req('user', ''),
        req('pass', ''),
        5
    );
    if ($err !== '') json_fail($err);
    try {
        $ver = $driver === 'pgsql' ? $pdo->query('SELECT version()')->fetchColumn() : $pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Exception $ex) {
        $ver = '-';
    }
    json_ok(array(), '连接成功（' . strtoupper($driver) . ' ' . $ver . '）');
}

/* ==================== 数据库就绪校验（第 2 步「下一步」触发） ====================
 * 职责：强校验必填项 + 验证连接/可用性；SQLite 选择后即创建空库文件；
 *       检测目标库是否已存在安装完成标记（users 表非空），供前端弹窗决策。 */
if ($action === 'check_db') {
    $driver = req('driver', 'sqlite');
    if (!ConfigStore::dbDriverValid($driver)) json_fail('未知的数据库驱动');
    // ICD-10 诊断库完整性校验（结构校验：字段是否齐全）
    list($icd10Rel, $icd10NameErr) = install_sqlite_name_path(req('icd10_name', 'icd10'));
    if ($icd10NameErr !== '') json_fail('ICD-10 诊断库名称无效：' . $icd10NameErr);
    $icd10Check = install_validate_icd10($icd10Rel);
    if ($icd10Check !== '') json_fail($icd10Check);
    if ($driver === 'sqlite') {
        list($rel, $err) = install_sqlite_name_path(req('name', ''));
        if ($err !== '') json_fail($err);
        $file = APP_ROOT . '/' . $rel;
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (!is_dir($dir) || !is_writable($dir)) json_fail('目录不可写：' . str_replace(APP_ROOT, '.', $dir));
        $created = false;
        if (is_file($file)) {
            if (!ConfigStore::isSqliteFile($file)) {
                json_fail('该文件已存在且不是有效的 SQLite 数据库，请更换数据库名称');
            }
        } else {
            // 用户已选择 SQLite：此处创建空库文件（建表在最终安装执行）
            try {
                $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                // 写入 SQLite Magic Header（空文件不算有效数据库）
                $pdo->exec('CREATE TABLE IF NOT EXISTS __install_probe(id INTEGER)');
                $pdo->exec('DROP TABLE __install_probe');
                unset($pdo);
                $created = true;
            } catch (Exception $ex) {
                json_fail('创建 SQLite 数据库失败：' . $ex->getMessage());
            }
        }
        $installed = false;
        $foreign = false;
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $installed = install_db_installed($pdo);
            $foreign = !$installed && install_db_table_count($pdo, 'sqlite') > 0;
        } catch (Exception $ex) {}
        json_ok(array('installed' => $installed, 'foreign' => $foreign, 'created' => $created, 'path' => $rel), '');
    }
    // MySQL / PostgreSQL
    list($pdo, $err) = install_remote_pdo(
        $driver,
        req('host', ''),
        req('port', $driver === 'pgsql' ? '5432' : '3306'),
        req('dbname', ''),
        req('user', ''),
        req('pass', ''),
        8
    );
    if ($err !== '') json_fail($err);
    $installed = install_db_installed($pdo);
    $foreign = !$installed && install_db_table_count($pdo, $driver) > 0;
    json_ok(array('installed' => $installed, 'foreign' => $foreign, 'created' => false), '');
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

    $mode = post('mode', 'fresh');
    $mode = in_array($mode, array('fresh', 'attach'), true) ? $mode : 'fresh';
    // 数据库/缓存驱动白名单统一走 ConfigStore 注册表（安装向导与系统设置共用）
    $dbDriver = post('db_driver', 'sqlite');
    $dbDriver = ConfigStore::dbDriverValid($dbDriver) ? $dbDriver : 'sqlite';
    $cacheDriver = post('cache_driver', 'file');
    $cacheDriver = ConfigStore::cacheDriverValid($cacheDriver) ? $cacheDriver : 'file';
    // 扩展可用性校验（缺扩展的驱动拒绝安装选择）
    $drvOpts = ConfigStore::driverOptions();
    if ($dbDriver !== 'sqlite' && !empty($drvOpts['db'][$dbDriver]['extension']) && !extension_loaded($drvOpts['db'][$dbDriver]['extension'])) {
        json_fail('当前 PHP 未安装 ' . strtoupper($drvOpts['db'][$dbDriver]['extension']) . ' 扩展，无法使用 ' . $drvOpts['db'][$dbDriver]['label']);
    }
    if ($cacheDriver !== 'file' && !empty($drvOpts['cache'][$cacheDriver]['extension']) && !extension_loaded($drvOpts['cache'][$cacheDriver]['extension'])) {
        json_fail('当前 PHP 未安装 ' . strtoupper($drvOpts['cache'][$cacheDriver]['extension']) . ' 扩展，无法使用 ' . $drvOpts['cache'][$cacheDriver]['label'] . ' 缓存');
    }
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
        // 仅接收数据库名称，统一存放于 data/db/ 并自动补全 .db 后缀
        list($sqlitePath, $err) = install_sqlite_name_path(post('sqlite_name', post('sqlite_path', '')));
        if ($err !== '') json_fail($err);
    } else {
        $dbParams = array(
            'host' => post('db_host', ''),
            'port' => post('db_port', $dbDriver === 'pgsql' ? '5432' : '3306'),
            'dbname' => post('db_name', ''),
            'user' => post('db_user', ''),
            'pass' => post('db_pass', ''),
        );
        // 安装前强校验必填项并验证连通性（MySQL / PostgreSQL）
        list($tp, $err) = install_remote_pdo($dbDriver, $dbParams['host'], $dbParams['port'], $dbParams['dbname'], $dbParams['user'], $dbParams['pass'], 8);
        if ($err !== '') json_fail($err);
    }

    // ===== ICD-10 独立字典库名称（存 config.db，不写主库） =====
    list($icd10Path, $icd10Err) = install_sqlite_name_path(post('icd10_name', 'icd10'));
    if ($icd10Err !== '') json_fail('ICD-10 字典库名称无效：' . $icd10Err);
    $icd10Check = install_validate_icd10($icd10Path);
    if ($icd10Check !== '') json_fail($icd10Check);

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
    if ($dbDriver === 'mysql' || $dbDriver === 'pgsql') {
        ConfigStore::set('db.' . $dbDriver . '.host', $dbParams['host']);
        ConfigStore::set('db.' . $dbDriver . '.port', $dbParams['port']);
        ConfigStore::set('db.' . $dbDriver . '.dbname', $dbParams['dbname']);
        ConfigStore::set('db.' . $dbDriver . '.user', $dbParams['user']);
        ConfigStore::set('db.' . $dbDriver . '.pass', $dbParams['pass']);
        ConfigStore::set('db.sqlite.path', '');
    } else {
        ConfigStore::set('db.sqlite.path', $sqlitePath);
    }
    // ICD-10 独立字典库路径（存 config.db，不写主业务库）
    ConfigStore::set('db.icd10.path', $icd10Path);
    ConfigStore::set('cache.driver', $cacheDriver);
    if ($cacheDriver === 'redis') {
        ConfigStore::set('cache.redis.host', post('redis_host', '127.0.0.1'));
        ConfigStore::set('cache.redis.port', post('redis_port', '6379'));
        ConfigStore::set('cache.redis.auth', post('redis_auth', ''));
        ConfigStore::set('cache.redis.prefix', post('redis_prefix', 'clinic_sess:'));
        ConfigStore::set('cache.redis.timeout', post('redis_timeout', '2.0'));
    } elseif ($cacheDriver === 'memcached') {
        ConfigStore::set('cache.memcached.servers', post('memcached_servers', '127.0.0.1:11211'));
        ConfigStore::set('cache.memcached.prefix', post('memcached_prefix', 'clinic_sess:'));
    }
    if (ConfigStore::get('app.key', '') === '') {
        ConfigStore::set('app.key', bin2hex(random_bytes(32)));
    }
    ConfigStore::resetCache();

    // ===== 安装模式决策：检测目标库是否已有安装完成标记 =====
    // 关联现有库（attach）：必须检测到已安装数据，否则报错；
    // 全新安装（fresh）但目标库已有数据：清空后重建（前端已弹窗确认）。
    $preInstalled = false;
    try {
        if ($dbDriver === 'sqlite') {
            $probeFile = APP_ROOT . '/' . $sqlitePath;
            if (is_file($probeFile) && ConfigStore::isSqliteFile($probeFile)) {
                $probe = new PDO('sqlite:' . $probeFile, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                $preInstalled = install_db_installed($probe);
                unset($probe);
            }
        } else {
            $preInstalled = install_db_installed($tp);
        }
    } catch (Exception $ex) {
        $preInstalled = false;
    }
    if ($mode === 'attach' && !$preInstalled) {
        json_fail('所选数据库不存在已安装的数据（未检测到安装完成标记）。若需全新安装请选择「全新安装」');
    }
    if ($mode === 'fresh' && $preInstalled) {
        // 用户已确认全新安装：清空目标库后重建
        try {
            DatabaseManager::wipeMain();
        } catch (Exception $ex) {
            json_fail('清空原数据库失败：' . $ex->getMessage());
        }
    }

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
        ConfigStore::set('install.done', '1');
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
        $adminId = UserRepository::insert('INSERT INTO users(emp_no, username, password, name, role, email, theme, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)', array(
            '0001', $username, password_hash($password, PASSWORD_DEFAULT), $realname !== '' ? $realname : '系统管理员', 'admin', $adminEmail, 'auto', 1, now_str(),
        ));
        $main->commit();
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
    ConfigStore::set('install.done', '1');

    json_ok(array('admin_id' => isset($adminId) ? $adminId : 0, 'mode' => 'fresh'), '系统安装成功，请使用管理员账号登录');
}

json_fail('未知操作');