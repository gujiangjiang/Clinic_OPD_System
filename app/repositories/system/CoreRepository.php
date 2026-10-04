<?php
/**
 * ============================================================
 * CoreRepository.php — 系统核心仓库（设置/消息/审核）
 * ============================================================
 * 说明：原业务方法（setting/messages/audits 等）全库无调用，
 * 业务代码均以内联 SQL 直接调 CoreRepository::q/one/val/exec/insert
 * （继承 BaseRepository）访问数据库。本类保留为统一数据访问门面，
 * 不承载具体业务方法，避免双份 SQL 维护。
 * ============================================================ */
class CoreRepository extends BaseRepository {
}
