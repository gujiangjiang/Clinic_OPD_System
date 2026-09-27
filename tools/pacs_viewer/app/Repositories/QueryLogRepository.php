<?php
/** app/Repositories/QueryLogRepository.php — 检索日志（管理端可查） */
class PvQueryLogRepository {

    public static function add($username, $keyword, $resultCount) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        return PvDatabase::insert(
            "INSERT INTO query_log(username,keyword,result_count,ip,created_at) VALUES(?,?,?,?,?)",
            array((string)$username, (string)$keyword, (int)$resultCount, $ip, date('Y-m-d H:i:s'))
        );
    }
    public static function recent($limit = 50) {
        $limit = max(1, min(500, (int)$limit));
        return PvDatabase::q("SELECT * FROM query_log ORDER BY id DESC LIMIT " . $limit);
    }
    public static function count() {
        return (int)PvDatabase::val("SELECT COUNT(*) FROM query_log");
    }
    public static function clear() {
        return PvDatabase::exec("DELETE FROM query_log");
    }
}
