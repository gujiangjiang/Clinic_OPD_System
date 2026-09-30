<?php
/**
 * ============================================================
 * PushRepository.php — 实时推送事件仓库
 * ============================================================
 * 说明：封装 SSE 实时推送事件队列（push_events：叫号/消息/危急值）
 * 的写入、读取与过期清理，统一经主库 DatabaseManager::getMain()
 * 预编译参数绑定。
 * ============================================================ */
class PushRepository extends BaseRepository {

    /** 事件入队（channel 形如 scr:{token} / msg:{uid} / room:{id} / dept:{id}） */
    public static function emit($channel, $payload) {
        return self::insert('INSERT INTO push_events(channel, payload, created_at) VALUES(?,?,?)',
            array($channel, (string)$payload, now_str()));
    }

    /** 读取通道增量事件（长轮询游标） */
    public static function poll($channel, $afterId, $limit = 20) {
        return self::q('SELECT id, payload FROM push_events WHERE channel=? AND id>? ORDER BY id ASC LIMIT ' . (int)$limit, array($channel, (int)$afterId));
    }

    /** 清理过期事件（默认保留 3 天） */
    public static function prune($expiredBefore) {
        return self::exec('DELETE FROM push_events WHERE created_at < ?', array($expiredBefore));
    }
}