<?php
/**
 * ============================================================
 * app/Database.php — 独立 SQLite 数据库（PDO）
 * ============================================================
 * 本工具自带数据库 data/pacs_viewer.db，首次访问自动建库建表并播种
 * 默认管理员与设置项；不与门诊主系统数据库发生任何关系。
 * ============================================================ */
class PvDatabase {

    /** @var PDO|null */
    private static $pdo = null;

    /** 初始化（幂等）：建立连接 + 建表 + 播种 */
    public static function init() {
        self::pdo();
        self::migrate();
        self::seed();
    }

    /** 获取 PDO 单例 */
    public static function pdo() {
        if (self::$pdo instanceof PDO) return self::$pdo;
        if (!is_dir(PV_DATA)) @mkdir(PV_DATA, 0775, true);
        $file = PV_DATA . '/pacs_viewer.db';
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } catch (Exception $e) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h3>无法打开 PACS 浏览器数据库</h3><p>请确认目录 <code>' . htmlspecialchars(PV_DATA) . '</code> 可写。</p>';
            echo '<p style="color:#888">' . htmlspecialchars($e->getMessage()) . '</p>';
            exit;
        }
        self::$pdo = $pdo;
        return self::$pdo;
    }

    /** 建表 */
    private static function migrate() {
        $pdo = self::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT DEFAULT '',
            role TEXT NOT NULL DEFAULT 'user',
            status INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            skey TEXT PRIMARY KEY,
            svalue TEXT DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS query_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT DEFAULT '',
            keyword TEXT DEFAULT '',
            result_count INTEGER DEFAULT 0,
            ip TEXT DEFAULT '',
            created_at TEXT DEFAULT ''
        )");
    }

    /** 播种默认账号与设置（仅当为空） */
    private static function seed() {
        $pdo = self::pdo();
        $now = date('Y-m-d H:i:s');
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) {
            $st = $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,status,created_at) VALUES(?,?,?,?,?,?)");
            $st->execute(array('admin', password_hash('admin123', PASSWORD_DEFAULT), '系统管理员', 'admin', 1, $now));
            $st->execute(array('doctor', password_hash('doctor123', PASSWORD_DEFAULT), '演示医生', 'user', 1, $now));
        }
        $setCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($setCount === 0) {
            $defaults = array(
                'site_title'       => '模拟 PACS 影像浏览器',
                'hospital_name'    => '淮海省人民医院',
                'pacs_query_mode'  => 'Demo',            // Demo 内置模拟 / Remote 远程接口
                'pacs_endpoint'    => '',                // 远程 PACS/DICOMWeb 接口地址
                'pacs_api_key'     => '',
                'pacs_ae_title'    => 'CLINIC_OPD',
                'pacs_remote_ae'   => 'PACS_SERVER',
                'pacs_server_host' => '',
                'pacs_server_port' => '104',
                'pacs_timeout'     => '5',
                'viewer_default_ww'=> '400',
                'viewer_default_wl'=> '40',
            );
            $st = $pdo->prepare("INSERT INTO settings(skey,svalue) VALUES(?,?)");
            foreach ($defaults as $k => $v) { $st->execute(array($k, (string)$v)); }
        }
    }

    /* ---------- 查询门面 ---------- */
    public static function q($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return $st->fetchAll();
    }
    public static function one($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params);
        $r = $st->fetch(); return $r === false ? null : $r;
    }
    public static function val($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params);
        $v = $st->fetchColumn(); return $v === false ? null : $v;
    }
    public static function exec($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return $st->rowCount();
    }
    public static function insert($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return (int)self::pdo()->lastInsertId();
    }
}
