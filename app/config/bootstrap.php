<?php
/**
 * ============================================================
 * bootstrap.php v1.0.0 — 系统启动引导
 * ============================================================
 * 说明：所有请求（页面 / AJAX）统一入口加载本文件：
 * 1. 定义全局路径常量
 * 2. 引入辅助函数与核心类（Session / DatabaseManager / CSRF / Auth / Upload / Router）
 * 3. 引入公共字典 options_data.php
 * 4. 读取站点时区设置（管理员可配置，未配置时使用服务器默认时区）
 * ============================================================ */

/* ---------- 全局路径常量 ---------- */
define('APP_ROOT', dirname(dirname(__DIR__)));        // 项目根目录（本文件位于 app/config/，上两级）
define('DATA_DIR', APP_ROOT . '/data');               // 数据目录（分散式数据库、Session 文件）
define('UPLOAD_DIR', APP_ROOT . '/public/uploads');   // 上传目录（public 内，可被 Web 访问）
define('API_PATH', APP_ROOT . '/app/api');            // AJAX 接口目录
define('VIEW_PATH', APP_ROOT . '/app/views');         // 页面视图目录
define('APP_VERSION', '3.0.4');

/* ============================================================
 * 数据库驱动配置
 * ------------------------------------------------------------
 * 当前使用 SQLite（分散式数据库：每个模块一个 .db 文件，
 * 由 DatabaseManager 统一创建与自动迁移）。
 *
 * 【预留 MySQL 接口】如需切换 MySQL：
 *   1. 将 DB_DRIVER 改为 'mysql'，并填写下方 MYSQL_* 常量；
 *   2. DatabaseManager 会根据驱动拼装不同的 DSN，
 *      各模块表结构在 app/config/schema/*.php 中集中定义；
 *   3. 切换时仅需把建表语句中的 AUTOINCREMENT 改为 AUTO_INCREMENT
 *      （见 schema 文件顶部说明），业务查询代码无需改动。
 * ============================================================ */
define('DB_DRIVER', 'sqlite');          // 可选：sqlite / mysql
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', '3306');
define('MYSQL_DB_PREFIX', 'his_');      // MySQL 下每个分散库对应一个数据库：his_core / his_user ...
define('MYSQL_USER', 'root');
define('MYSQL_PASS', '');

/* 调试模式：正式部署请改为 false（关闭页面错误输出，仅记录日志） */
define('DEBUG', true);

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/* ============================================================
 * AJAX 接口错误输出控制（防止污染 JSON 响应）
 * ------------------------------------------------------------
 * 说明：PHP 8.x 下，warning/deprecated/notice 等提示默认会以
 * HTML 形式直接输出到响应体，若出现在 JSON 之前（如
 * "<br /><b>Deprecated</b>: explode(): Passing null..."），
 * 前端 res.json() 会解析失败，导致列表一直转圈并弹出
 * 「网络请求失败」。因此所有 AJAX/API 请求一律关闭错误显示
 * （错误仍会写入日志，便于排查），保证接口始终返回纯净 JSON。
 * 判断依据：请求头 X-Requested-With 或请求路径以 /api/ 开头。
 * ============================================================ */
$__isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
    || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') === 0);
if ($__isAjax) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/* 兜底时区：仅当管理员未设置站点时区时生效 */
date_default_timezone_set('Asia/Shanghai');

/* ---------- 引入辅助函数（helpers + 条形码生成，全站可用） ---------- */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/barcode.php';

/* ---------- 启动会话（Session 文件保存到 data/session，避开 Web 访问） ---------- */
require_once __DIR__ . '/../core/Session.php';
Session::start();

/* ---------- 引入核心类 ---------- */
require_once __DIR__ . '/../core/DatabaseManager.php';
require_once __DIR__ . '/../core/IdObfuscator.php';
require_once __DIR__ . '/../core/DataExportImport.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Upload.php';
require_once __DIR__ . '/../core/Router.php';

/* ---------- 站点时区（管理员设置，默认取创建管理员时的浏览器时区） ---------- */
$__tz = DB::val('core', "SELECT svalue FROM settings WHERE skey='timezone'");
if ($__tz) {
    date_default_timezone_set($__tz);
}

/* ---------- 公共字典（性别/民族/职业/职称/频次/途径等，所有页面共用） ---------- */
require_once __DIR__ . '/options_data.php';
