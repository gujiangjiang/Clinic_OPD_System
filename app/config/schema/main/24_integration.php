<?php
/**
 * ============================================================
 * main/24_integration.php — 外部接口/系统集成（Outbox / 入向审计）
 * ============================================================ */
return array(
    'tables' => array(),
    'migrations' => array(
        // v41：外部接口/系统集成双向架构——
        //  · his_sync_tasks 出向同步任务补偿表（Outbox）：挂号/收费结算/开单/发药
        //    等业务提交后异步入队，后台任务调用 HIS/FHIR/HL7/LIS 驱动投递；
        //    500/超时等异常保留失败状态供监控面板一键重试。UNIQUE(business_type,
        //    business_id) 保证同业务只保留一条任务（重复入队幂等合并）。
        //  · inbound_events 入向调用审计表：FHIR/HL7/LIS/HIS/支付回调等所有
        //    对外暴露端点接收的请求统一落账，供监控面板溯源与排障。
        // 注：v41 中 reports.pdf_url 归属检验/检查模块（见 14_lab_exam.php）。
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
            "CREATE TABLE IF NOT EXISTS inbound_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint TEXT NOT NULL DEFAULT '',
                provider TEXT NOT NULL DEFAULT '',
                is_success INTEGER NOT NULL DEFAULT 1,
                summary TEXT DEFAULT '',
                payload TEXT DEFAULT '',
                remote_ip TEXT DEFAULT '',
                created_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_inbound_events_created ON inbound_events(created_at)",
        ),
    ),
);
