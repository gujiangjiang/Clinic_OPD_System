<?php
/**
 * ============================================================
 * main/01_system.php — 系统设置 / 配置审计 / 系统日志
 * ============================================================ */
return array(
    'tables' => array(

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            skey TEXT PRIMARY KEY,
            svalue TEXT
        )",

        // 接口/配置变更审计（记录管理员调整的键与区域，便于溯源；不记录密钥明文）
        'config_audit' => "CREATE TABLE IF NOT EXISTS config_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT,
            area TEXT,
            keys_changed TEXT,
            detail TEXT,
            ip TEXT,
            created_at TEXT
        )",

        // 统一系统日志表（日志中心）：操作日志 + 接口日志统一落库；
        // 服务器日志直读 PHP 日志文件，不入本表。详见 migrations v46 注释。
        'system_logs' => "CREATE TABLE IF NOT EXISTS system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL DEFAULT 'operation',
            category TEXT NOT NULL DEFAULT '',
            direction TEXT NOT NULL DEFAULT '',
            action TEXT NOT NULL DEFAULT '',
            level TEXT NOT NULL DEFAULT 'info',
            summary TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '',
            payload TEXT NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0,
            username TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT '',
            target TEXT NOT NULL DEFAULT '',
            remote_ip TEXT NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL DEFAULT '',
            created_at TEXT
        )",
        // system_logs 索引策略（追加写表，严格精简二级索引以降低写入放大）：
        //  - idx_system_logs_created (created_at)：全局时间段收敛 + 超期归档定位（核心）
        //  - idx_system_logs_user_created (user_id, created_at)：按操作人审计（行为溯源）
        //  - idx_system_logs_channel (channel, category, id)：日志中心左侧分类列表/计数路径
        //    （保留：后台唯一高频读取路径；三库均可用）
        // 严禁在 detail/payload 等长文本列建常规索引（前置模糊查询无法命中 B-Tree）。
        'system_logs_idx_channel' => "CREATE INDEX IF NOT EXISTS idx_system_logs_channel ON system_logs(channel, category, id)",
        'system_logs_idx_created' => "CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at)",
        'system_logs_idx_user_created' => "CREATE INDEX IF NOT EXISTS idx_system_logs_user_created ON system_logs(user_id, created_at)",
    ),
    'migrations' => array(
        // v46：统一系统日志表（日志中心）——
        // 操作日志（登录/退出/改密/改资料等）与接口日志（FHIR/DICOM/HL7/LIS/HIS/
        // 医保支付/存证签名，含入向/出向）统一落库，由 LogService 读写；
        // 服务器日志直接读取 PHP 日志文件，不入本表。
        //  - channel   operation 操作 // interface 接口
        //  - category  operation: login/account；interface: fhir/dicom/hl7/lis/his/insurance/evid
        //  - direction interface: inbound 入向 / outbound 出向
        //  - level     normal 正常 / info 提示 / warning 警告 / error 错误
        46 => array(
            "CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL DEFAULT 'operation',
                category TEXT NOT NULL DEFAULT '',
                direction TEXT NOT NULL DEFAULT '',
                action TEXT NOT NULL DEFAULT '',
                level TEXT NOT NULL DEFAULT 'info',
                summary TEXT NOT NULL DEFAULT '',
                detail TEXT NOT NULL DEFAULT '',
                payload TEXT NOT NULL DEFAULT '',
                user_id INTEGER NOT NULL DEFAULT 0,
                username TEXT NOT NULL DEFAULT '',
                role TEXT NOT NULL DEFAULT '',
                target TEXT NOT NULL DEFAULT '',
                remote_ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_system_logs_channel ON system_logs(channel, category, id)",
            "CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at)",
        ),
        // v47：日志表按操作人审计的通用复合索引（user_id, created_at）。
        // 追加写表仅补此一条高价值索引，不在 detail/payload 长文本列建索引。
        47 => array(
            "CREATE INDEX IF NOT EXISTS idx_system_logs_user_created ON system_logs(user_id, created_at)",
        ),
        // v49：日志容量与保留默认值调整（行数上限 500 / 保留 7 天，检索默认 3 天）。
        // 仅当现存值仍为旧默认（5000/30）时归一为新默认，避免覆盖管理员自定义值；
        // 键缺失时补默认，保证升级库与新装库配置一致。
        49 => array(
            "UPDATE settings SET svalue='500' WHERE skey='log.max_rows' AND svalue='5000'",
            "UPDATE settings SET svalue='7' WHERE skey='log.retention_days' AND svalue='30'",
            "INSERT OR IGNORE INTO settings(skey, svalue) VALUES('log.max_rows','500')",
            "INSERT OR IGNORE INTO settings(skey, svalue) VALUES('log.retention_days','7')",
        ),
    ),
);
