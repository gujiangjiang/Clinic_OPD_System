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
        return new PDO(self::dsn($driver, $p), $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }

    /** 参数取值（缺失保持空串，不注入隐式默认值，保证与历史 DSN 拼接完全一致） */
    private static function v($p, $k) {
        return isset($p[$k]) ? (string)$p[$k] : '';
    }
}
