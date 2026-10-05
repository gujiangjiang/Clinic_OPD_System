<?php
/**
 * ============================================================
 * main/24_integration.php — 外部接口/系统集成（Outbox）
 * ============================================================ */
return array(
    'tables' => array(),
    'migrations' => array(
        // v41：外部接口/系统集成出向架构——
        //  · his_sync_tasks 出向同步任务补偿表（Outbox）：挂号/收费结算/开单/发药
        //    等业务提交后异步入队，后台任务调用 HIS/FHIR/HL7/LIS 驱动投递；
        //    500/超时等异常保留失败状态供监控面板一键重试。UNIQUE(business_type,
        //    business_id) 保证同业务只保留一条任务（重复入队幂等合并）。
        //  注：入向调用审计（原 inbound_events）已统一到日志中心·接口日志，
        //      见 v48 下线说明。
        41 => array(
            "CREATE TABLE IF NOT EXISTS his_sync_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_type TEXT NOT NULL,
                business_id INTEGER NOT NULL DEFAULT 0,
                payload TEXT NOT NULL DEFAULT '{}',
                status TEXT NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT '',
                created_at TEXT,
                updated_at TEXT,
                UNIQUE(business_type, business_id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_his_sync_tasks_status ON his_sync_tasks(status, updated_at)",
        ),
        // v48：下线冗余的入向审计表 inbound_events——
        // 入向调用早已改为"双写"（写 inbound_events + 写日志中心·接口日志 system_logs），
        // 且接口管理面板的入向审计板块已移除，该表成为无 UI 读取、无保留策略的重复数据源
        // （常见 60 万+ 行）。现停止写入（integration_log_inbound 只写日志中心）并移除该表，
        // 消除冗余存储与无界增长；来源 IP 由 system_logs.remote_ip 承载（clientIp() 更准确）。
        48 => array(
            "DROP TABLE IF EXISTS inbound_events",
        ),
    ),
);
