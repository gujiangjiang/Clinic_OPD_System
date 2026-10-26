<?php
/**
 * ============================================================
 * ConnectionFactory.php — 数据库连接统一工厂
 * ============================================================
 * 说明：主库 / 备份库 / 迁移源与目标 / 安装探测 / 配置库等所有
 * MySQL / PostgreSQL / SQLite 连接串与 PDO 创建统一由此实现，
 * 避免各文件散落拼接导致的字符集、参数处理不一致（DRY）。
 * 仅做连接构建，不持有连接缓存（各业务类自行管理生命周期）。
 * ============================================================ */
class ConnectionFactory {

    /**
     * 构建 PDO DSN（mysql 统一 utf8mb4；空参数保持原样，与历史行为一致）
     * @param string $driver sqlite|mysql|pgsql
     * @param array  $p      sqlite: {path}；mysql/pgsql: {host, port, dbname}
     * @return string
     */
    public static function dsn($driver, $p) {
        $p = is_array($p) ? $p : array();
        if ($driver === 'pgsql') {
            return 'pgsql:host=' . self::v($p, 'host')
                 . ';port=' . self::v($p, 'port')
                 . ';dbname=' . self::v($p, 'dbname');
        }
        if ($driver === 'mysql') {
            return 'mysql:host=' . self::v($p, 'host')
                 . ';port=' . self::v($p, 'port')
                 . ';dbname=' . self::v($p, 'dbname')
                 . ';charset=utf8mb4';
        }
        return 'sqlite:' . self::v($p, 'path');
    }

    /**
     * 创建 PDO（统一 ERRMODE_EXCEPTION + FETCH_ASSOC）
     * @param string $driver
     * @param array  $p
     * @param string|null $user mysql/pgsql 用户名（sqlite 传 null）
     * @param string|null $pass mysql/pgsql 密码（sqlite 传 null）
     * @return PDO
     */
    public static function pdo($driver, $p, $user = null, $pass = null) {
        $pdo = new PDO(self::dsn($driver, $p), $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        self::pinSession($pdo, $driver);
        return $pdo;
    }

    /**
     * 会话时区锚定（MySQL / PostgreSQL）：连接建立后把会话时区设为站点时区，
     * 使任何 SQL 端时间函数（NOW() / CURRENT_TIMESTAMP / 时间戳转换等）与 PHP
     * now_str() 完全一致，消除「数据库时区 vs 站点时区」分叉（历史 bug 根因：
     * 宝塔容器 UTC + 站点 Asia/Shanghai 相差 8 小时）。
     * 说明：
     *  - SQLite 无会话时区概念——时间运算必须走 PHP 端（helpers.d/time.php）；
     *  - 直连 `new PDO(ConnectionFactory::dsn(...))` 的调用点须显式调用本方法；
     *  - 锚定失败不阻断连接（记日志）：仅影响 SQL 端函数的时区语义。
     * @param PDO    $pdo
     * @param string $driver sqlite|mysql|pgsql
     */
    public static function pinSession(PDO $pdo, $driver) {
        if ($driver !== 'mysql' && $driver !== 'pgsql') return;
        try {
            $tz = date_default_timezone_get();
            if ($driver === 'mysql') {
                // 用固定偏移（免依赖 MySQL 时区表）；按当前时刻计算，含夏令时
                $off = (new DateTime('now', new DateTimeZone($tz)))->format('P');
                if (preg_match('/^[+-]\d{2}:\d{2}$/', $off)) {
                    $pdo->exec("SET time_zone = '" . $off . "'");
                }
            } else {
                // PG 直接支持时区名；名不合法时回退固定偏移
                if (preg_match('#^[A-Za-z_/]+$#', $tz)) {
                    $pdo->exec("SET TIME ZONE '" . $tz . "'");
                } else {
                    $off = (new DateTime('now', new DateTimeZone($tz)))->format('P');
                    $pdo->exec("SET TIME ZONE INTERVAL '" . $off . "' HOUR TO MINUTE");
                }
            }
        } catch (Exception $ex) {
            error_log('[ConnectionFactory] 会话时区锚定失败（' . $driver . '）：' . $ex->getMessage());
        }
    }

    /** 参数取值（缺失保持空串，不注入隐式默认值，保证与历史 DSN 拼接完全一致） */
    private static function v($p, $k) {
        return isset($p[$k]) ? (string)$p[$k] : '';
    }
}
