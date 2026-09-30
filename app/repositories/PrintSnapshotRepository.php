<?php
/**
 * ============================================================
 * PrintSnapshotRepository.php — 单据打印快照仓库
 * ============================================================
 * 说明：封装打印快照（print_snapshots：开单/出具时刻当事人与上下文
 * 固化，法律合规）的读写，统一经主库 DatabaseManager::getMain()
 * 预编译参数绑定。
 * ============================================================ */
class PrintSnapshotRepository extends BaseRepository {

    /** 按业务键取快照（无则 null） */
    public static function byBiz($bizType, $bizId) {
        return self::one('SELECT * FROM print_snapshots WHERE biz_type=? AND biz_id=?', array((string)$bizType, (int)$bizId));
    }

    /** 快照是否存在 */
    public static function exists($bizType, $bizId) {
        return (int)self::val('SELECT COUNT(*) FROM print_snapshots WHERE biz_type=? AND biz_id=?', array((string)$bizType, (int)$bizId)) > 0;
    }

    /** 新增快照 */
    public static function create($data) {
        return self::insertRow('print_snapshots', $data);
    }

    /** 覆盖更新快照 */
    public static function update($bizType, $bizId, $data) {
        $set = array();
        $params = array();
        foreach ($data as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
        $params[] = (string)$bizType;
        $params[] = (int)$bizId;
        return self::exec('UPDATE print_snapshots SET ' . implode(',', $set) . ' WHERE biz_type=? AND biz_id=?', $params);
    }
}