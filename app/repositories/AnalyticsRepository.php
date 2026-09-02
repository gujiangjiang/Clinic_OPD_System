<?php
/**
 * ============================================================
 * AnalyticsRepository.php — 运营统计分析仓库
 * ============================================================
 * 说明：原业务方法（regToday/trend/orderByDoctor 等）全库无调用，
 * 运营分析接口（admin_analytics/*）均以内联 SQL 直接调
 * AnalyticsRepository::q/val（继承 BaseRepository）。本类保留为
 * 统一数据访问门面，不承载具体业务方法，避免双份 SQL 维护。
 * ============================================================ */
class AnalyticsRepository extends BaseRepository {
}
